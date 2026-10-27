# The Purchase sent by Laravel, the courier calls, and the scheduler

For the person who runs the shop. What the shop's own server sends to Meta and TikTok, how to switch it off, and why the cron line every minute is safe.

## 1. Marketing Settings: the two switches and the server address

Admin > Settings > Marketing Settings > "Server-Side Conversion Tracking".

| Setting | What it does | Default |
| --- | --- | --- |
| Send Purchase to Meta from Laravel (CAPI) | When OFF the shop makes **no call to Meta** and logs `Meta Purchase skipped, switched off.` | ON |
| Send Purchase to TikTok from Laravel (Events API) | The same for TikTok | ON |
| Server container address (Stape) | https://, no slash at the end. Public (not a secret). Informational: the browser tags read the address from the GTM constant `AN - Server URL` | empty |
| The access tokens | Write-only as before (blank keeps what is saved; the bin icon clears) | n/a |

**Keep the Meta switch ON.** bKash, Nagad and Rocket orders only get a Purchase from here (when you verify the payment), because the browser never sends one for them. For the other orders Meta merges
this server's Purchase and the Stape one, because both use the same event id `order-<number>`.

"Send Meta test event" now answers with Meta's own summary, for example `[events_received=1, fbtrace_id=...]` or the refusal with its code. The token is always masked.

## 2. When the Laravel Purchase is sent (audit)

