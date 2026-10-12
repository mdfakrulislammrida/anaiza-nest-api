# Landing pages

Campaign pages built in the admin (Content > Landing pages) and shown at `/lp/<address>` on the storefront. Made for ad
destinations: one offer, one order form.

## Making one

1. **Page**: title, address (`/lp/<address>`), Draft or Published, and an optional opening and closing time in Dhaka time.
   After the closing time the page says "This offer has ended" and links to the shop. A page that has not opened yet is
   not found by visitors.
2. **Layout**: *Focus* (logo only, not a link; no menu, no footer links) or *Normal* (the shop's header and footer).
3. **Content**: add blocks, drag them into order, switch one off with "Show this block" to hide it without deleting it.
   Hero, Text, Custom HTML, Products, Benefits row, FAQ, Reviews, Order form, Offer countdown.
4. **Search and sharing**: "Keep this page out of search engines" is ON by default. Switch it off to let the page be
   found; it then enters the sitemap on the next site build.
5. **Preview** opens the page whatever its state, through a link that works for two hours and is never indexed or counted.
6. **Duplicate** makes a draft copy for the next campaign.

Everything goes live from the admin without rebuilding the storefront: a page made after the last build is served by the
`/lp` shell, and a page built into the site refreshes itself from the API when it opens.

## Custom HTML

Paste HTML, or upload an `.html` / `.htm` file (up to 1 MB). The file's `<style>` tags and everything inside `<body>` are
put into the box so they can still be edited. When the page is saved the HTML is cleaned and a list of what was removed
is shown at the top of the page.

Removed: script tags, inline event handlers (`onclick` and the like), `javascript:` addresses, iframes other than YouTube
and Vimeo (YouTube is switched to its no-cookie address), forms and form fields (use the Order form block), external
stylesheets, `<link>`/`<meta>`/`<base>`, embedded objects, and CSS that can run code. Kept: normal markup, classes,
inline styles, `<style>` blocks, images, inline SVG shapes.

* CSS is scoped: every rule in a `<style>` block is limited to that block's wrapper, so `body { ... }` or `.header { ... }`
  only affects the author's own content. The wrapper also uses `contain: layout paint` and `isolation: isolate`, so
  nothing in it can cover or restyle the site header, the order form or the cookie banner.
* Images: upload the pictures the HTML refers to under "Images used by this HTML" (they are added to the media library).
  A relative path (`images/photo.jpg`) is pointed at the upload with the same file name; paths that cannot be matched are
  listed after saving.
* `id` attributes and `#anchor` links are prefixed, so they cannot collide with the site's own.

### Scripts

Scripts are never allowed by default, for anyone. A super-admin can allow them in two ways:

* switch on "May allow scripts in landing page HTML" on a person (Administration > Admin Users), or
* give a role the "Allow scripts in landing page HTML" permission (Administration > Roles).

Only then does the block's "Allow scripts in this HTML" switch work. A super-admin without that switch is treated like
everyone else. Even with it on:

* scripts are **never** kept on a Focus-mode page that has the Order form block (the form takes payment details); the list
  at the top of the page says so;
* the block carries a WARNING note in the page's report for as long as scripts are active;
* if someone without the permission edits the page's blocks and saves, the scripts are removed on that save.

### Content-Security-Policy (currently report-only)

The storefront's policy is in `public/.htaccess`. What a landing page needs from it:

| Needs | Policy | Status |
| --- | --- | --- |
| Inline `<style>` blocks and `style=""` attributes from custom HTML | `style-src 'unsafe-inline'` | already allowed |
| Inline `<script>` in custom HTML (only when scripts are allowed) | `script-src 'unsafe-inline'` | already allowed |
| A script loaded from another site (`<script src="https://...">`, scripts allowed only) | that host in `script-src` | **add the host** if you use one |
| YouTube / Vimeo videos | `frame-src` has `www.youtube-nocookie.com` and `player.vimeo.com` | already allowed |
| Pictures from any https address | `img-src https:` | already allowed |
| FAQ block structured data | `<script type="application/ld+json">` is data, not executed | not affected |

The checkout itself, the marketing tags and the cookie banner are the shop's own and need nothing new.

## Orders from a landing page

The Products block's button adds the piece to the cart and scrolls to the Order form block (or opens `/checkout` when the
page has none). The Order form block **is** the shop's checkout (`CheckoutForm`, `embedded`), posting to `POST /api/orders`:
the same validation, bot trap, per-phone limit, blocked buyers, stock check, coupon rules, abandoned-checkout tracking
and emails. The order carries `landing_page` (the slug); it is recorded as `landing_page_id` and `landing_slug` and shown
on the order, with a filter on the Orders list.

## Numbers

Landing pages list: views (once per visitor session, bots left out), add-to-cart, orders, conversion (orders / views)
and revenue, for the date range chosen in the list's filter. Orders and revenue come from the orders themselves, so they
agree with the Orders list; a cancelled order or a failed payment is not counted.

## Tracking

* ViewContent for the products on the page (GA4 `view_item_list`, Meta `ViewContent`, TikTok `ViewContent`) with the
  feed ids (SKU, else product id), AddToCart, InitiateCheckout (the first time the visitor touches the order form) and
  Purchase through the existing layer, browser and server side.
* UTM parameters **and** the ad click ids `fbclid`, `gclid` and `ttclid` are captured on any page (same consent rule, 30
  days), sent with the order, stored on it, and passed to the server-side Meta (`fbc`) and TikTok (`ttclid`) events.
