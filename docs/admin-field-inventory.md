# Admin field inventory

Every place content is entered from the admin, traced into the storefront. Written from the code, for a content playbook.

* Admin: `anaiza-nest-api` at `b0a9e23` (Filament resources and pages under `app/Filament`).
* Storefront: `anaiza-nest-storefront` at `46469b2` (Next.js static export; components under `src/components`, pages under `src/app`).
* Nothing here was taken from memory or from helper text alone. Where the admin's own helper text and the code disagree, the code wins and the disagreement is listed in section 17.
* **verify** marks anything not certain from the code (usually because it depends on the host, a PHP limit, or a browser's behaviour).
* "Recommended" lengths are practical advice. They are labelled **(code)** when the admin enforces or states them, and **(advice)** when they are not in the code.

## How to read the tables

| Column | Meaning |
| --- | --- |
| Label | The label as shown in the admin (Filament turns a field name into sentence case when no label is set). |
| Where it appears | Storefront page and position. |
| Req. | Required to save in the admin form. |
| Limit | Validation the admin applies (max length, range, type). Fields with no limit have none in the form; the database column may still cut very long text (verify). |
| Recommended | Practical length. |
| When empty | What the storefront does: hidden, fallback text, or a hardcoded default. |

Image tables use different columns: types and size limit, what the code does with the file, the ratio it is shown at on a phone (under 640 px) and on desktop, the smallest size that looks sharp, whether it is cropped (and from where), whether there is an alt-text field, and the exact alt fallback.

### Facts that apply to every image upload

* **Default size limit.** A `FileUpload` with no `maxSize` has no field limit. Livewire's temporary-upload default of 12 MB (12,288 KB) then applies, and the host's PHP `upload_max_filesize` / `post_max_size` may be lower (**verify** on the server). Fields below state their own limit where there is one.
* **`->image()` means `image/*`.** Every field marked "any image" accepts every `image/*` type the browser reports, SVG included. Only fields with an explicit type list (product images, occasion tiles, payment logos, media library) are restricted. **verify** whether SVG should be allowed on the other fields.
* **WebP conversion** uses Intervention Image with the GD driver, quality 82. A file that cannot be decoded (a corrupt file, an SVG) is kept as uploaded and the failure is logged; saving never fails because of it.
* **Resizing never upscales.** `scaleDown` only makes images smaller. A source smaller than a rendition width does not get that rendition.
* **Cropping** happens in one place only (`cover()`, from the centre): the auto-made mobile crop of a banner. Everywhere else the file is stored uncropped and the browser crops with CSS `object-cover`, which is also from the centre.

---

## 1. Site settings (Settings > Site settings)

Source: `app/Filament/Pages/ManageSiteSettings.php`, delivered to the storefront by `GET /api/site-settings` (`SiteSettingResource`). The storefront builds with a copy and refreshes it once on page load, so an edit shows without a rebuild (except where noted).

### 1.1 Brand

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Site Name | Header logo fallback and logo `alt`, footer copyright line, JSON-LD Organization and WebSite name, feeds (shop name as brand fallback, RSS channel title), emails (header fallback, `{{site_name}}`), the browser-title template `… \| Anaiza Nest` and the homepage title use the **hardcoded** brand name (`BRAND.name`), not this field | Yes | max 255 | 2 to 4 words (advice) | Cannot be empty. Storefront falls back to "Anaiza Nest" while settings load. |
| Tagline | Under the header logo (desktop only), footer under the logo, homepage `<title>` (`Name \| Tagline`) | No | max 120 | The kit line "Gifted, beautifully." (code) | Cleared on purpose: the API sends null and the tagline is hidden everywhere (the homepage title is then just "Anaiza Nest"). Before the settings were ever saved: the kit line. |
| Contact Phone | Promo bar (tap to call), footer "Get in touch", contact page, checkout "Call" help line, email footer, JSON-LD contactPoint, WhatsApp number fallback | No | tel, max 30 | International or local, one number (advice) | Phone hidden in all those places. WhatsApp loses its fallback number. |
| Contact Email | Footer, contact page, JSON-LD contactPoint, fallback recipient for shop alerts and wallet-order alerts | No | email, max 255 | A monitored mailbox (advice) | Hidden; alert emails fall back further or are not sent. |
| Address | Footer "Get in touch", contact page, JSON-LD PostalAddress, email footer | No | max 255 | One line, street and area (advice) | Footer and contact page show the hardcoded "Dhaka, Bangladesh". JSON-LD and emails omit it. |
| Brand description | JSON-LD Organization description, `llms.txt` summary | No | max 1000 | 2 to 4 factual sentences (advice) | Falls back to the footer About blurb, then to the kit boilerplate. **Not** the default meta description (see section 17, row 5). |
| WhatsApp number / bubble / first message / hidden pages | See 1.6 | | | | |

Image fields:

| Label | Types, max size | What the code does | Shown at (mobile / desktop) | Min. recommended | Crop | Alt field and fallback |
| --- | --- | --- | --- | --- | --- | --- |
| Logo, navy | any image, no field limit | Stored as uploaded. No resize, no WebP. | Header: 28 px high (`h-7`), width auto, minimum width 120 px, `object-contain`, left-aligned. Same on mobile and desktop. | Height 56 px for retina; at least 120 px wide (advice, helper says "at least 120 px wide") | None (contained) | No alt field. `alt` = Site Name. If no navy logo: previous logo, then the site name as text. |
| Logo, ivory | any image | As above | Footer, same 28 px rule | As above | None | `alt` = Site Name. No ivory logo: the site name is written as text (the navy logo is **not** reused on dark). |
| Logo, one colour (black) | any image | As above | Invoice PDF only, max height 48 px | 96 px high (advice) | None | Not an `<img alt>`; PDF. No file: navy logo, then previous logo. |
| Monogram | any image | Stored as uploaded. | Browser tab icon and Apple touch icon (the file is referenced directly as `icon` and `apple`) | Square, 512 x 512 (advice, **verify** how each browser scales it) | Browser decides | None. No monogram: no site icon from settings (the `favicon.ico` in the app folder applies). |
| Previous logo (fallback) | any image | As above | Only where the navy logo is missing; also the logo in emails when no navy logo | As navy logo | None | `alt` = Site Name |

Logo in emails: `logo_navy`, else previous logo, drawn 40 px high (`height="40"`), `alt` = Site Name; no logo: the site name in Georgia 24 px.

### 1.2 Delivery & returns

All numeric, all required. They drive the real delivery fee at checkout (`DeliveryFeeCalculator`), the checkout estimate, the product page, the promo bar, the trust strip and the product structured data.

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| Free delivery inside Dhaka over (৳) | Promo bar default text, product page Delivery info, checkout fee, JSON-LD shippingRate for Dhaka | Yes | integer >= 0 | 0 = Dhaka fee on every order. Not optional. |
| Delivery fee inside Dhaka (৳) | Checkout fee and estimate, JSON-LD | Yes | integer >= 0 | Not optional |
| Delivery fee outside Dhaka (৳) | Checkout fee and estimate, JSON-LD | Yes | integer >= 0 | Not optional |
| Return window (days) | **JSON-LD MerchantReturnPolicy only** (the storefront has no on-page text built from it; `returnWindowText()` exists but is not used) | Yes | 0 to 365 | 0 = JSON-LD says returns are not permitted |
| Delivery time inside Dhaka: from / to (days) | Trust strip "Delivery" tile, product page Delivery info accordion, order confirmation, JSON-LD transit time | Yes | 0 to 60; "to" >= "from" | Not optional |
| Delivery time outside Dhaka: from / to (days) | Same | Yes | 0 to 60; "to" >= "from" | Not optional |

The Shipping and Returns **pages** are separate text (section 5). Nothing keeps those pages and these numbers in step.

### 1.3 Homepage wording

All optional, all `live` with brand-voice notes.

| Label | Where it appears | Limit | Recommended | When empty (hardcoded default) |
| --- | --- | --- | --- | --- |
| Hero badge | Small pill above the hero headline | max 80 | 2 to 4 words | **Hidden** |
| Hero headline | Homepage hero panel `<h1>` | max 160 | 6 to 9 words | "Tea sets and gifts, packed by hand." |
| Hero paragraph | Homepage hero panel, under the italic line | max 400 | 1 to 2 sentences | The kit one-liner: "Anaiza Nest is a Dhaka gifting house for tea sets, gift boxes and homeware, packed by hand and ready to give." |
| Special prices tile line | Homepage hero side tile 1, after the "Special prices" title | max 120 | Under 40 characters | "Selected pieces at lower prices" |
| New arrivals tile line | Homepage hero side tile 2, after the "New arrivals" title | max 120 | Under 40 characters | "New gifts to explore" |
| Newsletter headline | Homepage newsletter section **and** newsletter popup | max 120 | 3 to 6 words | "New pieces, sent with care." |
| Newsletter text | Same two places | max 240 | One sentence | "Join our list for new arrivals and special prices." |

Always present in the hero (hardcoded, not editable): the italic line "For the people you'd pour a second cup for.", the buttons "Shop tea sets" and "Find the right gift", the two tile titles "Special prices" and "New arrivals".

### 1.3a Homepage pictures

All optional. A picture shows on the site as soon as it is saved, with no rebuild (the page picks it up a moment after it opens; the next rebuild puts it straight into the page). JPEG, PNG or WebP only (no SVG, no GIF), 5 MB each and under about 36 megapixels. The shop converts each to WebP (quality 82), makes copies (never larger than the original), keeps their sizes, deletes the original, and serves a `srcset`. It never crops: the storefront fits the picture to its panel from the centre (`object-fit: cover`). A small or oddly shaped upload gets a heads-up, never a block.

| Label | Recommended upload | Copies made (px wide) | Where it appears | When empty |
| --- | --- | --- | --- | --- |
| Hero picture, desktop | 1600 x 1000 (16:10) | 640, 1000, 1600 | Behind the hero panel from 640 px; text on a flat deep-ink panel over the left side (about 70% at 1280 px wide) | Plain deep-ink panel |
| Hero picture, mobile | 900 x 1200 (3:4) | 450, 900 | Behind the hero panel under 640 px; text panel over the lower part. Used only with a desktop picture | Phones use the desktop picture, cut from its middle |
| Hero picture description | max 160 characters | | `alt` text of the hero picture (desktop and mobile) | `alt=""` (decoration); never filled from the headline |
| Special prices tile picture | 1200 x 750 (16:10) | 600, 1200 | Behind side tile 1; text on a flat panel along the bottom | Plain navy tile |
| Special prices tile picture description | max 160 | | `alt` of that picture | `alt=""` |
| New arrivals tile picture | 1200 x 750 (16:10) | 600, 1200 | Behind side tile 2; text on a flat panel along the bottom | Plain charcoal tile |
| New arrivals tile picture description | max 160 | | `alt` of that picture | `alt=""` |

Keep the subject on the right (wide screens), at the top (phones) and in the upper part (tiles): the text panels cover the rest.

### 1.4 Corporate gifting

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Short intro on the page (optional) | `/corporate-gifting`, under the H1 (line breaks kept) | No | max 500 | 2 to 3 sentences | "Ordering gifts for your company? Send us the details below and we will reply with a quotation." |
| Send new enquiries to | Not shown. Notification recipient. | No | email, max 255 | | No email; enquiries are still saved. |

### 1.5 Shop alerts and order protection (not shown to customers)

| Label | Effect | Limit | Default |
| --- | --- | --- | --- |
| Send alerts to | Recipient of new-order, stock and backup emails | email, max 255 | Falls back to the payment notification address, then the contact email |
| Email me when a cash on delivery order arrives | Toggle | | On |
| Email me a morning note about products that are finished or running low | Toggle | | On |
| Most orders from one phone number in 24 hours | Checkout refuses beyond this; the buyer is sent to WhatsApp | 0 to 50 | 5 (0 = no limit) |

### 1.6 WhatsApp

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| WhatsApp number | Floating bubble and every "message us on WhatsApp" link on the storefront (checkout help, contact page) | No | max 20, must be international `+8801XXXXXXXXX` | Contact Phone is used; with neither, no bubble and no links |
| Show the WhatsApp bubble | Floating round button, bottom right | No | toggle | On. Links elsewhere stay. |
| First message in the chat | Pre-filled text in the chat | No | max 300 | "Hello, I would like some help choosing a gift." |
| Pages where the bubble is hidden | Path list (a path covers its sub-pages; "/" is home only) | No | tags | Bubble shows everywhere |

### 1.7 Product feeds, product pages

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| Storefront URL for feeds | Start of every product link inside the Google, Meta, TikTok feeds | No | url, max 255 | `FRONTEND_URL` from the server |
| Say "Only a few left" at or below this stock | Product page, above the quantity | Yes | integer >= 0 | 0 never shows it (default 5) |

The sentence is hardcoded: "Only a few left in this colour." when the selected option's name contains "colour"/"color", otherwise "Only a few left."

### 1.8 Header

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| Top Promo Bar Text | Dark bar above the header on every page (on landing pages only with the Normal layout) | No | max 255 | The API sends the default "Free delivery inside Dhaka on orders over ৳{threshold}", so **the bar cannot be hidden by emptying the field**. Left as the generated default it keeps following the free-delivery number. |
| Show a Categories menu in the header | "Categories" dropdown after Shop | No | toggle | |
| Auto-list all categories under Shop | Puts categories under the Shop item and replaces the dropdown | No | toggle | |
| Main Navigation Links | Header menu | No | see 1.9 | Built-in six items: Home, Shop, Special prices, Gift finder, Blog, Contact |

### 1.9 Menu items (header and footer links)

Used by Main Navigation Links, its sub-items (max 12 each, one level) and each Footer link column.

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| Type | Page / Category / Blog / Custom URL | Yes | | |
| Page / Category | Resolved to the current address, so renaming a slug never breaks it | Yes for its type | | A deleted target drops the item |
| URL | Custom type only | Yes for Custom | max 255; `/path` or `https://` | |
| Label | Link text | Required for Custom; optional otherwise | max 60 | Page title, category name, or "Blog" |

### 1.10 Footer

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| About Blurb | Footer, left column | No | max 500 | 1 to 2 sentences | API sends the kit one-liner. Also the **default meta description for every page** that sets none (section 14.3). |
| Link columns (title + links) | Footer columns | No | up to 3 columns; title required, max 60 | | Built-in column "Customer care" with 5 links |
| Social Media Links (platform + URL) | Footer icons (open in a new tab; `aria-label` = platform key) and JSON-LD `sameAs` | No | URL required, max 255; platforms: facebook, instagram, youtube, tiktok, twitter, linkedin, pinterest, whatsapp | | No icons |
| Copyright Text | Footer bottom: "© {year} {Site Name}. {text}" | No | max 255 | "All rights reserved." | "All rights reserved." |

Social icons: facebook, twitter, instagram, youtube, linkedin have their own glyphs; tiktok, pinterest, whatsapp use a generic link glyph.

Hardcoded footer: "Get in touch", the payment chips (bKash, Nagad, Rocket, plus "Cash on delivery" while COD is on), "Cookie settings" (only while the cookie banner is on).

---

## 2. Homepage (Content > Homepage Sections, Content > Banners / Sliders)

The homepage is a list of sections in order. If the list is empty or unreachable, the storefront uses five defaults: hero banner, special prices, featured gifts, new arrivals, newsletter (`defaultHomepageSections.ts`).

### 2.1 Section fields (all section types)

Source: `HomepageSectionResource.php`.

| Label | Req. | Limit | Notes |
| --- | --- | --- | --- |
| Type | Yes | Hero Banner, Special prices, Featured gifts, New arrivals, Newsletter, Shop by occasion, Why Anaiza Nest, Custom HTML | An unknown type is skipped by the storefront |
| Position | Yes | number | Sort order; also drag to reorder in the list |
| Enabled | | toggle | Off = not shown |
| Deal ends at (optional) | No | Dhaka time; Special prices only | A real countdown beside the Special prices heading. Empty or in the past: no countdown, nothing invented. |
| Section title (optional) | No | max 255 | Types: Special prices, Featured gifts, New arrivals, Newsletter, Shop by occasion, Why Anaiza Nest, Custom HTML (not Hero Banner) |
| Section subtitle (optional) | No | max 255 | Same types, except Custom HTML |

### 2.2 What each type shows, and its defaults

| Type | Content source (not choosable in the admin) | Title when empty | Subtitle when empty | Hidden when |
| --- | --- | --- | --- | --- |
| Hero Banner | The slider (2.4), the hero panel (1.3) and the trust strip (hardcoded: "Gift-ready", "Packed by hand", "Cash on delivery" while COD is on, "Delivery") | none | none | Never hidden; with no live slides only the panel shows |
| Special prices | Products on sale (sale price below price), first 5 | "Special prices" | "Selected pieces at lower prices." | No products on sale |
| Featured gifts | Products with "Show in Featured gifts" on, first 8; if none are flagged, the 8 newest | "Featured gifts" | "A few pieces to give, packed by hand and ready to go." | No products |
| New arrivals | Products with "Mark as new arrival", newest first, first 9 | "New arrivals" | "Recently added to the collection." | No products |
| Newsletter | Email sign-up form | The Newsletter headline of Site settings (1.3) | The Newsletter text (1.3) | Never |
| Shop by occasion | Tiles (2.3) | "Shop by occasion" | none | No tile switched on and linked |
| Why Anaiza Nest | Reasons (2.3) | "Why Anaiza Nest" | none | No reason with a line |
| Custom HTML | The HTML box (2.3) | none (no heading) | none | HTML empty |

### 2.3 Tiles, reasons, custom HTML

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Tile: Label | On the tile (serif, on a small ivory chip when there is a picture) | Yes | max 60 | 1 to 3 words | Tile cannot save |
| Tile: Shown | | | toggle | | Off = not shown |
| Tile: Links to / Category / Page / URL | Tile destination | Yes (type chosen; target required for its type) | URL max 255 | | A tile with no link is not shown |
| Reason: Title (optional) | Serif line above the reason | No | max 60 | 2 to 4 words | Hidden |
| Reason: One line | Body of the reason | Yes | max 160 | One sentence | Reason cannot save |
| Reasons (list) | Why Anaiza Nest grid | No | max 5 | | Section hidden |
| Custom HTML | Rendered **as it is, unsanitised**, inside a `max-w-7xl` block | Yes for this type | none in the form | | Section hidden. Needs the report-only CSP to allow what it loads. |

| Image field | Types, max | What the code does | Shown at (mobile / desktop) | Min. recommended | Crop | Alt |
| --- | --- | --- | --- | --- | --- | --- |
| Tile: Picture (optional) | JPEG, PNG, WebP; 3 MB (3,072 KB) | Converted to WebP, capped at 600 px wide; the uploaded original is deleted; stored under `occasions/generated` | 4:3 box. 2 tiles per row on phones (about 170 px wide), 3 per row from 640 px, 4 from 1024 px | 600 x 450 (code caps at 600 wide) | `object-cover`, from the centre | No alt field. `alt=""` (decorative; the label is the visible text). |

Plain tile with no picture: a linen panel with the label.

### 2.4 Banners / sliders (Content > Banners / Sliders)

The slider sits above the hero panel. Up to **5** slides; the "create" button disappears at 5 (`Banner::MAX_SLIDES`).

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Headline | Slide, bottom left over a dark gradient (serif); also the image `alt` | No | max 120 | 3 to 8 words | No headline |
| Subtext | Under the headline | No | max 255 | One short line | Hidden |
| Link (optional) | Whole slide is the link | No | max 255; must start `https://` or `/` | | Slide does not link |
| Button text (optional) | A button drawn on the slide | No | max 40 | 2 to 4 words | **Only shown when the slide has a link**; no button otherwise |
| Starts / Ends (optional) | Schedule (the admin list shows Live, Not yet, Ended, Off) | No | Ends must be after Starts | | Straight away / no end |
| Order | Sort | Yes | integer >= 0, default 0 | | |
| On | | | toggle | | |
| Slider settings (header action): Move to the next slide by itself | Autoplay; pauses on hover, touch, focus, hidden tab, off-screen, and reduced-motion | | toggle | | Off |
| Slider settings: Seconds on each slide | Autoplay interval | Yes | 6 to 30 | 7 | 7 |

| Image field | Types, max | What the code does | Shown at (mobile / desktop) | Min. recommended | Crop | Alt |
| --- | --- | --- | --- | --- | --- | --- |
| Desktop image | any image; no field limit; required | WebP; capped at 1920 px wide; also a 1280 px and a 640 px copy (a copy is made only when the source is at least that wide); the original is deleted | Container `max-w-7xl` (1,280 px max), ratio **12:5** from 640 px up | 1920 x 800 (the admin's own target); 1280 x 533 is the least that is sharp at 1x | `object-cover` from the centre | `alt` = Headline; empty headline: `alt=""` |
| Mobile image (optional) | any image | WebP, capped at 900 px wide when uploaded. **Not uploaded:** a 750 x 500 crop is made from the **centre** of the desktop image, at save time, and made again whenever the desktop image changes (an uploaded mobile image is never overwritten). | Ratio **3:2** under 640 px, full width | 750 x 500 | Auto-crop is `cover()` from the centre. Anything off-centre in the desktop image may be lost on phones: upload a mobile image when the subject is not central. | Same as desktop |

The first slide loads at once (`eager`, high priority); later slides are lazy. The admin saves the slider settings with a note that "the storefront is a static build"; the homepage actually refreshes the slides and the settings live (section 17, row 4).

---

## 3. Categories (Catalog > Categories)

Source: `CategoryResource.php`; API `CategoryResource`; page `src/app/category/[slug]` and `CategoryDetail.tsx`.

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Name | Category page `<h1>`, header Categories menu, breadcrumbs, listing, banner `alt`, JSON-LD ItemList and BreadcrumbList names, feed `product_type` and `custom_label_0` | Yes | max 255 | 1 to 3 words | Cannot save |
| Slug | Address `/category/{slug}` | Yes | max 255, unique | Short, lower case | Auto-filled from Name when creating |
| Google product category (optional) | Feeds only (`google_product_category`) | No | max 255; text or number | A path from Google's list | Not sent |
| Product type for feeds (optional) | Feeds only (`product_type`) | No | max 255 | | Category name |
| Show in the storefront Categories menu | Header menu / under Shop | No | toggle | | On by default |
| Menu order | Menu sort (then by name) | Yes | integer >= 0, default 0 | | |
| Description | **Not shown anywhere** (internal note) | No | none | | |
| Summary / intro (shown directly under the title) | Under the H1; also meta description fallback; `llms.txt` category line | No | max 600; helper states 50 to 100 words | 50 to 100 words **(code helper)** | Hidden |
| Long SEO description | Below the product grid, headed "About {Name}" with the brand's prose styling; phones show about 360 px then "Read more" (the full text is in the page HTML); any `<h1>` becomes `<h2>`; tables are kept (stacked on phones). Edited in the visual editor or as HTML ("Edit this text as"); content with a table opens as HTML | No | none in the form; helper says typically 300 to 800 words; scripts, styles, classes and ids are removed on save | 300 to 800 words **(code helper)** | Section hidden |
| Raw HTML paste / upload (.html) | Replaces the long description above, kept as written (not passed through the visual editor); a note lists what was removed | No | `text/html`; no size field limit; only the body of an uploaded file is used | | |
| Category FAQ: Question | FAQ accordion near the bottom, and FAQPage JSON-LD | Yes per row | max 120 | Under 90 | Section hidden when no rows |
| Category FAQ: Answer | Same | Yes per row | max 500; plain text | 2 to 4 sentences | |
| (FAQ list) | | | max 8 rows | | |
| Meta title | `<title>` (template adds " \| Anaiza Nest") and og:title | No | max 70 | 50 to 60 **(code helper)** | Category name |
| Meta description | `<meta description>`, og:description | No | max 160 | 120 to 155 **(code helper)** | Falls back to the Summary / intro; if that is empty, the site-wide default description |

Hardcoded on the page: the "About {Name}" heading, "Frequently Asked Questions" (capitalised unlike the product page), "Read more" / "Read less", the empty state "Nothing in this category just yet" and its text.

| Image field | Types, max | What the code does | Shown at (mobile / desktop) | Min. recommended | Crop | Alt |
| --- | --- | --- | --- | --- | --- | --- |
| Desktop banner | any image; no field limit | WebP, capped 1920 px wide; width and height stored; **no extra renditions** | Full width (no max width), ratio **4:1** from 640 px up. Also the only image in the category's social-share tags (og:image). | Admin target 1600 x 400. On a 1920 px screen it is shown 1920 x 480, so 1920 x 480 is sharper. | `object-cover`, from the centre | No alt field. `alt` = category name |
| Mobile banner | any image | WebP, capped 900 px wide when uploaded. Not uploaded: 750 x 500 `cover()` crop from the centre of the desktop banner, regenerated when the desktop banner changes (an uploaded mobile banner is never overwritten). | Ratio **3:2** under 640 px, full width | 750 x 500 | Auto-crop from the centre | Category name |
| Tile / thumbnail | any image | WebP, capped 600 px wide | **Not displayed anywhere on the storefront.** The admin helper says "Used in nav/category grids", but no component reads it (verify against any future grid). | 600 x 600 | n/a | None |

The banner is the page's largest image: `loading="eager"`, high priority, with the kit's gift frame drawn over it. A category with no banner has no banner area at all.

---

## 4. Products (Catalog > Products)

Source: `ProductResource.php`, `ImagesRelationManager`, `VariantsRelationManager`; API `ProductResource`; `ProductDetail.tsx`, `ProductCard.tsx`, `ProductGallery.tsx`, `AddToCartForm.tsx`, `jsonld.ts`.

### 4.1 Core fields

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Category | Breadcrumb, related products, category listing | Yes | | | |
| Brand | JSON-LD brand name, feeds `brand`. Brand **logo is not displayed** anywhere. | No | | | JSON-LD brand = the shop (Organization); feeds use the shop name with a warning |
| Name | Product `<h1>`, cards, cart, wishlist, breadcrumb, gallery `alt`, JSON-LD name, feed title (cut at 150) | Yes | max 100 | 60 to 70 **(code helper)** | Cannot save |
| Slug | `/product/{slug}` | Yes | max 255, unique | | Auto-filled from Name |
| Short description | Under the price on the product page; JSON-LD `description`; meta description fallback; feed description (second choice) | No | max 200, plain text | 100 to 160 (advice) | Hidden on the page; JSON-LD has no description |
| Summary (shown directly under the title) | Under the H1 (3 lines on phones, tap to expand); feed description (**first choice**) | No | max 600; helper says 50 to 100 words | 50 to 100 words **(code helper)** | Hidden |
| Description | "Description" section below the fold (about 10 rem then "Read more" on phones); feed description (third choice, tags stripped); any `<h1>` becomes `<h2>` | No | none in the form | 150 to 400 words (advice) | Section hidden |
| Raw HTML paste / upload (.html) | Overwrites Description | No | `text/html` | | |
| Price (BDT) | Everywhere; JSON-LD; feeds | Yes | number >= 0 (stored as whole taka) | | |
| Sale price (BDT) | Shown as "Special price"; "Save {n}%" is calculated and rounded; strike-through of the price | No | number >= 0. **No rule forces it below Price**: a sale price at or above the price is ignored (no discount shows). verify with an editor. | | No sale |
| Stock | "In stock" / "Out of stock", add-to-cart limit, low-stock text, feed availability | Yes | number >= 0, default 0 | | |
| SKU | "SKU: …" on the page; **the id that the feeds and the pixels use** | Yes | unique | Stable, never reuse (advice) | Cannot save |
| GTIN / barcode (optional) | JSON-LD `gtin8/12/13/14` by length; feeds | No | 8, 12, 13 or 14 digits; max 14 | | Not published |
| Manufacturer part number (optional) | JSON-LD `mpn`; feeds | No | max 100 (feeds cut at 70) | | Not published |
| Mark as new arrival | Homepage "New arrivals"; "New" chip on the card (when not on sale) | No | toggle | | |
| Show in Featured gifts | Homepage "Featured gifts"; feed `custom_label_2` | No | toggle | | |
| Active (visible in store) | Off hides it everywhere and from feeds | No | toggle (default on) | | |
| Exclude from product feeds | Keeps it out of Google, Meta and TikTok feeds only | No | toggle | | |
| Tags, Labels, Attributes | **Not displayed on the storefront** (the API sends them; no component shows them; a `tag` filter parameter exists but has no control). verify before promising tag or label features. | No | | | |

### 4.2 In the box, care, specifications

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Gift box included | Price reads "৳1,450, gift box included" | No | toggle | Leave off unless true **(code helper)** | Off |
| What is in the box (lines) | "What is in the box?" list | No | each line max 120; up to 12 | Under 60 characters | Section hidden |
| Care instructions | "How do I care for it?" paragraph | No | max 400, plain text | 1 to 3 sentences | Section hidden |
| Specifications: Label | "Specifications" table; "Material" and "Colour"/"Color" also become JSON-LD properties | Yes per row | max 100; up to 20 rows | | Section hidden |
| Specifications: Value | Table cell | Yes per row | max 255 | | |

### 4.3 Product FAQ

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| Question | "Frequently asked questions" accordion near the bottom (question is an `<h3>`); FAQPage JSON-LD | Yes per row | max 120 | Section hidden |
| Answer | Same | Yes per row | max 500, plain text | |
| (list) | | | up to 8 | |

### 4.4 Variants (relation manager)

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| Attribute (e.g. Colorway) | Option label "{Attribute}: {Value}"; cart line "Colorway: Emerald"; order and email lines | Yes | max 255 | |
| Value (e.g. Burgundy) | As above | Yes | max 255 | |
| Price (BDT), Sale price (BDT), Stock, SKU (overrides) | Option price and stock; a variant with a SKU of its own is its own feed item and pixel id; a variant with its own price becomes its own JSON-LD Offer | No | numbers >= 0; SKU max 255 | Inherits the product's |
| Image | Choosing the option jumps the gallery to that image | No | one of this product's images | Inherits the product's main image |

### 4.5 Video (optional)

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| YouTube / Vimeo URL | Click-to-play facade under the add-to-cart block; plays through YouTube no-cookie or Vimeo player | No | url, max 255; recognised forms: youtube.com/watch?v=, /shorts/, /embed/, youtu.be/, vimeo.com/{id} | No video |
| ...or upload MP4 | Same facade, plain `<video>` | No | `video/mp4` only; max 15 MB (15,360 KB) | |
| Poster image | The picture shown before play | No in the form, **required in practice** | any image; no field limit | **No poster: no video is shown at all**, whichever source is filled in |

The URL takes priority over an uploaded file. Nothing loads or plays until the visitor taps play.

### 4.6 SEO

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Meta title | `<title>` and og:title | No | max 70 | 50 to 60 | Product name |
| Meta description | `<meta description>`, og:description | No | max 160 | 120 to 155 | Short description; if that is empty, the site-wide default description |

### 4.7 Product images

Source: `ImagesRelationManager`, `ProductImageObserver`. Up to **8** images per product (the add button disables at 8). A note appears below 4 ("four shots work well"); it never blocks saving.

| Label | Types, max | What the code does | Shown at (mobile / desktop) | Min. recommended | Crop | Alt |
| --- | --- | --- | --- | --- | --- | --- |
| Image | JPEG, PNG, WebP only; 3 MB (3,072 KB); required | WebP quality 82, **aspect ratio kept**. Renditions at 400, 800 and 1200 px wide (400 and 800 only when the source is at least that wide). The 1200 px file becomes the stored `url`, with its real width and height saved. The uploaded original stays on the disk (not deleted; verify disk use). A second JPEG copy (long side 1024 to 2000 px) is made on demand for the feeds. | Square **1:1** everywhere: product card, gallery main image (`sizes` 100vw on phones, 50vw on desktop), gallery thumbnails (5 per row), cart page line (96 px), cart drawer (64 px), wishlist (80 px) | **800 x 800** (the admin warns below this, and when the ratio is outside 0.9 to 1.1) | `object-cover` from the centre. A non-square photo is cropped to a square on the site. | Alt text field (max 255). **The storefront does not use it**; the fallback is always the product name (section 17, row 1). |

| Field on each image | Limit | Effect |
| --- | --- | --- |
| Alt text | max 255, optional | **Not read by the storefront gallery, cards, cart or JSON-LD.** Gallery main image `alt` = product name; thumbnails "{name} thumbnail {n}"; cards, cart and wishlist: product name. Read only by the variant image picker in the admin and by landing page product blocks. |
| Sort order | integer, required, default 0 | The lowest number is the main image everywhere (card, social image fallback, JSON-LD, feed `image_link`) |

---

## 5. Pages (Content > Pages)

Source: `PageResource.php`; storefront `/pages/{slug}`.

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Title | `<h1>`, breadcrumb, `<title>` fallback, menu label | Yes | max 255 | | |
| Slug | `/pages/{slug}` | Yes | max 255, unique | | Auto from Title |
| Content | Page body under the H1, in the brand's prose styling; `<h1>` becomes `<h2>`; tables are kept (stacked on phones). Edited in the visual editor or as HTML ("Edit this text as"); content with a table opens as HTML | No | none; scripts, styles, classes and ids are removed on save | | Page shows its title only |
| Raw HTML paste / upload | Replaces Content, kept as written; a note lists what was removed | No | `text/html`; only the body of an uploaded file is used | | |
| Meta title | `<title>`, og:title | No | max 70 | 50 to 60 | Page title |
| Meta description | `<meta description>` | No | max 160 | 120 to 155 | Site-wide default description (the page sets none) |
| Social share image URL | og:image, twitter:image | No | **a URL typed as text**, max 255 (not an upload) | 1200 x 630 (advice) | No og:image |

Pages the storefront links by fixed path, so their slugs matter: `shipping`, `returns` (footer defaults and policy links), `terms` (checkout footnote and `llms.txt`). A page created after the last build is served by the client shell, which sets only the browser title and has none of the page's other tags until the next build (verify in the browser).

---

## 6. Blog (Content > Blog Articles)

There is **no blog category screen and no blog tag screen** in the admin. Articles are a flat list.

Source: `ArticleResource.php`; `/blog`, `/blog/{slug}`.

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Title | Article `<h1>`, blog card heading, breadcrumb, `<title>` fallback, JSON-LD headline, image `alt` | Yes | max 255 | 50 to 65 (advice) | |
| Slug | `/blog/{slug}` | Yes | max 255, unique | | |
| Publish at | Date on card and article; JSON-LD datePublished; draft until set and in the past | No | date and time | | Draft (hidden) |
| Author name (optional) | Byline "By {name}"; JSON-LD author | No | max 255 | | No byline, no author in JSON-LD |
| Author bio (optional) | "About the author" box (needs a name); JSON-LD author description | No | max 500 | 1 to 2 sentences | Hidden |
| Content | Article body in the brand's prose styling; an "In this article" table of contents is built from `<h2>` headings (shown when there are 2 or more); `<h1>` becomes `<h2>`; tables are kept (stacked on phones); card excerpt is taken from it. Edited in the visual editor or as HTML ("Edit this text as"); content with a table opens as HTML | No | none; scripts, styles, classes and ids are removed on save | 600+ words (advice) | |
| Raw HTML paste / upload | Replaces Content, kept as written; a note lists what was removed | No | `text/html`; only the body of an uploaded file is used | | |
| Meta title | `<title>`, og:title | No | max 255 | 50 to 60 | Title |
| Meta description | `<meta description>`; JSON-LD description | No | max 255 (not 160) | 120 to 155 | Site-wide default description; JSON-LD has none (no excerpt fallback) |

| Image field | Types, max | What the code does | Shown at (mobile / desktop) | Min. recommended | Crop | Alt |
| --- | --- | --- | --- | --- | --- | --- |
| Featured image | any image; no field limit | **Stored exactly as uploaded: no resize, no WebP.** The file is served at full size, so very large photos load slowly. | Blog card **16:10** (full width on phones, half and a third on larger screens); article page **16:9**, rounded, inside the 3xl (768 px) column | 1280 x 720; keep the subject central because the card is 16:10 and the page is 16:9 (advice) | `object-cover` from the centre | No alt field. `alt` = article title. No image: the card shows the words "Anaiza Nest" on a linen tile; the article shows no image. |
| Social share image | any image | Stored as uploaded | og:image and twitter:image only | 1200 x 630 (advice) | Platform decides | None |

Blog listing page text is hardcoded: title "Blog", description "Gift guides, care tips, and stories from Anaiza Nest."

---

## 7. FAQs (Engagement > FAQs)

The site-wide FAQ page at `/faq`. (Product and category FAQs are separate: sections 3 and 4.)

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Question | `/faq` accordion; FAQPage JSON-LD; the landing page FAQ block | Yes | max 255 | Under 90 | |
| Answer | Accordion body (line breaks kept, plain text); JSON-LD | Yes | no limit in the form | 2 to 5 sentences | |
| Sort order | Order | Yes | integer, default 0 | | |

No FAQs: the page says "No questions here yet. If you need a hand, write to us on the Contact page."

---

## 8. Landing pages (Content > Landing pages)

Source: `LandingPageResource.php`, `LandingBlocks.php`, `HtmlCleaner.php`; storefront `src/app/lp`, `LandingPageClient.tsx`, `landing/LandingBlocks.tsx`. See also `docs/landing-pages.md`.

### 8.1 Page

| Label | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- |
| Title | Page title fallback, admin list, SEO title fallback, hero headline fallback, a hidden `<h1>` when there is no hero | Yes | max 255 | | |
| Address (`/lp/…`) | The page address | Yes | max 120, letters/numbers/dashes, unique | Short, no dates | Auto from Title |
| Status | Draft: only the preview link works. Published: live between the times below. | Yes | | | Draft |
| Layout | **Focus**: logo only (not a link), no menu, no footer links. **Normal**: the site's promo bar, header and footer. In **both**, the cart drawer, popups and WhatsApp bubble are not drawn (the order form is on the page); the cookie banner always is. | Yes | | | Focus |
| Opens at / Closes at (Dhaka time) | Before opening: not found. After closing: "This offer has ended" with the closing time and a link to the shop; nothing of the offer is sent. | No | Closes after opens | | Live as soon as published / no end |
| Keep this page out of search engines | `noindex, nofollow` and left out of the sitemap | | toggle | | **On** |
| Title in search results and when shared | `<title>` (with " \| Anaiza Nest") and og:title | No | max 70 | 50 to 60 | Page title |
| Description | `<meta description>` and og:description | No | max 255 | 120 to 155 | Site-wide default description (meta) |
| Canonical address (optional) | `<link rel="canonical">` | No | url, max 255 | Only when repeating another page | `/lp/{address}` |

| Image field | Types, max | What the code does | Shown at | Min. recommended | Alt |
| --- | --- | --- | --- | --- | --- |
| Picture when shared | any image; 4 MB (4,096 KB) | Stored as uploaded | og:image only | 1200 x 630 (advice) | None |

### 8.2 Blocks

Every block has **Show this block** (default on; off = not sent to the storefront at all). Blocks are added, reordered, cloned and collapsed in the builder. The builder labels each block with its first text for easy finding.

| Block | Field (label) | Where it appears | Req. | Limit | Recommended | When empty |
| --- | --- | --- | --- | --- | --- | --- |
| Hero | Headline | The page `<h1>`, over the picture | No | max 160 | 4 to 9 words | The page title |
| | Subline | Under the headline | No | max 300 | One sentence | Hidden |
| | Button text | Button under the text | No | max 60 | 2 to 4 words | "Order now, pay on delivery" |
| | Button goes to | The products block, the order form, or an address | Yes | | | The products |
| | Address | Only for "An address" | When chosen | max 255; `https://`, `/`, `#`, `mailto:`, `tel:` | | No button |
| Text | Text (rich editor) | Section, `max-w-3xl`; `<h1>` becomes `<h2>`; cleaned like custom HTML | Yes | none | | |
| Custom HTML | HTML | Rendered in a scoped box (below) | No | max 1,048,576 characters | | Block hidden |
| | ...or upload an .html or .htm file | Fills the HTML box with the file's `<style>` tags and `<body>`; head links are not brought over | No | `text/html`, extensions html/htm; max 1 MB (1,024 KB) | | |
| | Images used by this HTML | Matched by file name to relative paths in the HTML; added to the media library | No | multiple images; 4 MB each; names kept | | Unmatched paths are listed after saving |
| | Allow scripts in this HTML | Off for everyone; needs the explicit grant; never on a Focus page with the order form | No | toggle | | Off |
| Products | Heading (max 120), Pick products by hand / All products of a category, Products (max 24; search by name or SKU; active only), Category, Most products to show (1 to 48, default 12), Layout (Grid / List), Show the price and the sale price, Show the "Order now, pay on delivery" button, Let visitors choose quantity and option | Block with id `landing-products`; grid is 2 columns on phones, 3 from 640 px | Products or category required to show anything | | | No products: block hidden |
| Benefits row | Heading (max 120); lines: Icon (7 choices) + Text | Row of icon and short line; 1 column on phones, 3 from 640 px | Text required per line | Text max 80; max 6 lines (default 3) | 2 to 5 words | No lines: block hidden |
| FAQ | Heading (max 120), Questions (pick, or empty for all) | Accordion plus FAQPage JSON-LD | No | | | Heading "Questions people ask"; no FAQs at all: block hidden |
| Reviews | Heading (max 120), Most reviews to show | Approved reviews of the products shown on the page, with the product name | No | 1 to 20, default 6 | | Heading "What people say"; **no approved reviews: block hidden** |
| Order form | Heading (max 120), Intro (max 300) | The shop's checkout, embedded (stacked, quantities editable, sticky order button on phones) | No | | | Heading "Your order". With nothing chosen: "Choose a gift above and it will appear here, ready to order." |
| Offer countdown | Label (max 80) | Navy strip with a real countdown | No | | "This offer ends in" | **No closing time, or already passed: block hidden.** There is never a made-up timer. |

### 8.3 Images on landing pages

| Image field | Types, max | What the code does | Shown at (mobile / desktop) | Min. recommended | Crop | Alt |
| --- | --- | --- | --- | --- | --- | --- |
| Hero: Picture, desktop | any image; 4 MB (4,096 KB) | **Stored as uploaded: no resize, no WebP** (unlike banners and products). Loaded at once (`eager`, high priority). | Full-bleed section, minimum height 26 rem (416 px) on phones, 30 rem (480 px) from 640 px; the picture covers it | Desktop 1920 x 640; (advice, derived from the CSS, not enforced) | `object-cover` from the centre; tall boxes on phones crop the sides | `alt=""` (decorative). No field. |
| Hero: Picture, mobile (optional) | any image; 4 MB | Same | Used under 640 px (`<source media="(max-width: 639px)">`) | 750 x 830 (advice, derived) | Same | `alt=""` |
| Products block pictures | (product images, section 4.7) | The product's renditions | Square (list layout: 112 px on phones, 160 px from 640 px) | 800 x 800 | `object-cover`, centre | `alt` = the image's Alt text, else the product name (**the only place the product Alt text field is used**) |
| Custom HTML images | via "Images used by this HTML" | Stored as uploaded | As the HTML lays them out (`max-width: 100%`) | | None | Whatever the HTML says |

Only the first image on the page is eager: the hero image; with no hero image, the first product's image.

### 8.4 What custom HTML is allowed to do

Cleaned on save from an allow-list; what was removed is listed at the top of the edit page and in a notification. Removed: `<script>`, inline `on…` attributes, `javascript:` addresses, iframes other than YouTube and Vimeo (YouTube is switched to its no-cookie host), forms and form fields, `<link>`/`<meta>`/`<base>`, external stylesheets, `<object>`/`<embed>`/`<canvas>`, CSS that can run code, `@import`, `@font-face`. Kept: normal markup, classes, inline styles, `<style>` blocks (every selector is prefixed with a wrapper class, so it cannot restyle the site), images, small inline SVG shapes. Needs from the report-only Content-Security-Policy: inline `<style>` and `style=""` (allowed); inline `<script>` only when scripts are granted (allowed by `'unsafe-inline'`); scripts from another host need that host added to `script-src`.

---

## 9. Banners and popups

### 9.1 Popups (Settings > Popups)

Two popups, each with the same fields. **Their text is not editable** (apart from the newsletter popup borrowing the Site settings newsletter wording).

| Label | Where it appears | Req. | Limit | Default | When empty |
| --- | --- | --- | --- | --- | --- |
| Enabled | | | toggle | Off | |
| Show | After a delay / On scroll (past 50% of the page) / On exit intent (a mouse leaving the top of the page; never fires on touch devices) | Yes | | After a delay | |
| Delay (seconds) | Only for "After a delay" | Yes when delay | 0 to 300 | 5 | |
| Show on | All pages / Home / Shop / Product pages / Category pages (the popup never shows on /cart, /checkout, /order-confirmation, or on landing pages) | No | multi-select | All pages | |
| Image (optional) | Top of the popup | No | any image; no field limit | | No picture |

A close button is always present; closing starts a 24-hour cooldown (`localStorage`). Newsletter popup text: headline and text from Site settings > Homepage wording (defaults "New pieces, sent with care." and "Join our list for new arrivals and special prices."), button label "Subscribe" hardcoded. Gift finder popup text is hardcoded: "Not sure what to give?", "Answer three quick questions and we will suggest a few thoughtful gifts.", button "Find the right gift".

| Image field | Types, max | What the code does | Shown at (mobile / desktop) | Min. recommended | Crop | Alt |
| --- | --- | --- | --- | --- | --- | --- |
| Newsletter popup image | any image; no field limit | Stored as uploaded | A band **160 px high** (`h-40`) across the popup. The popup is at most 448 px wide, so about 2.8:1 on desktop and 2.2:1 on a phone (width = viewport minus 32 px). | 900 x 320 (advice, derived) | `object-cover`, centre | No alt field. Hardcoded `alt="Newsletter"` |
| Gift finder popup image | any image | Same | Same | Same | Same | Hardcoded `alt="Gift finder"` |

---

## 10. Cookie consent (Settings > Cookie consent)

| Label | Where it appears | Req. | Limit | Default (when blank) |
| --- | --- | --- | --- | --- |
| Show the cookie banner | Small bar at the bottom; **built into the page head, so changing it needs a storefront rebuild** | | toggle | On |
| Mode | Notice only / Opt-in required; **needs a rebuild** | Yes | | |
| Banner text | The bar's sentence | No | max 400 | "We use cookies to keep the shop working and to see how it is used. You choose what else we may use." |
| Privacy policy page | Link in the bar | No | any CMS page | No link |
| Link label | Text of that link | No | max 60 | "Privacy policy" |
| Accept all / Reject non-essential / Customize / Save choices (labels) | Buttons | No | max 40 each | "Accept all" / "Reject non-essential" / "Customize" / "Save choices" |

Wording changes refresh live; the mode and the on/off switch are baked at build time. Hardcoded in the customise panel: the three category names "Necessary", "Analytics", "Marketing" and their descriptions ("Keep the cart and checkout working. Always on.", "Help us see how the shop is used.", "Let us measure our ads and show you relevant ones."), and the footer link "Cookie settings".

---

## 11. Email templates (Settings > Email templates)

Ten emails. Each has the same fields; any field left blank falls back to the built-in wording, field by field. The header (logo, shop name), the single main button, the footer (tagline, address, contact) and, for the cart and review emails, the "Not for you? Unsubscribe" line are fixed.

| Label | Req. | Limit | Notes |
| --- | --- | --- | --- |
| Use my wording | | toggle | Off: the built-in email is sent |
| Subject | No | max 255 | Placeholders allowed |
| Greeting and intro (HTML) | No | none | Raw HTML put in as written |
| Closing (HTML) | No | none | After the button. An HTML comment such as `<!-- none -->` sends no closing |
| Footer note (optional) | No | none | |
| Custom full HTML + Custom HTML | No | none | Replaces everything between header and footer |

Placeholders: `{{customer_name}}`, `{{order_number}}`, `{{order_total}}`, `{{order_date}}`, `{{payment_method}}`, `{{delivery_address}}`, `{{site_name}}`, `{{tracking_number}}`, `{{courier_name}}`, blocks `{{order_items_table}}`, `{{order_totals}}`, `{{shipping_details}}`, `{{review_links}}`, `{{product_names}}`, `{{cart_items_block}}`, `{{recovery_link}}`, `{{coupon_code}}`, `{{coupon_value}}`, `{{coupon_expires_at}}`, `{{minimum_order}}`, `{{reset_link}}`, `{{reset_expires_in}}`. A placeholder that does not apply is left out.

| Template | When sent | Built-in subject | Main button (fixed) |
| --- | --- | --- | --- |
| Order confirmation | Order placed (invoice PDF attached) | "Order #{{order_number}} confirmed - {{site_name}}" | Track your order |
| Order shipped | Status changed to Shipped (once) | "Your order #{{order_number}} has shipped - {{site_name}}" | Track your order |
| Order delivered | Status changed to Delivered (once) | "Your order #{{order_number}} has been delivered - {{site_name}}" | Shop tea sets |
| Order cancelled | Status changed to Cancelled (once) | "Your order #{{order_number}} has been cancelled - {{site_name}}" | Shop tea sets |
| Welcome | Registration or first social sign-in | "Welcome to {{site_name}}" | Shop tea sets |
| Password reset | Reset requested | "Choose a new password - {{site_name}}" | Choose a new password |
| Review request | Days after delivery | "How has it settled in? Order #{{order_number}}" | Write a review |
| Review request reminder | One reminder | "A quiet reminder about order #{{order_number}}" | Write a review |
| Cart reminder | Cart left | "Something you chose is still waiting" | Open your cart |
| Cart offer | Second cart email, with a code | "A little something for your cart, {{customer_name}}" | Open your cart |

Image: the email logo is the navy (or previous) logo, 40 px high, `alt` = Site Name; the invoice PDF uses the one-colour logo, max height 48 px.

---

## 12. Payment settings (Settings > Payment Settings)

For each of bKash, Nagad, Rocket:

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| {Wallet} number | Checkout: "Pay with {wallet}" panel with a copy button and the exact amount | No | tel, max 20 | **The method is not offered at all** |
| Logo (optional) | Beside the method name in the checkout list | No | PNG, JPEG or WebP; **1 MB (1,024 KB)**; stored as uploaded | Round navy chip with the first letter |
| Steps for the customer | Numbered steps in the pay panel; `{{amount}}` and `{{number}}` filled in | No | rich text (bold, italic, lists only); short | Built-in four steps: "Open the {wallet} app and choose Send Money." / "Send {{amount}} to {{number}}." / "Copy the transaction ID from the {wallet} confirmation message." / "Enter the number you paid from and the transaction ID below, then place your order." |

| Label | Where it appears | Req. | Limit | When empty |
| --- | --- | --- | --- | --- |
| Cash on Delivery Enabled | Checkout choice, the "Order now, pay on delivery" button and the "Cash on delivery" seal, trust strip, footer chips; the site says "Order now" when off | | toggle | On |
| Email me when a wallet order arrives | Not shown to customers | No | email, max 255 | Contact email |

| Image field | Types, max | What the code does | Shown at | Min. recommended | Crop | Alt |
| --- | --- | --- | --- | --- | --- | --- |
| Wallet logo | PNG, JPEG, WebP; 1 MB | None | 28 px high (`h-7`), width auto, in the method row | 112 px high (advice) | None | `alt=""` |

Hardcoded around it: "Pay with {wallet}", "{wallet} number to pay", "Copy number", "Amount to send", "The number you paid from", the transaction ID label and its "6 to 20 letters and digits." hint, and "Pay when your order arrives." for cash on delivery.

---

## 13. Shipping zones and delivery text (Sales > Shipping Zones)

| Label | Where it appears | Req. | Limit |
| --- | --- | --- | --- |
| Name | Admin order form only | Yes | max 255 |
| Delivery Fee (BDT) | Admin order form only (sets the fee when an order is typed in by hand) | Yes | number >= 0 |
| Estimated Delivery | **Nowhere on the storefront** | No | max 50 |

**Shipping zones do not drive what customers see or pay.** The storefront never reads `/api/shipping-zones`, and the checkout fee comes from Site settings > Delivery & returns (1.2). Delivery text on the storefront comes from there too: promo bar default, trust strip tile, product page Delivery info accordion, order confirmation, and JSON-LD. The accordion's wording is hardcoded: "We deliver across Bangladesh: {range} business days inside Dhaka, {range} outside. Delivery is free inside Dhaka on orders over {amount}. You can follow any order on the Track order page with your order number and phone number."

---

## 14. Marketing and SEO settings

### 14.1 Marketing settings (Settings > Marketing Settings)

| Label | Where it is used | Req. | Limit |
| --- | --- | --- | --- |
| Google Tag Manager Container ID | Injected into every page head (when the banner mode allows) | No | max 50 |
| Google Analytics 4 ID | Loaded directly only when there is **no** GTM container | No | max 50 |
| Meta Pixel ID | Browser pixel; also needed for the server-side event | No | max 50 |
| TikTok Pixel ID | Browser pixel; also needed for the server-side event | No | max 50 |
| Meta Conversions API Access Token | Server only (never sent to the browser) | No | max 2000 |
| TikTok Events API Access Token | Server only | No | max 2000 |
| Meta / TikTok test event code | Adds the code to server events while filled in; remove after testing | No | max 60 |

A blank ID loads nothing. Event ids are the SKU (else product id); Purchase is deduplicated with the server event through `order-{id}`.

### 14.2 Product feeds (Settings > Product feeds)

Fields: "Include out-of-stock items, marked as out of stock" (toggle), "Rebuild feeds now", "Regenerate token" (changes every feed address), and Storefront URL for feeds (Site settings 1.7). What the feeds read from product and category fields:

| Feed field | Source | Limit applied |
| --- | --- | --- |
| id | Variant or product SKU, else the product id | Must be unique, duplicates are dropped with a warning |
| title | Product Name (variant: "{Name} - {Attribute}: {Value}") | Plain text, double quotes removed, cut at **150** characters |
| description | Summary, else Short description, else Description | Plain text, no emoji, cut at **5,000** characters; no description: the title is used (warning) |
| link | Storefront URL + `/product/{slug}` | |
| image_link, additional_image_link | Product images (variant image first) as **JPEG** renditions, long side 1024 to 2000 px, quality 86 | Up to **10** additional; under 500 px on the short side is a warning (Meta and TikTok reject it); Meta needs JPEG or PNG, TikTok JPG or PNG |
| price, sale_price | Price, Sale price, as "1450.00 BDT" | Sale price only when below price |
| availability | Stock | |
| brand | Brand name, else the shop name | max 100 |
| gtin, mpn | Product GTIN (digits), MPN | mpn cut at 70; `identifier_exists=no` when both are blank |
| item_group_id | Product id, when variants have SKUs of their own | |
| product_type | Category "Product type for feeds", else the category name | max 750 |
| google_product_category | Category "Google product category" | |
| custom_label_0 / _1 / _2 | Category name / on sale yes or no / featured yes or no | label 0 max 100 |

A product is left out when it is inactive, switched off for feeds, has no price, or is out of stock with the toggle off.

### 14.3 Structured data and SEO titles: what reads which field

| Output | Reads | Notes and limits |
| --- | --- | --- |
| Organization / WebSite JSON-LD (every page) | Site Name, logo (navy else previous), Brand description (else footer About), social links, contact phone and email, address | A missing field is left out, not guessed |
| Product JSON-LD | Name, **Short description** (not Summary), SKU, GTIN, MPN, specifications (Material and Colour become properties; every row becomes `additionalProperty`), Brand, **first** image (else the social share image), price, variants with their own price (separate Offers), stock, shipping from Delivery & returns, return window, approved reviews | Description limit: none; keep under about 5,000 |
| ItemList JSON-LD (category) | First 24 products: name, URL, first image, price, availability | |
| FAQPage JSON-LD | Product FAQ, Category FAQ, the /faq page, a landing FAQ block | |
| BreadcrumbList JSON-LD | Product, category, CMS page, blog article | |
| BlogPosting JSON-LD | Title, author name and bio, featured image, publish and update dates, Meta description | No description fallback |
| `<title>` | Meta title, else name or title; template `{title} \| Anaiza Nest`; home is `Anaiza Nest \| {Tagline}` | Aim for under 60 characters; the admin caps Meta title at 70 |
| `<meta description>` | Meta description (product: else Short description; category: else Summary; others: none) | Aim for 120 to 155; capped at 160 (blog and landing: 255). If a page sets none, the layout's description applies: **footer About blurb** (the built HTML shows this for pages that set a description of their own; verify for one that sets none). |
| og:title, og:description | Product, category, page, blog and landing set their own. **Shop, FAQ, contact, gift finder, corporate gifting, blog list and special prices do not**, so a shared link shows the homepage's title and description (verified in the built HTML). | |
| og:image | Product: social share image, else first gallery image. Category: desktop banner. Page: the URL typed. Blog: social share image, else featured image. Landing: "Picture when shared". Home and the rest: **none** | 1200 x 630 is the usual target (advice) |

---

## 15. Reviews (what the customer sees)

Customers write reviews; the admin only approves, hides or replies (Engagement > Reviews).

| Field | Who enters it | Where it appears | Req. | Limit |
| --- | --- | --- | --- | --- |
| Rating | Customer | Stars and the average beside the product name; JSON-LD aggregateRating and review | Yes | 1 to 5 |
| Title | Customer | Review heading | No | max 120 |
| Review | Customer | Review body (line breaks kept) | Yes | min 10, max 2000 characters |
| Shown as (name) | Customer | Under the review, with the date | Yes | max 80 |
| Order number + phone, or a signed review link, or a signed-in account | Customer | Proves the purchase; "Verified purchase" chip | One of them | order number max 20, phone max 30 |
| Photos | Customer | Row of 80 px square thumbnails under the text, each opening the full photo in a new tab | No | up to 3; JPG, JPEG, PNG or WebP; 3 MB each |
| Reply | Admin | "Reply from Anaiza Nest" box under the review | No | max 1000 |
| Status | Admin | Only approved reviews show; the admin list badge counts pending ones | | Pending / Approved / (hidden) |

| Image | What the code does | Shown at | Alt |
| --- | --- | --- | --- |
| Review photo | Always converted to WebP: full copy capped at 1000 px wide, thumbnail 400 px wide; the original is never kept | Thumbnail 80 x 80 px square (`object-cover`, centre) on every screen; the full copy opens in a new tab | `alt=""` |

No reviews: the product page says "No reviews yet. If you have ordered this piece, we would love to hear how it was." and shows no stars, no count and no rating JSON-LD. The landing page Reviews block is hidden.

---

## 16. Other admin screens that exist but do not put content on the storefront

| Screen | Status |
| --- | --- |
| Testimonials (Engagement) | **Not displayed.** The API and a `getTestimonials()` function exist, but no storefront component uses them. "Featured on homepage" does nothing visible. verify before promising testimonials. |
| Brands (Catalog): logo | Name appears in JSON-LD and feeds only. The logo upload (`brands`, any image) is not displayed. |
| Product tags, labels (colour), attributes | Not displayed (4.1). |
| Media library | Upload and keep files (JPG, PNG, WebP, GIF, PDF; 8 MB). The storefront does not read it; it is for copying addresses. |
| Coupons, Cart recovery, Review requests, Integrations (social login), Blocked buyers, Contact submissions, Corporate enquiries, Newsletter subscribers, Customers, Orders, Admin users, Roles | Operations or inbox screens, not storefront content. Review requests and Cart recovery carry timing switches and one WhatsApp message each, sent from the admin, not shown on the site. |

---

# Cross-cutting lists

## A. Text hardcoded in the storefront (not editable from the admin)

Not exhaustive of every error message; it lists what a visitor reads as page content. Source file in brackets.

| Where | Text |
| --- | --- |
| Header (`Header.tsx`, `MainNav.tsx`, `SearchBar.tsx`) | Search placeholder "Search for tea sets, ceramics, gift boxes..."; the dropdown heading "Categories"; icon labels "Account", "Wishlist", "Cart" |
| Promo bar (`TopPromoBar.tsx`) | "Track order", "Log in / Sign up" |
| Footer (`Footer.tsx`) | "Get in touch"; payment chips "bKash", "Nagad", "Rocket", "Cash on delivery"; "Cookie settings"; fallback address "Dhaka, Bangladesh" |
| Homepage hero (`Hero.tsx`) | The italic line "For the people you'd pour a second cup for."; buttons "Shop tea sets", "Find the right gift"; tile titles "Special prices", "New arrivals" |
| Trust strip (`TrustBadges.tsx`) | "Gift-ready / Beautiful to open, safe to send.", "Packed by hand / Sent with care.", "Cash on delivery / Pay when your order arrives.", "Delivery" (the days text is built from settings) |
| Homepage (`HomeClient.tsx`, `page.tsx`) | Defaults in 2.2; error "We couldn't load the collection just now. Please refresh in a moment." |
| Product listing (`shop/page.tsx`, `ProductListing.tsx`, `SortDropdown.tsx`, `PriceFilterPanel.tsx`) | Page title "All gifts" and description "Browse the full Anaiza Nest gift catalog."; "Sort:" with "Most relevant", "Newest", "Price: low to high", "Price: high to low"; fixed price bands "Under ৳1,500", "৳1,500 – ৳3,000", "৳3,000 – ৳6,000", "Above ৳6,000" |
| Special prices page (`hot-deals/page.tsx`) | `<title>` "Special prices", description "Selected Anaiza Nest pieces at lower prices.", heading "Special prices", "Selected pieces at lower prices." |
| Product page (`ProductDetail.tsx`, `AddToCartForm.tsx`, `ProductSpecs.tsx`) | "SKU:", "In stock" / "Out of stock", "Save {n}%", ", gift box included", "Special price", "New", "Options", "Add to cart", "Order now, pay on delivery" (or "Order now"), "Pay with bKash, Nagad, Rocket or cash on delivery.", "Delivery info" + its sentence (section 13), "Description", "Read more" / "Read less", "What is in the box?", "Specifications", "How do I care for it?", "Frequently asked questions", "You may also like", "Gift-ready" seal, "Play product video" |
| Category page (`CategoryDetail.tsx`) | "About {name}", "Frequently Asked Questions", "Read more", "Nothing in this category just yet", "We are still setting this category out. Please check back soon, or have a look at the rest of the collection.", "See all gifts" |
| Reviews (`ProductReviews.tsx`) | "Reviews", "Verified purchase", "Reply from Anaiza Nest", "No reviews yet. …", "Write a review", the form labels and error messages |
| Gift finder (`GiftFinderQuiz.tsx`) | Heading "Find the right gift", "Not sure what to give?", "Three quick questions, then a few thoughtful ideas.", recipients (A close friend, A family elder, Partner or spouse, Colleague or boss, A new baby), occasions (Wedding, Housewarming, Birthday, Just because), budgets (Under ৳1,500, ৳1,500 – ৳3,000, ৳3,000 – ৳5,000, No limit). **The quiz answers do not filter by recipient or occasion; only the budget filters products** (verify) |
| Contact (`contact/page.tsx`, `ContactForm.tsx`, `ContactDetails.tsx`) | "Get in touch", "Questions about an order or a gift, or a corporate quotation? Send us a message and we will help.", form labels, "Thank you. We have your message and will reply soon.", "Phone", "WhatsApp", "Message us on WhatsApp", "Email", "Address"; `<title>` "Contact us" and its description |
| Corporate gifting (`corporate-gifting/page.tsx`, `CorporateEnquiryForm.tsx`) | `<title>` "Corporate gifting" and description; heading; all form labels; button "Ask for a corporate quotation" |
| FAQ page (`faq/page.tsx`) | `<title>` "FAQs", description "Answers to common questions about ordering, shipping, and returns.", heading "Frequently asked questions", the empty text |
| Blog (`blog/page.tsx`, `BlogDetail.tsx`) | "Blog" and its description, "By {author}", "About the author", "In this article" |
| Cart, checkout, order confirmation, tracking, wishlist, account | "Your cart", "Your cart is empty", "Browse the collection and find something worth keeping.", "Order summary", "Subtotal", "Delivery", "Total", "Shipping details", "Payment method", every form label and validation message, "Order received" / "Order confirmed", "Thank you, {first name}.", "Track your order", "Your wishlist", "Tap the heart on anything you would like to keep.", the whole account / login / register / reset flow |
| Not found (`not-found.tsx`) | "We couldn't find that page" and its text |
| Cookie banner (`CookieConsent.tsx`) | The three category names and descriptions (section 10) |
| Popups (`PopupManager.tsx`) | Gift finder popup text (section 9) |
| Landing pages (`LandingPageClient.tsx`, `LandingBlocks.tsx`) | "This offer has ended", "Thank you for stopping by. The gifts are still waiting in the shop.", "See the shop", "We could not find that page", "Preview. Only people with this link can see this page…", "Sold out", "Choose a gift above and it will appear here, ready to order.", block default headings listed in 8.2 |
| Brand constants (`brand.ts`) | Brand kit wording: name, tagline, one-liner, boilerplate, "Order now, pay on delivery", "Find the right gift", "Shop tea sets", "Send a gift", "Ask for a corporate quotation"; the Bangla line "যত্নে সাজানো উপহার।" |
| Order and email (not storefront) | The unsubscribe sentence in emails, the invoice's headings and wording |

## B. Fields that feeds, structured data and SEO read, with the limits they need

See 14.2 and 14.3. In one list, the limits that matter most when writing content:

| Field | Needed by | Limit that matters |
| --- | --- | --- |
| Product Name | `<h1>`, feed title, JSON-LD | Feed cuts at 150; admin caps at 100; search results show about 60 |
| Summary / Short description / Description | Feed description; JSON-LD uses Short description only | Feeds cut at 5,000, plain text, no emoji; Short description max 200 |
| Meta title | `<title>`, og:title | Admin max 70; aim for 50 to 60 |
| Meta description | `<meta>`, og:description | Admin max 160 (blog and landing 255); aim for 120 to 155 |
| First product image | Card, gallery, JSON-LD image, og:image fallback, feed image | At least 800 x 800; feeds need 500 px on the short side and make a 1024 to 2000 px JPEG |
| Product SKU | Feed id, pixel content id, JSON-LD sku | Unique and stable: the feed id and the pixel id must match for dynamic ads |
| GTIN, MPN | Feeds, JSON-LD | GTIN 8, 12, 13 or 14 digits |
| Brand | Feeds, JSON-LD | Else the shop name; max 100 in feeds |
| Category Google product category, Product type | Feeds | Text or number; product type max 750 |
| Specifications "Material" and "Colour" labels | JSON-LD `material`, `color` | Label must be exactly "Material", "Colour" or "Color" (any case) |
| Delivery & returns numbers | JSON-LD shipping and return policy, checkout | Whole numbers |
| FAQ Q and A | FAQPage JSON-LD | Q max 120, A max 500 on products and categories |
| Contact phone, email, address; social links | Organization JSON-LD | |

## C. Fields needed for the site to look right that an editor could easily leave empty

| Field | What goes wrong when empty |
| --- | --- |
| Product images (and a main image with the lowest sort order) | Cards and the gallery show a linen tile with the words "Anaiza Nest"; JSON-LD has no image; the feed item has no `image_link` and Meta and TikTok will not list it |
| Product Short description | No line under the price; Product JSON-LD has no description; no description fallback for the meta description when Meta description is also empty |
| Product Summary | Nothing under the title; the feed falls back to Short description or the long Description |
| Product Meta description | Falls back to Short description; if both are empty the site-wide footer blurb is the description |
| Product video **poster** | The video is hidden entirely, though a YouTube URL or MP4 is saved |
| Variant Image | Choosing an option does not change the gallery |
| Category banner (desktop) | No banner area and no social image for the category; a mobile image alone is used on both sizes |
| Category Summary / intro | No sentence under the H1; no meta description fallback; blank `llms.txt` line |
| Homepage: no live slide | Only the hero panel and its wording; no error |
| Site settings: logos | Header shows the site name in type; footer shows the name (no ivory logo means no reuse of navy); emails and invoices fall back as in 1.1 |
| Site settings: Contact phone / email / address | Phone link, WhatsApp fallback, footer lines and Organization JSON-LD contact details disappear |
| Site settings: WhatsApp number and Contact phone both empty | No bubble and no WhatsApp links anywhere |
| Site settings: Social links | No footer icons; no `sameAs` |
| Payment settings: every wallet number blank and Cash on Delivery off | Checkout says "There is no way to pay online just now." |
| Pages: Meta description | No page-specific description; shared links use the home page's text |
| Blog: Featured image | Card shows the words "Anaiza Nest" on a tile; no social image fallback; Social share image also empty means no og:image |
| Blog: Meta description | No description meta and no JSON-LD description |
| Blog: Author name | No byline and no author in JSON-LD |
| Cookie consent: Privacy policy page | The banner has no privacy link |
| Landing page: Description and Picture when shared | Site-wide description; no og:image |
| Landing page: Hero headline | The page title is used as the headline |
| Landing page: no Order form block | Product buttons send the visitor to `/checkout`, the normal page |
| Landing page: Closing time with a Countdown block | The countdown block is not shown |
| Email templates: Use my wording off or fields blank | Built-in wording is sent (not an error) |
| Delivery & returns, Return window | Set to 0 states "returns not permitted" in structured data |

## D. Images shown at different ratios in different places

| Image | Where and at what ratio | What to do when uploading |
| --- | --- | --- |
| **Product image** | Square 1:1 on cards, gallery, thumbnails, cart, wishlist and landing product tiles (centre crop). og:image (when no social share image): the first gallery image at its own ratio, a WebP file. Product JSON-LD: the same file. Feeds: a JPEG at the original ratio, 1024 to 2000 px long side. | Upload square, subject centred. Add a separate **Social share image** at 1200 x 630 if the product is shared. |
| **Category desktop banner** | 4:1 on the category page (640 px and up); the **same file** is the only social image for the category (a 4:1 strip, which platforms crop awkwardly) | Keep the subject centred; accept that social previews crop the strip |
| **Category mobile banner** | 3:2 under 640 px; auto-made from the middle of the desktop banner when not uploaded | Upload one when the subject is off-centre |
| **Slider desktop image** | 12:5 at up to 1,280 px wide from 640 px up | Design for 12:5; leave safe space; text is drawn bottom-left over a dark gradient |
| **Slider mobile image** | 3:2 under 640 px, full width; auto 750 x 500 centre crop | Same |
| **Blog featured image** | 16:10 on cards, 16:9 on the article, then the og:image fallback at its own ratio | Keep the subject central and away from top and bottom edges |
| **Occasion tile picture** | 4:3 at 170 px to about 300 px wide | 600 x 450 |
| **Logos** | 28 px high in header and footer, 40 px in emails, 48 px on the invoice, raw file in JSON-LD | One wide master, high resolution; the code never redraws it |
| **Landing hero** | Full-bleed, minimum 416 px (phones) or 480 px (wider) high, covering from the centre; so the visible ratio depends on the screen | Upload separate desktop and mobile pictures with the subject in the middle third |
| **Popup image** | A 160 px high band, about 2.2:1 to 2.8:1 | Wide and short; keep the subject central |
| **Review photo** | 80 x 80 thumbnail (square crop from the centre) and the full photo in a new tab | Customer-supplied; nothing to do |

---

## 17. Disagreements between the admin's own text and the code

These were found while writing the inventory. Each is a candidate fix, not a change made by this document.

| # | Where | What the admin says | What the code does |
| --- | --- | --- | --- |
| 1 | Products > Images > Alt text ("Describe the image for accessibility and SEO.") | Used for accessibility and SEO | The storefront gallery, cards, cart and JSON-LD ignore it and use the product name. Only the landing page product block reads it. |
| 2 | Categories > Tile / thumbnail ("Used in nav/category grids elsewhere on the site.") | Used in grids | No storefront component displays it. |
| 3 | Categories > "Show in the storefront Categories menu" ("…changes appear after the next storefront build") | Needs a rebuild | The header refreshes its category list on load, so the menu follows within a page load. A new category's own page still needs the build or the shell. |
| 4 | Banners > Slider settings saved notification ("a change shows after the next storefront build") | Needs a rebuild | The homepage fetches the slides and the slider settings on load; autoplay and interval follow without a rebuild. |
| 5 | Site settings > Brand description ("…the default meta description…") | Used as the default meta description | The default meta description is the footer About blurb (then the kit one-liner). The Brand description feeds JSON-LD and `llms.txt` only. |
| 6 | Site settings > Hero headline placeholder "Handcrafted tea sets & gifts, done right." | A hint of the default | The default shown when empty is "Tea sets and gifts, packed by hand." |
| 7 | Site settings > Special prices tile line placeholder "Special prices, while stock lasts" | A hint of the default | The default shown when empty is "Selected pieces at lower prices". |
| 8 | Site settings > Return window | "Delivery & returns… every place the storefront quotes returns" | Only structured data uses it; no on-page text is built from it. |
| 9 | Sale price ("Set this lower than the regular price…") | Should be lower | Not enforced; a sale price at or above the price is silently ignored. |
| 10 | Shipping zones > "Estimated Delivery" | Reads as customer-facing | Not shown on the storefront; only Site settings delivery days are. |
| 11 | Testimonials > "Featured on homepage" | Shows on the homepage | No component displays testimonials. |
| 12 | `->image()` fields | "Image" | Accepts SVG on every field without an explicit type list (categories, banners, logos, popups, blog, landing, brands, testimonials). The media library, product images, occasion tiles and payment logos are restricted. |
| 13 | Share previews | | Shop, FAQ, contact, gift finder, corporate gifting, blog list and special prices pages have no og:title or og:description of their own, so a shared link shows the homepage's. The homepage and these pages have no og:image. |
