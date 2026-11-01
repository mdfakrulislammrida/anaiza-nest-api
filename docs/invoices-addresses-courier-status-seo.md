# Invoice download, page addresses, courier status updates, share images (OPS1-B)

## 1. Invoice download

Three ways to get the PDF, all returning `Content-Type: application/pdf` and
`Content-Disposition: attachment; filename="AnaizaNest-Invoice-<order number>.pdf"`. Any failure is JSON (never an HTML page, never a 500 for a
wrong link).

| Path | Who | Wrong access |
|---|---|---|
| `GET /api/orders/{id}/invoice?expires=…&signature=…` | Anyone holding the signed link. The link is `invoice_url` in the order answer (right after checkout, and on Track order once the phone is proved). Valid 7 days. | 403 `invoice_link_invalid` |
| `GET /api/account/orders/{id}/invoice` (Sanctum token) | The signed-in customer, own orders only | 401 JSON; another customer's order 404 |
| `GET /admin/orders/{id}/invoice` | Admin with *Manage orders*; also the **Download invoice** button on the order page | login redirect / 403 |

* A failure to build the PDF is logged (order id, error class, the server problems found; no name, phone or address) and answered as 500 JSON
  `invoice_failed` with a plain sentence.
* CORS exposes `Content-Disposition`, `Content-Length` and `Retry-After`, so the storefront can read the file name.
* Rate limit `invoice`: 20 a minute per address.
* **System health > Invoices** and `InvoicePdf::problems()` check what breaks PDFs on shared hosting: PHP `dom`, `mbstring`, `gd`; writable
  `storage/fonts`, `storage/app`, `storage/framework/cache`, `bootstrap/cache`; the Hind Siliguri font files (the ৳ sign); `memory_limit` of 128M or more.
  Remote images are off in the PDF; the logo and product pictures on this server are embedded from disk, other pictures are fetched once with
  short timeouts and skipped if slow.
* **Root cause of "the invoice does not download":** there was no invoice address in the API at all (`routes/api.php` at the previous commit has no
  invoice route, and the order answer had no `invoice_url`). The PDF was only ever made as an email attachment. Not a server setting.
* **If the PDF fails on Hostinger** (System health says so): in hPanel > PHP Configuration enable `dom`, `mbstring`, `gd`; raise `memory_limit` to
  256M; make sure `storage/` and `bootstrap/cache` are writable (permissions 755/775 for the folders).

## 2. Page addresses (slugs)

The API is the one source of truth for where a category, page or landing page lives.

* Every item carries `slug` and a ready `path` (`/category/<slug>`, `/pages/<slug>`, `/lp/<slug>`, `/product/<slug>`), and `previous_slugs`.
* A slug is lower-case letters, numbers and single hyphens; not one the storefront already uses (`admin, api, cart, checkout, shop, …`: see
  `Slugs::RESERVED`); unique among its kind and not an old address of another page. The admin says why in words.
* Changing a slug remembers the old one (`slug_redirects`). The old address still answers, with a `redirect` hint:

```json
{ "data": { "slug": "eid-gifts", "path": "/category/eid-gifts", "previous_slugs": ["eid"], "...": "..." },
  "redirect": { "from": "/category/eid", "to": "/category/eid-gifts", "status": 301 } }
```
  (`redirect` is absent when the address asked for is the current one; pages and categories use `GET /api/categories/{slug}`, `/api/pages/{slug}`;
  landing pages `GET /api/landing-pages/{slug}`.)
* `GET /api/sitemap` now lists `products`, `pages`, `landing_pages`, `categories` as `{ slug, path, updated_at }`, `articles` as before, and
  `redirects: [{ type, from, to }]` for every old address. The product feeds use the same `/product/<slug>` path.
* "Shop by occasion" tiles are homepage-section tiles, not a model. A tile is `{ label, image, href, kind, slug, path }`: `href` is what to link to
  (a tile that points at a category or page follows a changed slug on its own); `kind` is `category`, `page`, `gift_finder` or `custom`.
* **Does the storefront need a rebuild to show a changed address?** The homepage refreshes tiles from the API after load, so a tile follows at once.
  Category and page *routes* are generated at build time, so a new address needs a storefront build to exist as a route; until then the old route keeps
  working. After a build, `redirects` from the sitemap data turns every old address into a redirect.

## 3. Courier status updates

| | Steadfast | Pathao |
|---|---|---|
| Webhook | `POST /api/webhooks/steadfast`. `Authorization: Bearer <token>` **and** `X-Signature` (HMAC-SHA256 of the raw body, keyed with the token), both `hash_equals`. 401 on a mismatch, 404 when off or no token saved. Throttled. | None built. Pathao's merchant panel does offer a webhook, but its documentation was not supplied, so it is not guessed at. Followed by the status check only. |
| Statuses | pending, in_review, hold, exceptional, *_approval_pending (rider's word, shown only), delivered, partial_delivered, cancelled, return_*; anything new is stored as "Unknown status" and shows in Settings > Courier statuses to be mapped | Whatever Pathao reports; stored as unknown until mapped |
| What a status does | Per the status map: *nothing*, *shipped*, *delivered*, *partly delivered*, *returned or cancelled* | same |
| Side effects | Delivered: order Delivered (the existing delivered email, once), review timer starts. Cash on delivery: recorded only when a status **check** (not the webhook alone) confirms a final delivered. Returned/cancelled/partial: flags the order and emails the owner. **Stock and refunds are never touched.** | same |
| Polling | `couriers:sync` (also `courier:sync`) every 15 minutes | same (the only way) |
| Logging | every webhook is kept in the order's Courier history (no names, phones, addresses or tokens); a refused one is a log line with no body | n/a |

Rules, in code and tests (`CourierStatusApplier`, `CourierStatusRulesTest`):
forward only (processing → shipped → delivered); never changes an order that is delivered, cancelled or returned; a repeated or late report changes
nothing; every automatic change is a line in the order's **Courier history** that says where it came from (*Steadfast webhook*, *couriers:sync*,
*Sync now*) and the courier's own word; an email that fails to send never makes the webhook fail (it is sent after the answer and any failure is
logged); no new customer messages (the existing shipped/delivered emails go out once, as for any status change).

