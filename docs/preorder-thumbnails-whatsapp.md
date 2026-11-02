# Pre-order status, product pictures in the admin list, the WhatsApp confirm text (OPS3-B)

## Product pictures on the admin Products list

The list had **no picture column at all**: a product has no picture of its own, its pictures are rows of its gallery (`product_images`: the original and 400 / 800 px
copies), and the list never read them. Nothing was wrong with the files or with `/storage` (the storefront serves the same files from `api.anaizanest.com/storage`).
No server step is needed.

`App\Support\ProductThumbnail` gives the picture: the main one (the gallery's first by position), then the rest of the gallery, then a variant's picture, then a
neutral tile. Each picture gives its smallest copy (400, 800, then the original); a copy whose file is gone from the disk is skipped for the next, and another
server's address is used as it is. The list column is 48 px, square, 2 px corners, lazy-loaded; the phone card and the best-sellers table use the same helper.
The other admin lists with pictures (categories, banners, brands, articles, testimonials, the media library, the gallery and variant tables, the order Items table)
already read the `public` disk and have no code fault; the order Items table got its picture in OPS1.

## The Pre-order status (`preorder`, "Pre-order")

Set by a person only (the order page, the bulk "Change status"); nothing sets it, and checkout is unchanged. It sits before Pending.

Where statuses live, and what was done:

| Place | Done |
|---|---|
| `OrderResource::STATUSES` (admin options, filter, bulk change, the Orders list status column) | added first |
| Badge colours: Orders list, phone card, a customer's orders table | new colour `preorder` (violet, registered in `AdminPanelProvider`) |
| Orders list tabs | new "Pre-orders" tab with a count |
| API order answer (`Http\Resources\OrderResource`) | `status` is `preorder`; new `status_label` ("Pre-order") for every status |
| `CourierBooking::preview/book` (Send to courier, bulk, automatic booking) | blocked: "Move this order to Processing first." |
| `CourierStatusApplier` (webhook, status check, Sync now) | a report about a Pre-order changes nothing and is written in its Courier history |
| `CourierSync` (status check), `CourierSync::one`, `couriers:preview-effects` | a Pre-order is not followed or listed |
| System health "Pending after a day" | counts `pending` only: already excluded |
| Dashboard "Total Sales / Today's Sales" | count Processing, Shipped, Delivered only: a Pre-order is not a sale yet (it is in "Total Orders") |
| Customer emails (`TemplateDefinitions::STATUS_TEMPLATES`) | only shipped, delivered, cancelled: the switch to or from Pre-order sends nothing |
| Stock | untouched by any status |
| Legacy importer (`TARGET_STATUSES`) | unchanged (old-site history only) |

**Purchase tracking (reported, not changed):** the Purchase events (the storefront's GA4 and Meta pixel on the order confirmation page, and the server's Meta and
TikTok conversion events in `ConversionEvents`) fire when the order is **created** at checkout, whatever it is called later. Setting Pre-order afterwards does not
send or cancel anything. The retry command and "Resend" in the admin do not look at the order's status. So a Pre-order has already counted as a Purchase.

## The WhatsApp confirm text

The sentence was in one place only: `App\Support\System\ShopAlerts` (the "New order" email to the shop's own address, its link "Confirm with the customer on
WhatsApp"). It now ends `May we confirm the order Sir` (no full stop). It is not stored in the database, so no migration and nothing to edit in the admin. The link
is built by `App\Support\WhatsApp::link`, which URL-encodes the whole message. The sentence is in no API answer. (Site settings > Shop alerts has a help line that
says "a WhatsApp link to confirm the address"; it describes the link, not the message, and was left alone.)
