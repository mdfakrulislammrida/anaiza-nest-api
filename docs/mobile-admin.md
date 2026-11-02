# The admin on a phone (Batch OPS2-B)

From 768 px up every admin screen is exactly as before. Below 768 px:

* **Lists are cards.** Orders, Products, Customers, Abandoned checkouts, Reviews, Courier statuses, Coupons, Shipping zones, and the two dashboard
  tables keep every column for a desktop (and in the column menu) and add one card column for a phone (`App\Filament\Support\MobileCards`). Filament's
  own Stack/Split would turn the table into cards at every width, so they are not used. Tapping the card opens the record.
* **Row actions** are in a line on a desktop and in one "Actions" menu on a phone (`MobileCards::phone()`, `MobileCards::actions()`).
* **The order page** reads in a phone's order: status and totals, the main buttons (Send to courier, Sync now, Mark verified, Download invoice) full
  width with the rest under "More", customer and address, Items and Courier history as stacked cards, then the courier details, notes and the rest. The
  order comes from CSS keyed to the form's order of parts; `MobileAdminTest` fails if a part is added or moved so the numbers can follow.
* **Sidebar** starts shut on a phone and after every page load; fields are 16 px; buttons and menu items are at least 44 px; dialogs fit 360 px.

## How it ships

All of it is an inline `<style>` (and one tiny script for the sidebar) added through the panel's existing render hook
(`resources/views/filament/admin/mobile-styles.blade.php`, registered in `AdminPanelProvider`). There is no Node build, no stylesheet file and no
`filament:assets` step. On the server: `git pull`, then `php artisan optimize:clear`.

## What the card shows

| List | Card |
|---|---|
| Orders | number and time, status, customer and phone, payment, courier and its status, total |
| Products | picture, name, active or hidden, SKU and category, stock, price |
| Customers | name, number of orders, phone, email |
| Abandoned checkouts | name, status, phone and email, cart total and pieces, last activity, stage and reminders sent |
| Reviews | name and stars, status, product, the start of the review, when |
| Courier statuses | courier and status, effect, meaning, final, cash, armed, times seen |
| Coupons | code, whether it is live, the discount and minimum, end and uses |
| Shipping zones | name, fee, days |
| Dashboard | best sellers: picture, name, units, SKU, price. Sources: campaign, revenue, source, orders |

## Looking at it

`scripts/mobile-admin-fixture.php` fills a THROWAWAY SQLite database with made-up data (it refuses anything else), and
`scripts/mobile-admin-check.mjs` drives headless Chrome over a local copy of the admin at 390 and 360 px: `--assert` exits 1 when any screen's page or
table scrolls sideways; `--shots <folder>` saves screenshots (`docs/mobile-admin-screenshots/` is not committed); `--desktop` also records the table
columns at 1280 px. It needs Chrome or Edge and no package.
