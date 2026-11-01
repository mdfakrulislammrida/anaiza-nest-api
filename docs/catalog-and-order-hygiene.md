# SKU hygiene, variant feed items, clean campaign text, and what was found about the 503s and the mail

For the person who runs the shop. What changed in batch TRK5-B, the two commands to run once on the server (both are dry runs until you add a flag), and the findings.

## 1. SKUs

The SKU is the id a product or variant has everywhere: the product feeds (Meta, Google, TikTok), the `content_ids` the ad pixels send, and the shop's own Purchase. They match a catalogue item only when they are **exactly** the same text.

**The rule** (`App\Support\Sku::clean`): trim, make inner whitespace one space, and remove a leading `SKU` word with an optional `:` or `-` or spaces after it (any case). `SKU ANZ-SG-002-SQ` becomes `ANZ-SG-002-SQ`; `SKUNK-1` and `ANZ-SKU-1` are left alone.

- **On save** (admin, import, anything): a product's and a variant's SKU are cleaned. In the admin form a SKU that is empty once cleaned, or already used by another product or variant (case ignored), is refused with a plain message naming the other owner. A variant may leave its SKU empty (it then takes its product's) or repeat its own product's.
- **In the feeds**: ids are cleaned again on the way out, so an old value in the table can never be written as `SKU ...`.

**Check the live data, then clean it** (both on the server, after `git pull`):

```
php artisan catalog:sku-audit          # lists what would change and every duplicate; changes nothing
php artisan catalog:sku-audit --fix    # applies the clean-up (only after reading the list); duplicates are never changed for you
```

After `--fix` the product feeds are rebuilt on their next request, and Meta picks the new ids up on its next scheduled fetch (or press **Update now** on the data source in Commerce Manager).

## 2. Variants as feed items

A variant with a SKU of its own is **its own item** in `/feeds/meta.csv` and `/feeds/google.xml` (and `tiktok.csv`, where the product row itself is left out). This was already how the feeds worked; this batch added the SKU cleaning and the tests that keep it true.

| Field | Value |
| --- | --- |
| `id` | the variant's SKU (cleaned) |
| `item_group_id` | the **product's id** (not its SKU: it always exists and does not change when a SKU is retyped; the SKU is the item id of the product's own row) |
| title | the product's title + ` - ` + the option, e.g. `Tea coaster set - Shape: Square` |
| price, sale price, availability | the variant's own, else the product's. Out of stock stays marked (`out of stock`), or the item is left out when Settings > Product feeds says to leave out-of-stock items |
| image | the variant's own image first, else the product's |
| link | the product page. The storefront has no address parameter that selects a variant, so every item points at the product page |

A product without variants is exactly as before. If Meta shows only the products and not their variants, check in this order: the variant really has a SKU of its own (Admin > Products > the product > Variants); `catalog:sku-audit` shows no duplicate; the data source in Commerce Manager has fetched the feed since (the shop keeps a feed for an hour).

## 3. Campaign text on orders

`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term` are stored as one stable text (`App\Support\Utm`, the same rule as the storefront's `cleanUtmValue`):

- percent-encoding is decoded **once**, and only when the value really holds a `%XX` sequence (`100% off` stays as it is);
- a value with no space in it but plus signs between its words (two or more, or a plus around a separator such as `|`) has them read as spaces: `AN+|+SALES+|+Broad` becomes `AN | SALES | Broad`. One plus (`Buy1+Get1`), a plus at the end (`C++`) and a value that already has spaces are left alone;
- spaces are trimmed and doubled spaces made single.

Only these five fields are ever touched. Orders already stored are not rewritten until you say so:

```
php artisan orders:utm-clean           # counts, and three before/after samples; changes nothing
php artisan orders:utm-clean --apply   # updates only those five fields of those orders (the order's own updated time is left alone)
```

## 4. POST /api/checkout-sessions answering 503 (investigation)

**What the application can answer here:** `204` (always, even when saving the note fails: the error is caught and written to the log with its reason), `422` (a malformed body), `429` (more than 90 a minute from one address, with `Retry-After`), and **`503` only when the whole site is in maintenance mode** (`php artisan down`, the file `storage/framework/down`). Nothing in the application returns 503 for this route otherwise. All reproduced in `tests/Feature/Api/CheckoutSessionFailureTest.php`.

**Can it block a checkout?** No, not from the storefront: the browser sends it fire-and-forget and ignores the answer. The order is a separate request, and a broken `abandoned_checkouts` table does not stop an order (tested).

**The one application-side risk, fixed:** SQLite has one writer at a time and the shop lets an order wait up to 10 seconds for its turn. The checkout-session report is a best-effort note, but it waited just as long, holding a PHP worker. On shared hosting a pile of held workers is what makes the host answer **503** to everyone. The report now waits at most 1.5 seconds and is simply dropped if the database is busy.

**The 503 for the storefront's own pages (HEAD requests to `/cart`, `/checkout`, `/account`, `/track-order`)** cannot come from this application: those are static files on the storefront's host. That is the host itself (a resource or rate limit, a security rule that dislikes HEAD requests).

**What to check on Hostinger** (hPanel): *Hosting > Resource usage* (CPU, memory, I/O and **entry processes / PHP workers**: a flat line at the limit when the 503s happened means the plan is too small, or something holds workers); *Hosting > Logs* (the error log and access log at those times: a `503` with no matching line in `storage/logs` is the host's, not Laravel's); that no `storage/framework/down` file was left behind after a deploy; and whether a firewall or "hotlink/bot protection" rule answers HEAD requests.

## 5. Mail and the order that stayed Pending (investigation)

- **Does a failed email retry?** It did not. A failure was logged and noted in System health, and the order email was lost unless the status was changed again. **Now it does**: the order remembers it (`orders.emails_failed`), and the scheduled task `emails:retry` (every 15 minutes) sends it again 15 minutes, 1 hour and 3 hours after each failure, within 24 hours of the first, never twice (what is sent is recorded first). Only when those tries are used up does it reach Settings > System health. A "shipped" note is dropped if the order has moved on (e.g. to delivered).
- **Does an SMTP timeout block anything?** The email is sent after the response has gone, so the visitor never waits for it. In the admin the PHP worker is still busy until the mail server answers, so the timeout matters: it is **10 seconds** (`MAIL_TIMEOUT`, default 10; `config/mail.php`), added in batch TRK2-B. If the server's `.env` sets `MAIL_TIMEOUT` to something larger, that wins: check it.
- **What makes an order show as "Pending for more than a day"?** (Settings > System health > Orders.) The rule: the order's status is exactly **Pending**, it was created **more than 24 hours ago**, and it is **not an order brought over from the old site**. Nothing moves an order out of Pending by itself: only a person (Processing, Shipped, Cancelled ...), or for a wallet payment the Mark verified / Mark failed buttons (they change the *payment* state, not the order status). A cash-on-delivery order nobody confirmed, and a wallet order still awaiting verification, both count.
