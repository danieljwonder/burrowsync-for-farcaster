<?php
/**
 * Thin Neynar REST API client built on the WordPress HTTP API.
 *
 * Neynar is plain REST over HTTPS with an `x-api-key` header, so this
 * plugin deliberately ships dependency-free: no Composer packages, no
 * SDK. Endpoint names verified against https://docs.neynar.com
 * (API version 3.x) at build time:
 *
 *   POST /v2/farcaster/cast/                publish a cast (signer_uuid,
 *                                           text, embeds<=2, channel_id,
 *                                           idem idempotency key)
 *   GET  /v2/farcaster/signer/              signer status by signer_uuid
 *   GET  /v2/farcaster/user/bulk/           user profiles by fids
 *   GET  /v2/farcaster/feed/user/casts/     recent casts by a fid
 *   POST /v2/farcaster/webhook/             create webhook (returns the
 *                                           shared secret for signature
 *                                           verification)
 *   GET  /v2/farcaster/webhook/             fetch webhook by webhook_id
 *   DELETE /v2/farcaster/webhook/           delete webhook
 *
 * All methods return a decoded array on success or WP_Error on failure.
 * Error messages never include the API key.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSync_FC_Neynar_Client {

    const API_BASE = 'https://api.neynar.com';
    const TIMEOUT  = 15; // seconds

    /** @var BSync_FC_Settings */
    private $settings;

    /**
     * @param BSync_FC_Settings|null $settings
     */
    public function __construct($settings = null) {
        $this->settings = $settings ? $settings : BSync_FC_Settings::get_instance();
    }

    /* ---------------------------------------------------------------------
     * Casts
     * ------------------------------------------------------------------ */

    /**
     * Publish a cast.
     *
     * @param string $text       cast text (must already fit 1024 bytes)
     * @param array  $embeds     list of embed URLs (max 2)
     * @param string $channel_id channel to post in ('' = home feed)
     * @param string $idem       idempotency key: Neynar dedupes identical
     *                           requests carrying the same key, which is
     *                           how retries after an ambiguous timeout
     *                           cannot double-post (see BSync_FC_Publisher)
     * @return array|WP_Error    decoded response with cast.hash on success
     */
    public function publish_cast($text, array $embeds = array(), $channel_id = '', $idem = '') {
        $body = array(
            'signer_uuid' => (string) $this->settings->get('signer_uuid'),
            'text'        => $text,
        );
        if (!empty($embeds)) {
            $body['embeds'] = array();
            foreach (array_slice($embeds, 0, 2) as $url) {
                $body['embeds'][] = array('url' => $url);
            }
        }
        if ($channel_id !== '') {
            $body['channel_id'] = $channel_id;
        }
        if ($idem !== '') {
            $body['idem'] = $idem;
        }
        return $this->request('POST', '/v2/farcaster/cast/', array(), $body);
    }

    /**
     * Recent casts authored by a FID (used by the polling fallback and by
     * ambiguous-retry reconciliation).
     *
     * @param int    $fid
     * @param int    $limit
     * @param string $cursor pagination cursor ('' = start)
     * @param bool   $include_replies
     * @return array|WP_Error
     */
    public function get_user_casts($fid, $limit = 25, $cursor = '', $include_replies = false) {
        $query = array(
            'fid'             => intval($fid),
            'limit'           => intval($limit),
            'include_replies' => $include_replies ? 'true' : 'false',
        );
        if ($cursor !== '') {
            $query['cursor'] = $cursor;
        }
        return $this->request('GET', '/v2/farcaster/feed/user/casts/', $query);
    }

    /* ---------------------------------------------------------------------
     * Signer + user
     * ------------------------------------------------------------------ */

    /**
     * Look up a signer's status. Response: signer_uuid, status
     * (generated|pending_approval|approved|revoked), public_key, fid
     * (once approved), signer_approval_url (while pending).
     *
     * @param string $signer_uuid
     * @return array|WP_Error
     */
    public function lookup_signer($signer_uuid) {
        return $this->request('GET', '/v2/farcaster/signer/', array('signer_uuid' => $signer_uuid));
    }

    /**
     * Fetch a user profile by FID (username, display name, pfp).
     *
     * @param int $fid
     * @return array|WP_Error the user object, or WP_Error
     */
    public function get_user($fid) {
        $res = $this->request('GET', '/v2/farcaster/user/bulk/', array('fids' => intval($fid)));
        if (is_wp_error($res)) {
            return $res;
        }
        if (empty($res['users'][0])) {
            return new WP_Error('bsync_fc_no_user', __('No Farcaster user found for that FID.', 'burrowsync-for-farcaster'));
        }
        return $res['users'][0];
    }

    /* ---------------------------------------------------------------------
     * Webhooks
     * ------------------------------------------------------------------ */

    /**
     * Create a Neynar webhook subscribed to cast.created for one FID.
     * The response includes webhook.secrets[].value — the shared secret
     * used to verify inbound deliveries (see BSync_FC_Webhook).
     *
     * @param string $name       display name in the Neynar portal
     * @param string $target_url this site's REST endpoint
     * @param int    $fid        author FID filter
     * @return array|WP_Error
     */
    public function create_webhook($name, $target_url, $fid) {
        $body = array(
            'name' => $name,
            'url'  => $target_url,
            'subscription' => array(
                'cast.created' => array(
                    'author_fids' => array(intval($fid)),
                ),
            ),
        );
        return $this->request('POST', '/v2/farcaster/webhook/', array(), $body);
    }

    /**
     * @param string $webhook_id
     * @return array|WP_Error
     */
    public function get_webhook($webhook_id) {
        return $this->request('GET', '/v2/farcaster/webhook/', array('webhook_id' => $webhook_id));
    }

    /**
     * @param string $webhook_id
     * @return array|WP_Error
     */
    public function delete_webhook($webhook_id) {
        return $this->request('DELETE', '/v2/farcaster/webhook/', array(), array('webhook_id' => $webhook_id));
    }

    /* ---------------------------------------------------------------------
     * Transport
     * ------------------------------------------------------------------ */

    /**
     * Perform one API request.
     *
     * @param string $method GET|POST|PUT|DELETE
     * @param string $path   e.g. '/v2/farcaster/cast/'
     * @param array  $query  query-string parameters
     * @param array  $body   JSON body for write methods
     * @return array|WP_Error decoded JSON array
     */
    private function request($method, $path, array $query = array(), array $body = array()) {
        $api_key = $this->settings->get_api_key();
        if ($api_key === null || $api_key === '') {
            return new WP_Error('bsync_fc_no_api_key', __('Neynar API key is not configured.', 'burrowsync-for-farcaster'));
        }

        $url = self::API_BASE . $path;
        if (!empty($query)) {
            $url = add_query_arg(array_map('strval', $query), $url);
        }

        $args = array(
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => array(
                'accept'       => 'application/json',
                'x-api-key'    => $api_key,
                'content-type' => 'application/json',
            ),
        );
        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // Transport failure (DNS, TLS, timeout). Message is safe: WP
            // HTTP errors never contain request headers (the API key).
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = (is_array($data) && !empty($data['message']))
                ? sanitize_text_field($data['message'])
                : sprintf(__('Neynar API returned HTTP %d.', 'burrowsync-for-farcaster'), $code);
            return new WP_Error('bsync_fc_api_error', $message, array('status' => $code));
        }

        if (!is_array($data)) {
            return new WP_Error('bsync_fc_bad_response', __('Neynar API returned a non-JSON response.', 'burrowsync-for-farcaster'));
        }

        return $data;
    }
}
