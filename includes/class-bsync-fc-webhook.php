<?php
/**
 * Inbound webhook endpoint.
 *
 * Registers the ONLY unauthenticated route this plugin exposes:
 *
 *   POST /wp-json/burrowsync-for-farcaster/v1/webhook
 *
 * Every delivery is authenticated by Neynar's webhook signature before
 * anything else happens:
 *
 *   - Header:    X-Neynar-Signature
 *   - Algorithm: HMAC SHA-512 over the RAW request body, hex-encoded
 *   - Secret:    the webhook shared secret issued by Neynar when the
 *                webhook was created (stored encrypted, see BSync_FC_Settings)
 *   - Compare:   hash_equals() (constant-time)
 *
 * (Verified against docs.neynar.com "Verify webhooks with HMAC
 * signatures" at build time. The signature MUST be computed over the raw
 * bytes — never re-serialize the parsed JSON.)
 *
 * Requests with a missing or invalid signature are rejected with 401 and
 * create nothing. Valid deliveries are acknowledged with 200 immediately;
 * the actual work (post creation, media sideloading) runs in a queued
 * WP-Cron job so the handler stays well inside webhook timeout budgets.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSync_FC_Webhook {

    const REST_NAMESPACE = 'burrowsync-for-farcaster/v1';
    const LEGACY_REST_NAMESPACE = 'farcaster-for-wp/v1';
    const REST_ROUTE     = '/webhook';
    const SIGNATURE_HEADER = 'X-Neynar-Signature';

    /** Cron hook that processes one stored webhook payload. */
    const JOB_HOOK   = 'bsync_fc_process_webhook_job';
    /** Option-name prefix for stored payloads awaiting processing. */
    const JOB_PREFIX = 'bsync_fc_webhook_job_';

    /** @var BSync_FC_Webhook|null */
    private static $instance = null;

    /** @var BSync_FC_Settings */
    private $settings;

    /**
     * @return BSync_FC_Webhook
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = BSync_FC_Settings::get_instance();
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action(self::JOB_HOOK, array($this, 'process_job'));
    }

    public function register_routes() {
        $args = array(
            'methods'  => 'POST',
            'callback' => array($this, 'handle_request'),
            // Public by design; authentication happens via the HMAC
            // signature check inside the callback.
            'permission_callback' => '__return_true',
        );
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, $args);

        // Webhooks created by the pre-release "Farcaster for WP" plugin point
        // at its namespace. Keep answering there so existing Neynar webhooks
        // keep delivering after the switch, with the same signature check.
        register_rest_route(self::LEGACY_REST_NAMESPACE, self::REST_ROUTE, $args);
    }

    /**
     * The endpoint URL for this site — derived from the site's own REST
     * URL, never hardcoded. Shown in the setup screen for copy-paste and
     * used when creating the webhook programmatically.
     *
     * @return string
     */
    public static function endpoint_url() {
        return rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
    }

    /**
     * REST callback.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_request($request) {
        $raw = $request->get_body();
        $signature = $request->get_header(self::SIGNATURE_HEADER);
        $secret = $this->settings->get_webhook_secret();

        if (!self::verify_signature($raw, $signature, $secret)) {
            return new WP_REST_Response(
                array('error' => 'invalid signature'),
                401
            );
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['type'])) {
            // Authenticated but malformed; acknowledge so Neynar does not
            // retry a payload we can never parse.
            return new WP_REST_Response(array('ok' => true, 'skipped' => 'unparseable'), 200);
        }

        if ($payload['type'] !== 'cast.created' || empty($payload['data']) || !is_array($payload['data'])) {
            return new WP_REST_Response(array('ok' => true, 'skipped' => 'event type'), 200);
        }

        $cast = $payload['data'];

        // Cheap pre-filters before queueing. Author is re-verified in
        // BSync_FC_Inbound as well — the configured FID is authoritative no
        // matter how the webhook is (mis)configured upstream.
        $configured_fid = intval($this->settings->get('fid'));
        $author_fid = isset($cast['author']['fid']) ? intval($cast['author']['fid']) : 0;
        if (!$configured_fid || $author_fid !== $configured_fid) {
            return new WP_REST_Response(array('ok' => true, 'skipped' => 'author fid'), 200);
        }

        if (!$this->settings->get('inbound_enabled')) {
            return new WP_REST_Response(array('ok' => true, 'skipped' => 'inbound disabled'), 200);
        }

        $this->queue_cast($cast);

        return new WP_REST_Response(array('ok' => true), 200);
    }

    /**
     * Verify a webhook delivery. Pure function so the regression tests
     * can exercise it without WordPress.
     *
     * @param string      $raw_body  raw request bytes
     * @param string|null $signature value of X-Neynar-Signature
     * @param string|null $secret    webhook shared secret
     * @return bool
     */
    public static function verify_signature($raw_body, $signature, $secret) {
        if (!is_string($secret) || $secret === '') {
            // No secret configured -> nothing can be verified -> reject.
            return false;
        }
        if (!is_string($signature) || $signature === '' || !is_string($raw_body) || $raw_body === '') {
            return false;
        }
        $expected = hash_hmac('sha512', $raw_body, $secret);
        return hash_equals($expected, strtolower(trim($signature)));
    }

    /* ---------------------------------------------------------------------
     * Deferred processing
     * ------------------------------------------------------------------ */

    /**
     * Store the cast payload and schedule an immediate cron job for it.
     * The payload lives in its own option row (cron args stay tiny) and
     * is keyed by cast hash, which also collapses duplicate deliveries
     * that arrive before the first job has run.
     *
     * @param array $cast the cast object from the webhook payload
     */
    private function queue_cast(array $cast) {
        $hash = isset($cast['hash']) ? strtolower(sanitize_text_field($cast['hash'])) : '';
        if ($hash === '') {
            return;
        }
        $key = self::JOB_PREFIX . md5($hash);
        update_option($key, wp_json_encode($cast), false);

        if (!wp_next_scheduled(self::JOB_HOOK, array($key))) {
            wp_schedule_single_event(time(), self::JOB_HOOK, array($key));
        }
        if (!defined('DOING_CRON') || !DOING_CRON) {
            spawn_cron(); // process within seconds, not on the next visit
        }
    }

    /**
     * Cron callback: create the note (idempotently) from a stored payload.
     *
     * @param string $key option name holding the payload
     */
    public function process_job($key) {
        if (strpos($key, self::JOB_PREFIX) !== 0) {
            return;
        }
        $raw = get_option($key);
        delete_option($key);
        if (!$raw) {
            return;
        }
        $cast = json_decode($raw, true);
        if (!is_array($cast)) {
            return;
        }
        BSync_FC_Inbound::get_instance()->create_note_from_cast($cast);
    }
}
