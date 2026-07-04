<?php
/**
 * Minimal WordPress function stubs so the plugin classes can be
 * exercised from the CLI without a WordPress install. Not shipped with
 * the plugin (excluded via .distignore).
 *
 * All identifiers here are obviously fake: FID 12345, hashes made of
 * repeated digits, example.com URLs. Nothing user-specific.
 *
 * The wp_insert_post() stub reproduces WordPress core's ordering
 * EXACTLY, because the anti-echo guard depends on it:
 *
 *   1. post row created
 *   2. meta_input written           <- origin stamps land here
 *   3. transition_post_status fires <- the outbound publisher's guard
 *                                      runs here
 *
 * If an origin stamp ever moves out of meta_input (e.g. back to a
 * post-insert update_post_meta() call), step 3 sees no origin meta, the
 * post gets queued, and tests/test-inbound-guard.php fails.
 */

define('ABSPATH', __DIR__ . '/');
define('BSYNC_FC_PLUGIN_DIR', dirname(__DIR__) . '/');
define('BSYNC_FC_VERSION', 'test');
define('AUTH_KEY', 'test-auth-key-0123456789abcdef0123456789abcdef');
define('AUTH_SALT', 'test-auth-salt-fedcba9876543210');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['__options'] = array();
$GLOBALS['__meta'] = array();
$GLOBALS['__posts'] = array();
$GLOBALS['__cron'] = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__status_history'] = array(); // post_id => _farcaster_sync_status values ever written
$GLOBALS['__next_post_id'] = 100;

/* ---- options ---- */

