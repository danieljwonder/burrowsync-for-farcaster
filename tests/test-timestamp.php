<?php
/**
 * Timestamp conversion. Farcaster protocol timestamps are seconds since
 * the FARCASTER epoch (2021-01-01T00:00:00Z), not the Unix epoch:
 * unix = farcaster_ts + 1609459200. Neynar's hydrated objects instead
 * carry ISO-8601 strings. Both must convert correctly.
 */

require __DIR__ . '/wp-stubs.php';
require BSYNC_FC_PLUGIN_DIR . 'includes/class-bsync-fc-inbound.php';

$offset = BSync_FC_Inbound::FARCASTER_EPOCH_OFFSET;
check('epoch offset constant is 2021-01-01T00:00:00Z', $offset === strtotime('2021-01-01T00:00:00Z'));

/* ---- Farcaster-epoch numerics (hub-style) ---- */

check('farcaster ts 1 -> unix offset+1', BSync_FC_Inbound::cast_timestamp_to_unix(1) === $offset + 1);

$fc_ts = 100000000; // ~2024-03 in Farcaster epoch terms
check('farcaster ts converts with +1609459200', BSync_FC_Inbound::cast_timestamp_to_unix($fc_ts) === $fc_ts + $offset);
check('numeric string handled the same', BSync_FC_Inbound::cast_timestamp_to_unix((string) $fc_ts) === $fc_ts + $offset);

/* ---- already-Unix numerics pass through ---- */

$unix = 1708025006; // 2024-02-15T19:23:26Z
check('unix ts passes through unchanged', BSync_FC_Inbound::cast_timestamp_to_unix($unix) === $unix);

/* ---- ISO-8601 strings (Neynar-hydrated payloads) ---- */

check('ISO string parses to UTC', BSync_FC_Inbound::cast_timestamp_to_unix('2024-02-15T19:23:22.000Z') === strtotime('2024-02-15T19:23:22Z'));
check('ISO string without millis parses', BSync_FC_Inbound::cast_timestamp_to_unix('2021-01-01T00:00:00Z') === $offset);

/* ---- garbage falls back to now ---- */

$now = time();
$parsed = BSync_FC_Inbound::cast_timestamp_to_unix('not a timestamp');
check('garbage falls back to now', abs($parsed - $now) <= 2);
$parsed = BSync_FC_Inbound::cast_timestamp_to_unix(null);
check('null falls back to now', abs($parsed - $now) <= 2);

finish('TIMESTAMP');
