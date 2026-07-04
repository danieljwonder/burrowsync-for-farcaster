# BurrowSync for Farcaster

Two-way sync between a WordPress site and a Farcaster account, using [Neynar](https://neynar.com) for both reading (webhooks) and writing (managed signers).

> **The BurrowSync idea:** your WordPress site is the burrow — the home you own. BurrowSync digs tunnels from it to the surface networks: this plugin is the Farcaster tunnel, [nostr-for-wp](https://github.com/danieljwonder/nostr-for-wp) connects Nostr, and every burrow connects to the warren. Suite home: [burrowsync.com](https://burrowsync.com).

- **Inbound**: your top-level casts become WordPress `note` posts — original timestamp, channel, images, canonical cast link.
- **Outbound**: publishing a `note` casts it from your account — first image as an embed, byte-safe truncation with permalink for long notes.
- **Dependency-free**: plain REST over the WordPress HTTP API. No Composer packages, no Node SDK.
- Single-user model: one configured FID per install (the site owner's), entered at setup. Nothing user-specific is hardcoded.

Interoperates with (but does not require) the companion plugin [nostr-for-wp](https://github.com/danieljwonder/nostr-for-wp).

## Content-flow rules

| # | Content | Direction | Behaviour |
|---|---------|-----------|-----------|
| 1 | Cast by the configured FID (top-level) | Farcaster → WP | Imported as a `note`, stamped `_farcaster_origin` |
| 2 | WP-native note | WP → Farcaster | Published as a cast |
| 3 | Nostr-origin note (`_nostr_origin`, created by nostr-for-wp) | WP → Farcaster | Published as a cast — mirrored content deliberately flows on |
| 4 | Farcaster-origin note (`_farcaster_origin`) | WP → Farcaster | **Never** published back (anti-echo guard) |
| 5 | Anything | WP → Nostr | Never — this plugin does not touch Nostr |

### The anti-echo model (for future maintainers)

WordPress core's `wp_insert_post()` writes `meta_input`, **then** fires `transition_post_status` — synchronously, inside the one call. The outbound publisher listens on that transition. So the `_farcaster_origin` stamp **must** travel via `meta_input`; an `update_post_meta()` call after the insert returns is too late — the publisher would already have queued the post. `tests/test-inbound-guard.php` locks this in: an inbound-created note must *never be queued* (not "queued then skipped").

Self-echo is the second trap: when this plugin casts a note, Neynar's webhook fires `cast.created` for that very cast. The publisher stores the returned hash as `_farcaster_cast_hash`; the inbound handler looks up every incoming hash against stored values (single indexed query) and skips matches. The same lookup makes webhook redelivery idempotent.

### Interop with nostr-for-wp

- Both plugins share the `note` CPT. This plugin checks `post_type_exists('note')` at `init` priority 20 and only registers the CPT when absent (identical slug/shape).
- Setting **“Allow Farcaster-origin notes to publish to Nostr”** (default **off**): when off, imported notes are also stamped `_nostr_origin` — a deliberate reuse of the companion plugin's suppression convention so it will not bridge them onward. When on, no extra stamp, and nostr-for-wp (if active) treats them as bridgeable.
- Interop is meta-conventions only. This plugin never calls nostr-for-wp classes, functions, or hooks, and works fully without it.

## Setup

1. **Neynar API key** — sign up at [neynar.com](https://neynar.com) (paid service), create an API key. Also create a *Sign in with Neynar* client (authorized origin = your site's domain) and note its client ID.
2. **Connect the signer** — *Settings → Farcaster*, enter key + client ID, click *Sign in with Neynar*, approve in your Farcaster app (Neynar's hosted flow shows the QR/deeplink). The plugin verifies the signer server-side (`GET /v2/farcaster/signer`) and stores the `signer_uuid` and FID. All sync is locked to that FID.
3. **Webhook** — click *Create webhook*: registers `cast.created` filtered to your FID, pointed at `/wp-json/burrowsync-for-farcaster/v1/webhook` (derived from your site URL, shown on screen), and stores the shared secret. Manual dashboard setup is documented on the screen as fallback.
4. **Sync behaviour** — directions, inbound status (publish/draft), replies/recasts toggles, image sideloading, default channel, permalink appending, Nostr bridging, polling fallback.

> **Why SIWN instead of raw signer creation?** Neynar's raw managed-signer flow (`POST /v2/farcaster/signer/signed_key`) requires an EIP-712 signature from an *app developer's* custody wallet mnemonic. A self-hosted plugin has no app operator, and asking users to paste a seed phrase into wp-admin is not acceptable. SIWN is Neynar's supported no-mnemonic path and yields the same kind of managed signer, approved and revocable by the user's custody wallet. An advanced fallback accepts a pre-existing `signer_uuid` (verified with Neynar before storing).

### Webhook verification

Every delivery to the (only) public endpoint is authenticated before parsing:

- Header `X-Neynar-Signature`, hex HMAC **SHA-512** over the **raw** request body, keyed with the webhook shared secret, compared with `hash_equals()`.
- Missing/invalid signature → `401`, nothing created. Valid deliveries get a fast `200`; post creation and media sideloading run in an immediately-spawned WP-Cron job.

### Outbound details

- Trigger: `transition_post_status` → `publish` for `note` (covers scheduled publishes). Anti-echo guard first.
- Cast text limit is **1024 bytes** (bytes, not characters — measured with `strlen`). Longer notes are truncated on a word boundary with an ellipsis and the permalink appended. Embeds: first image (featured, first attached, or first `<img>`), then the permalink when truncated — max two.
- Failures never fail the WordPress publish. Retries back off 5 min / 15 min / 60 min / hourly, giving up after 24 h with a visible failed state and per-post *Retry now*.
- Exactly-once: a per-post Neynar `idem` idempotency key is minted at first queue time and reused on every retry, so an ambiguous timeout (request delivered, response lost) cannot double-post — Neynar deduplicates server-side. This is the documented design choice; no text/timestamp reconciliation is needed.

### Secrets at rest

API key and webhook secret are encrypted with sodium `crypto_secretbox`, keyed from `AUTH_KEY`/`AUTH_SALT` (which live in `wp-config.php`, outside the database). This protects against database-only leaks — dumps, SQLi, stolen backups — not against a compromised host. Secrets are masked in the UI after save and never logged.

## Hosting requirements

Standard WordPress (5.9+, PHP 7.4+) with outbound HTTPS. Inbound webhooks additionally need the site reachable over **public HTTPS**; sites behind auth walls or on intranets should enable the 15-minute polling fallback instead (same idempotent path — webhook + poll never double-create). No host-specific assumptions.

## Development

```
burrowsync-for-farcaster.php        bootstrap, CPT registration, activation
includes/
  class-bsync-fc-settings.php   options + encrypted secrets
  class-bsync-fc-neynar-client.php  REST client (wp_remote_*)
  class-bsync-fc-webhook.php    REST endpoint + signature verification
  class-bsync-fc-inbound.php    cast -> note (origin stamping, dedupe)
  class-bsync-fc-publisher.php  note -> cast (queue, retry, truncation)
  class-bsync-fc-signer.php     SIWN connect + signer health checks
  class-bsync-fc-cron.php       polling fallback + daily signer check
admin/                      settings screen, note column, meta box
tests/                      standalone regression tests (no WP needed)
```

Run the tests with any CLI PHP:

```sh
sh tests/run-tests.sh
```

They cover: the inbound-guard invariant (core-faithful `wp_insert_post` ordering), webhook signature verification and 401 behaviour, redelivery and self-echo dedupe, 1024-byte truncation (including multibyte), Farcaster-epoch timestamp conversion (`unix = farcaster_ts + 1609459200`), and secrets round-trip/masking. All fixtures use obviously fake FIDs, hashes, and usernames.

## License

GPLv2 or later — see [LICENSE](LICENSE).

## Trademarks

BurrowSync is an independent open-source project, not affiliated with, endorsed by, or sponsored by Merkle Manufactory Inc. (Farcaster) or Neynar. The names "Farcaster" and "Neynar" are used only to describe interoperability with those services.
