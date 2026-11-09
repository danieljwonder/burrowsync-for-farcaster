<?php
/**
 * REGRESSION TEST: posts created by inbound Farcaster sync must NEVER be
 * queued for outbound publishing (the anti-echo guard, content-flow
 * rule 4). Not "queued then skipped" — never queued at all.
 *
 * Uses the REAL BSync_FC_Inbound create path and the REAL BSync_FC_Publisher,
 * with a wp_insert_post() stub (tests/wp-stubs.php) that reproduces
 * WordPress core's ordering exactly:
 *
 *   1. post row created
 *   2. meta_input written           <- _farcaster_origin lands here
 *   3. transition_post_status fires <- the publisher's guard runs here
 *
 * If the origin stamp ever moves out of meta_input (e.g. back to a
 * post-insert update_post_meta call), step 3 sees no origin meta and the
 * post gets queued — and this test fails.
 *
 * Also covered here because they share the harness:
 *   - control: a WP-native note IS queued (guard is not overbroad)
 *   - rule 3: a Nostr-origin note IS queued (mirrored content flows on)
 *   - interop: _nostr_origin is stamped on Farcaster-origin notes when
 *     the bridge setting is off, and not stamped when it is on
 *
 * Standalone: no WordPress, no network. All identity values are fake.
 */

require __DIR__ . '/wp-stubs.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-publisher.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-inbound.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-neynar-client.php';

$settings = bsync_fc_test_connect_settings();
check('precondition: install is connected', $settings->is_connected());

$publisher = BSync_FC_Publisher::get_instance();
$inbound = BSync_FC_Inbound::get_instance();

/* ---- control: a WP-native note publish DOES queue ---- */

$native_id = wp_insert_post(array(
    'post_title'  => 'Native WP note',
    'post_content'=> 'Written in WordPress by a human.',
    'post_status' => 'publish',
    'post_type'   => 'note',
));
check('control: native WP note IS queued', wp_next_scheduled(BSync_FC_Publisher::CRON_HOOK, array($native_id)) !== false);

/* ---- rule 3: a Nostr-origin note DOES queue for Farcaster ---- */

$nostr_id = wp_insert_post(array(
    'post_title'  => 'Nostr-origin note',
    'post_content'=> 'Mirrored into WP by burrowsync-for-nostr.',
    'post_status' => 'publish',
    'post_type'   => 'note',
    'meta_input'  => array('_nostr_origin' => '1'), // as burrowsync-for-nostr stamps it
));
check('rule 3: Nostr-origin note IS queued for Farcaster', wp_next_scheduled(BSync_FC_Publisher::CRON_HOOK, array($nostr_id)) !== false);

/* ---- rule 4: an inbound cast -> note must NEVER be queued ---- */

$cast = array(
    'hash'      => '0x' . str_repeat('11', 20),
    'author'    => array('fid' => 12345, 'username' => 'examplecaster'),
    'text'      => "An inbound cast that must not echo back out.\n\nSecond line.",
    'timestamp' => '2024-02-15T19:23:22.000Z',
    'parent_hash' => null,
    'parent_author' => array('fid' => null),
    'embeds'    => array(),
);
$note_id = $inbound->create_note_from_cast($cast);
check('inbound note created', $note_id !== false && $note_id > 0);
check('inbound note has _farcaster_origin', (bool) get_post_meta($note_id, '_farcaster_origin', true));
check('inbound note has cast hash stored', get_post_meta($note_id, '_farcaster_cast_hash', true) === '0x' . str_repeat('11', 20));
check('inbound note: no cron attempt scheduled', wp_next_scheduled(BSync_FC_Publisher::CRON_HOOK, array($note_id)) === false);
$history = isset($GLOBALS['__status_history'][$note_id]) ? $GLOBALS['__status_history'][$note_id] : array();
check('inbound note: status NEVER set to queued', !in_array('queued', $history, true));

/* ---- epoch conversion lands the correct post date ---- */

$expected_unix = strtotime('2024-02-15T19:23:22.000Z');
$post = get_post($note_id);
check('inbound note post date matches cast time', $post->post_date === gmdate('Y-m-d H:i:s', $expected_unix));

/* ---- interop: _nostr_origin suppression stamp (bridge OFF, default) ---- */

check('bridge OFF: inbound note stamped _nostr_origin', (bool) get_post_meta($note_id, '_nostr_origin', true));

/* ---- interop: no suppression stamp when bridge is ON ---- */

$settings->set('allow_nostr_bridge', true);
$cast_two = $cast;
$cast_two['hash'] = '0x' . str_repeat('22', 20);
$note_two = $inbound->create_note_from_cast($cast_two);
check('bridge ON: inbound note created', $note_two !== false && $note_two > 0);
check('bridge ON: no _nostr_origin stamp', !get_post_meta($note_two, '_nostr_origin', true));
check('bridge ON: still has _farcaster_origin', (bool) get_post_meta($note_two, '_farcaster_origin', true));
check('bridge ON: still never queued', wp_next_scheduled(BSync_FC_Publisher::CRON_HOOK, array($note_two)) === false);
$settings->set('allow_nostr_bridge', false);

/* ---- author FID is authoritative: foreign casts are dropped ---- */

$foreign = $cast;
$foreign['hash'] = '0x' . str_repeat('33', 20);
$foreign['author'] = array('fid' => 99999, 'username' => 'someoneelse');
check('cast by a different FID is not imported', $inbound->create_note_from_cast($foreign) === false);

/* ---- replies are excluded by default, included when opted in ---- */

$reply = $cast;
$reply['hash'] = '0x' . str_repeat('44', 20);
$reply['parent_hash'] = '0x' . str_repeat('55', 20);
$reply['parent_author'] = array('fid' => 67890);
check('reply excluded by default', $inbound->create_note_from_cast($reply) === false);

$settings->set('include_replies', true);
$reply_id = $inbound->create_note_from_cast($reply);
check('reply imported when include_replies is on', $reply_id !== false && $reply_id > 0);
check('imported reply never queued', wp_next_scheduled(BSync_FC_Publisher::CRON_HOOK, array($reply_id)) === false);
$settings->set('include_replies', false);

finish('INBOUND-GUARD');
