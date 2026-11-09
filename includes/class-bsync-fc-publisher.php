<?php
/**
 * Outbound sync: WordPress `note` post -> Farcaster cast.
 *
 * Trigger: transition_post_status to `publish` for the `note` CPT. This
 * covers interactive publishes and scheduled ones (WP-Cron transitions
 * future -> publish through the same hook).
 *
 * GUARD ORDER (the anti-echo rule):
 * The FIRST content check is `_farcaster_origin` — posts created by this
 * plugin's inbound sync must NEVER be published back to Farcaster. The
 * inbound mapper stamps that meta via wp_insert_post()'s meta_input, so
 * it is guaranteed to exist by the time this hook fires (see
 * BSync_FC_Inbound's docblock for why the timing matters). Posts carrying
 * `_nostr_origin` (created by the companion burrowsync-for-nostr plugin) are
 * deliberately NOT filtered: content mirrored into WP via Nostr should
 * reach Farcaster (content-flow rule 3).
 *
 * FAILURE HANDLING: a WordPress publish never fails because of Farcaster.
 * If Neynar is unreachable or rate-limits, the post stays queued and
 * WP-Cron retries with backoff — 5 min, 15 min, 60 min, then hourly —
 * giving up 24h after the first failure with a visible "failed" state
 * and a manual "Retry now" action.
 *
 * EXACTLY-ONCE: the returned cast hash is stored as
 * `_farcaster_cast_hash` and any post that already has one is skipped.
 * For the ambiguous case (timeout after the request may have been
 * delivered), every attempt for a given post sends the same Neynar
 * `idem` idempotency key, generated once when the post is first queued;
 * Neynar deduplicates server-side, so a retry after an ambiguous timeout
 * cannot double-post. This is the documented choice — no text+timestamp
 * reconciliation query is needed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSync_FC_Publisher {

    const CRON_HOOK = 'bsync_fc_process_outbound_post';

    /** Cast text limit in BYTES (not characters). Enforced with strlen(). */
    const CAST_BYTE_LIMIT = 1024;

    /** Retry delays (seconds) after the 1st, 2nd, 3rd failure; hourly beyond. */
    const RETRY_SCHEDULE = array(300, 900, 3600);

    /** Stop automatic retries this long after the first failure. */
    const MAX_RETRY_WINDOW = DAY_IN_SECONDS;

    /** @var BSync_FC_Publisher|null */
    private static $instance = null;

    /** @var BSync_FC_Settings */
    private $settings;

    /**
     * @return BSync_FC_Publisher
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = BSync_FC_Settings::get_instance();

        add_action('transition_post_status', array($this, 'handle_post_publish'), 20, 3);
        add_action(self::CRON_HOOK, array($this, 'process_post'));
        add_action('wp_ajax_bsync_fc_retry_post', array($this, 'retry_post_ajax'));
    }

    /* ---------------------------------------------------------------------
     * Queueing
     * ------------------------------------------------------------------ */

    /**
     * transition_post_status handler.
     *
     * @param string  $new_status
     * @param string  $old_status
     * @param WP_Post $post
     */
    public function handle_post_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }
        if ($post->post_type !== 'note') {
            return;
        }
        if (wp_is_post_autosave($post->ID) || wp_is_post_revision($post->ID)) {
            return;
        }

        // ANTI-ECHO — content-flow rule 4, always the first content check:
        // never publish back what came FROM Farcaster.
        if (get_post_meta($post->ID, '_farcaster_origin', true)) {
            return;
        }

        // NOTE: no `_nostr_origin` check here — rule 3: Nostr-origin notes
        // DO sync out to Farcaster.

        // Idempotency: already casted.
        if (get_post_meta($post->ID, '_farcaster_cast_hash', true)) {
            return;
        }

        if (!$this->settings->get('outbound_enabled') || !$this->settings->is_connected()) {
            return;
        }

        $this->queue_post($post->ID);
    }

    /**
     * Mark a post queued and schedule an immediate processing attempt.
     *
     * @param int $post_id
     */
    public function queue_post($post_id) {
        update_post_meta($post_id, '_farcaster_sync_status', 'queued');
        delete_post_meta($post_id, '_farcaster_last_error');

        // One idempotency key per post, minted at first queue time and
        // reused verbatim on every retry (see class docblock).
        if (!get_post_meta($post_id, '_farcaster_idem', true)) {
            update_post_meta($post_id, '_farcaster_idem', substr(md5($post_id . '|' . wp_generate_password(32, false)), 0, 16));
        }

        $this->schedule_attempt($post_id, time());

        if (!defined('DOING_CRON') || !DOING_CRON) {
            spawn_cron(); // interactive publishes go out within seconds
        }
    }

    /**
     * @param int $post_id
     * @param int $timestamp
     */
    private function schedule_attempt($post_id, $timestamp) {
        wp_clear_scheduled_hook(self::CRON_HOOK, array($post_id));
        wp_schedule_single_event($timestamp, self::CRON_HOOK, array($post_id));
        update_post_meta($post_id, '_farcaster_next_retry', $timestamp);
    }

    /* ---------------------------------------------------------------------
     * Processing
     * ------------------------------------------------------------------ */

    /**
     * Attempt to publish one queued post. Cron callback and the workhorse
     * behind "Retry now".
     *
     * @param int $post_id
     * @return array{success: bool, message: string}
     */
    public function process_post($post_id) {
        $post_id = intval($post_id);
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return array('success' => false, 'message' => __('Post is not published.', 'burrowsync-for-farcaster'));
        }

        // Re-assert the anti-echo guard at processing time too, in case a
        // job was queued by an older version or crafted manually.
        if (get_post_meta($post_id, '_farcaster_origin', true)) {
            wp_clear_scheduled_hook(self::CRON_HOOK, array($post_id));
            delete_post_meta($post_id, '_farcaster_sync_status');
            return array('success' => false, 'message' => __('Farcaster-origin posts are never re-published.', 'burrowsync-for-farcaster'));
        }

        if (get_post_meta($post_id, '_farcaster_cast_hash', true)) {
            update_post_meta($post_id, '_farcaster_sync_status', 'synced');
            return array('success' => true, 'message' => __('Already published to Farcaster.', 'burrowsync-for-farcaster'));
        }

        if (!$this->settings->is_connected()) {
            $this->record_failure($post_id, __('Farcaster signer is not connected.', 'burrowsync-for-farcaster'));
            return array('success' => false, 'message' => __('Farcaster signer is not connected.', 'burrowsync-for-farcaster'));
        }

        // Concurrency guard: cron and a manual retry could race.
        $lock_key = 'bsync_fc_publish_lock_' . $post_id;
        if (get_transient($lock_key)) {
            return array('success' => false, 'message' => __('A publish attempt is already in progress.', 'burrowsync-for-farcaster'));
        }
        set_transient($lock_key, 1, 2 * BSync_FC_Neynar_Client::TIMEOUT + 30);

        try {
            $permalink = get_permalink($post_id);
            $built = self::build_cast_text(
                $post->post_content,
                $permalink,
                (bool) $this->settings->get('always_append_permalink')
            );

            $embeds = array();
            $image = $this->first_image_url($post_id, $post->post_content);
            if ($image) {
                $embeds[] = $image; // image first — embed priority rule
            }
            if ($built['truncated'] && count($embeds) < 2) {
                $embeds[] = $permalink; // then the permalink, max two embeds
            }

            $idem = (string) get_post_meta($post_id, '_farcaster_idem', true);

            $client = new BSync_FC_Neynar_Client($this->settings);
            $result = $client->publish_cast(
                $built['text'],
                $embeds,
                (string) $this->settings->get('default_channel'),
                $idem
            );

            if (is_wp_error($result)) {
                $message = sanitize_text_field($result->get_error_message());
                $this->record_failure($post_id, $message);
                return array('success' => false, 'message' => $message);
            }

            $hash = isset($result['cast']['hash']) ? strtolower(sanitize_text_field($result['cast']['hash'])) : '';
            if ($hash === '') {
                $message = __('Neynar accepted the cast but returned no hash.', 'burrowsync-for-farcaster');
                $this->record_failure($post_id, $message);
                return array('success' => false, 'message' => $message);
            }

            $this->mark_published($post_id, $hash);
            return array('success' => true, 'message' => __('Published to Farcaster.', 'burrowsync-for-farcaster'));

        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Record success. Storing `_farcaster_cast_hash` is both the
     * exactly-once marker for this pipeline and the key the inbound
     * handler uses to recognise (and skip) the webhook self-echo.
     *
     * @param int    $post_id
     * @param string $hash
     */
    private function mark_published($post_id, $hash) {
        update_post_meta($post_id, '_farcaster_cast_hash', $hash);
        update_post_meta($post_id, '_farcaster_sync_status', 'synced');
        update_post_meta($post_id, '_farcaster_synced_at', current_time('mysql'));

        $username = (string) $this->settings->get('username');
        update_post_meta($post_id, '_farcaster_cast_url', BSync_FC_Inbound::canonical_cast_url($username, $hash));

        delete_post_meta($post_id, '_farcaster_attempts');
        delete_post_meta($post_id, '_farcaster_first_failure');
        delete_post_meta($post_id, '_farcaster_next_retry');
        delete_post_meta($post_id, '_farcaster_last_error');
        wp_clear_scheduled_hook(self::CRON_HOOK, array($post_id));
    }

    /**
     * Record a failed attempt and schedule the next retry with backoff.
     *
     * @param int    $post_id
     * @param string $message sanitised, never contains secrets
     */
    private function record_failure($post_id, $message) {
        $attempts = intval(get_post_meta($post_id, '_farcaster_attempts', true)) + 1;
        update_post_meta($post_id, '_farcaster_attempts', $attempts);
        update_post_meta($post_id, '_farcaster_last_error', $message);

        $first_failure = intval(get_post_meta($post_id, '_farcaster_first_failure', true));
        if (!$first_failure) {
            $first_failure = time();
            update_post_meta($post_id, '_farcaster_first_failure', $first_failure);
        }

        if ((time() - $first_failure) >= self::MAX_RETRY_WINDOW) {
            // Give up on automatic retries; manual "Retry now" still works.
            update_post_meta($post_id, '_farcaster_sync_status', 'failed');
            delete_post_meta($post_id, '_farcaster_next_retry');
            wp_clear_scheduled_hook(self::CRON_HOOK, array($post_id));
            return;
        }

        update_post_meta($post_id, '_farcaster_sync_status', 'queued');
        $delay = isset(self::RETRY_SCHEDULE[$attempts - 1]) ? self::RETRY_SCHEDULE[$attempts - 1] : HOUR_IN_SECONDS;
        $this->schedule_attempt($post_id, time() + $delay);
    }

    /* ---------------------------------------------------------------------
     * Content mapping (pure; exercised directly by the tests)
     * ------------------------------------------------------------------ */

    /**
     * Build the cast text from post content within the 1024-BYTE limit.
     *
     * Farcaster's limit is bytes, not characters — a 500-character note
     * full of multibyte glyphs can exceed it. All measuring uses strlen()
     * on UTF-8 (bytes), never mb_strlen().
     *
     * - Fits and no always-append: sent as-is.
     * - Otherwise: truncated on a word boundary, with room reserved for
     *   an ellipsis and the permalink, which is appended to the text.
     *
     * @param string $content        raw post content
     * @param string $permalink      the post's permalink
     * @param bool   $always_append  append the permalink even when it fits
     * @return array{text: string, truncated: bool}
     */
    public static function build_cast_text($content, $permalink, $always_append = false) {
        $text = self::plain_text($content);

        if (strlen($text) <= self::CAST_BYTE_LIMIT && !$always_append) {
            return array('text' => $text, 'truncated' => false);
        }

        $suffix_glue = "\n\n";
        $ellipsis = '…'; // 3 bytes in UTF-8

        if (strlen($text) <= self::CAST_BYTE_LIMIT) {
            // Fits, but the user wants the permalink appended.
            $with_link = $text . $suffix_glue . $permalink;
            if (strlen($with_link) <= self::CAST_BYTE_LIMIT) {
                return array('text' => $with_link, 'truncated' => false);
            }
            // Appending pushed it over: fall through to truncation.
        }

        $suffix = $ellipsis . $suffix_glue . $permalink;
        $budget = self::CAST_BYTE_LIMIT - strlen($suffix);
        if ($budget < 0) {
            $budget = 0; // pathological permalink length; keep link only
        }

        $truncated = self::truncate_to_bytes($text, $budget);

        return array('text' => $truncated . $suffix, 'truncated' => true);
    }

    /**
     * Byte-safe, word-boundary truncation. Never splits a multibyte
     * character: the cut prefers the last whitespace inside the budget
     * and falls back to a multibyte-aware byte cut for unbroken runs.
     *
     * @param string $text  UTF-8 text
     * @param int    $limit byte budget
     * @return string
     */
    public static function truncate_to_bytes($text, $limit) {
        if (strlen($text) <= $limit) {
            return rtrim($text);
        }
        if ($limit <= 0) {
            return '';
        }

        // mb_strcut cuts at a byte offset without splitting characters.
        $cut = function_exists('mb_strcut')
            ? mb_strcut($text, 0, $limit, 'UTF-8')
            : substr($text, 0, $limit);

        // Prefer a word boundary within the cut.
        $last_space = max(
            (int) strrpos($cut, ' '),
            (int) strrpos($cut, "\n"),
            (int) strrpos($cut, "\t")
        );
        if ($last_space > 0) {
            $cut = substr($cut, 0, $last_space);
        }

        return rtrim($cut);
    }

    /**
     * Reduce post content to plain text with line breaks preserved.
     *
     * @param string $content
     * @return string
     */
    public static function plain_text($content) {
        $text = $content;
        // Block-level closes become line breaks before tags are stripped.
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(p|div|li|h[1-6]|blockquote)>/i', "\n", $text);
        $text = wp_strip_all_tags($text, false);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse 3+ consecutive newlines, normalise CRLF, trim.
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * First image for the embed: featured image, else the first attached
     * image, else the first <img> URL in the content.
     *
     * @param int    $post_id
     * @param string $content
     * @return string image URL or ''
     */
    private function first_image_url($post_id, $content) {
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            $url = wp_get_attachment_image_url($thumb_id, 'large');
            if ($url) {
                return $url;
            }
        }

        $attachments = get_children(array(
            'post_parent'    => $post_id,
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'numberposts'    => 1,
            'orderby'        => 'menu_order ID',
            'order'          => 'ASC',
        ));
        if (!empty($attachments)) {
            $first = array_shift($attachments);
            $url = wp_get_attachment_image_url($first->ID, 'large');
            if ($url) {
                return $url;
            }
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m)) {
            return esc_url_raw($m[1]);
        }

        return '';
    }

    /* ---------------------------------------------------------------------
     * Admin: manual retry + status
     * ------------------------------------------------------------------ */

    /**
     * AJAX: "Retry now" for a queued/failed post.
     */
    public function retry_post_ajax() {
        check_ajax_referer('bsync_fc_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'burrowsync-for-farcaster'));
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(__('Invalid post ID.', 'burrowsync-for-farcaster'));
        }

        // Manual retry resets the give-up window.
        delete_post_meta($post_id, '_farcaster_first_failure');
        delete_post_meta($post_id, '_farcaster_attempts');

        $result = $this->process_post($post_id);
        if ($result['success']) {
            wp_send_json_success(array(
                'message'   => $result['message'],
                'cast_hash' => get_post_meta($post_id, '_farcaster_cast_hash', true),
            ));
        }
        wp_send_json_error($result['message']);
    }

    /**
     * Per-post sync status for admin display.
     *
     * @param int $post_id
     * @return array{status: string, attempts: int, next_retry: ?int, last_error: ?string, cast_hash: ?string, cast_url: ?string, origin: bool}
     */
    public function get_post_status($post_id) {
        $origin = (bool) get_post_meta($post_id, '_farcaster_origin', true);
        $hash = get_post_meta($post_id, '_farcaster_cast_hash', true) ?: null;
        $status = get_post_meta($post_id, '_farcaster_sync_status', true) ?: 'none';

        if ($origin) {
            $status = 'origin-farcaster';
        } elseif ($hash && !in_array($status, array('queued', 'failed'), true)) {
            $status = 'synced';
        }

        return array(
            'status'     => $status,
            'attempts'   => intval(get_post_meta($post_id, '_farcaster_attempts', true)),
            'next_retry' => intval(get_post_meta($post_id, '_farcaster_next_retry', true)) ?: null,
            'last_error' => get_post_meta($post_id, '_farcaster_last_error', true) ?: null,
            'cast_hash'  => $hash,
            'cast_url'   => get_post_meta($post_id, '_farcaster_cast_url', true) ?: null,
            'origin'     => $origin,
        );
    }
}