The status check: newest first, 40 a run, 45 seconds, an 8 second timeout per call, a parcel is not asked again for 15 minutes (further apart after
failures), stops following a parcel **30 days after booking** (`COURIER_SYNC_STOP_AFTER_DAYS`), one parcel failing for any reason never stops the
others. **Sync now** on the order page runs the same code for that one parcel.

**Settings > Courier**: *Update order status from courier automatically* (on by default). Off: reports are still kept and shown, the order is never
changed. "Book automatically" stays off by default and nothing in the status code can book a parcel.

### Owner steps in the courier panels (no secret appears here)

1. Steadfast panel > Webhook: callback URL = the **Webhook URL** shown in Settings > Courier; authentication = *Bearer token*, the same token you
   generated and saved there (generate, copy, save, then paste into Steadfast). Choose the delivery-status event.
2. Pathao: nothing to set; parcels are followed by the status check. The nine Pathao statuses the shop has seen are already mapped but **not armed**: see the follow-up below, then press **Arm Pathao statuses**.

## 4. SEO block and share images

Every public page answer has `seo: { title, description, image }` (alongside the older `meta_title`, `meta_description`, `og_image`, kept so the
storefront keeps working until it moves over). `image` is a full https address, or `null` (then the storefront's default share image applies).

| Entity | Fields in the admin | Image order |
|---|---|---|
| Product | meta title, meta description, share image | share image → main picture → category share image/thumbnail/banner |
| Category | meta title, meta description, **share image (new)** | share image → thumbnail → banner |
| Page | meta title, meta description, share image address | share image |
| Article | meta title, meta description, share image | share image → cover |
| Landing page | title, description, share image | share image |
| Occasion | none: an occasion is a tile that links to a category, page or landing page, which carry the SEO | — |

The favicon and the default share image are files in the storefront (`public/`), not admin settings, and no admin setting was built for them.

## 5. Follow-up: Pathao statuses (armed), order thumbnails, brand pictures

**Pathao statuses.** A migration adds the nine statuses the shop has seen, each with a meaning, a rank and an effect, but **not armed**. Not armed
means the order shows the meaning (for example "In transit") and nothing else happens: no order status, no cash, no end of checking. Rows you
have already edited are never overwritten; a repeat of the seeding changes nothing; spellings (`On_the_way_to_Sorting HUB`,
`on the way to sorting hub`) are one row.

| Pathao status | Means | Effect | Rank |
|---|---|---|---|
| pending, Pickup_Requested, Assigned_for_Pickup | Booked, waiting for pickup | Nothing | 10, 10, 15 |
| On_the_way_to_Sorting HUB, At_the_Sorting_HUB, In_Transit, Received_at_Last_Mile_HUB | In transit | The order is shipped | 30 |
| Assigned_for_Delivery | Out for delivery | Nothing | 45 |
| Delivered | Delivered | The order is delivered, Final, Cash collected | 100 |

Any other Pathao status stays "Unknown status" until mapped by hand (none is guessed).

1. `php artisan couriers:preview-effects` lists every order that would change (order, courier, consignment, courier status, order now, order
   would become, cash recorded, email, SMS). It writes nothing and calls no courier. `--apply` makes those changes after asking; `--notify`
   (with `--apply`) also sends the customer emails for old news.
2. **Settings > Courier statuses > Arm Pathao statuses** switches the Pathao rows on. Arming changes no order by itself; the next status check
   (or `--apply`) does. A parcel whose status was already stored is worked out once, on that next check.
3. **Old news rule.** A status the shop has held for more than 24 hours that only now changes an order is a catch-up: no shipped or delivered
   email is sent, and a delivery found this way does not start a review request (`review_request_status = catch_up`). A status that arrives
   fresh (under 24 hours) sends the existing shipped and delivered emails, once, as before. The shop sends no SMS at all.

**Order page.** The Items table has a 48 px square thumbnail (2 px corners, lazy-loaded) before Product: the picture saved on the line, else the
variant's, else the product's main picture (smallest size), else a neutral tile. It opens the product in the admin. It only reads; it never makes or
saves a thumbnail. The invoice is unchanged. The Orders list would gain from a thumbnail of the first item only if packing is done from the list
(it would cost a picture per row); the order email already carries a 64 px thumbnail per line.

**Brand pictures.** `GET /api/site-settings` returns `logo_navy`, `logo_ivory`, `logo_mono`, `monogram` and `default_share_image` as full https
addresses (or null), and `brand.<name> = { url, width, height }` with the pixel size read from the file (null for a picture on another server).
The new **Default share image** upload (Site settings > Brand, 1200 by 630) and the Monogram both say: "A change here shows on the website after the
site is rebuilt and uploaded."