function get_option($key, $default = false) {
    return isset($GLOBALS['__options'][$key]) ? $GLOBALS['__options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['__options'][$key] = $value;
    return true;
}
function delete_option($key) {
    unset($GLOBALS['__options'][$key]);
    return true;
}

/* ---- post meta ---- */

function get_post_meta($post_id, $key, $single = false) {
    return isset($GLOBALS['__meta'][$post_id][$key]) ? $GLOBALS['__meta'][$post_id][$key] : '';
}
function update_post_meta($post_id, $key, $value) {
    if ($key === '_farcaster_sync_status') {
        $GLOBALS['__status_history'][$post_id][] = $value;
    }
    $GLOBALS['__meta'][$post_id][$key] = $value;
    return true;
}
function delete_post_meta($post_id, $key) {
    unset($GLOBALS['__meta'][$post_id][$key]);
    return true;
}

/* ---- posts ---- */

function get_post($id) {
    return isset($GLOBALS['__posts'][$id]) ? $GLOBALS['__posts'][$id] : null;
}

/**
 * Core-ordering-faithful wp_insert_post(). See the file docblock.
 */
function wp_insert_post($post_data, $wp_error = false) {
    $id = $GLOBALS['__next_post_id']++;

    $post = (object) array(
        'ID'           => $id,
        'post_type'    => isset($post_data['post_type']) ? $post_data['post_type'] : 'post',
        'post_status'  => isset($post_data['post_status']) ? $post_data['post_status'] : 'draft',
        'post_title'   => isset($post_data['post_title']) ? $post_data['post_title'] : '',
        'post_content' => isset($post_data['post_content']) ? $post_data['post_content'] : '',
        'post_date'    => isset($post_data['post_date']) ? $post_data['post_date'] : '',
    );
    $GLOBALS['__posts'][$id] = $post;

    // (2) meta_input — exactly like core, before the transition.
    if (!empty($post_data['meta_input']) && is_array($post_data['meta_input'])) {
        foreach ($post_data['meta_input'] as $k => $v) {
            update_post_meta($id, $k, $v);
        }
    }

    // (3) transition_post_status — where BSync_FC_Publisher listens.
    if (class_exists('BSync_FC_Publisher')) {
        BSync_FC_Publisher::get_instance()->handle_post_publish($post->post_status, 'new', $post);
    }

    return $id;
}

function wp_update_post($post_data) {
    $id = $post_data['ID'];
    if (isset($GLOBALS['__posts'][$id])) {
        foreach ($post_data as $k => $v) {
            if ($k !== 'ID') {
                $GLOBALS['__posts'][$id]->$k = $v;
            }
        }
    }
    return $id;
}

function wp_is_post_autosave($id) { return false; }
function wp_is_post_revision($id) { return false; }
function set_post_thumbnail($post_id, $attachment_id) { return true; }
function get_post_thumbnail_id($post_id) { return 0; }
function get_children($args) { return array(); }
function wp_get_attachment_image_url($id, $size) { return false; }
function get_permalink($post_id) { return 'https://example.com/note/' . intval($post_id) . '/'; }

/* ---- cron ---- */

function wp_schedule_single_event($ts, $hook, $args = array()) {
    $GLOBALS['__cron'][$hook . ':' . json_encode($args)] = $ts;
    return true;
}
function wp_clear_scheduled_hook($hook, $args = array()) {
    unset($GLOBALS['__cron'][$hook . ':' . json_encode($args)]);
}
function wp_next_scheduled($hook, $args = array()) {
    $k = $hook . ':' . json_encode($args);
    return isset($GLOBALS['__cron'][$k]) ? $GLOBALS['__cron'][$k] : false;
}
function spawn_cron() {}

/* ---- transients ---- */

function get_transient($k) { return isset($GLOBALS['__transients'][$k]) ? $GLOBALS['__transients'][$k] : false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['__transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['__transients'][$k]); return true; }

/* ---- misc WP ---- */

function add_action() {}
function add_filter() {}
function __($s, $d = null) { return $s; }
function current_time($t) { return date('Y-m-d H:i:s'); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_unslash($v) { return $v; }
function wp_generate_password($length = 12, $special = true) {
    return substr(bin2hex(random_bytes($length)), 0, $length);
}
function sanitize_text_field($str) {
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)));
}
function sanitize_textarea_field($str) {
    // Like core: strips tags but preserves newlines.
    return trim(strip_tags((string) $str));
}
function esc_url_raw($url) { return filter_var($url, FILTER_SANITIZE_URL); }
function esc_url($url) { return filter_var($url, FILTER_SANITIZE_URL); }
function wp_strip_all_tags($text, $remove_breaks = false) {
    $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
    $text = strip_tags($text);
    if ($remove_breaks) {
        $text = preg_replace('/[\r\n\t ]+/', ' ', $text);
    }
    return trim($text);
}
function get_date_from_gmt($string, $format = 'Y-m-d H:i:s') { return $string; /* tests run as UTC */ }
function is_wp_error($thing) { return $thing instanceof WP_Error; }

class WP_Error {
    private $code;
    private $message;
    public $error_data;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->error_data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

class WP_REST_Response {
    private $data;
    private $status;
    public function __construct($data = null, $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
}

/* ---- $wpdb stub (only what BSync_FC_Inbound::find_post_by_cast_hash uses) ---- */

class BSync_FC_Test_WPDB {
    public $postmeta = 'wp_postmeta';
    public $options = 'wp_options';
    private $last_args = array();
    public function prepare($sql, ...$args) {
        $this->last_args = $args;
        return $sql;
    }
    public function get_var($sql) {
        $needle = isset($this->last_args[0]) ? $this->last_args[0] : null;
        foreach ($GLOBALS['__meta'] as $post_id => $meta) {
            if (isset($meta['_farcaster_cast_hash']) && $meta['_farcaster_cast_hash'] === $needle) {
                return $post_id;
            }
        }
        return null;
    }
    public function query($sql) { return 0; }
}
$GLOBALS['wpdb'] = new BSync_FC_Test_WPDB();

/* ---- shared check() helper ---- */

$GLOBALS['__fail'] = 0;
function check($label, $cond) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) {
        $GLOBALS['__fail']++;
    }
}
function finish($suite) {
    $fail = $GLOBALS['__fail'];
    echo $fail === 0 ? "\nALL $suite CHECKS PASSED\n" : "\n$fail $suite CHECKS FAILED\n";
    exit($fail > 0 ? 1 : 0);
}

/* ---- plugin classes under test ---- */

require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-settings.php';

/**
 * Configure a fully connected install with obviously fake identity.
 * FID 12345 / @examplecaster do not correspond to any real account.
 */
function bsync_fc_test_connect_settings() {
    $settings = BSync_FC_Settings::get_instance();
    $settings->set_api_key('TEST-FAKE-NEYNAR-KEY-000000');
    $settings->set_webhook_secret('test-webhook-shared-secret');
    $settings->update(array(
        'fid'           => 12345,
        'username'      => 'examplecaster',
        'signer_uuid'   => '00000000-0000-4000-8000-000000000000',
        'signer_status' => 'approved',
    ));
    return $settings;
}
