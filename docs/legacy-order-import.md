# Importing the old site's orders (`orders:import-legacy`)

The old site's export is three MySQL dump files: `customers.sql`, `orders.sql` and `order_details.sql`. They hold real customer data.
They live in `storage/imports/`, which is git-ignored. Never commit them; reports from this command show counts only.

## Mapping

| Old | New |
| --- | --- |
| `orders.invoice_id` (the number customers were given) | `orders.legacy_order_id` (unique), shown as the order number on the invoice, in admin ("Old order #") and on Track |
| `orders.id` | not kept (only used to join the lines) |
| `orders.amount` | `orders.total`, exactly (nothing is priced again) |
| `orders.shipping_charge` | `orders.delivery_fee` |
| `orders.discount` | `orders.discount_amount` |
| `amount - shipping_charge + discount` | `orders.subtotal` (so subtotal + fee - discount = total, always) |
| `orders.order_status` | `orders.status`, through a map you give (see below) |
| `orders.created_at` / `updated_at` | same, read as Bangladesh time and stored as UTC; `delivered_at` = `updated_at` for delivered orders |
| `orders.note` / `admin_note` | `gift_note` / `payment_note` |
| `customers.name`, `phone`, `address`, `district`, `area` | the order's `ship_*` snapshot (area becomes thana) and a customer record |
| `order_details.product_name`, `qty`, `sale_price` | `order_items.product_name`, `quantity`, `price` (no `product_id`, no picture) |
| `order_details.product_size` / `product_color` | `variant_name` / `variant_value` ("Size / Colour") |
| payment | `cod` |
| (new) | `orders.source = 'legacy_import'` |

Phones are normalised to `01XXXXXXXXX` (`+88`, `88`, spaces and dashes dropped; a missing leading 0 restored). Customers are merged
by normalised phone, with the shop's own customers and with each other. New customers get no email and a random password nobody knows.

## What an imported order never does

It is written without model events and marked `source = legacy_import`. Every place that sends something skips it: confirmation and status
emails, review requests (email and WhatsApp), the abandoned-cart reminders (the order only counts as "already ordered"), Meta / TikTok / GA4
events, courier booking (manual, bulk and automatic), and the "pending for a day" health warning. Stock is never changed (lines have no product).

## Running it

```
php artisan orders:import-legacy --dry-run
php artisan orders:import-legacy --force --status-map="5=delivered,6=cancelled"
```

- `--dry-run` (also what you get with neither flag) prints: rows in the export, orders to import / already imported / skipped (with reasons),
  date range, total taka, counts per status and per old code, and things worth knowing. No names, phones, emails or addresses.
- `--force` imports in one transaction. Before it writes anything it copies the SQLite file to `storage/backups/before-legacy-import-<time>.sqlite`
  and prints the path. If the database is not a SQLite file it refuses.
- The old `order_status` codes are not documented in the export. The dry run uses an assumed map (`1=pending, 2=processing, 3=shipped, 5=delivered,
  6=cancelled`) and says so; a real run refuses unless you give `--status-map` (or `--accept-default-status-map`). Targets: `pending`, `processing`
  (alias `confirmed`), `shipped`, `delivered`, `cancelled`, `returned`.
- Running it again never duplicates: an order whose old number is already there is left alone.

## Left out, and why

An order is skipped (and counted by reason) when it has no number, repeats a number, has no lines in the export, belongs to a customer who is
not in the export, has a status code with no mapping, or has an amount or line that is not a number. Lines that belong to no order in the export
are ignored and counted.
