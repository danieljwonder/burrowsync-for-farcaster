<?php
/**
 * Recurring WP-Cron tasks.
 *
 *  - bsync_fc_daily_signer_check: verifies the managed signer is still
 *    approved (handled in BSync_FC_Signer).
 *  - bsync_fc_poll_casts: OPTIONAL resilience fallback (default off). Sites
 *    that cannot receive webhooks (behind auth walls, no public HTTPS)
 *    or that want belt-and-braces coverage for webhook outages can poll
 *    the connected FID's recent casts via the Neynar feed API. The poll
 *    funnels every cast through the same idempotent inbound path keyed
 *    on cast hash, so webhook + poll can never double-create a note.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSync_FC_Cron {

    const POLL_HOOK  = 'bsync_fc_poll_casts';
    const CHECK_HOOK = 'bsync_fc_daily_signer_check';
    const POLL_INTERVAL = 'bsync_fc_fifteen_minutes';

    /** @var BSync_FC_Cron|null */
    private static $instance = null;

    /** @var BSync_FC_Settings */
    private $settings;

    /**
     * @return BSync_FC_Cron
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = BSync_FC_Settings::get_instance();

        add_filter('cron_schedules', array($this, 'register_interval'));
        add_action(self::POLL_HOOK, array($this, 'poll_casts'));
        add_action('init', array($this, 'sync_poll_schedule'), 30);
    }

    /**
     * @param array $schedules
     * @return array
     */
    public function register_interval($schedules) {
        $schedules[self::POLL_INTERVAL] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Every 15 minutes (BurrowSync for Farcaster)', 'burrowsync-for-farcaster'),
        );
        return $schedules;
    }

    /**
     * Keep the poll schedule in step with the setting.
     */
    public function sync_poll_schedule() {
        $enabled = (bool) $this->settings->get('polling_enabled');
        $scheduled = (bool) wp_next_scheduled(self::POLL_HOOK);

        if ($enabled && !$scheduled) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::POLL_INTERVAL, self::POLL_HOOK);
        } elseif (!$enabled && $scheduled) {
            wp_clear_scheduled_hook(self::POLL_HOOK);
        }
    }

    /**
     * Activation-time scheduling of the daily signer health check.
     */
    public static function schedule_recurring() {
        if (!wp_next_scheduled(self::CHECK_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CHECK_HOOK);
        }
    }

    /**
     * Deactivation-time unscheduling (options and meta stay untouched).
     */
    public static function unschedule_recurring() {
        wp_clear_scheduled_hook(self::CHECK_HOOK);
        wp_clear_scheduled_hook(self::POLL_HOOK);
    }

    /**
     * Poll recent casts by the connected FID and run each through the
     * idempotent inbound path. Uses a persisted "seen up to" timestamp so
     * each run only touches new casts; pagination is unnecessary because
     * runs are 15 minutes apart and the feed returns newest-first.
     */
    public function poll_casts() {
        if (!$this->settings->get('polling_enabled') || !$this->settings->get('inbound_enabled')) {
            return;
        }
        $fid = intval($this->settings->get('fid'));
        if (!$fid || !$this->settings->is_connected()) {
            return;
        }

        $client = new BSync_FC_Neynar_Client($this->settings);
        $result = $client->get_user_casts($fid, 25, '', (bool) $this->settings->get('include_replies'));
        if (is_wp_error($result) || empty($result['casts']) || !is_array($result['casts'])) {
            return;
        }

        $since = intval($this->settings->get('polling_cursor'));
        $newest_seen = $since;
        $inbound = BSync_FC_Inbound::get_instance();

        foreach ($result['casts'] as $cast) {
            if (!is_array($cast)) {
                continue;
            }
            $ts = BSync_FC_Inbound::cast_timestamp_to_unix(isset($cast['timestamp']) ? $cast['timestamp'] : null);
            if ($since && $ts <= $since) {
                continue; // already covered by a previous run
            }
            $newest_seen = max($newest_seen, $ts);

            // Recasts are excluded unless opted in. In the user-casts
            // feed a recast surfaces as a cast authored by someone else,
            // so the inbound author-FID check drops them; the toggle is
            // honoured there implicitly.
            $inbound->create_note_from_cast($cast);
        }

        if ($newest_seen > $since) {
            $this->settings->set('polling_cursor', $newest_seen);
        }
    }
}
