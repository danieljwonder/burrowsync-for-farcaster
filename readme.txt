=== BurrowSync for Farcaster – Two-Way WordPress Sync ===
Contributors: danieljwonder
Tags: farcaster, neynar, sync, social, crossposting
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two-way sync between WordPress notes and Farcaster casts via the Neynar API. Casts become notes; published notes become casts.

== Description ==

**Beta.** Pre-1.0. It runs in production, but test it on a staging site before relying on it.

Your WordPress site is the burrow — the home you own, underground and safe. BurrowSync digs tunnels from it to the surface networks. This plugin is the Farcaster tunnel; a companion connects Nostr, and every burrow connects to the warren.

Concretely: BurrowSync for Farcaster keeps a personal WordPress site and a Farcaster account in two-way sync using [Neynar](https://neynar.com):

* **Farcaster → WordPress**: your top-level casts arrive as `note` posts within webhook latency, with the original timestamp, channel, images, and a link back to the cast.
* **WordPress → Farcaster**: publishing a note casts it from your account, with the first image attached and automatic byte-safe truncation (permalink appended) for long notes.

The plugin targets a single-user personal site: one Farcaster account (FID) per install — yours.

= Content-flow rules =

| Content | Direction | Behaviour |
| --- | --- | --- |
| Cast by your FID (top-level) | Farcaster → WP | Imported as a note, marked as Farcaster-origin |
| Note written in WordPress | WP → Farcaster | Published as a cast |
| Note imported from Nostr (BurrowSync for Nostr) | WP → Farcaster | Published as a cast (mirrored content flows on) |
| Note imported from Farcaster | WP → Farcaster | **Never** published back (anti-echo guard) |
| Replies / recasts | Farcaster → WP | Excluded by default; settings toggles available |

The plugin never creates duplicates: your own outbound casts are recognised when the webhook echoes them back, and redelivered webhooks are deduplicated by cast hash.

= Interop with BurrowSync for Nostr =

If the companion BurrowSync for Nostr plugin is active, both plugins share the same `note` post type. Notes imported from Nostr are also casted to Farcaster. Whether notes imported from Farcaster should bridge onward to Nostr is your choice via a setting (default: off). BurrowSync for Farcaster works fully without the companion plugin.

= What you need =

* A **Neynar account** (paid service) — the plugin uses Neynar for reading (webhooks) and writing (managed signers). No other dependencies; all requests use the WordPress HTTP API.
* A site reachable over **public HTTPS** for the webhook. Sites behind auth walls or without HTTPS can use the built-in polling fallback instead.

= Custody model, plainly =

Writing to Farcaster uses a Neynar **managed signer**: the signing key is generated and held by Neynar, and you approve it once from your Farcaster custody wallet (via Neynar's hosted "Sign in with Neynar" flow, which shows a QR code / deeplink). You can revoke the signer from your custody wallet at any time; the plugin checks daily and will tell you if it has been revoked. Your custody keys and seed phrase are never entered into WordPress.

= Privacy and disclosure =

* Publishing a note sends its content (and image URLs) to Neynar's API, which posts it to the public Farcaster network.
* Inbound content comes from the Farcaster network via Neynar webhooks.
* Your Neynar API key grants its holder signing ability through the managed signer; the key and the webhook secret are stored encrypted (keyed from your WordPress salts) and are masked in the UI. This protects database dumps, not a fully compromised host.
* No telemetry, no phoning home, no update checks beyond standard WordPress mechanisms.

= Trademarks =

BurrowSync is an independent open-source project. It is not affiliated with, endorsed by, or sponsored by Merkle Manufactory Inc. (Farcaster) or Neynar. "Farcaster" and "Neynar" are used only to describe interoperability with those services.

== Installation ==

1. Upload the plugin and activate it. An admin notice links to **Settings → Farcaster**.
2. **API key**: sign up at neynar.com, create an API key, paste it in step 1 of the setup screen. Also create a "Sign in with Neynar" client in the portal (set its authorized origin to your domain) and paste the client ID.
3. **Connect your account**: click the "Sign in with Neynar" button and approve the signer with your Farcaster app. Your FID and username appear when approval completes.
4. **Webhook**: click "Create webhook" — the plugin registers a Neynar webhook for your FID pointed at your site's endpoint (shown on the screen) and stores the secret. If your plan does not allow API webhook creation, create it manually in the Neynar dashboard (event `cast.created`, filter `author_fids` = your FID, target = the endpoint URL shown) and paste the secret.
5. **Sync behaviour**: choose directions, inbound post status, image handling, optional default channel, and the polling fallback if you cannot receive webhooks.

Each step shows its current status, so partial setups resume where you left off.

== Frequently Asked Questions ==

= Do I need to pay for Neynar? =

Yes. Neynar is a commercial API and both the webhook (reading) and managed signer (writing) features require a subscription. See neynar.com for plans.

= Why does approval happen in my Farcaster app? =

Adding a signer is an on-chain Farcaster operation that only your custody wallet can authorise. The plugin never sees your custody keys; it only stores the resulting signer UUID.

= What happens if Neynar is down when I publish? =

The WordPress publish always succeeds. The cast is queued and retried with backoff (5 min, 15 min, 60 min, then hourly) for up to 24 hours, after which it is marked failed with a per-post "Retry now" button. Retries reuse an idempotency key so a cast can never be posted twice.

= Will my imported casts echo back to Farcaster? =

No. Notes created from your casts are stamped as Farcaster-origin before WordPress fires any publish hooks, and the outbound publisher refuses them as its first check. This invariant is covered by a regression test.

= Does deactivating the plugin lose my connection? =

No. Deactivation only unschedules background tasks. The signer, API key, and all sync metadata survive deactivate/reactivate. Deleting the plugin removes its settings and secrets; post content is never deleted, and sync metadata on posts is only removed if you opted into a full purge first.

= My site is not publicly reachable — can I still sync? =

Outbound (WP → Farcaster) works anywhere with outbound HTTPS. For inbound, enable the 15-minute polling fallback in step 4 instead of the webhook.

== Changelog ==

= 0.9.0 =
* First public beta. Versioning stays below 1.0 while the BurrowSync family is in testing

* Initial release: webhook-driven inbound sync, queued outbound publishing with retries, Sign in with Neynar signer connection, encrypted secrets, BurrowSync for Nostr interop, polling fallback.
