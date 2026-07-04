<?php
/**
 * Secrets at rest: the Neynar API key and webhook secret must round-trip
 * through the sodium secretbox layer, never appear in plaintext in the
 * stored option (i.e. in a database dump), and be masked for the UI.
 */

require __DIR__ . '/wp-stubs.php';

$settings = BSync_FC_Settings::get_instance();
check('sodium available for the test run', $settings->can_encrypt());

$api_key = 'TEST-FAKE-NEYNAR-KEY-a1b2c3d4';
$webhook_secret = 'test-fake-webhook-secret-e5f6';

$settings->set_api_key($api_key);
$settings->set_webhook_secret($webhook_secret);

/* ---- round trip ---- */

check('API key round-trips', $settings->get_api_key() === $api_key);
check('webhook secret round-trips', $settings->get_webhook_secret() === $webhook_secret);

/* ---- nothing in "the database" is plaintext ---- */

$dump = serialize($GLOBALS['__options']); // stands in for a full DB dump
check('DB dump does not contain the API key', strpos($dump, $api_key) === false);
check('DB dump does not contain the webhook secret', strpos($dump, $webhook_secret) === false);

$stored = $GLOBALS['__options'][BSync_FC_Settings::OPTION_SECRETS];
check('stored values are base64 blobs', (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', $stored['api_key']));
check('ciphertext differs from plaintext', base64_decode($stored['api_key']) !== $api_key);

/* ---- masking for the admin UI ---- */

$masked = $settings->get_masked_secret('api_key');
check('masked value hides the secret', strpos($masked, $api_key) === false);
check('masked value keeps only the last 4 chars', substr($masked, -4) === substr($api_key, -4));
check('masked value contains bullet characters', strpos($masked, "\xE2\x80\xA2") !== false);

/* ---- clearing removes the stored value ---- */

$settings->set_api_key('');
check('clearing the API key removes it', $settings->get_api_key() === null);
$stored = $GLOBALS['__options'][BSync_FC_Settings::OPTION_SECRETS];
check('cleared key gone from storage', !isset($stored['api_key']));

/* ---- tamper resistance ---- */

$settings->set_api_key($api_key);
$stored = $GLOBALS['__options'][BSync_FC_Settings::OPTION_SECRETS];
$raw = base64_decode($stored['api_key']);
$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
$stored['api_key'] = base64_encode($raw);
$GLOBALS['__options'][BSync_FC_Settings::OPTION_SECRETS] = $stored;
check('tampered ciphertext decrypts to null, not garbage', $settings->get_api_key() === null);

finish('CRYPTO');
