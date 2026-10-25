# Marketing tokens and sale prices (Batch X)

## Marketing Settings (Settings > Marketing Settings)

| Field | Stored | Shown again on the page |
| --- | --- | --- |
| Google Tag Manager ID, GA4 ID, Meta Pixel ID, TikTok Pixel ID | plain text | yes, and returned by `GET /api/marketing-settings` (they are public by nature) |
| Meta test event code, TikTok test event code | plain text | yes, never returned by the public API |
| Meta Conversions API access token, TikTok Events API access token | encrypted (`encrypted` cast, APP_KEY) | never. The field is blank and says "Saved (ends in ••••1234)" |

- Blank token field on save = keep the saved token. Typing a token replaces it. The trash icon in the field clears it (after a confirm).
- `MarketingSetting::$hidden` keeps both tokens out of `toArray()`/`toJson()`. `MarketingSettingResource` lists only the four ids.
- "Send Meta test event" (page header) sends one `TestEvent` to Meta with the saved Pixel ID and token (plus the test event code when
  one is set) and shows Meta's own answer. It uses the saved values, so save first. Without a test code Meta counts it as a real custom event.
- There is no settings cache on the API side: a saved value is served at once. The storefront reads the ids at build time
  (`layout.tsx`, static export), so a new Pixel/GA4/GTM ID reaches visitors after a storefront rebuild and upload. The server-side
  tokens are read at the moment an order is sent, so a new token works at once.

## Sale prices

A sale price counts only when it is more than 0 and lower than the regular price (`App\Support\Pricing::saleApplies`). Otherwise
`effective_price` is the regular price, `on_sale` is false and `discount_percent` is null. The same rule applies to product variants
and the product feeds. The admin forms reject 0, negative and not-lower values; blank means no sale. The models store a sale price of
0 or less as blank.

Orders are priced from the database only (`OrderController::store` reads `effective_price` under lock); prices sent by the browser are ignored.

One-time clean-up of rows that already hold a 0:

```
php artisan products:fix-sale-prices --dry-run   # lists what would change
php artisan products:fix-sale-prices             # blanks them and lists the names
```
