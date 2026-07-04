# Regression tests

Standalone CLI tests — no WordPress install, no network, no dependencies.
`wp-stubs.php` provides minimal WordPress function stubs; crucially, its
`wp_insert_post()` reproduces core's ordering exactly (post row →
meta_input → transition_post_status) because the anti-echo guard depends
on that ordering.

Run everything:

```sh
sh tests/run-tests.sh
```

| Suite | Covers |
| --- | --- |
| `test-inbound-guard.php` | The core invariant: inbound-created notes are NEVER queued for outbound publishing (not queued-then-skipped); control cases prove WP-native and Nostr-origin notes ARE queued; `_nostr_origin` interop stamping; author-FID enforcement; reply filtering; post-date epoch conversion |
| `test-webhook-verify.php` | HMAC SHA-512 signature verification (401 on missing/invalid/unverifiable), redelivery dedupe, self-echo dedupe by cast hash, foreign-FID rejection |
| `test-outbound-retry.php` | Neynar outage: WP publish never fails, backoff schedule (5/15 min), successful retry stores the hash exactly once, identical `idem` key across attempts, 24h give-up to a failed state |
| `test-truncation.php` | 1024-BYTE cast limit: word-boundary truncation with ellipsis + permalink, multibyte/emoji safety, always-append setting, HTML flattening |
| `test-timestamp.php` | Farcaster-epoch conversion (`unix = ts + 1609459200`), ISO-8601 handling, fallbacks |
| `test-crypto.php` | Secrets round-trip, no plaintext in stored options (database-dump check), UI masking, tamper rejection |

All fixtures use obviously fake identifiers (FID `12345`, `@examplecaster`,
repeated-digit hashes, `example.com`). Nothing here corresponds to a real
account.
