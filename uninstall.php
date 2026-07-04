<?php
/**
 * Uninstall handler for BurrowSync for Farcaster.
 *
 * Runs only when the plugin is DELETED from the plugins screen (never on
 * deactivation — deactivate + reactivate must not lose the signer or any
 * sync state).
 *
 * Default behaviour is conservative: remove the plugin's options
 * (including the encrypted API key and webhook secret) and scheduled
 * events, but leave all post content AND all origin/sync post meta
 * intact. Origin meta (`_farcaster_origin`, and `_nostr_origin` where
 * this plugin stamped it) is what prevents echo loops if the plugin —
 * or its companion — is ever reinstalled, so it is only removed when the
 * user explicitly opted into a full purge on the settings screen.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$bsync_fc_settings = get_option('bsync_fc_settings', array());
$bsync_fc_full_purge = is_array($bsync_fc_settings) && !empty($bsync_fc_settings['purge_on_uninstall']);

// Scheduled events.
if (function_exists('wp_unschedule_hook')) {
    wp_unschedule_hook('bsync_fc_process_outbound_post');
    wp_unschedule_hook('bsync_fc_process_webhook_job');
    wp_unschedule_hook('bsync_fc_poll_casts');
    wp_unschedule_hook('bsync_fc_daily_signer_check');
}

// Options (secrets included — they are useless without the plugin).
delete_option('bsync_fc_settings');
delete_option('bsync_fc_secrets');
delete_option('bsync_fc_show_setup_notice');

// Pre-release "Farcaster for WP" option names, in case this install was
// migrated from before the BurrowSync rename.
delete_option('fcwp_settings');
delete_option('fcwp_secrets');
delete_option('fcwp_show_setup_notice');

// Any webhook payloads still queued (both option-name generations).
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bsync\\_fc\\_webhook\\_job\\_%' OR option_name LIKE 'fcwp\\_webhook\\_job\\_%'");

if ($bsync_fc_full_purge) {
    // Explicitly requested full purge: strip this plugin's post meta.
    // Posts themselves are NEVER deleted. `_nostr_origin` is left alone —
    // it may also have been written by the companion nostr-for-wp plugin
    // and removing it could cause that plugin to re-publish old posts.
    $bsync_fc_meta_keys = array(
        '_farcaster_origin',
        '_farcaster_cast_hash',
        '_farcaster_cast_url',
        '_farcaster_cast_timestamp',
        '_farcaster_channel',
        '_farcaster_sync_status',
        '_farcaster_synced_at',
        '_farcaster_attempts',
        '_farcaster_first_failure',
        '_farcaster_next_retry',
        '_farcaster_last_error',
        '_farcaster_idem',
    );
    foreach ($bsync_fc_meta_keys as $bsync_fc_key) {
        delete_post_meta_by_key($bsync_fc_key);
    }
}
