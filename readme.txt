=== Woo2Nostr ===
Contributors: 4G0R4
Tags: woocommerce, nostr, NIP-99, marketplace, bitcoin
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 0.1.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Mirror WooCommerce products to Nostr NIP-99 (kind 30402) + Gamma Markets spec. Selective or bulk sync, variations/bundles, Shopstr-compatible, optional Nostr order inbox.

== Description ==

Woo2Nostr lets merchants mirror existing WooCommerce products to Nostr classified listings (NIP-99 `kind:30402` / `30403`) at their discretion.

**Publishing**
* Per-product metabox or Products list bulk action / dedicated Bulk Sync page.
* Background queue via Action Scheduler (bundled with WooCommerce).
* Supports simple, variable (+ one card per variation), grouped/bundle (single composite listing with bundle discount), virtual/downloadable.
* Gamma Markets extension: `type`, `stock`, `spec`, `weight/dim`, `shipping_option` (30406), collections (30405).
* Shopstr-compatible by default (`t` `shopstr` + `image` tag + optional cache POST) — toggleable.
* Price/currency exactly matches WooCommerce store currency.

**Key custody (merchant choice)**
* Server nsec (encrypted at rest) — enables background sync + polling.
* NIP-07 browser extension — manual publish.
* NIP-46 bunker (`bunker://`, also accepts `nostrconnect://` legacy) — remote signer.

**Orders (optional, on by default)**
* Polling inbox every 2 min for NIP-17 gift-wrapped `kind 16/17` orders addressed to merchant pubkey, creates WooCommerce orders (HPOS compatible).

**Privacy**
* Profile `lud16` / `payment_preference` pulled from `kind:0` and editable in settings; optional sync-back.

== Installation ==

1. Upload to `/wp-content/plugins/woo2nostr` and activate.
2. WooCommerce must be active.
3. Go to WooCommerce > Nostr and configure key mode, relays, Shopstr, payment prefs.
4. Use Products list bulk actions or product edit metabox to publish.

== Frequently Asked Questions ==

= Does it require a Nostr key? =
Yes, merchant chooses server nsec, NIP-07, or NIP-46.

= Which relays? =
Default `wss://relay.primal.net, wss://nos.lol, wss://relay.nostr.band, wss://relay.nostr.net, wss://auth.nostr1.com, wss://relay.damus.io` (free, good NIP-99 retention). Paid opt-in: `wss://relay.nostr.wine` + `wss://eden.nostr.land` (requires NIP-42 auth, ~18k sats / ~4M msats/mo, indefinite retention, NIP-99 supported). Coracle has no dedicated relay — it’s a client that uses your relays (its `bucket.coracle.social` is ephemeral cache, not for NIP-99). All editable.

== Changelog ==

= 0.1.3 =
* Per-product "Exclude from sync/bulk queue" checkbox in product metabox; excluded products are skipped by "All published products", list bulk action, and auto-sync (the per-product Publish button still works). Excluded count shown on Bulk page + diagnostics.

= 0.1.0 =
* Initial release: NIP-99 publish, variations, bundles, Shopstr, 3 key modes, polling inbox.

== Upgrade Notice ==

= 0.1.0 =
First release.
