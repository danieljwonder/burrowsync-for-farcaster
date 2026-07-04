<?php
/**
 * Signer connection management (Neynar managed signers).
 *
 * WHY SIGN IN WITH NEYNAR (SIWN) AND NOT RAW SIGNER CREATION:
 *
 * Neynar's raw managed-signer flow (POST /v2/farcaster/signer then
 * POST /v2/farcaster/signer/signed_key) requires the *app developer's*
 * Farcaster custody wallet to produce an EIP-712 signature over the new
 * signer key. That is unworkable for a self-hosted WordPress plugin:
 * there is no app operator, and asking users to paste a custody seed
 * phrase into wp-admin would be reckless. Verified against
 * docs.neynar.com at build time.
 *
 * SIWN is Neynar's supported no-mnemonic path: the user creates a free
 * "client ID" in the Neynar developer portal (same account as the API
 * key), the settings page renders Neynar's hosted sign-in button, and
 * the approval — including QR code / deeplink handling for mobile
 * custody wallets — happens on Neynar's hosted page. The callback hands
 * back the fid and signer_uuid, which this class verifies SERVER-SIDE
 * against GET /v2/farcaster/signer before storing anything: the browser
 * callback is untrusted input.
 *
 * A manual fallback accepts a signer_uuid created elsewhere (e.g. via
 * Neynar's own tooling) and runs the same server-side verification.
 *
 * The signer's custody model, stated plainly for the readme: the signing
 * key lives with Neynar, is approved by the user's Farcaster custody
 * wallet, and can be revoked from that wallet at any time. This class
 * re-checks approval status daily (and on settings load) and surfaces a
 * reconnect flow when the signer is revoked.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSync_FC_Signer {

    /** @var BSync_FC_Signer|null */
    private static $instance = null;

    /** @var BSync_FC_Settings */
    private $settings;

    /**
     * @return BSync_FC_Signer
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = BSync_FC_Settings::get_instance();

        add_action('wp_ajax_bsync_fc_connect_signer', array($this, 'connect_signer_ajax'));
        add_action('wp_ajax_bsync_fc_disconnect_signer', array($this, 'disconnect_signer_ajax'));
        add_action('bsync_fc_daily_signer_check', array($this, 'check_signer_health'));
    }

    /**
     * AJAX: store a signer after verifying it with Neynar. Used both by
     * the SIWN success callback and the manual signer_uuid form.
     */
    public function connect_signer_ajax() {
        check_ajax_referer('bsync_fc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'burrowsync-for-farcaster'));
        }

        $signer_uuid = isset($_POST['signer_uuid']) ? sanitize_text_field(wp_unslash($_POST['signer_uuid'])) : '';
        if ($signer_uuid === '') {
            wp_send_json_error(__('Missing signer UUID.', 'burrowsync-for-farcaster'));
        }

        $result = $this->verify_and_store_signer($signer_uuid);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: forget the stored signer locally (does not revoke on-chain;
     * revocation belongs to the user's custody wallet).
     */
    public function disconnect_signer_ajax() {
        check_ajax_referer('bsync_fc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'burrowsync-for-farcaster'));
        }

        $this->settings->update(array(
            'signer_uuid'   => '',
            'signer_status' => '',
            'fid'           => 0,
            'username'      => '',
        ));

        wp_send_json_success(array('message' => __('Signer disconnected.', 'burrowsync-for-farcaster')));
    }

    /**
     * Verify a signer_uuid against Neynar and persist identity on success.
     * The FID comes from Neynar's signer lookup — never from the browser.
     *
     * @param string $signer_uuid
     * @return array|WP_Error array with fid/username/status on success
     */
    public function verify_and_store_signer($signer_uuid) {
        $client = new BSync_FC_Neynar_Client($this->settings);

        $signer = $client->lookup_signer($signer_uuid);
        if (is_wp_error($signer)) {
            return $signer;
        }

        $status = isset($signer['status']) ? sanitize_text_field($signer['status']) : '';
        $fid = isset($signer['fid']) ? intval($signer['fid']) : 0;

        if ($status !== 'approved' || !$fid) {
            return new WP_Error('bsync_fc_signer_not_approved', sprintf(
                /* translators: %s: signer status reported by Neynar */
                __('Signer is not approved yet (status: %s). Complete the approval in your Farcaster app, then try again.', 'burrowsync-for-farcaster'),
                $status ? $status : __('unknown', 'burrowsync-for-farcaster')
            ));
        }

        $username = '';
        $user = $client->get_user($fid);
        if (!is_wp_error($user) && !empty($user['username'])) {
            $username = sanitize_text_field($user['username']);
        }

        $this->settings->update(array(
            'signer_uuid'   => $signer_uuid,
            'signer_status' => 'approved',
            'fid'           => $fid,
            'username'      => $username,
        ));

        delete_option('bsync_fc_show_setup_notice');

        return array(
            'fid'      => $fid,
            'username' => $username,
            'status'   => 'approved',
        );
    }

    /**
     * Re-check that the stored signer is still approved. Runs daily via
     * cron and on settings-page load. A revoked signer flips the stored
     * status so the admin UI shows a prominent reconnect warning.
     *
     * @return string current status ('' when nothing is configured)
     */
    public function check_signer_health() {
        $signer_uuid = (string) $this->settings->get('signer_uuid');
        if ($signer_uuid === '' || $this->settings->get_api_key() === null) {
            return '';
        }

        $client = new BSync_FC_Neynar_Client($this->settings);
        $signer = $client->lookup_signer($signer_uuid);
        if (is_wp_error($signer)) {
            // Transient API failure: keep the stored status; do not lock
            // the user out because Neynar had a bad minute.
            return (string) $this->settings->get('signer_status');
        }

        $status = isset($signer['status']) ? sanitize_text_field($signer['status']) : '';
        if ($status !== '') {
            $this->settings->set('signer_status', $status);
        }
        return $status;
    }
}
