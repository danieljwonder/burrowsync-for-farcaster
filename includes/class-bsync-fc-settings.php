<?php
/**
 * Settings store for BurrowSync for Farcaster.
 *
 * Two wp_options rows:
 *   - bsync_fc_settings  non-secret configuration (FID, signer_uuid, toggles).
 *   - bsync_fc_secrets   secrets, each value encrypted before storage.
 *
 * SECRETS AT REST: the Neynar API key and the webhook shared secret are
 * encrypted with sodium crypto_secretbox before being written to
 * wp_options. The secretbox key is derived from the WordPress
 * AUTH_KEY/AUTH_SALT constants in wp-config.php, which live outside the
 * database. This is deliberate, honest obfuscation: it protects against
 * database-only leaks (SQL dumps, SQL injection, stolen backups) but NOT
 * against an attacker with full filesystem access to the host, who could
 * read wp-config.php and decrypt. That is the accepted threat model —
 * and the managed signer itself remains revocable at any time from the
 * user's Farcaster custody wallet.
 *
 * Secrets are never logged and are masked in the admin UI after save.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSync_FC_Settings {

    const OPTION_SETTINGS = 'bsync_fc_settings';
    const OPTION_SECRETS  = 'bsync_fc_secrets';

    /** Key-derivation context for secrets written by this plugin. */
    const KEY_CONTEXT = 'burrowsync-for-farcaster';

    /**
     * Context used by the pre-release "Farcaster for WP" plugin. Its secrets
     * blob is copied verbatim by the legacy-option migration, so it must
     * still decrypt here; get_secret() re-encrypts it under KEY_CONTEXT.
     */
    const LEGACY_KEY_CONTEXT = 'farcaster-for-wp';

    /** @var BSync_FC_Settings|null */
    private static $instance = null;

    /**
     * @return BSync_FC_Settings
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Defaults for every non-personal setting. The plugin must reach a
     * working state with only the user's own credentials added on top.
     *
     * @return array
     */
    public static function defaults() {
        return array(
            // Identity (populated by the signer connect flow).
            'fid'                => 0,
            'username'           => '',
            'signer_uuid'        => '',
            'signer_status'      => '',      // generated|pending_approval|approved|revoked
            'client_id'          => '',      // SIWN client ID (public identifier, not a secret)

            // Webhook.
            'webhook_id'         => '',

            // Sync direction.
            'inbound_enabled'    => true,    // Farcaster -> WP
            'outbound_enabled'   => true,    // WP -> Farcaster

            // Inbound content settings.
            'inbound_status'     => 'publish', // or 'draft'
            'include_replies'    => false,
            'include_recasts'    => false,
            'sideload_images'    => true,    // false = link images by URL

            // Outbound content settings.
            'always_append_permalink' => false,
            'default_channel'    => '',      // blank = home feed

            // Interop: when false (default) Farcaster-origin notes are also
            // stamped _nostr_origin so burrowsync-for-nostr will not bridge them.
            'allow_nostr_bridge' => false,

            // Resilience.
            'polling_enabled'    => false,   // WP-Cron poll fallback
            'polling_cursor'     => '',

            // Uninstall behaviour.
            'purge_on_uninstall' => false,
        );
    }

    /**
     * @return array full settings merged over defaults
     */
    public function all() {
        $stored = get_option(self::OPTION_SETTINGS, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        return array_merge(self::defaults(), $stored);
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key) {
        $all = $this->all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    /**
     * @param string $key
     * @param mixed  $value
     */
    public function set($key, $value) {
        $all = $this->all();
        $all[$key] = $value;
        update_option(self::OPTION_SETTINGS, $all, false);
    }

    /**
     * @param array $values key => value map to merge in
     */
    public function update(array $values) {
        $all = array_merge($this->all(), $values);
        update_option(self::OPTION_SETTINGS, $all, false);
    }

    /* ---------------------------------------------------------------------
     * Connection state helpers
     * ------------------------------------------------------------------ */

    /**
     * Fully connected: API key stored, signer approved, FID known.
     *
     * @return bool
     */
    public function is_connected() {
        return $this->get_api_key() !== null
            && $this->get('signer_uuid') !== ''
            && $this->get('signer_status') === 'approved'
            && intval($this->get('fid')) > 0;
    }

    /**
     * Whether the inbound webhook can be verified (secret present).
     *
     * @return bool
     */
    public function has_webhook_secret() {
        return $this->get_webhook_secret() !== null;
    }

    /* ---------------------------------------------------------------------
     * Secrets
     * ------------------------------------------------------------------ */

    /**
     * @return string|null plaintext Neynar API key
     */
    public function get_api_key() {
        return $this->get_secret('api_key');
    }

    /**
     * @param string $key plaintext API key ('' clears)
     */
    public function set_api_key($key) {
        $this->set_secret('api_key', $key);
    }

    /**
     * @return string|null plaintext webhook shared secret
     */
    public function get_webhook_secret() {
        return $this->get_secret('webhook_secret');
    }

    /**
     * @param string $secret plaintext webhook secret ('' clears)
     */
    public function set_webhook_secret($secret) {
        $this->set_secret('webhook_secret', $secret);
    }

    /**
     * Masked representation for the admin UI (never the full secret).
     *
     * @param string $name secret name
     * @return string e.g. "••••••••1a2b" or ''
     */
    public function get_masked_secret($name) {
        $plain = $this->get_secret($name);
        if ($plain === null || $plain === '') {
            return '';
        }
        $tail = strlen($plain) > 4 ? substr($plain, -4) : '';
        return str_repeat("\xE2\x80\xA2", 8) . $tail;
    }

    /**
     * @param string $name
     * @return string|null plaintext or null when unset/undecryptable
     */
    private function get_secret($name) {
        $secrets = get_option(self::OPTION_SECRETS, array());
        if (!is_array($secrets) || empty($secrets[$name])) {
            return null;
        }
        $plain = $this->decrypt($secrets[$name]);
        if ($plain === null) {
            $plain = $this->decrypt($secrets[$name], self::LEGACY_KEY_CONTEXT);
            if ($plain !== null) {
                try {
                    $this->set_secret($name, $plain); // upgrade to the current key
                } catch (Exception $e) {
                    // Still readable via the legacy key; retry on next read.
                }
            }
        }
        return $plain;
    }

    /**
     * @param string $name
     * @param string $plaintext '' removes the secret
     * @throws Exception when encryption is unavailable
     */
    private function set_secret($name, $plaintext) {
        $secrets = get_option(self::OPTION_SECRETS, array());
        if (!is_array($secrets)) {
            $secrets = array();
        }
        if ($plaintext === '' || $plaintext === null) {
            unset($secrets[$name]);
        } else {
            $secrets[$name] = $this->encrypt($plaintext);
        }
        update_option(self::OPTION_SECRETS, $secrets, false);
    }

    /* ---------------------------------------------------------------------
     * Encryption at rest (see class docblock for the threat model)
     * ------------------------------------------------------------------ */

    /**
     * Sodium ships with PHP >= 7.2, so this is virtually always true on a
     * supported install; the admin UI surfaces an error when it is not.
     *
     * @return bool
     */
    public function can_encrypt() {
        return function_exists('sodium_crypto_secretbox') && defined('AUTH_KEY') && AUTH_KEY !== '';
    }

    /**
     * Derive the at-rest key from the WordPress salts.
     *
     * @return string 32 raw bytes
     * @throws Exception
     */
    private function derive_key($context = self::KEY_CONTEXT) {
        if (!$this->can_encrypt()) {
            throw new Exception('Secret storage requires the PHP sodium extension and AUTH_KEY defined in wp-config.php.');
        }
        $salt = defined('AUTH_SALT') ? AUTH_SALT : $context;
        return sodium_crypto_generichash(AUTH_KEY . '|' . $salt . '|' . $context, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * @param string $plaintext
     * @return string base64(nonce || box)
     * @throws Exception
     */
    private function encrypt($plaintext) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $this->derive_key());
        return base64_encode($nonce . $box);
    }

    /**
     * @param string $stored base64(nonce || box)
     * @return string|null plaintext, or null if missing/undecryptable
     */
    private function decrypt($stored, $context = self::KEY_CONTEXT) {
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        try {
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($box, $nonce, $this->derive_key($context));
            return ($plain === false) ? null : $plain;
        } catch (Exception $e) {
            return null;
        }
    }
}