| Question | Answer |
| --- | --- |
| Where is it started? | **Cash on delivery:** `OrderController::store` -> `ConversionEvents::sendAtPlacement`, at the moment the order is created. **bKash/Nagad/Rocket:** `PaymentActions::decide('verified')` -> `ConversionEvents::sendOnVerification`, when you mark the payment verified. No status change (shipped, delivered, cancelled) sends or reverses a Purchase. Imported old orders never send |
| Synchronously, after the response, or on a queue? | **After the response** (`dispatchAfterResponse`): the visitor already has the answer when the call to Meta starts. There is no queue and no worker, so a failure cannot sit in a queue |
| Timeouts | 3 s to connect, 8 s in all (before: PHP's default, 30 s) |
| On failure | One `error` line in the log and one row in `conversion_attempts`. **If the failure may be temporary** (no answer, a timeout, 429, 5xx, or Meta's own "transient" flag; TikTok codes 40100 and 50000+) the scheduled `conversions:retry` tries again, **at most 3 more times, 10 minutes, 30 minutes and 2 hours after the failure before, within 24 hours of the first try**. A 400, 401 or 403 (a bad token, bad data) is **not** repeated and goes to Settings > System health as an error line, as does a send that used up its tries. The order is never affected. `conversion_sent_at` is still the "first send started" mark that stops a second first send; whether a platform has the sale is the latest row in `conversion_attempts` |
| `value` | The items total after any discount, **without delivery** = `total - delivery_fee`, never negative (before: the total with delivery). TikTok the same. `currency` BDT |
| Other fields | `event_id` = `order-<order number>`; `order_id`; `content_ids` / `contents` = the SKUs; `content_type` product. Neither Meta nor TikTok has a shipping field, so the fee is not sent |
| Hashed (SHA-256) | email, phone (as 880...), first name, last name, city (district), state (division), country `bd`. TikTok: email and phone |
| Not hashed (as the platforms require) | `client_ip_address`, `client_user_agent` |
| `fbc` | The `_fbc` cookie value when the storefront passes it (stored; a click id, so it is kept like `fbclid`), else built from `fbclid` and the order's time. TikTok gets `ttclid`. TikTok has no `fbp` or `fbc`, so it gets neither |
| `fbp` | The `_fbp` cookie, **when the storefront passes it with the order** (`fbp` in the order request; checked for the shape `fb.1.<ms>.<number>`, a wrong one is dropped). Kept on the order until the sale is sent, then erased |
| `external_id` | The storefront's SHA-256 of the customer id or phone (64 lower-case hex characters, checked), **sent as it arrived and never hashed again**, so Meta can tie this Purchase to the browser's and the server container's. Erased with `fbp`. TikTok gets it too (as text) |
| IP address and browser | Kept on the order at checkout (only when marketing was allowed), with `fbp` and `external_id`, and **used by every try**. They are erased as soon as every platform is done and none failed (cash on delivery: seconds after checkout; wallet: after you verify). While a send has failed they stay for the automatic tries and Send again, and are erased **24 hours after the first try** whatever happened. A payment marked failed erases them at once |
| `event_source_url`, `action_source` | `<shop address>/order-confirmation`, `website` |
| Can it be sent twice? | No. Verifying twice, failed-then-verified, and changing an order from cash on delivery to a wallet method all hit the same one-time mark (`conversion_sent_at`). A retry takes its due time first (so two runs cannot both send it), only a failed platform is ever tried again, and the event id stays `order-<number>`, so a try that did reach Meta before the answer was lost is merged by Meta. A cancelled order is not reversed |
| The order page | Admin > Orders > an order > **Marketing Attribution** shows "Meta Purchase: sent / retrying (reason) / failed (reason)" (TikTok too when it was tried). **Send Purchase again** (top of the page) repeats a platform that failed: only after a minute since its last try, and a second press while one is running does nothing. It needs the browser details, which are kept 24 hours after a failed send |

### The log lines (no token, no customer data)

```
Meta Purchase sent.                    order_id, event_id, value, events_received, fbtrace_id, test_code
Meta Purchase failed.                  order_id, event_id, status, error, fbtrace_id
Meta Purchase skipped, switched off.   order_id, event_id
Meta Purchase skipped, not set up.     order_id, event_id
TikTok Purchase sent.   /  failed.  /  skipped, switched off.  /  skipped, not set up.      (request_id instead of fbtrace_id)
```

Example success: `Meta Purchase sent. {"order_id":1042,"event_id":"order-1042","value":3700,"events_received":1,"fbtrace_id":"AbC...","test_code":false}`.
Example failure: `Meta Purchase failed. {"order_id":1042,"event_id":"order-1042","status":400,"error":"Invalid OAuth access token ...","fbtrace_id":"AbC..."}` (the token itself is replaced).

## 3. Steadfast and Pathao never block a page

- **No admin page calls a courier while it is drawn.** There is no balance widget or status badge that does. The only calls are: the "Test Steadfast" / "Test Pathao" buttons, "Send to courier" (and the automatic booking after a status change, which runs after the response), and the courier status check in the scheduler (`couriers:sync`, every 15 minutes, only for parcels not yet delivered).
- Calls from the scheduler and anything else use **3 s to connect and 8 s in all**. Only the Test buttons and booking wait longer (**5 s and 20 s**), because a person is waiting.
- A refused key (401/403) is noted and nothing more is sent with those keys until they are saved again (unchanged); a timeout or a refusal shows a message on that page only and never an error.
- A failed test shows a toast that disappears by itself (it used to be "persistent", which could wait in the session and appear on a later page, such as Create Product).

## 4. The scheduler (one cron line, every minute)

`* * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1`

| Task | When (Dhaka time) | What it touches | Lock |
| --- | --- | --- | --- |
| `scheduler-heartbeat` | every minute | one database row (read by Settings > System health and `golive:check`) | 2 min |
| `conversions:retry` | every 10 min | database; **Meta and TikTok** (8 s per call, at most 40 s per run), only failed Purchases that are due; also erases visitor details kept for a send over 24 hours ago | 9 min |
| `reviews:request` | every 5 min | database; **emails** (at most 40 s of sending per run) | 10 min |
| `checkouts:recover` | every 5 min | database; **emails** (at most 40 s per run) | 10 min |
| `couriers:sync` | every 15 min | **Steadfast / Pathao** status calls, 3 s / 8 s each, at most 45 s per run, only unfinished parcels | 14 min |
| `backup:run` | 02:30 | database + pictures into one zip in `storage/app/backups` (14 daily, 4 weekly, 3 manual kept) | 120 min |
| `checkouts:tidy` | 03:30 | database | 10 min |
| `system:tidy` | 03:45 | database | 10 min |
| `stock:digest` | 09:00 | database; one email | 10 min |
| `couriers:digest` | 09:30 | database; one email | 10 min |

Every task has an overlap lock that **expires**, so a crashed run never blocks the next one for long. Mail waits at most 10 seconds for a mail server that does not answer. Nothing uses `runInBackground`
(it needs a process-starting function that shared hosting may disable). Each run of `php artisan schedule:run` costs about a second of PHP start-up plus the due tasks.
