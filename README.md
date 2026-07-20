# WC Stock Sync

Central stock management across multiple WooCommerce installations. Run several stores (for example a Norwegian and an English site selling the same products) and keep stock quantities in sync automatically.

## How it works

- One site is configured as the **hub** — the authoritative source of stock levels. The other sites are **spokes**.
- When stock changes anywhere (a sale, a refund/restock, or a manual edit in wp-admin), the change is picked up via WooCommerce's stock hooks and sent to the hub.
- The hub updates its own stock **atomically** (safe under concurrent sales on different sites) and broadcasts the resulting authoritative value to all spokes.
- Products and variations are matched across sites by **SKU**. Works with both simple and variable products.

### Reliability

- Outbound messages are written to a local **queue** (a fast DB insert) — customer-facing requests never wait on network calls to other sites.
- A background worker (WP-Cron) delivers queued messages within seconds under normal conditions, and **retries with backoff** if a peer site is temporarily unreachable. The system is self-healing: temporary outages cause delay, not data loss.
- Every message carries a unique id; receivers ignore duplicates, so retries and replayed requests can never double-apply a stock change.

### Security

- Sites communicate over a dedicated REST endpoint (`/wp-json/stock-sync/v1/webhook`) authenticated with an **HMAC-SHA256 signature** over a shared secret — no WooCommerce API keys needed.
- Requests older than 5 minutes are rejected, and duplicate message ids are ignored (replay protection).

## Requirements

- WordPress 6.5+
- WooCommerce
- PHP 7.4+
- The same SKU on corresponding products/variations across all connected sites. For variable products the SKU on **each variation** is what counts.

## Installation

See [INSTALL.md](INSTALL.md) for a detailed field-by-field walkthrough with troubleshooting. The short version:

1. Install and activate the plugin on **every** site that should participate.
2. On the hub site: *WooCommerce → Stock Sync* → set role **Hub**, choose a Site ID, generate a shared secret, and list the spoke URLs (one per line).
3. On each spoke site: set role **Spoke**, choose a Site ID, paste the **same** shared secret, and set the Hub URL.
4. On the hub: click **Run full resync** to push current stock levels to all spokes.
5. Test: change a stock quantity on one site and confirm it updates on the others. The **Recent events** log on each site's settings page shows exactly what was sent and received.

## Notes & tips

- Products that only exist on some sites are fine — unknown SKUs are simply ignored by the receiver. However, every product you want synced **must exist on the hub**, since all sync flows through it.
- Products without an SKU, or without "Manage stock" enabled, are skipped.
- WP-Cron (which powers queue delivery and retries) is triggered by site traffic. On low-traffic sites, configure a real server cron job to hit `wp-cron.php` on each site for punctual delivery.
- If your sites live on the same server and you see delivery timeouts in the log, ask your host about loopback requests / concurrent PHP worker limits.
- If SKUs can't be made identical across sites right away, the `wcss_resolve_sku` filter lets you map incoming SKUs to local product IDs in code.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

Built by [Mountaineering Labs](https://mountaineering.no).
