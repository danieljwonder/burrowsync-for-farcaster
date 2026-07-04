<?php
/**
 * Webhook security and idempotency:
 *
 *  - missing signature  -> 401, nothing created
 *  - invalid signature  -> 401, nothing created
 *  - no secret stored   -> 401 (nothing can be verified)
 *  - valid signature    -> 200, note created via the queued job
 *  - redelivery         -> 200, NO duplicate note
 *  - self-echo          -> 200, NO note (hash already stored on the
 *                          outbound post by the publisher)
 *
 * Signature scheme per docs.neynar.com: X-Neynar-Signature carries a
 * hex HMAC SHA-512 of the raw request body keyed with the webhook
 * shared secret.
 */

require __DIR__ . '/wp-stubs.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-publisher.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-inbound.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-webhook.php';

/** Minimal stand-in for WP_REST_Request. */
class BSync_FC_Fake_Request {
    private $body;
    private $headers;
    public function __construct($body, array $headers = array()) {
        $this->body = $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }
    public function get_body() { return $this->body; }
    public function get_header($name) {
        $name = strtolower($name);
        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }
}

$settings = bsync_fc_test_connect_settings();
$secret = 'test-webhook-shared-secret';
$webhook = BSync_FC_Webhook::get_instance();

/** Deliver a payload and drain any queued job, returning the response. */
function deliver($webhook, $body, $signature) {
    $request = new BSync_FC_Fake_Request($body, $signature !== null ? array('X-Neynar-Signature' => $signature) : array());
    $response = $webhook->handle_request($request);
    // Drain queued jobs the way WP-Cron would.
    foreach (array_keys($GLOBALS['__cron']) as $key) {
        if (strpos($key, BSync_FC_Webhook::JOB_HOOK) === 0) {
            $args = json_decode(substr($key, strlen(BSync_FC_Webhook::JOB_HOOK) + 1), true);
            unset($GLOBALS['__cron'][$key]);
            $webhook->process_job($args[0]);
        }
    }
    return $response;
}

function count_notes() {
    $n = 0;
    foreach ($GLOBALS['__posts'] as $post) {
        if ($post->post_type === 'note') {
            $n++;
        }
    }
    return $n;
}

$payload = json_encode(array(
    'created_at' => 1708025006,
    'type' => 'cast.created',
    'data' => array(
        'object' => 'cast',
        'hash' => '0x' . str_repeat('aa', 20),
        'parent_hash' => null,
        'parent_author' => array('fid' => null),
        'author' => array('object' => 'user', 'fid' => 12345, 'username' => 'examplecaster'),
        'text' => 'Hello from the Farcaster network.',
        'timestamp' => '2024-02-15T19:23:22.000Z',
        'embeds' => array(),
    ),
));
$good_sig = hash_hmac('sha512', $payload, $secret);

/* ---- pure verifier ---- */

check('verify: correct signature accepted', BSync_FC_Webhook::verify_signature($payload, $good_sig, $secret));
check('verify: wrong signature rejected', !BSync_FC_Webhook::verify_signature($payload, hash_hmac('sha512', $payload, 'wrong-secret'), $secret));
check('verify: empty signature rejected', !BSync_FC_Webhook::verify_signature($payload, '', $secret));
check('verify: null signature rejected', !BSync_FC_Webhook::verify_signature($payload, null, $secret));
check('verify: missing secret rejects everything', !BSync_FC_Webhook::verify_signature($payload, $good_sig, null));
check('verify: tampered body rejected', !BSync_FC_Webhook::verify_signature($payload . ' ', $good_sig, $secret));

/* ---- handler: rejects create nothing ---- */

$res = deliver($webhook, $payload, null);
check('missing signature -> 401', $res->get_status() === 401);
check('missing signature -> nothing created', count_notes() === 0);

$res = deliver($webhook, $payload, str_repeat('0', 128));
check('invalid signature -> 401', $res->get_status() === 401);
check('invalid signature -> nothing created', count_notes() === 0);

/* ---- handler: valid delivery creates exactly one note ---- */

$res = deliver($webhook, $payload, $good_sig);
check('valid signature -> 200', $res->get_status() === 200);
check('valid delivery -> one note created', count_notes() === 1);

/* ---- redelivery of the same cast -> no duplicate ---- */

$res = deliver($webhook, $payload, $good_sig);
check('redelivery -> 200', $res->get_status() === 200);
check('redelivery -> still one note', count_notes() === 1);

/* ---- self-echo: our own outbound cast comes back via webhook ---- */

// Simulate the publisher having cast a WP-native note: the post exists
// and carries the returned cast hash.
$outbound_id = wp_insert_post(array(
    'post_title'   => 'Native note that we casted',
    'post_content' => 'Casted to Farcaster by this plugin.',
    'post_status'  => 'publish',
    'post_type'    => 'note',
));
$echo_hash = '0x' . str_repeat('bb', 20);
update_post_meta($outbound_id, '_farcaster_cast_hash', $echo_hash);
$before = count_notes();

$echo_payload = json_encode(array(
    'created_at' => 1708025999,
    'type' => 'cast.created',
    'data' => array(
        'object' => 'cast',
        'hash' => $echo_hash,
        'parent_hash' => null,
        'parent_author' => array('fid' => null),
        'author' => array('object' => 'user', 'fid' => 12345, 'username' => 'examplecaster'),
        'text' => 'Casted to Farcaster by this plugin.',
        'timestamp' => '2024-02-15T20:00:00.000Z',
        'embeds' => array(),
    ),
));
$res = deliver($webhook, $echo_payload, hash_hmac('sha512', $echo_payload, $secret));
check('self-echo -> 200', $res->get_status() === 200);
check('self-echo -> NO new note created', count_notes() === $before);

/* ---- casts by other FIDs never import, even if delivered ---- */

$foreign_payload = json_encode(array(
    'type' => 'cast.created',
    'data' => array(
        'hash' => '0x' . str_repeat('cc', 20),
        'parent_hash' => null,
        'parent_author' => array('fid' => null),
        'author' => array('fid' => 99999, 'username' => 'someoneelse'),
        'text' => 'A cast by a different user.',
        'timestamp' => '2024-02-15T21:00:00.000Z',
        'embeds' => array(),
    ),
));
$before = count_notes();
$res = deliver($webhook, $foreign_payload, hash_hmac('sha512', $foreign_payload, $secret));
check('foreign-FID cast -> 200 (acknowledged)', $res->get_status() === 200);
check('foreign-FID cast -> nothing created', count_notes() === $before);

finish('WEBHOOK');
