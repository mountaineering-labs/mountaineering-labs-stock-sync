=== Mountaineering Labs Stock Sync for WooCommerce ===
Contributors: mountaineeringlabs
Tags: woocommerce, stock, synchronization, multistore, inventory
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Central stock management across multiple WooCommerce stores. One hub owns the stock; spokes stay in sync automatically via SKU matching.

== Description ==

Run several WooCommerce stores that sell the same products — for example a domestic and an international site — and keep stock quantities in sync automatically.

**How it works**

* One site is configured as the **hub**, the authoritative source of stock levels. The other sites are **spokes**.
* When stock changes anywhere (a sale, a refund/restock, or a manual edit in wp-admin), the change is picked up via WooCommerce's stock hooks and sent to the hub.
* The hub updates its own stock **atomically** (safe under concurrent sales on different sites) and broadcasts the resulting authoritative value to all spokes.
* Products and variations are matched across sites by **SKU**. Works with both simple and variable products.

**Reliable by design**

* Outbound messages are written to a local queue (a fast database insert) — customer-facing requests never wait on network calls to other sites.
* A background worker delivers queued messages within seconds and retries with backoff if a peer site is temporarily unreachable. Temporary outages cause delay, not data loss.
* Every message carries a unique id; receivers ignore duplicates, so retries can never double-apply a stock change.

**Secure**

* Sites communicate over a dedicated REST endpoint authenticated with an HMAC-SHA256 signature over a shared secret — no WooCommerce API keys needed.
* Stale requests are rejected and duplicate message ids are ignored (replay protection).

**Languages**

English admin UI, fully internationalized. Translations are managed through translate.wordpress.org and delivered automatically.

== Installation ==

1. Install and activate the plugin on **every** site that should participate.
2. On the hub site: go to *WooCommerce → Stock Sync*, set role **Hub**, choose a Site ID, generate a shared secret, and list the spoke URLs (one per line).
3. On each spoke site: set role **Spoke**, choose a Site ID, paste the **same** shared secret, and set the Hub URL.
4. On the hub: click **Run full resync** to push current stock levels to all spokes.
5. Test: change a stock quantity on one site and confirm it updates on the others. The **Recent events** log on each site's settings page shows exactly what was sent and received.

Corresponding products/variations must share the same SKU on every site, and have "Manage stock" enabled. For variable products the SKU on **each variation** is what counts.

== Frequently Asked Questions ==

= Do all products have to exist on every site? =

No. Products that only exist on some sites are simply not synced — unknown SKUs are ignored by the receiver. However, every product you want synced must exist on the hub, since all sync flows through it.

= Does a spoke work if the hub is down? =

Yes. Orders complete normally — stock reports are queued locally and delivered automatically once the hub is reachable again.

= Why is sync slow on my site? =

Delivery runs on WP-Cron, which is triggered by site visits. On low-traffic sites, configure a real server cron job to hit wp-cron.php on each site.

= My sites are on the same server and I see timeouts in the log =

Ask your host about loopback requests and the number of concurrent PHP workers. The queue retries automatically, so changes still arrive — just with delay.

= Can I map different SKUs to each other across sites? =

Identical SKUs are strongly recommended, but the `wcss_resolve_sku` filter lets developers map incoming SKUs to local product IDs in code.

== Changelog ==

= 1.4.0 =
* Renamed to Mountaineering Labs Stock Sync for WooCommerce for a distinctive name.
* Admin settings script is now enqueued via wp_enqueue_script instead of inline output.
* Removed bundled translation files; translations are handled by translate.wordpress.org.

= 1.3.0 =
* Renamed to Mountaineering Labs Stock Sync for WooCommerce (trademark compliance).
* Hardened SQL statements and cleaned up coding-standard warnings.

= 1.2.0 =
* Added German, French, Spanish, Danish and Swedish translations.

= 1.1.0 =
* English admin UI with Norwegian (bokmål and nynorsk) translations.
* Replay/duplicate protection: every message carries a unique id; retries can no longer double-apply a change.
* uninstall.php: cleans up all plugin data on deletion.
* Schema migrations now run on in-place upgrades as well as activation.

= 1.0.0 =
* Initial release: hub/spoke stock sync with signed messages, durable outbound queue with retry, and full resync.

== Upgrade Notice ==

= 1.2.0 =
Adds five new admin languages. No functional changes.
