# Couriers: Steadfast and Pathao

Booking an order with a courier from the admin, following the parcel, and showing the customer where it is. Everything timed
runs from the Laravel scheduler (the one cron line); nothing uses a queue worker.

## What it does

- **Settings > Courier** holds both couriers' details. Every secret is stored encrypted, is blank on the page after saving
  (it says "Saved"), and is never in a log, an API answer or a test result. Leave a field blank to keep what is saved.
- **Send to courier** is on each order (the list row and the order page) and as a bulk action (at most 50). It shows the exact
  data first. The cash to collect is worked out on the server from the order: the whole total for cash on delivery, nothing for
  a verified wallet payment. An order whose payment is unverified or failed, or with no phone or address, cannot be sent.
- An order is booked once. A second click shows the booking. A failed booking leaves the order unbooked with the reason on
  it, and the button turns into "Try again". The "Order shipped" email is sent (once) when you leave the tick on "Set the order
  to Shipped".
- **Status checking**: `php artisan couriers:sync` every 15 minutes follows every booked parcel. Steadfast also calls the
  webhook (below). Both go through one place (`CourierStatusApplier`), so running them together is safe.
- **Settings > Courier statuses** is the table that says what each status means. A status we have not seen is added to it as
  "Unknown status" for you to map.
- A morning note (`couriers:digest`, 09:30 Dhaka time) lists bookings that failed or have had no news for 3 days. Nothing is
  sent on a quiet day.
- **System health** shows the last good call to each courier, how many booked parcels have not been checked lately, and the
  last Steadfast webhook. **Network check** can test that this server reaches each courier.

## The rules built in

| Rule | Where |
| --- | --- |
| A booking only ever happens from the booking service, never from the "Test connection" buttons | `CourierBooking`, `ManageCourierSettings` |
| Steadfast has **no sandbox**: every booking is a real parcel. The test button only calls `/ping` and `/get_balance` | `SteadfastClient` |
| Steadfast 401: never retried, Steadfast is paused until the keys are saved again (ten refusals in five minutes lock the account for an hour) | `SteadfastClient` |
| A 200 can hide a failure: the body is always read | `SteadfastClient` |
| The order number is the invoice (add a prefix in Settings if a test site shares the account), so a repeat cannot book twice. One retry on a lost connection; a "duplicate invoice" after it means the first try got through | `CourierBooking` |
| Text is cleaned and cut before sending (`{ } ; < > $` become spaces; name 100, address 490 or 220, note 480) | `CourierText` |
| Pathao: city, zone and area are never sent; the address goes as "address, thana, district". No null is ever sent. Not retried on a lost connection (a repeat could book twice) | `PathaoClient` |
| Pathao has a sandbox: while it is on, a red banner is on every admin page | `AdminPanelProvider` |
| `*_approval_pending` is a rider's word: never delivers an order, never marks cash collected, never ends the checking | status map |
| Only `delivered`, `partial_delivered` and `cancelled` are final (Steadfast) | status map |
| Delivered sets the order Delivered (once: the delivered email and the review timer follow) | `CourierStatusApplier` |
| Returned or cancelled by the courier flags the order and tells you. **Stock and refunds are never touched** | `CourierStatusApplier`, `CourierAlerts` |
| Cash collected is recorded only when a status **check** confirms a final status that collected it, never from a webhook alone | `CourierStatusApplier` |
| Never backwards: a report earlier than the one on the order is ignored; a final parcel never changes | `CourierStatusApplier` |
| The check is light: newest first, 40 a run, 45 seconds, 800 calls a courier a day, backs off 15 minutes doubling to 6 hours after a failure, gives up for the run after 3 failures in a row | `CourierSync`, `config/couriers.php` |
| `/fraud_check` is not used (it would send customer phone numbers to a third party) | not built |

## The Steadfast webhook

`POST {API address}/api/webhooks/steadfast`. Steadfast signs every request: the receiver checks `Authorization: Bearer <token>`
and `X-Signature` (the hex HMAC-SHA256 of the raw body, keyed with the token) with `hash_equals`. Either wrong is a 401.
With no token saved, or Steadfast switched off, the address does not exist (404). It answers at once and works after the
response; the `Idempotency-Key` is stored (unique), so a retry is answered and ignored. Only `delivery_status` can change an
order; the other eight event types are stored in `courier_events` and nothing more (their sample payloads are empty in the
docs). Anything that could identify a person is left out of what is kept.

## Server steps

1. **Back up the database** (Settings > System health > Back up now, or `php artisan backup:run`).
2. Pull the new code, then:
   ```
   cd /path/to/api
   git pull origin main
   php artisan migrate --force
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   ```
   The migration adds three tables (`courier_settings`, `courier_events`, `courier_status_maps`, with the Steadfast statuses
   filled in), and columns on `orders`. Nothing existing is changed or removed.
3. The cron line you already have runs everything: `* * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1`.
   `couriers:sync` (every 15 minutes) and `couriers:digest` (09:30) are added to the scheduler by the code.
4. In the admin: **Settings > Network check > Check now**. The three lines (Steadfast `portal.packzy.com`, Pathao live
   `api-hermes.pathao.com`, Pathao sandbox `courier-api-sandbox.pathao.com`) should say "Answered". A bare Pathao host answers
   404: that still means the way is open. "No answer" means the host blocks that address: tell the hosting support.
5. **Roles**: staff roles do not get the new permission by themselves. Tick **Send orders to a courier** on the roles that
   should have it (super admin always has it).
6. **Settings > Courier**: enter the details, save, press **Test Steadfast** / **Test Pathao**.
   - Steadfast: API key, secret key. The test shows your balance.
   - Pathao: client id, client secret, username, password, Sandbox on or off (the login must match), then the store id (the
     test lists your stores with their ids).
   - Optionally the tracking page link for each (`{code}` where the code goes); without one the customer sees the code and no link.
7. **Steadfast webhook**: in Settings > Courier press **Generate** next to the webhook token, copy it, **Save**. In the
   Steadfast panel (Webhook page) paste the **Webhook URL** shown on that screen and the same token as the auth token. The
   token is not shown again after saving; to change it, generate and save a new one and paste it into Steadfast too.
8. Check **Settings > Courier statuses** reads right. Pathao's statuses appear there as it reports them: map each one when you
   see it (a delivered status needs "Final" ticked and "The order is delivered" to deliver an order).
9. **Storefront**: the Track order page shows the courier, the code, a link and a plain sentence. That needs a storefront
   rebuild and upload (the API fields are already live without one, and the page works as before until then).

## Not built, and why

- **A Pathao webhook**: its docs have none. Pathao is followed by polling only.
- **Steadfast and Pathao bulk endpoints**: every order is its own request, so one refusal never spoils the others and each
  gets its exact result. A batch of 50 stops starting new bookings after 25 seconds (the host's time limit) and says which were
  not sent; send them again.
- **Return requests, pickup requests, payments** (Steadfast) and **price plan** (Pathao): not asked for.
- **Calling `status_with_return_status_by_cid`**: the return statuses (with Steadfast's spelling "proccessing") are in the map,
  but the endpoint is not called yet: its answer was not in what was supplied.
- **A packing slip with the tracking code**: the shop has no packing slip, only the gift card print. The tracking code is on the
  order page and in the shipped email.
- **Partial advance payments**: the shop has no such payment. The cash to collect is the total (cash on delivery), or nothing
  (a verified wallet payment).
