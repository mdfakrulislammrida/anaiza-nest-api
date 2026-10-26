# Opening the shop: purge the test data, import the old orders, check

Order on the server (Tajin). Every command that changes data needs `--force`, runs in a transaction and first copies the SQLite
file to `storage/backups/` (the path is printed). Reports hold counts only, never a name, phone or email.

```
php artisan migrate --force
php artisan data:purge-test --dry-run
php artisan data:purge-test --force                      # add --start-order-number=N to choose the first real order number
php artisan orders:import-legacy --dry-run               # storage/imports/ holds the three old .sql files (see legacy-order-import.md)
php artisan orders:import-legacy --force --status-map="..."
php artisan golive:check
```

Purge before import: the import must not be deleted by the purge.

## data:purge-test

Deletes: orders and their lines, courier events, payment / wallet transaction ids (they live on the orders), abandoned checkouts and the
coupons made for them, product reviews, customer accounts and their sign-ins, newsletter sign-ups, visit counters (checkout and landing
page), queued / failed / batched jobs, password-reset tokens, and the mail and courier failure notes. A coupon you made stays; only its
"used" marks are cleared. The counters of the emptied tables start again at 1, so the next order is number 1 (or `--start-order-number`).

Kept, never touched: products, variants, categories, pages, FAQs, banners, popups, every settings table, admin users and roles, email
templates, shipping zones, blocked contacts, email opt-outs. Listed but left for you to read and delete: contact-form messages and
gift enquiries. There are no wishlist, cart or notification tables in the API (carts and wishlists live in the visitor's browser).
Stock levels are not restored: test orders that reduced stock must be corrected by hand in Products.

## golive:check

PASS / WARN / FAIL for: APP_ENV and APP_DEBUG, APP_URL (default https://api.anaizanest.com, change with `--api-url`), FRONTEND_URL
(`--site-url`), CORS origins (must include the shop), Sanctum stateful domains, mail settings (nothing is sent), scheduler heartbeat
within 5 minutes, storage link, payment methods and wallet numbers, active products with a photo, the five pages, marketing ids (masked),
leftover test-event codes, Google / Facebook login, newest backup age. Exit code 1 when anything FAILs. Secrets show only as "set" or "missing".
