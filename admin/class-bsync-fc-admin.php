<?php
/**
 * Admin UI: setup screen (Settings > Farcaster), first-run notice, the
 * "Farcaster" column on the note list, the per-post meta box, and the
 * settings save / webhook-creation handlers.
 *
 * Every mutating action checks `manage_options` and a nonce. Secrets are
 * shown masked after save and are only overwritten when a new,
 * non-masked value is submitted.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSync_FC_Admin {

    const PAGE_SLUG = 'burrowsync-for-farcaster';

    /** @var BSync_FC_Admin|null */
    private static $instance = null;

    /** @var BSync_FC_Settings */
    private $settings;

    /**
     * @return BSync_FC_Admin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = BSync_FC_Settings::get_instance();

        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_notices', array($this, 'setup_notice'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_siwn_redirect'), 5);
        add_action('admin_post_bsync_fc_save_settings', array($this, 'save_settings'));
        add_action('wp_ajax_bsync_fc_create_webhook', array($this, 'create_webhook_ajax'));

        add_filter('manage_note_posts_columns', array($this, 'note_columns'));
        add_action('manage_note_posts_custom_column', array($this, 'note_column_content'), 10, 2);
        add_action('add_meta_boxes_note', array($this, 'add_meta_box'));

        add_filter('plugin_action_links_' . BSYNC_FC_PLUGIN_BASENAME, array($this, 'action_links'));
    }

    /* ---------------------------------------------------------------------
     * Menu, notice, assets
     * ------------------------------------------------------------------ */

    public function add_menu() {
        add_options_page(
            __('Farcaster Settings', 'burrowsync-for-farcaster'),
            __('Farcaster', 'burrowsync-for-farcaster'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_settings_page')
        );
    }

    /**
     * @param array $links
     * @return array
     */
    public function action_links($links) {
        array_unshift($links, '<a href="' . esc_url($this->settings_url()) . '">' . esc_html__('Settings', 'burrowsync-for-farcaster') . '</a>');
        return $links;
    }

    /**
     * First-run notice pointing at the setup screen, plus a persistent
     * warning when the signer has been revoked from the custody wallet.
     */
    public function setup_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_settings = $screen && strpos((string) $screen->id, self::PAGE_SLUG) !== false;

        if ($this->settings->get('signer_status') === 'revoked' && !$on_settings) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
                esc_html__('Farcaster signer revoked.', 'burrowsync-for-farcaster'),
                esc_html__('This site can no longer publish to Farcaster.', 'burrowsync-for-farcaster'),
                esc_url($this->settings_url()),
                esc_html__('Reconnect now', 'burrowsync-for-farcaster')
            );
        }

        if (!get_option('bsync_fc_show_setup_notice') || $on_settings) {
            return;
        }
        if ($this->settings->is_connected()) {
            // Stale first-run flag, e.g. set before migrated credentials
            // could be decrypted. Clear it rather than nag a working site.
            delete_option('bsync_fc_show_setup_notice');
            return;
        }
        printf(
            '<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('BurrowSync for Farcaster is almost ready.', 'burrowsync-for-farcaster'),
            esc_html__('Connect your Neynar account to start syncing casts and notes.', 'burrowsync-for-farcaster'),
            esc_url($this->settings_url()),
            esc_html__('Open the setup screen', 'burrowsync-for-farcaster')
        );
    }

    /**
     * @param string $hook
     */
    public function enqueue_assets($hook) {
        $is_settings = strpos($hook, self::PAGE_SLUG) !== false;
        $is_post_screen = in_array($hook, array('post.php', 'post-new.php'), true);
        if (!$is_settings && !$is_post_screen) {
            return;
        }

        wp_enqueue_style('bsync-fc-admin', BSYNC_FC_PLUGIN_URL . 'admin/css/bsync-fc-admin.css', array(), BSYNC_FC_VERSION);
        wp_enqueue_script('bsync-fc-admin', BSYNC_FC_PLUGIN_URL . 'admin/js/bsync-fc-admin.js', array('jquery'), BSYNC_FC_VERSION, true);
        wp_localize_script('bsync-fc-admin', 'bsyncFcAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bsync_fc_admin_nonce'),
            'strings' => array(
                'working'      => __('Working…', 'burrowsync-for-farcaster'),
                'retry'        => __('Retry now', 'burrowsync-for-farcaster'),
                'createWebhook'=> __('Create webhook', 'burrowsync-for-farcaster'),
                'connected'    => __('Connected', 'burrowsync-for-farcaster'),
                'error'        => __('Something went wrong.', 'burrowsync-for-farcaster'),
                'confirmDisconnect' => __('Disconnect this signer from WordPress? (It stays approved on Farcaster until you revoke it from your custody wallet.)', 'burrowsync-for-farcaster'),
            ),
        ));

        if ($is_settings && $this->settings->get('client_id')) {
            // Neynar's hosted Sign In With Neynar button. Depends on
            // bsync-fc-admin so bsyncFcOnSignInSuccess exists before SIWN inits.
            wp_enqueue_script(
                'bsync-fc-siwn',
                'https://neynarxyz.github.io/siwn/raw/1.2.0/index.js',
                array('bsync-fc-admin'),
                null,
                true
            );
        }
    }

    /**
     * HTTP origin Neynar must allow for postMessage back to this site.
     * Must match the "Authorized origins" field in the Neynar developer
     * portal exactly (scheme + host + port, no path, no trailing slash).
     *
     * @return string e.g. https://example.kinsta.cloud
     */
    public static function authorized_origin() {
        $parts = wp_parse_url(home_url());
        if (!$parts || empty($parts['host'])) {
            return '';
        }
        $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'https';
        $origin = $scheme . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    /**
     * Defensive fallback: if a signer_uuid ever arrives as a query param on
     * the settings page (e.g. a future Neynar flow change, or a user pasting
     * a return URL), verify and store it server-side. The primary connect
     * path is the SIWN postMessage callback; this route is not normally hit.
     */
    public function handle_siwn_redirect() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth-style return from Neynar.
        if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== self::PAGE_SLUG) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (empty($_GET['signer_uuid'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $signer_uuid = sanitize_text_field(wp_unslash($_GET['signer_uuid']));
        if ($signer_uuid === '') {
            return;
        }

        $result = BSync_FC_Signer::get_instance()->verify_and_store_signer($signer_uuid);
        $args = array('page' => self::PAGE_SLUG);
        if (is_wp_error($result)) {
            set_transient(
                'bsync_fc_settings_errors_' . get_current_user_id(),
                array($result->get_error_message()),
                MINUTE_IN_SECONDS
            );
            $args['bsync_fc_error'] = '1';
        } else {
            $args['bsync_fc_connected'] = '1';
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    /* ---------------------------------------------------------------------
     * Settings save
     * ------------------------------------------------------------------ */

    /**
     * admin-post handler for the settings form.
     */
    public function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'burrowsync-for-farcaster'));
        }
        check_admin_referer('bsync_fc_save_settings');

        $errors = array();

        // --- Secrets: only overwrite when a new non-masked value arrives.
        if (isset($_POST['bsync_fc_api_key'])) {
            $api_key = sanitize_text_field(wp_unslash($_POST['bsync_fc_api_key']));
            if ($api_key !== '' && strpos($api_key, "\xE2\x80\xA2") === false) {
                if ($this->settings->can_encrypt()) {
                    $this->settings->set_api_key($api_key);
                } else {
                    $errors[] = __('Cannot store the API key: the PHP sodium extension or AUTH_KEY is missing.', 'burrowsync-for-farcaster');
                }
            }
        }
        if (isset($_POST['bsync_fc_webhook_secret'])) {
            $secret = sanitize_text_field(wp_unslash($_POST['bsync_fc_webhook_secret']));
            if ($secret !== '' && strpos($secret, "\xE2\x80\xA2") === false) {
                if ($this->settings->can_encrypt()) {
                    $this->settings->set_webhook_secret($secret);
                } else {
                    $errors[] = __('Cannot store the webhook secret: the PHP sodium extension or AUTH_KEY is missing.', 'burrowsync-for-farcaster');
                }
            }
        }

        // --- Non-secret settings.
        $updates = array(
            'client_id'       => isset($_POST['bsync_fc_client_id']) ? sanitize_text_field(wp_unslash($_POST['bsync_fc_client_id'])) : '',
            'inbound_enabled' => !empty($_POST['bsync_fc_inbound_enabled']),
            'outbound_enabled'=> !empty($_POST['bsync_fc_outbound_enabled']),
            'inbound_status'  => (isset($_POST['bsync_fc_inbound_status']) && $_POST['bsync_fc_inbound_status'] === 'draft') ? 'draft' : 'publish',
            'include_replies' => !empty($_POST['bsync_fc_include_replies']),
            'include_recasts' => !empty($_POST['bsync_fc_include_recasts']),
            'sideload_images' => !empty($_POST['bsync_fc_sideload_images']),
            'always_append_permalink' => !empty($_POST['bsync_fc_always_append_permalink']),
            'allow_nostr_bridge'      => !empty($_POST['bsync_fc_allow_nostr_bridge']),
            'polling_enabled' => !empty($_POST['bsync_fc_polling_enabled']),
            'purge_on_uninstall' => !empty($_POST['bsync_fc_purge_on_uninstall']),
            'default_channel' => isset($_POST['bsync_fc_default_channel'])
                ? sanitize_text_field(wp_unslash($_POST['bsync_fc_default_channel']))
                : '',
        );
        $this->settings->update($updates);

        // Manual signer UUID entry (fallback path) — verified server-side.
        if (!empty($_POST['bsync_fc_manual_signer_uuid'])) {
            $uuid = sanitize_text_field(wp_unslash($_POST['bsync_fc_manual_signer_uuid']));
            $result = BSync_FC_Signer::get_instance()->verify_and_store_signer($uuid);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            }
        }

        $query = array('page' => self::PAGE_SLUG, 'updated' => '1');
        if ($errors) {
            set_transient('bsync_fc_settings_errors_' . get_current_user_id(), $errors, MINUTE_IN_SECONDS);
            $query['bsync_fc_error'] = '1';
        }
        wp_safe_redirect(add_query_arg($query, admin_url('options-general.php')));
        exit;
    }

    /**
     * AJAX: create the Neynar webhook programmatically and store its
     * secret. Falls back to manual dashboard setup (documented on the
     * settings screen) when the API plan does not permit it.
     */
    public function create_webhook_ajax() {
        check_ajax_referer('bsync_fc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'burrowsync-for-farcaster'));
        }

        $fid = intval($this->settings->get('fid'));
        if (!$fid) {
            wp_send_json_error(__('Connect the signer first — the webhook is filtered to your FID.', 'burrowsync-for-farcaster'));
        }

        $client = new BSync_FC_Neynar_Client($this->settings);
        $name = 'wordpress-sync-' . preg_replace('/[^a-z0-9-]/', '-', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
        $result = $client->create_webhook($name, BSync_FC_Webhook::endpoint_url(), $fid);

        if (is_wp_error($result)) {
            wp_send_json_error(sprintf(
                /* translators: %s: API error message */
                __('Could not create the webhook automatically (%s). Create it in the Neynar dashboard instead and paste the secret below.', 'burrowsync-for-farcaster'),
                $result->get_error_message()
            ));
        }

        $webhook = isset($result['webhook']) && is_array($result['webhook']) ? $result['webhook'] : array();
        $webhook_id = isset($webhook['webhook_id']) ? sanitize_text_field($webhook['webhook_id']) : '';
        $secret = '';
        if (!empty($webhook['secrets'][0]['value'])) {
            $secret = (string) $webhook['secrets'][0]['value'];
        }

        if ($webhook_id === '' || $secret === '') {
            wp_send_json_error(__('Neynar created the webhook but did not return a secret. Fetch it from the Neynar dashboard and paste it below.', 'burrowsync-for-farcaster'));
        }

        $this->settings->set('webhook_id', $webhook_id);
        $this->settings->set_webhook_secret($secret);

        wp_send_json_success(array(
            'webhook_id' => $webhook_id,
            'message'    => __('Webhook created and secret stored.', 'burrowsync-for-farcaster'),
        ));
    }

    /* ---------------------------------------------------------------------
     * Settings page
     * ------------------------------------------------------------------ */

    /**
     * @return string
     */
    private function settings_url() {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Re-check signer health on settings load (cheap, once per load).
        $signer_status = BSync_FC_Signer::get_instance()->check_signer_health();

        $s = $this->settings;
        $api_key_set = ($s->get_api_key() !== null);
        $fid = intval($s->get('fid'));
        $username = (string) $s->get('username');
        $connected = $s->is_connected();
        $webhook_ready = $s->has_webhook_secret();
        $endpoint = BSync_FC_Webhook::endpoint_url();

        $errors = get_transient('bsync_fc_settings_errors_' . get_current_user_id());
        delete_transient('bsync_fc_settings_errors_' . get_current_user_id());
        ?>
        <div class="wrap bsync-fc-settings">
            <h1><?php esc_html_e('BurrowSync for Farcaster', 'burrowsync-for-farcaster'); ?></h1>

            <?php if (!empty($errors) && is_array($errors)) : ?>
                <?php foreach ($errors as $error) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
                <?php endforeach; ?>
            <?php elseif (isset($_GET['bsync_fc_connected'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Farcaster account connected.', 'burrowsync-for-farcaster'); ?></p></div>
            <?php elseif (isset($_GET['updated'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'burrowsync-for-farcaster'); ?></p></div>
            <?php endif; ?>

            <?php if ($signer_status === 'revoked') : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e('Signer revoked.', 'burrowsync-for-farcaster'); ?></strong>
                    <?php esc_html_e('This site can no longer publish to Farcaster. Reconnect below to approve a new signer.', 'burrowsync-for-farcaster'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$s->can_encrypt()) : ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e('Secrets cannot be stored: this server is missing the PHP sodium extension or the AUTH_KEY constant in wp-config.php.', 'burrowsync-for-farcaster'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="bsync_fc_save_settings" />
                <?php wp_nonce_field('bsync_fc_save_settings'); ?>

                <!-- Step 1 -->
                <div class="bsync-fc-step <?php echo $api_key_set ? 'bsync-fc-step-done' : ''; ?>">
                    <h2><span class="bsync-fc-step-badge"><?php echo $api_key_set ? '&#10003;' : '1'; ?></span>
                        <?php esc_html_e('Neynar API key', 'burrowsync-for-farcaster'); ?></h2>
                    <p>
                        <?php
                        printf(
                            /* translators: %s: link to neynar.com */
                            esc_html__('Sign up at %s (Neynar is a paid service) and copy an API key from the developer portal.', 'burrowsync-for-farcaster'),
                            '<a href="https://neynar.com" target="_blank" rel="noopener noreferrer">neynar.com</a>'
                        );
                        ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="bsync_fc_api_key"><?php esc_html_e('API key', 'burrowsync-for-farcaster'); ?></label></th>
                            <td>
                                <input type="password" class="regular-text" id="bsync_fc_api_key" name="bsync_fc_api_key"
                                    value="<?php echo esc_attr($s->get_masked_secret('api_key')); ?>"
                                    autocomplete="off" />
                                <p class="description"><?php esc_html_e('Stored encrypted. Leave the masked value untouched to keep the current key.', 'burrowsync-for-farcaster'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bsync_fc_client_id"><?php esc_html_e('SIWN client ID', 'burrowsync-for-farcaster'); ?></label></th>
                            <td>
                                <input type="text" class="regular-text" id="bsync_fc_client_id" name="bsync_fc_client_id"
                                    value="<?php echo esc_attr($s->get('client_id')); ?>" />
                                <p class="description">
                                    <?php esc_html_e('From the same Neynar portal: create an app / client to enable the "Sign in with Neynar" connect button in step 2.', 'burrowsync-for-farcaster'); ?>
                                    <?php
                                    $origin = self::authorized_origin();
                                    if ($origin !== '') {
                                        printf(
                                            ' %s <code class="bsync-fc-endpoint">%s</code>',
                                            esc_html__('Add this exact value under Authorized origins (no path, no trailing slash):', 'burrowsync-for-farcaster'),
                                            esc_html($origin)
                                        );
                                    }
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Step 2 -->
                <div class="bsync-fc-step <?php echo $connected ? 'bsync-fc-step-done' : ''; ?>">
                    <h2><span class="bsync-fc-step-badge"><?php echo $connected ? '&#10003;' : '2'; ?></span>
                        <?php esc_html_e('Connect your Farcaster account', 'burrowsync-for-farcaster'); ?></h2>

                    <?php if ($connected) : ?>
                        <p class="bsync-fc-connected">
                            <?php
                            printf(
                                /* translators: 1: username, 2: FID */
                                esc_html__('Connected as %1$s (FID %2$d). All sync is locked to this account.', 'burrowsync-for-farcaster'),
                                '<strong>@' . esc_html($username) . '</strong>',
                                intval($fid)
                            );
                            ?>
                            <button type="button" class="button-link bsync-fc-disconnect"><?php esc_html_e('Disconnect', 'burrowsync-for-farcaster'); ?></button>
                        </p>
                    <?php else : ?>
                        <p><?php esc_html_e('Approving a signer lets this site cast as you. The signing key is held by Neynar (a "managed signer"); you approve it once with your Farcaster custody wallet and can revoke it there at any time.', 'burrowsync-for-farcaster'); ?></p>

                        <?php if ($api_key_set && $s->get('client_id')) : ?>
                            <?php
                            // NOTE: no data-redirect_uri here, deliberately.
                            // redirect_uri is SIWN's mobile-deeplink mechanism;
                            // on web it switches Neynar's hosted page into a
                            // redirect flow whose final button fails silently
                            // for https admin URLs. The documented web flow is
                            // postMessage-only: the popup closes itself and the
                            // success callback receives signer_uuid + fid,
                            // gated by "Authorized origins" in the dev portal.
                            ?>
                            <div class="neynar_signin"
                                data-client_id="<?php echo esc_attr($s->get('client_id')); ?>"
                                data-success-callback="bsyncFcOnSignInSuccess"
                                data-theme="light"></div>
                            <p class="description"><?php esc_html_e('Keep this WordPress tab open. The button opens a Neynar popup; when approval finishes the popup closes automatically and this page refreshes. If the popup completes but nothing happens here, check that Authorized origins in your Neynar app settings matches your site origin exactly (step 1 shows the exact value).', 'burrowsync-for-farcaster'); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('Save an API key and SIWN client ID in step 1 to enable the connect button.', 'burrowsync-for-farcaster'); ?></p>
                        <?php endif; ?>

                        <details class="bsync-fc-manual">
                            <summary><?php esc_html_e('Advanced: connect with an existing signer UUID', 'burrowsync-for-farcaster'); ?></summary>
                            <p class="description"><?php esc_html_e('If you already created an approved managed signer with this API key, paste its UUID; it will be verified with Neynar before being stored.', 'burrowsync-for-farcaster'); ?></p>
                            <input type="text" class="regular-text" name="bsync_fc_manual_signer_uuid" value="" placeholder="00000000-0000-0000-0000-000000000000" />
                        </details>
                    <?php endif; ?>
                </div>

                <!-- Step 3 -->
                <div class="bsync-fc-step <?php echo $webhook_ready ? 'bsync-fc-step-done' : ''; ?>">
                    <h2><span class="bsync-fc-step-badge"><?php echo $webhook_ready ? '&#10003;' : '3'; ?></span>
                        <?php esc_html_e('Inbound webhook', 'burrowsync-for-farcaster'); ?></h2>
                    <p><?php esc_html_e('Neynar delivers your new casts to this site\'s endpoint. The site must be reachable over public HTTPS; if it is not (auth wall, intranet), skip this step and enable the polling fallback in step 4.', 'burrowsync-for-farcaster'); ?></p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Endpoint URL', 'burrowsync-for-farcaster'); ?></th>
                            <td><code class="bsync-fc-endpoint"><?php echo esc_html($endpoint); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Automatic setup', 'burrowsync-for-farcaster'); ?></th>
                            <td>
                                <button type="button" class="button button-secondary" id="bsync-fc-create-webhook" <?php disabled(!$connected); ?>>
                                    <?php esc_html_e('Create webhook', 'burrowsync-for-farcaster'); ?>
                                </button>
                                <span id="bsync-fc-webhook-result" role="status"></span>
                                <?php if ($s->get('webhook_id')) : ?>
                                    <p class="description">
                                        <?php
                                        printf(
                                            /* translators: %s: webhook ID */
                                            esc_html__('Webhook ID: %s', 'burrowsync-for-farcaster'),
                                            '<code>' . esc_html($s->get('webhook_id')) . '</code>'
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bsync_fc_webhook_secret"><?php esc_html_e('Webhook secret', 'burrowsync-for-farcaster'); ?></label></th>
                            <td>
                                <input type="password" class="regular-text" id="bsync_fc_webhook_secret" name="bsync_fc_webhook_secret"
                                    value="<?php echo esc_attr($s->get_masked_secret('webhook_secret')); ?>" autocomplete="off" />
                                <p class="description"><?php esc_html_e('Filled automatically by "Create webhook". For manual setup: create a webhook in the Neynar dashboard for event cast.created filtered to your FID, pointed at the endpoint above, and paste its secret here. Stored encrypted.', 'burrowsync-for-farcaster'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Step 4 -->
                <div class="bsync-fc-step">
                    <h2><span class="bsync-fc-step-badge">4</span> <?php esc_html_e('Sync behaviour', 'burrowsync-for-farcaster'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Direction', 'burrowsync-for-farcaster'); ?></th>
                            <td>
                                <label><input type="checkbox" name="bsync_fc_inbound_enabled" <?php checked($s->get('inbound_enabled')); ?> />
                                    <?php esc_html_e('Farcaster → WordPress (casts become notes)', 'burrowsync-for-farcaster'); ?></label><br />
                                <label><input type="checkbox" name="bsync_fc_outbound_enabled" <?php checked($s->get('outbound_enabled')); ?> />
                                    <?php esc_html_e('WordPress → Farcaster (published notes become casts)', 'burrowsync-for-farcaster'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Inbound notes', 'burrowsync-for-farcaster'); ?></th>
                            <td>
                                <label><?php esc_html_e('Post status:', 'burrowsync-for-farcaster'); ?>
                                    <select name="bsync_fc_inbound_status">
                                        <option value="publish" <?php selected($s->get('inbound_status'), 'publish'); ?>><?php esc_html_e('Publish', 'burrowsync-for-farcaster'); ?></option>
                                        <option value="draft" <?php selected($s->get('inbound_status'), 'draft'); ?>><?php esc_html_e('Draft', 'burrowsync-for-farcaster'); ?></option>
                                    </select>
                                </label><br />
                                <label><input type="checkbox" name="bsync_fc_include_replies" <?php checked($s->get('include_replies')); ?> />
                                    <?php esc_html_e('Include replies (default: top-level casts only)', 'burrowsync-for-farcaster'); ?></label><br />
                                <label><input type="checkbox" name="bsync_fc_include_recasts" <?php checked($s->get('include_recasts')); ?> />
                                    <?php esc_html_e('Include recasts', 'burrowsync-for-farcaster'); ?></label><br />
                                <label><input type="checkbox" name="bsync_fc_sideload_images" <?php checked($s->get('sideload_images')); ?> />
                                    <?php esc_html_e('Sideload embedded images into the media library (unchecked: link by URL)', 'burrowsync-for-farcaster'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Outbound casts', 'burrowsync-for-farcaster'); ?></th>
                            <td>
                                <label><input type="checkbox" name="bsync_fc_always_append_permalink" <?php checked($s->get('always_append_permalink')); ?> />
                                    <?php esc_html_e('Always append the note permalink (truncated notes always include it)', 'burrowsync-for-farcaster'); ?></label><br />
                                <label><?php esc_html_e('Default channel:', 'burrowsync-for-farcaster'); ?>
                                    <input type="text" name="bsync_fc_default_channel" value="<?php echo esc_attr($s->get('default_channel')); ?>" placeholder="<?php esc_attr_e('blank = home feed', 'burrowsync-for-farcaster'); ?>" />
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Nostr interop', 'burrowsync-for-farcaster'); ?></th>
                            <td>
                                <label><input type="checkbox" name="bsync_fc_allow_nostr_bridge" <?php checked($s->get('allow_nostr_bridge')); ?> />
                                    <?php esc_html_e('Allow Farcaster-origin notes to publish to Nostr (requires the BurrowSync for Nostr plugin)', 'burrowsync-for-farcaster'); ?></label>
                                <p class="description"><?php esc_html_e('Off (default): notes imported from Farcaster are marked so BurrowSync for Nostr will not bridge them onward. Has no effect when that plugin is not installed.', 'burrowsync-for-farcaster'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Resilience', 'burrowsync-for-farcaster'); ?></th>
                            <td>
                                <label><input type="checkbox" name="bsync_fc_polling_enabled" <?php checked($s->get('polling_enabled')); ?> />
                                    <?php esc_html_e('Poll for missed casts every 15 minutes (fallback for webhook outages or sites that cannot receive webhooks)', 'burrowsync-for-farcaster'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Uninstall', 'burrowsync-for-farcaster'); ?></th>
                            <td>
                                <label><input type="checkbox" name="bsync_fc_purge_on_uninstall" <?php checked($s->get('purge_on_uninstall')); ?> />
                                    <?php esc_html_e('Full purge on uninstall: also delete Farcaster sync meta from posts (posts themselves are never deleted)', 'burrowsync-for-farcaster'); ?></label>
                                <p class="description"><?php esc_html_e('Default off: uninstalling removes settings and secrets but leaves post content and origin markers intact.', 'burrowsync-for-farcaster'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button(__('Save settings', 'burrowsync-for-farcaster')); ?>
            </form>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Note list column
     * ------------------------------------------------------------------ */

    /**
     * @param array $columns
     * @return array
     */
    public function note_columns($columns) {
        $columns['bsync_fc_status'] = __('Farcaster', 'burrowsync-for-farcaster');
        return $columns;
    }

    /**
     * @param string $column
     * @param int    $post_id
     */
    public function note_column_content($column, $post_id) {
        if ($column !== 'bsync_fc_status') {
            return;
        }
        $info = BSync_FC_Publisher::get_instance()->get_post_status($post_id);
        $labels = array(
            'origin-farcaster' => __('From Farcaster', 'burrowsync-for-farcaster'),
            'synced'           => __('Synced', 'burrowsync-for-farcaster'),
            'queued'           => __('Queued', 'burrowsync-for-farcaster'),
            'failed'           => __('Failed', 'burrowsync-for-farcaster'),
            'none'             => '—',
        );
        $status = $info['status'];
        $label = isset($labels[$status]) ? $labels[$status] : $status;

        if ($info['cast_url'] && in_array($status, array('synced', 'origin-farcaster'), true)) {
            printf(
                '<a class="bsync-fc-badge bsync-fc-badge-%s" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_attr($status),
                esc_url($info['cast_url']),
                esc_html($label)
            );
        } else {
            printf('<span class="bsync-fc-badge bsync-fc-badge-%s">%s</span>', esc_attr($status), esc_html($label));
        }
    }

    /* ---------------------------------------------------------------------
     * Meta box
     * ------------------------------------------------------------------ */

    public function add_meta_box() {
        add_meta_box(
            'bsync-fc-sync-status',
            __('Farcaster', 'burrowsync-for-farcaster'),
            array($this, 'render_meta_box'),
            'note',
            'side',
            'default'
        );
    }

    /**
     * @param WP_Post $post
     */
    public function render_meta_box($post) {
        $info = BSync_FC_Publisher::get_instance()->get_post_status($post->ID);
        ?>
        <p>
            <strong><?php esc_html_e('Status:', 'burrowsync-for-farcaster'); ?></strong>
            <span class="bsync-fc-badge bsync-fc-badge-<?php echo esc_attr($info['status']); ?>">
                <?php echo esc_html($info['status']); ?>
            </span>
        </p>
        <?php if ($info['cast_hash']) : ?>
            <p>
                <strong><?php esc_html_e('Cast:', 'burrowsync-for-farcaster'); ?></strong><br />
                <?php if ($info['cast_url']) : ?>
                    <a href="<?php echo esc_url($info['cast_url']); ?>" target="_blank" rel="noopener noreferrer">
                        <code><?php echo esc_html(substr($info['cast_hash'], 0, 18)); ?>…</code>
                    </a>
                <?php else : ?>
                    <code><?php echo esc_html($info['cast_hash']); ?></code>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ($info['last_error']) : ?>
            <p class="bsync-fc-error-text">
                <strong><?php esc_html_e('Last error:', 'burrowsync-for-farcaster'); ?></strong>
                <?php echo esc_html($info['last_error']); ?>
                <?php if ($info['attempts']) : ?>
                    (<?php printf(esc_html__('%d attempts', 'burrowsync-for-farcaster'), intval($info['attempts'])); ?>)
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if (in_array($info['status'], array('queued', 'failed'), true)) : ?>
            <p>
                <button type="button" class="button bsync-fc-retry" data-post-id="<?php echo esc_attr($post->ID); ?>">
                    <?php esc_html_e('Retry now', 'burrowsync-for-farcaster'); ?>
                </button>
                <span class="bsync-fc-retry-result" role="status"></span>
            </p>
        <?php endif; ?>
        <?php if ($info['status'] === 'origin-farcaster') : ?>
            <p class="description"><?php esc_html_e('Imported from Farcaster; will never be published back.', 'burrowsync-for-farcaster'); ?></p>
        <?php endif; ?>
        <?php
    }
}
