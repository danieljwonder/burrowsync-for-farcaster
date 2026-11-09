<?php
/**
 * Plugin Name: BurrowSync for Farcaster – Two-Way WordPress Sync
 * Plugin URI: https://burrowsync.com
 * Description: Two-way synchronization between WordPress notes and Farcaster casts via the Neynar API. Inbound casts become "note" posts; published notes become casts. Interoperates with (but does not require) the BurrowSync for Nostr plugin.
 * Version: 0.9.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: Daniel Wonder
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: burrowsync-for-farcaster
 * Domain Path: /languages
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

/*
 * CONTENT-FLOW MODEL (the invariants everything else hangs off):
 *
 *  1. Farcaster-native casts (top-level, by the connected FID) sync INTO
 *     WordPress as `note` posts, stamped `_farcaster_origin`.
 *  2. WP-native `note` posts sync OUT to Farcaster as casts.
 *  3. Nostr-origin notes (created by the companion burrowsync-for-nostr plugin,
 *     carrying `_nostr_origin`) ALSO sync out to Farcaster. Deliberate:
 *     content mirrored into WP via Nostr should reach Farcaster too.
 *  4. Farcaster-origin posts are NEVER published back to Farcaster
 *     (anti-echo). The `_farcaster_origin` stamp is written via
 *     wp_insert_post()'s meta_input so it exists BEFORE the
 *     transition_post_status hook fires — see BSync_FC_Inbound.
 *  5. This plugin never reads from or writes to Nostr. Interop with
 *     burrowsync-for-nostr is purely via post meta conventions (`_nostr_origin`),
 *     never by calling its code.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BSYNC_FC_VERSION', '0.9.0');
define('BSYNC_FC_PLUGIN_FILE', __FILE__);
define('BSYNC_FC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BSYNC_FC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BSYNC_FC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin bootstrap.
 */
final class BSync_FC_Plugin {

    /** @var BSync_FC_Plugin|null */
    private static $instance = null;

    /**
     * @return BSync_FC_Plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-settings.php';
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-neynar-client.php';
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-webhook.php';
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-inbound.php';
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-publisher.php';
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-signer.php';
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-cron.php';

        if (is_admin()) {
            require_once BSYNC_FC_PLUGIN_DIR . 'admin/class-bsync-fc-admin.php';
        }
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'maybe_finish_setup'));

        // CPT registration runs late (priority 20) so that if burrowsync-for-nostr
        // is active and has already registered `note` (it does so at its
        // default init priority), we detect it and do NOT double-register.
        add_action('init', array($this, 'maybe_register_post_type'), 20);
    }

    /**
     * init: load textdomain, boot components.
     */
    public function init() {
        load_plugin_textdomain('burrowsync-for-farcaster', false, dirname(BSYNC_FC_PLUGIN_BASENAME) . '/languages');

        BSync_FC_Webhook::get_instance();
        BSync_FC_Inbound::get_instance();
        BSync_FC_Publisher::get_instance();
        BSync_FC_Signer::get_instance();
        BSync_FC_Cron::get_instance();

        if (is_admin()) {
            BSync_FC_Admin::get_instance();
        }
    }

    /**
     * Register the `note` custom post type — but only if no other plugin
     * (in practice: burrowsync-for-nostr) has registered it already. The slug and
     * shape intentionally mirror burrowsync-for-nostr's registration so the two
     * plugins share one post type when both are active.
     */
    public function maybe_register_post_type() {
        if (post_type_exists('note')) {
            return; // burrowsync-for-nostr (or another plugin) owns the registration.
        }

        register_post_type('note', array(
            'labels' => array(
                'name'               => __('Notes', 'burrowsync-for-farcaster'),
                'singular_name'      => __('Note', 'burrowsync-for-farcaster'),
                'menu_name'          => __('Notes', 'burrowsync-for-farcaster'),
                'add_new'            => __('Add New Note', 'burrowsync-for-farcaster'),
                'add_new_item'       => __('Add New Note', 'burrowsync-for-farcaster'),
                'edit_item'          => __('Edit Note', 'burrowsync-for-farcaster'),
                'new_item'           => __('New Note', 'burrowsync-for-farcaster'),
                'view_item'          => __('View Note', 'burrowsync-for-farcaster'),
                'search_items'       => __('Search Notes', 'burrowsync-for-farcaster'),
                'not_found'          => __('No notes found', 'burrowsync-for-farcaster'),
                'not_found_in_trash' => __('No notes found in trash', 'burrowsync-for-farcaster'),
            ),
            'public'            => true,
            'publicly_queryable'=> true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => array('slug' => 'note'),
            'capability_type'   => 'post',
            'has_archive'       => true,
            'hierarchical'      => false,
            'menu_position'     => 20,
            'menu_icon'         => 'dashicons-format-chat',
            'supports'          => array('title', 'editor', 'author', 'custom-fields', 'revisions', 'thumbnail'),
            'show_in_nav_menus' => true,
        ));
    }

