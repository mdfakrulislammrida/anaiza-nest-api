# Product pictures in emails and the invoice, and dark-mode-safe emails

## Pictures

- Every email that lists the order (confirmation, shipped, cancelled, the delivered and review emails, the shop's new-order note) and the invoice
  PDF shows each line's picture: a 128 px JPEG shown at 64 px (`width="64" height="64"`). Cart emails show it too.
- `App\Support\EmailThumbs` makes it once from the product's first picture (or the chosen variant's) and keeps it in `email-thumbs/` on the public
  disk. The file name is a hash of the source, so a changed product picture makes a new thumbnail.
- `order_items.image_path` is the snapshot: written when the order is placed. Later changes to the product never change an old email or invoice.
  A line with no picture (no image, product deleted) shows a linen tile with the first letter of its name (an outline tile on the black-and-white invoice).
- Orders placed before this: the migration fills them in from the product as it is now (there is no earlier picture to go back to). A line with
  no `image_path` that is opened later is filled the same way. `php artisan orders:backfill-images` runs it again; it is safe to repeat.
- The thumbnails are served from `/storage/email-thumbs/...`, so `php artisan storage:link` must be in place (it already is for product pictures) and
  `APP_URL` must be the full https address.

## Dark mode

- Every email has `<meta name="color-scheme" content="light dark">`, `<meta name="supported-color-schemes" content="light dark">` and a
  `@media (prefers-color-scheme: dark)` block (deep ink behind, navy card, ivory text, champagne small text and button).
- The header is a solid navy band with a gold line. The logo is the **ivory logo** from Site settings (made for a dark background); with none uploaded the
  name is set as text in ivory (Fraunces, then Georgia). The navy logo is never used on the band.
- No table or cell is transparent: `App\Support\Email\SolidColour` gives every table, cell and header cell without a colour the ivory card colour and
  navy text (the admin's own wording included). No pure white or pure black.

## Sale prices

Cart emails take a line priced at ৳0 and show what the piece costs now, which follows `App\Support\Pricing` (a sale price of 0 is no sale).
Order lines are priced when the order is placed, from the same rule.

## For Tajin

```
php artisan migrate --force
php artisan emails:send-tests --to=you@example.com
```

The second command sends one test of every email (the invoice attached to the confirmation, and the new-order note) to the address given, or to the
Shop alerts address in Site settings. `--order=123` shows a real order in them instead of the made-up one.
