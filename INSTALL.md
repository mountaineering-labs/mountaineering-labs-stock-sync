# Installation guide

This guide walks through a complete setup, field by field. The example uses three stores: `store-a.example` (the hub) and two spokes, `store-b.example` and `store-c.example`.

> **Before you start:** make sure corresponding products/variations share the same SKU on every site, and have "Manage stock" enabled. For variable products it is the SKU on **each variation** that counts — a variation with an empty SKU field is skipped, even if the parent product has one.

## Step 1 — Install the plugin on every site

On **each** of your sites:

1. Log in to wp-admin.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Choose `multi-store-stock-sync.zip` and click **Install Now**.
4. Click **Activate**.

## Step 2 — Configure the hub

On the site that should own the authoritative stock (usually your main store):

1. Go to **WooCommerce → Stock Sync**.
2. Set **Role** to `Hub`.
3. Enter a **Site ID** — a short, unique label for this site, e.g. `store-a`. It appears in the logs of the other sites so you can see where a change came from.
4. Click **Generate** under *Generate new shared secret*. Copy the generated value — you will paste it into every spoke in the next step.
5. In **Spoke URLs**, enter the full URL of every spoke site, one per line:
   ```
   https://store-b.example
   https://store-c.example
   ```
6. Click **Save Changes**.

## Step 3 — Configure each spoke

On every other site:

1. Go to **WooCommerce → Stock Sync**.
2. Set **Role** to `Spoke`.
3. Enter a unique **Site ID**, e.g. `store-b`.
4. Paste the **same shared secret** you generated on the hub. Copy-paste it — a single stray space will make the hub reject every message.
5. Set **Hub URL** to the hub site's address, e.g. `https://store-a.example`.
6. Click **Save Changes**.

## Step 4 — Initial sync

On the **hub**:

1. Go to **WooCommerce → Stock Sync**.
2. Click **Run full resync**. This queues the hub's current stock level for every SKU-carrying product/variation and delivers it to all spokes within moments.

## Step 5 — Verify

1. On the hub, open any synced product and change its stock quantity.
2. Within seconds (up to a couple of minutes on low-traffic sites), the same product on each spoke shows the new quantity.
3. Check **Recent events** at the bottom of each site's *Stock Sync* page — you should see the message being sent on one side and "Webhook received and applied" on the other.
4. Repeat the test in the other direction: place a test order (or edit stock) on a spoke and confirm the hub and the other spoke update.

## Troubleshooting

**Nothing arrives at the other site**
- Compare the shared secret on both sites character by character (best: re-copy-paste).
- Confirm the Hub URL / Spoke URLs are exact, including `https://` and any `www.`.
- Open `https://<other-site>/wp-json/stock-sync/v1/webhook` in a browser. A quick JSON error response (`rest_no_route`, 404) is **good** — it means the endpoint is reachable (it only accepts signed POST requests). No response at all means a firewall or security plugin is blocking REST requests.

**"Outbound sync failed … cURL error 28 (timeout)" in the log**
- The queue retries automatically, so the change will usually arrive a few minutes later on its own.
- If it happens constantly and your sites share one server, ask your host about *loopback requests* and the number of concurrent PHP workers.

**Sync is slow on quiet sites**
- Delivery runs on WP-Cron, which is triggered by site visits. Configure a real server cron job to request `https://your-site/wp-cron.php?doing_wp_cron` every minute or two on each site, and add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.

**A product refuses to sync**
- Check that the variation (not just the parent product) has an SKU, that the SKU is identical on all sites, and that "Manage stock" is enabled on the same level (variation vs. product) everywhere.
- Remember every synced product must also exist on the hub — two spokes cannot sync a product the hub does not have.

## Upgrading

Use **Plugins → Add New → Upload Plugin**, select the new zip, and choose **"Replace current with uploaded"**. Do **not** delete the plugin to reinstall it — deleting removes all settings, sync state and queued messages (that is what deletion is supposed to do).
