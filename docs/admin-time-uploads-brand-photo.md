# Admin: Dhaka time, Placed filters, upload previews, brand and photo (Batch ADM1)

## T1. Time zone: the facts

| Question | Answer |
|---|---|
| `config('app.timezone')` | `UTC`, written in `config/app.php` (no `APP_TIMEZONE` in `.env.example`, no Site setting) |
| What is stored | Laravel writes `created_at` and every timestamp as `Y-m-d H:i:s` in the application time zone, so in **UTC**. SQLite keeps it as text; MySQL as a timestamp/datetime |
| Scheduler | the run times (`backup:run` 02:30, `checkouts:tidy` 03:30, `system:tidy` 03:45, `stock:digest` 09:00, `couriers:digest` 09:30) each say `->timezone('Asia/Dhaka')`, so they run on Dhaka's clock. Not changed |
| Emails | `{{order_date}}` is `created_at->format('j F Y')` in UTC: an order placed between 00:00 and 05:59 in Dhaka shows the day before. Not changed |
| Invoice | `d M Y, h:i A` of `created_at` in UTC, with no zone named: it reads 6 hours behind Dhaka. Not changed |
| Dashboard | "Today's Sales" is `whereDate('created_at', today())`: the UTC day, i.e. 06:00 to 06:00 Dhaka. Not changed |
| Filament | 3.3 has no global display time zone; a time column shows the application zone (UTC) unless it is told otherwise. Before this batch the Orders `created_at` column (hidden by default) showed UTC, 6 hours behind Dhaka, while the phone card and some other places already converted by hand |

An order created at 11:14 AM in Dhaka was shown in the admin table as **05:14 AM**; it is now shown as `04 Nov 2026, 11:14 AM`.

**What was done and why.** `app.timezone` was NOT changed: every stored time is UTC, so changing it would re-read all existing rows 6 hours off and move the
scheduler, "pending too long" rules and email timing. The admin reads times in Asia/Dhaka by display only (`AdminPanelProvider::boot`): `TextColumn` and `TextEntry`
get the Dhaka zone and the format `04 Nov 2026, 11:14 AM`; date-and-time pickers get the zone (a typed time is Dhaka time and is stored as UTC); date-only and time-only
pickers are left alone, because a Dhaka midnight is the day before in UTC and the stored date would move back a day. No stored value is rewritten. The Article, Banner and Coupon
date-time pickers that used to read and write UTC now show the same instants in Dhaka time (a value someone typed earlier as if it were Dhaka time was stored as that UTC time and now reads 6 hours later: that is the instant it really is).

## T2. Placed column and filters

`Dhaka::format()`, `Dhaka::dayStart()/dayEnd()` (a Dhaka day turned into UTC for the query), `Dhaka::preset()` and `App\Filament\Support\PlacedFilter` (From, Until and Today, Yesterday,
Last 7 days, This month, Last month; a quick range wins over typed dates; chips with a remove button). Orders: a visible, sortable **Placed** column beside the order number, the
same time on the phone card, "Placed on" and the times that exist on the order page (payment verified, sent to courier, delivered, cash collected, returned by the courier;
the shop keeps no status history, so there is no "shipped" or "cancelled" time to show). The same filter: Customers (Joined), Reviews (Received), Coupons (Starts, Ends; a Starts column was added) and
Abandoned checkouts (Last activity, same From and Until names as before).

## I1. Upload previews

Two causes, both fixed, nothing about how files are saved changed:

1. **Product pictures table** (`ImagesRelationManager`): the picture column and the file-path text column were both named `url`. A table keeps one column per name, so the picture
   column was never shown, only the path. It is now `preview` and uses `ProductThumbnail`, the same picture the Products list shows.
2. **Every upload field**: Filament fetches a stored file's storage address from the browser before it draws the preview (`file-upload.js`, `fetch(source)`;
   `BaseFileUpload.php` builds the address from `Storage::url`, i.e. APP_URL). When the admin is opened on another host or scheme than APP_URL (the shop and the API answer on two
   host names, http or https) the browser refuses the fetch (`config/cors.php` opens only `api/*`): no picture, the item hangs, and the picture cannot even be removed. Reproduced locally
   by opening the admin on `localhost` with APP_URL on `127.0.0.1`.

Fix: `GET /admin/upload-preview/{path}` serves a stored picture from the page's own address (signed-in admins only, public disk only, pictures only, no way out of the disk;
it works without the `public/storage` link), and every `FileUpload` is configured once in `AdminPanelProvider::boot` (`UploadPreview`) to preview from it at 96 px.
A file that is gone, or a picture typed in as a full address, is a neutral tile with its name and stays in the field until the person removes it (Filament used to drop it
silently and the next save erased the value). CSS (inline, no build): 96 px tiles, 2 px corners, no shadow, a wrapping row for several, a 44 px hit area for the small buttons on a phone.
Nothing on the server has to be done.

## B1. Brand

"Anaiza Nest API" appeared in the login page heading, the top bar, the sidebar and the browser tab title (Filament takes the panel name from `APP_NAME`). `APP_NAME` is also the mail
sender name, part of the backup file's metadata, the cache and session key prefixes and the fallback of the site name in emails, the invoice and the feeds, so it is **not** changed:
the panel sets `brandName('Anaiza Nest')` itself, and the API root (`/`) still answers `Anaiza Nest API`. Logo (`AdminBrand`): the navy logo, then the previous logo, then the Monogram, then the
name as text; on a dark page the ivory logo, otherwise the same picture on a small ivory plate (a flat background; the picture is not recoloured). 48 px high and centred on the login page,
32 px in the bars, never wider than the screen. The Monogram is the browser tab icon when set (the storefront's generated icon files live on the shop's host, so the uploaded Monogram is used).

## U1. Photo

`users.avatar` (nullable; the migration leaves existing rows as they are and can be rolled back). My profile (`/admin/profile`) edits only the signed-in user: name, email, password, photo
(JPG, PNG or WebP, up to 2 MB, cropped square). The photo shows in the top-right menu and as a round thumbnail in the Users list. Replacing or removing it, or deleting the user, deletes the
old file (only inside `avatars/`). With no photo the initials are drawn as a small SVG in the page (Filament's own default called ui-avatars.com on every page); the only other outside host
the admin contacts is Filament's own font host, `fonts.bunny.net`.

## Looking at it

`node scripts/adm1-check.mjs uploads|multi|login|profile|shots` drives headless Chrome over a local copy (like `scripts/mobile-admin-check.mjs`); screenshots go to `docs/adm1-screenshots/` (not committed).
