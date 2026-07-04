<?php
/**
 * Outbound queue/retry behaviour with the real BSync_FC_Publisher and real
 * BSync_FC_Neynar_Client, driving the WordPress HTTP layer with a stub:
 *
 *  - Neynar unreachable at publish time -> the WordPress publish still
 *    succeeds, the post is queued, a retry is scheduled with backoff
 *  - the retry succeeds later, the cast hash is stored
 *  - further attempts are skipped (exactly-once)
 *  - the SAME idem idempotency key is sent on every attempt, so an
 *    ambiguous timeout (delivered but response lost) cannot double-post
 */

require __DIR__ . '/wp-stubs.php';

/* ---- WP HTTP API stub: scriptable per-test behaviour ---- */

$GLOBALS['__http_mode'] = 'down';   // 'down' | 'ok'
$GLOBALS['__http_requests'] = array(); // captured request bodies

function wp_remote_request($url, $args = array()) {
    $GLOBALS['__http_requests'][] = array('url' => $url, 'args' => $args);
    if ($GLOBALS['__http_mode'] === 'down') {
        return new WP_Error('http_request_failed', 'cURL error 7: connection refused');
    }
    return array(
        'response' => array('code' => 200),
        'body' => json_encode(array(
            'success' => true,
            'cast' => array(
                'hash' => '0x' . str_repeat('dd', 20),
                'author' => array('fid' => 12345),
                'text' => 'ok',
            ),
        )),
    );
}
function wp_remote_retrieve_response_code($response) {
    return isset($response['response']['code']) ? $response['response']['code'] : 0;
}
function wp_remote_retrieve_body($response) {
    return isset($response['body']) ? $response['body'] : '';
}
function add_query_arg($args, $url) {
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
}

require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-neynar-client.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-inbound.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-publisher.php';

$settings = bsync_fc_test_connect_settings();
$publisher = BSync_FC_Publisher::get_instance();

/* ---- publish while Neynar is down ---- */

$post_id = wp_insert_post(array(
    'post_title'   => 'Note published during an outage',
    'post_content' => 'This must not be lost.',
    'post_status'  => 'publish',
    'post_type'    => 'note',
));
check('publish succeeded in WordPress (post exists)', get_post($post_id) !== null);
check('post was queued', get_post_meta($post_id, '_farcaster_sync_status', true) === 'queued');
check('idem key minted at queue time', get_post_meta($post_id, '_farcaster_idem', true) !== '');
$idem_first = get_post_meta($post_id, '_farcaster_idem', true);

$result = $publisher->process_post($post_id);
check('attempt while down fails', $result['success'] === false);
check('post still queued (not failed yet)', get_post_meta($post_id, '_farcaster_sync_status', true) === 'queued');
check('attempts recorded', intval(get_post_meta($post_id, '_farcaster_attempts', true)) === 1);
check('last error stored', get_post_meta($post_id, '_farcaster_last_error', true) !== '');

$next = wp_next_scheduled(BSync_FC_Publisher::CRON_HOOK, array($post_id));
check('retry scheduled', $next !== false);
$delay = $next - time();
check('first backoff is ~5 minutes', $delay >= 290 && $delay <= 310);

/* ---- second failure: backoff grows to ~15 minutes ---- */

$result = $publisher->process_post($post_id);
check('second attempt fails too', $result['success'] === false);
$next = wp_next_scheduled(BSync_FC_Publisher::CRON_HOOK, array($post_id));
$delay = $next - time();
check('second backoff is ~15 minutes', $delay >= 890 && $delay <= 910);

/* ---- Neynar recovers: retry succeeds exactly once ---- */

$GLOBALS['__http_mode'] = 'ok';
$requests_before = count($GLOBALS['__http_requests']);

$result = $publisher->process_post($post_id);
check('retry succeeds after recovery', $result['success'] === true);
check('cast hash stored on the post', get_post_meta($post_id, '_farcaster_cast_hash', true) === '0x' . str_repeat('dd', 20));
check('status is synced', get_post_meta($post_id, '_farcaster_sync_status', true) === 'synced');
check('no further retry scheduled', wp_next_scheduled(BSync_FC_Publisher::CRON_HOOK, array($post_id)) === false);
check('failure bookkeeping cleared', get_post_meta($post_id, '_farcaster_attempts', true) === '' && get_post_meta($post_id, '_farcaster_last_error', true) === '');

/* ---- idem key was identical on every attempt ---- */

$idems = array();
foreach ($GLOBALS['__http_requests'] as $req) {
    if (!empty($req['args']['body'])) {
        $body = json_decode($req['args']['body'], true);
        if (isset($body['idem'])) {
            $idems[] = $body['idem'];
        }
    }
}
check('three publish attempts were made', count($idems) === 3);
check('same idem key on every attempt', count(array_unique($idems)) === 1 && $idems[0] === $idem_first);

/* ---- exactly-once: another process_post call is a no-op ---- */

$result = $publisher->process_post($post_id);
check('already-published post is skipped', $result['success'] === true);
check('no extra HTTP request made', count($GLOBALS['__http_requests']) === $requests_before + 1);

/* ---- give-up path: fresh post, permanently down, window elapsed ---- */

$GLOBALS['__http_mode'] = 'down';
$stuck_id = wp_insert_post(array(
    'post_title'   => 'Note that will exhaust retries',
    'post_content' => 'Doomed.',
    'post_status'  => 'publish',
    'post_type'    => 'note',
));
$publisher->process_post($stuck_id); // first failure stamps the window start
update_post_meta($stuck_id, '_farcaster_first_failure', time() - DAY_IN_SECONDS - 1);
$publisher->process_post($stuck_id);
check('post marked failed after the 24h window', get_post_meta($stuck_id, '_farcaster_sync_status', true) === 'failed');
check('no retry scheduled after giving up', wp_next_scheduled(BSync_FC_Publisher::CRON_HOOK, array($stuck_id)) === false);

finish('OUTBOUND-RETRY');
