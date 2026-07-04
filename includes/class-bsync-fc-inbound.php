<?php
/**
 * Inbound sync: Farcaster cast -> WordPress `note` post.
 *
 * ORIGIN STAMPING — THE TIMING RULE THAT MUST NEVER REGRESS:
 *
 * WordPress core's wp_insert_post() runs, in order:
 *   1. insert the post row
 *   2. write meta_input
 *   3. fire wp_transition_post_status (where outbound publishers listen)
 * — all synchronously inside the one call. Any origin meta written with
 * update_post_meta() AFTER wp_insert_post() returns arrives too late:
 * step 3 has already run and an outbound publisher will have queued the
 * post, echoing it straight back to the network it came from.
 *
 * Therefore `_farcaster_origin` (and, when Nostr bridging is disabled,
 * `_nostr_origin` too) is passed via meta_input so it exists BEFORE any
 * transition_post_status hook fires. tests/test-inbound-guard.php
 * asserts an inbound-created post is never queued — not queued at all,
 * not "queued then skipped".
 *
 * SELF-ECHO DEDUPE:
 *
 * When this plugin publishes a WP note to Farcaster, Neynar's webhook
 * fires cast.created for that very cast (it is a new cast by the
 * connected FID). The outbound publisher stores the returned hash as
 * `_farcaster_cast_hash` on the WP post, and this handler checks every
 * incoming hash against stored `_farcaster_cast_hash` values before
 * creating anything. One lookup covers both self-echo (hash on an
 * outbound post) and webhook redelivery (hash on a previously imported
 * note).
 *
 * INTEROP (`_nostr_origin` on Farcaster-origin notes):
 *
 * If nostr-for-wp is active, its outbound publisher suppresses anything
 * carrying `_nostr_origin`. When the "Allow Farcaster-origin notes to
 * publish to Nostr" setting is OFF (default), we deliberately reuse that
 * suppression convention by stamping `_nostr_origin` on the notes we
 * create — same meta_input timing rule. This is a documented convention
 * borrow, not an API call; nostr-for-wp code is never invoked and its
 * absence changes nothing.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSync_FC_Inbound {

    /** Seconds between the Unix epoch and the Farcaster epoch (2021-01-01T00:00:00Z). */
    const FARCASTER_EPOCH_OFFSET = 1609459200;

    /** @var BSync_FC_Inbound|null */
    private static $instance = null;

    /** @var BSync_FC_Settings */
    private $settings;

    /**
     * @return BSync_FC_Inbound
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = BSync_FC_Settings::get_instance();
    }

    /**
     * Create a `note` post from a Neynar cast object. Idempotent: safe to
     * call repeatedly with the same cast (webhook redelivery, polling
     * overlap, self-echo).
     *
     * @param array $cast Neynar cast object (webhook data or feed item)
     * @return int|false new post ID, existing post ID when already
     *                   imported, or false when skipped/invalid
     */
    public function create_note_from_cast(array $cast) {
        // The configured FID is authoritative regardless of webhook or
        // polling configuration upstream.
        $configured_fid = intval($this->settings->get('fid'));
        $author_fid = isset($cast['author']['fid']) ? intval($cast['author']['fid']) : 0;
        if (!$configured_fid || $author_fid !== $configured_fid) {
            return false;
        }

        $hash = isset($cast['hash']) ? strtolower(sanitize_text_field($cast['hash'])) : '';
        if ($hash === '') {
            return false;
        }

        // Top-level casts only by default; replies excluded unless opted in.
        $is_reply = !empty($cast['parent_hash'])
            || (isset($cast['parent_author']['fid']) && $cast['parent_author']['fid'] !== null);
        if ($is_reply && !$this->settings->get('include_replies')) {
            return false;
        }

        // Idempotency + self-echo dedupe: one indexed lookup by cast hash.
        $existing = self::find_post_by_cast_hash($hash);
        if ($existing) {
            return $existing;
        }

        $text = isset($cast['text']) ? (string) $cast['text'] : '';
        $timestamp = self::cast_timestamp_to_unix(isset($cast['timestamp']) ? $cast['timestamp'] : null);
        $channel = isset($cast['channel']['id']) ? sanitize_text_field($cast['channel']['id']) : '';
        $username = isset($cast['author']['username']) ? sanitize_text_field($cast['author']['username']) : '';
        $cast_url = self::canonical_cast_url($username, $hash);

        list($content, $image_urls) = $this->build_content($text, isset($cast['embeds']) && is_array($cast['embeds']) ? $cast['embeds'] : array());

        $status = ($this->settings->get('inbound_status') === 'draft') ? 'draft' : 'publish';

        $meta_input = array(
            // Anti-echo stamp; MUST travel via meta_input (see docblock).
            '_farcaster_origin'         => '1',
            '_farcaster_cast_hash'      => $hash,
            '_farcaster_cast_url'       => $cast_url,
            '_farcaster_cast_timestamp' => $timestamp,
        );
        if ($channel !== '') {
            $meta_input['_farcaster_channel'] = $channel;
        }

        if (!$this->settings->get('allow_nostr_bridge')) {
            // Deliberate reuse of nostr-for-wp's suppression convention:
            // posts carrying _nostr_origin are never published to Nostr by
            // that plugin. Stamped here (via meta_input, same timing rule)
            // so Farcaster-origin notes stay on Farcaster unless the user
            // opts in. Harmless when nostr-for-wp is not installed.
            $meta_input['_nostr_origin'] = '1';
        }

        $post_id = wp_insert_post(array(
            'post_type'     => 'note',
            'post_status'   => $status,
            'post_title'    => self::make_title($text),
            'post_content'  => $content,
            'post_date'     => get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp)),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $timestamp),
            'meta_input'    => $meta_input,
        ), true);

        if (is_wp_error($post_id) || !$post_id) {
            return false;
        }

        // Media handling happens after insert — it only touches
        // attachments/content, never the origin meta or post status.
        if (!empty($image_urls)) {
            $this->attach_images($post_id, $image_urls);
        }

        return $post_id;
    }

    /* ---------------------------------------------------------------------
     * Lookups
     * ------------------------------------------------------------------ */

    /**
     * Find a post (any status, any post type this plugin touches) whose
     * `_farcaster_cast_hash` equals the given hash.
     *
     * Direct prepared query on wp_postmeta: it narrows by meta_key (which
     * is indexed) to the small set of rows this plugin writes, rather
     * than a WP_Query meta scan across unrelated meta. Called once per
     * incoming event.
     *
     * @param string $hash lowercase 0x-prefixed cast hash
     * @return int post ID or 0
     */
    public static function find_post_by_cast_hash($hash) {
        global $wpdb;
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_farcaster_cast_hash' AND meta_value = %s LIMIT 1",
            strtolower($hash)
        ));
        return $post_id ? intval($post_id) : 0;
    }

    /* ---------------------------------------------------------------------
     * Mapping helpers (pure; exercised directly by the tests)
     * ------------------------------------------------------------------ */

    /**
     * Convert a cast timestamp to a Unix timestamp.
     *
     * Farcaster protocol (hub) timestamps are SECONDS SINCE THE FARCASTER
     * EPOCH — 2021-01-01T00:00:00Z — not the Unix epoch:
     * unix = farcaster_ts + 1609459200.
     *
     * Neynar's hydrated webhook/feed objects instead carry an ISO-8601
     * string (e.g. "2024-02-15T19:23:22.000Z"). Both forms are handled:
     * numeric values below 10^9 can only be Farcaster-epoch (that range
     * as Unix time would predate Farcaster's existence), larger numerics
     * are already Unix, strings are parsed as ISO-8601 UTC.
     *
     * @param mixed $value
     * @return int Unix timestamp (falls back to now if unparseable)
     */
    public static function cast_timestamp_to_unix($value) {
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) {
            $num = (int) $value;
            if ($num <= 0) {
                return time();
            }
            return ($num < 1000000000) ? $num + self::FARCASTER_EPOCH_OFFSET : $num;
        }
        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                return $parsed;
            }
        }
        return time();
    }

    /**
     * Canonical web URL for a cast. Farcaster's web client addresses
     * casts as https://farcaster.xyz/<username>/<first-10-chars-of-hash>.
     *
     * @param string $username
     * @param string $hash lowercase 0x-prefixed hash
     * @return string
     */
    public static function canonical_cast_url($username, $hash) {
        $short = substr($hash, 0, 10);
        if ($username === '') {
            $username = '~'; // profile-less fallback still resolves on the hash
        }
        return 'https://farcaster.xyz/' . rawurlencode($username) . '/' . rawurlencode($short);
    }

    /**
     * Build post content and collect image embed URLs.
     *
     * - Cast text becomes the content with line breaks preserved (stored
     *   as-is; wpautop renders paragraphs/breaks at display time).
     * - Image embeds are returned separately for sideloading.
     * - Non-image embed URLs are appended on their own line when the cast
     *   text does not already contain them.
     *
     * @param string $text
     * @param array  $embeds Neynar embed objects
     * @return array{0: string, 1: array} [content, image URLs]
     */
    public function build_content($text, array $embeds) {
        $content = sanitize_textarea_field($text);
        $images = array();
        $links = array();

        foreach ($embeds as $embed) {
            if (empty($embed['url']) || !is_string($embed['url'])) {
                continue; // quote-cast embeds (cast_id) are not imported
            }
            $url = esc_url_raw($embed['url']);
            if ($url === '') {
                continue;
            }
            if (self::embed_is_image($embed)) {
                $images[] = $url;
            } elseif (strpos($content, $url) === false) {
                $links[] = $url;
            }
        }

        if (!empty($links)) {
            $content = rtrim($content) . "\n\n" . implode("\n", $links);
        }

        return array($content, $images);
    }

    /**
     * @param array $embed Neynar embed object
     * @return bool whether the embed is an image
     */
    public static function embed_is_image(array $embed) {
        if (isset($embed['metadata']['content_type']) && is_string($embed['metadata']['content_type'])) {
            return strpos($embed['metadata']['content_type'], 'image/') === 0;
        }
        if (isset($embed['metadata']['image'])) {
            return true;
        }
        if (!empty($embed['url'])) {
            $path = (string) wp_parse_url($embed['url'], PHP_URL_PATH);
            return (bool) preg_match('/\.(png|jpe?g|gif|webp|avif)$/i', $path);
        }
        return false;
    }

    /**
     * A short title derived from the first line of the cast text.
     *
     * @param string $text
     * @return string
     */
    public static function make_title($text) {
        $first_line = trim(strtok(str_replace("\r\n", "\n", $text), "\n"));
        if ($first_line === '' || $first_line === false) {
            return __('Farcaster note', 'burrowsync-for-farcaster');
        }
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($first_line, 0, 80, '…', 'UTF-8');
        }
        return substr($first_line, 0, 80);
    }

    /* ---------------------------------------------------------------------
     * Media
     * ------------------------------------------------------------------ */

    /**
     * Sideload embedded images as attachments (default) with a graceful
     * fallback to plain linked images when sideloading fails or is
     * disabled. First successful sideload becomes the featured image.
     *
     * @param int   $post_id
     * @param array $image_urls
     */
    private function attach_images($post_id, array $image_urls) {
        $sideload = (bool) $this->settings->get('sideload_images');
        $appended = '';
        $featured_set = false;

        foreach ($image_urls as $url) {
            $attachment_id = 0;

            if ($sideload) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $result = media_sideload_image($url, $post_id, null, 'id');
                if (!is_wp_error($result)) {
                    $attachment_id = intval($result);
                }
            }

            if ($attachment_id) {
                if (!$featured_set) {
                    set_post_thumbnail($post_id, $attachment_id);
                    $featured_set = true;
                }
            } else {
                // Fallback: reference the remote image by URL.
                $appended .= "\n\n" . '<img src="' . esc_url($url) . '" alt="" />';
            }
        }

        if ($appended !== '') {
            $post = get_post($post_id);
            if ($post) {
                wp_update_post(array(
                    'ID'           => $post_id,
                    'post_content' => $post->post_content . $appended,
                ));
            }
        }
    }
}