    /**
     * Activation: register the CPT so rewrite rules include it, schedule
     * the daily signer health check, and set the first-run flag that
     * powers the setup admin notice.
     */
    public function activate() {
        $this->maybe_register_post_type();
        flush_rewrite_rules();

        $this->maybe_migrate_legacy_options();

        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-settings.php';
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-cron.php';
        BSync_FC_Cron::schedule_recurring();

        if (!BSync_FC_Settings::get_instance()->is_connected()) {
            update_option('bsync_fc_show_setup_notice', '1', false);
        }
        update_option('bsync_fc_setup_version', BSYNC_FC_VERSION, false);
    }

    /**
     * Run activation setup once per version if it never ran, e.g. when this
     * plugin was activated while Farcaster for WP was still active and stood
     * down. Migration and scheduling are both idempotent.
     */
    public function maybe_finish_setup() {
        if (get_option('bsync_fc_setup_version') === BSYNC_FC_VERSION) {
            return;
        }
        $this->activate();
    }

    /**
     * One-time migration from the pre-release "Farcaster for WP" option
     * names (fcwp_*). Installs that configured the plugin before the
     * BurrowSync rename keep their API key, signer, webhook secret, and
     * settings without re-running setup. The secrets blob is copied
     * verbatim; it was encrypted under the old plugin's key context, which
     * BSync_FC_Settings still accepts and upgrades on first read. Old
     * options are left in place (cheap,
     * and harmless if the old plugin file is briefly still present);
     * uninstall.php removes both generations.
     */
    private function maybe_migrate_legacy_options() {
        if (get_option('bsync_fc_settings') !== false) {
            return; // Already configured under the new name.
        }
        foreach (array(
            'fcwp_settings'          => 'bsync_fc_settings',
            'fcwp_secrets'           => 'bsync_fc_secrets',
            'fcwp_show_setup_notice' => 'bsync_fc_show_setup_notice',
        ) as $old => $new) {
            $value = get_option($old);
            if ($value !== false) {
                update_option($new, $value, false);
            }
        }
    }

    /**
     * Deactivation: unschedule cron only. Deliberately does NOT touch the
     * stored API key, signer_uuid, webhook secret, or any sync meta —
     * deactivate + reactivate must never force a signer re-approval.
     * Cleanup lives in uninstall.php and even there is conservative.
     */
    public function deactivate() {
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-cron.php';
        require_once BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-publisher.php';
        BSync_FC_Cron::unschedule_recurring();
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook(BSync_FC_Publisher::CRON_HOOK);
            wp_unschedule_hook(BSync_FC_Webhook::JOB_HOOK);
        }
        flush_rewrite_rules();
    }
}

/**
 * @return BSync_FC_Plugin
 */
function bsync_fc() {
    return BSync_FC_Plugin::get_instance();
}

/**
 * True while the pre-release "Farcaster for WP" plugin is active. Running
 * both would import and cast everything twice, so this plugin stands down
 * until it is deactivated. Matched by main-file name, since that plugin was
 * installed under more than one folder name.
 *
 * @return bool
 */
function bsync_fc_legacy_plugin_active() {
    if (class_exists('Farcaster_For_WP', false)) {
        return true;
    }
    $active = (array) get_option('active_plugins', array());
    if (is_multisite()) {
        $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
    }
    foreach ($active as $plugin) {
        if (basename((string) $plugin) === 'farcaster-for-wp.php') {
            return true;
        }
    }
    return false;
}

if (bsync_fc_legacy_plugin_active()) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>BurrowSync for Farcaster</strong> replaces Farcaster for WP. Deactivate Farcaster for WP to finish switching. Your API key, signer, webhook and settings carry over.</p></div>';
    });
} else {
    bsync_fc();
}
