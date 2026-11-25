=== Kontor Shop – Club Shop with Bank Transfer & Pickup ===
Contributors: veyraassistant
Tags: club shop, shop, bank transfer, stock, association
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.9.15
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The club shop for WordPress: products with sizes, stock, bank-transfer checkout, pickup and a simple cashier workflow. No payment provider needed.

== Description ==

Kontor Shop is a lean shop for clubs and associations. It covers what a club shop needs and leaves out what it does not: no payment provider contract, no transaction fees, no extension maze.

Members and guests order online, pay by bank transfer or in cash on pickup, and your treasurer marks orders as paid with one click. Whoever packs the parcels sees exactly what to ship.

= What you get =

* **Products with variants:** sizes, colours, pictures, categories and search, each product with its own page and address.
* **Cart and checkout:** guest orders without an account, stock is reserved while the customer checks out.
* **Payment without a provider:** bank transfer (prepayment), on invoice and cash on pickup – each can be switched on separately. Your bank details appear in the confirmation and the e-mail, with a QR code for banking apps.
* **Shipping:** flat rate, free shipping above an amount, self pickup.
* **Stock:** one warehouse with a full stock ledger, so every change can be traced.
* **Invoices:** PDF invoices and credit notes with continuous numbers, sent to the customer automatically.
* **Orders:** clear status workflow (open, paid, shipped, picked up, cancelled) and a cashier role that can mark payments.
* **E-mails:** editable templates for every step. Mails go through WordPress, so any SMTP plugin (WP Mail SMTP, FluentSMTP, Post SMTP …) works automatically. Undelivered mails wait in an outbox and are retried automatically.
* **Legal and privacy:** legal notice block in checkout, spam protection without captcha, personal data export and erasure through the WordPress privacy tools.
* **Blocks:** Shop, Cart, Checkout and Cart icon as blocks for the block editor (shortcodes still work).
* **Design:** picks up your theme colours, fonts and dark backgrounds; adjust style, colours, corners, columns and image format with a live preview – no code needed. Shop pages are created on activation. Works with classic and block themes.
* **Help built in:** a help centre with step-by-step guides for volunteers who rarely use WordPress.

= Kontor Shop Pro =

Pro adds what growing clubs need: member prices, several warehouses with their own warehouse managers, packing view on the phone straight from the order mail with partial shipments, discount codes and campaigns, back-in-stock notifications, automatic payment reminders and cancellation of unpaid orders, CSV export, reorder hints, a dashboard with key figures and alarm mail, a tamper-proof change log, bank statement matching (camt.053), group orders with a size list for the supplier, a cashier API, search-engine extras (structured product data, category pages) – and AI assistants for the whole team: create products with images, book stock and process orders by chat, each person with their own permissions. Pro shows no “powered by” line.

More at [veyra-assistant.com/kontor-shop](https://veyra-assistant.com/kontor-shop/).

= Languages =

English and German (Germany, Austria, Switzerland) are included.

== Installation ==

1. Install the plugin from the WordPress plugin directory and activate it.
2. Kontor Shop creates the pages "Shop", "Cart" and "Checkout".
3. Open **Kontor Shop → Settings** and enter your bank details under *Payment*.
4. Add your first products under **Kontor Shop → Products**.
5. Send a test mail under *Settings → System*. If it does not arrive, install an SMTP plugin.

== Frequently Asked Questions ==

= Do I need a payment provider? =

No. Customers pay by bank transfer or in cash on pickup. Your treasurer marks the order as paid in the back office.

= Do I need WooCommerce? =

No. Kontor Shop is standalone and does not depend on other shop plugins.

= Why do my order e-mails land in spam? =

Without an SMTP plugin, your web server sends the mails itself, which many mail providers distrust. Install an SMTP plugin such as WP Mail SMTP or FluentSMTP and connect it to your club's mailbox. Kontor Shop detects it and shows the status under *Settings → E-mail*.

= What happens if an e-mail cannot be sent? =

It goes to the outbox and is retried after 5 minutes, 30 minutes, 2, 6 and 24 hours. The dashboard warns you, and you can resend it by hand.

= Can I place the shop in my theme? =

Yes. Use the blocks or the shortcodes `[kontor_shop_catalog]`, `[kontor_shop_cart]`, `[kontor_shop_checkout]` and `[kontor_shop_cart_icon]`.

= Can AI assistants such as ChatGPT, Claude or Gemini use my shop? =

Yes. Under Kontor Shop → Settings → AI assistants you can switch on a public MCP server address (Model Context Protocol). Customers add it to their AI app as a connector; the assistant then searches your products and sees regular prices and availability – only what any visitor sees on the website. It is switched off by default. In Pro, the assistant can also prepare the cart and give the customer a ready checkout link (the customer always places the order themselves), and your team can manage the shop by chat with personal access keys.

= What happens to my data when I delete the plugin? =

You choose: keep all data (default) or remove everything. The choice appears when you click "Delete" in the plugin list.

== External services ==

This plugin uses the Freemius SDK for licensing, updates of the Pro version and, only if you agree, anonymous usage data.

* What is sent: if you opt in, your site URL, WordPress and PHP version, plugin version and the admin e-mail address. If you skip the opt-in, none of this is sent.
* When: on opt-in, on activation and deactivation of the plugin, and when you activate a Pro licence.
* Plans and prices: when you open the Upgrade page or, in the free version, Kontor Shop → AI assistant, your site asks Freemius for the current plans and prices (cached for 12 hours). No shop, customer or usage data is sent with it.
* Service: Freemius, Inc. – [Terms of Service](https://freemius.com/terms/), [Privacy Policy](https://freemius.com/privacy/).

Kontor Shop does not contact any other external service. Shop data, orders and customer data stay in your WordPress database. The optional AI assistant access (MCP) works the other way round: AI apps chosen by you or your customers call your shop; the plugin itself sends nothing to them.

== Third-party libraries ==

PDF invoices are created with [Dompdf](https://github.com/dompdf/dompdf) 3.1.6 (LGPL-2.1) and its dependencies php-font-lib and php-svg-lib (LGPL), html5-php and php-css-parser (MIT), bundled in vendor/dompdf-bundle. Only the built-in PDF fonts are included.

== Screenshots ==

1. Shop on a phone: products with pictures, sizes and category filter – here in the Twenty Twenty-Five theme.
2. Checkout with self pickup and prepayment by bank transfer.
3. Order confirmation with bank details and a QR code for banking apps.
4. Orders in the back office: what is open, overdue or ready to ship – and the next step for each order.
5. Stock ledger: every movement of a product with date, reason and person.
6. E-mail templates with placeholders for every step.
7. Payment settings: bank accounts, payment methods and QR code.

== Changelog ==

= 2.9.15 =
* New (Pro): each order remembers which member account was logged in (name, membership number). If name or email on the order differ from that member's profile – e.g. a member buys at member prices for someone else – the order list shows "Bought for someone else?" and the order shows the differences (field, member profile, order). Nothing is blocked; it's just for your information.
* New (Pro): the cashier interface and the AI order tools also report the member account and the differences.
* Changed: membership providers can pass the person's ID in their member management (MemberInfo::$member_id).

= 2.9.14 =
* New: shipping prices for domestic and abroad. Your domestic country gets its own price, all other shipping countries another one ("Abroad" left empty = same as domestic). Checkout switches the shipping price as soon as the customer picks a country; the product page shows both, e.g. "Shipping to Austria: €5.90, to Germany: €12".
* New (Pro): member prices for shipping, domestic and abroad, in a "Members" column (empty = regular price, 0 = free). The former switch "Members don't pay shipping" becomes €0 in both fields – nothing changes without being asked.
* New: Settings → Shipping redesigned in steps: "Where do you deliver?" (domestic country, foreign countries as chips, "All EU countries" in one click, self pickup), "What does shipping cost?" with a live preview of what guests and members pay and of the product page line, and (Pro) "Delivery time & returns" on the same page with one Save button – its country is now your domestic country, no code to type.
* New: "Free shipping from" is switched on with a tick and can be limited to domestic orders ("also applies abroad").
* New: Settings → Checkout. Phone number, billing address and the order status link moved there from Shipping.
* Changed: "Several warehouses with their own account" is collapsed while you only have one bank account.

= 2.9.13 =
* New: sold-out products no longer break the row. On "Label" cards they keep the same buy row as all others – on the left quietly "3 variants", on the right (Pro) a slim "Notify me" button; on phones only the bell. The red "Currently unavailable" line and the large two-line button are gone; the photo is slightly faded, the price tag grey, and the "Sold out" badge stays.
* New (Pro): "Notify me when available" opens a window over the page instead of unfolding a long form inside the card (on phones it slides up from the bottom). It shows the product, says "As soon as it's back, we'll let you know.", offers the variants as buttons ("Any" preselected), asks for the email address and folds name, quantity and message away. Afterwards the same window confirms: "Almost done!" with the note about the confirmation email (double opt-in as before). The browser remembers the address for the next request. The product page uses the same button and window.
* New: "Label" cards can show the first two lines of the description under the name (Settings → Design → Product cards, on by default, hidden on phones). The space is always two lines high, so all cards in a row stay the same height.
* New: the product list warns when a product is switched to visible but appears nowhere in the shop because its category (or, with Pro, its shop type) is switched off – "Not in the shop: the category is switched off".
* Fix: 17 German texts were written with "ae/oe/ue" instead of umlauts, among them "Derzeit nicht verfuegbar", "Warenkorb oeffnen", "Hinzugefuegt" and parts of the privacy policy proposal. They now use proper umlauts in all German language versions.

= 2.9.12 =
* New: product pages in the style of the "Label" cards. The product name now always appears as a heading next to the photo, with the category above it – many themes show no page title on shop pages, so the name was missing completely (also for search engines). If your theme shows the name itself and it now appears twice, choose Settings → Design → "Product name on product pages" → theme.
* New: on product pages the price hangs as a tag next to "In stock", the short description comes first, and sizes are large buttons instead of a selection list. The size a customer chose last time is already selected ("Your size S is already selected"). Sold-out sizes are struck through; choosing one shows "Notify me when available" as before. Quantity has − and + buttons, and the button says what happens: "Add S to cart". After adding, a line with "Go to cart" appears.
* New: three short info lines under the button, taken from your settings: shipping (flat rate, "free from" amount, countries), self pickup, and payment methods. Only what is set up appears; the exact shipping cost is still calculated at checkout. Pro adds "members ship for free" and "on invoice (members only)" where set.
* New: "More from <category>" below the product with up to four cards from the same category, available products first.
* New: on phones a bar with price and button stays at the bottom while the button above is out of view. Without a chosen size it jumps to the sizes.
* Fix: photos on white looked small on cards and product pages. The AI photos are landscape, and the product covers only about 40 % of the width; shown whole, a shirt filled less than half the frame. The shop now measures once per photo where the product is and zooms in so it fills about 85 % of the frame. A pale grey frame left around some AI photos no longer shows. No new photos are needed.
* Fix (Pro): a member's price in the price tag was green on green and could not be read. It now uses the tag's text colour.

= 2.9.11 =
* New: product cards in the style "Label" (Settings → Design → Product cards, now the default). The price hangs as a tag on the photo, the category is a small line, the name has at most two lines. Sizes are buttons right on the card: one tap puts exactly that size in the cart, sold-out sizes are struck through, and what doesn't fit in the row opens with "+2" above the photo. The shop remembers the size a customer chose (in their browser, letter and number sizes separately) and highlights it on all cards. Products without variants get a round "+" button; products with several options (e.g. size and colour) open the usual selection above the photo. On phones, two cards sit side by side. "Classic" keeps the previous layout.
* New: same height for all cards in a row. Every photo gets the same frame (portrait 4:5 for "Label" unless another image format is set). Photos on white are recognised automatically and shown whole on the sand-coloured stage; photos with a real background fill the frame.
* Fix: portrait photos stretched the image frame of their card ("Classic" layout), so the whole row became taller and neighbouring cards showed large empty areas. The frame now keeps its format.
* Fix: product pages and category pages (Pro) now use the colour of the shop type (or of the category, if it has its own) for buttons, "In stock" and links – like the catalog already did. Before, they fell back to the shop's primary colour.
* Fix (Pro, product photos): white and light parts of a product – a white hanger, a light cap brim, a white collar – no longer disappear when the shop turns a light grey paper or wall background white. The shop now compares each part with the brightness of the background at the same spot and keeps everything that is brighter; the background around and between (for example inside a hanger) still becomes pure white.
* Change (Pro, AI assistant): the assistant no longer cuts products out itself. When ChatGPT "prepared" photos with its code tool, it removed hands and backgrounds with a crude colour mask – edges came out frayed, caps looked bitten and hangers were gone. Now it may only straighten, crop and gently correct exposure; the shop makes a plain light background white itself. If the background is busy or a hand holds the product, the assistant asks for a new photo on white paper or a white wall, or keeps the original. Photos already damaged stay as they are – replace them with a new photo. After the update, refresh the shop app in ChatGPT once (Settings → Apps).
* Fix: the "CSV download" button in the "Export inventory list" window failed every time with "Export failed". The script read the wrong address from the form; the "Export CSV" link next to it was not affected.

= 2.9.10 =
* AI assistant (Pro): photo window in ChatGPT. When ChatGPT hands over only the internal number of a photo instead of the photo itself (seen with attached photos, not only with generated images), the shop no longer stops with an error: a small window appears in the chat, fetches the photo through ChatGPT's official file function, saves it to the product and shows it with a preview. If that is not possible, the photo can be picked right in the window. Photos the shop needs to check first are shown with the reason, and the assistant asks how to continue. After the update, refresh the shop app in ChatGPT once (Settings → Apps).
* AI assistant: the access list (Kontor Shop → AI assistant) now shows when the assistant last loaded the tool list and for which version – so you can see whether "refresh" in ChatGPT took effect.

= 2.9.9 =
* Fix (Pro, AI assistant): photos from the chat now reach the shop as files. ChatGPT sometimes sent only the internal number of an attached photo instead of the photo itself, so the upload failed with a general error. The image field now follows OpenAI's file format exactly. After the update, refresh the shop app in ChatGPT once (or start a new chat). If only a number still arrives – for example for an image ChatGPT generated itself – the assistant says so right away and offers the working ways: pass the original photo, attach the image to the chat again, or upload it in the browser. The shop log names what arrived instead of the file.
* Fix: "per page" in the product list and in stock requests had no effect. The selection sat inside the form for bulk actions, so changing it sent that form instead of loading the list with the new number. It now loads the list directly (filters and sorting are kept, back to page 1, the choice is remembered). Orders and stock were not affected.

= 2.9.8 =
* AI assistant (Pro): stock lists as an Excel file. Ask for example "send me the count sheet for warehouse Regular" and the assistant answers with a download link – it opens in the browser, also on the phone, without logging in to the shop. Two lists: the count sheet for a stock count (expected quantity on the shelf, an empty "Counted" column, signature line, prints landscape on page width with the header on every page) and the stock list (stock, minimum stock, sales of the last 30 days, days left, status). Filter by warehouse and category.
* The link is valid for 15 minutes and only works with the rights of the person who asked for it: warehouse managers get only their own warehouses, and someone who no longer has stock rights gets nothing. The file is made when the link is opened, with the stock of that moment. The CSV downloads in the stock pages stay as they are.

= 2.9.7 =
* AI assistant (Pro): merge products into variants. Single products that are only versions of one product (for example "Sticker – small", "– medium", "– large" or a scarf in three colours) can be combined into one product with variants in the chat. Each variant keeps its price, stock, product number, EAN, weight and photo; the old products are archived with their history, and open orders are completed as before. Nothing changes before the user confirms the plan.
* AI assistant (Pro): when importing a product list, rows that look like versions of one product (same name, different size, colour or version) are suggested as one product with variants. The user decides per group; different motifs stay single products unless the user wants otherwise.
* AI assistant (Pro): a photo can be set for a single variant ("this is the photo for the large one"). The shop shows it when the customer picks that variant.
* AI assistant (Pro): on request the shop builds a product image from the real variant photos as a modern group shot – the products cut out, slightly overlapping, the middle one in front, with soft shadows, in the shop image format. Nothing is redrawn, so print and lettering stay exact. It is only set as main image after the user has seen the preview and agreed.
* AI assistant (Pro): every product gets a product number. Products and variants the assistant creates (also from imported lists) get one automatically when none is given – category code plus running number, for example "AUF-001", variants with a suffix such as "AUF-001-GRO"; a shop that already uses plain numbers continues its own series. Existing products without a number can be numbered in one step after confirmation.
* Variants: two attribute rows with the same name (for example "Content" twice) are now merged into one attribute instead of the second being dropped silently when saving – in the product form and through the assistant.
* AI photos: when ChatGPT passes only a reference to an image from its image generator instead of the file, the shop now says clearly that this cannot work and offers the ways that do (attach the image again, upload link of the product) instead of asking to try again.

= 2.9.6 =
* Email templates: the sample values in the preview and in “Save & send test email to me” now read exactly like the real German emails – with umlauts (“Bitte überweise … bis spätestens …”), the reservation note after the payment deadline, and “Hinweis:” for return notes. Real emails were already correct; only the preview and test emails are affected.

= 2.9.5 =
* New: "Ready to go online". A hidden product counts as ready when it has a price, stock (with variants: an active variant in stock), a photo, a description and a category. Ready products are easy to forget – now the shop overview shows a "Ready to go online" tile (also in the WordPress dashboard widget) as soon as there are any, and the product list has a matching view with a short hint; switch them on one by one or with the bulk action "Make visible". Nothing goes online automatically.
* AI assistant (Pro): when a hidden product becomes complete through the last action (creating it, changing it, setting the photo, booking stock), the assistant asks once whether it may go online, and switches it visible only after a yes. "What is there to do?" lists ready products and those missing exactly one thing (for example only the photo). Only shop managers are asked, because only they may publish products.

= 2.9.4 =
* AI assistant (Pro), product photos: one clear rule set for ChatGPT and other assistants. "Upload this photo" passes the original; "prepare the photo for the shop" runs a fixed workflow – cut the real product out (never redraw it), pure white background, soft contact shadow only where it fits, keep shape, colour, logo, lettering and engravings unchanged, compare with the original before uploading, and ask for a new photo if that is not possible. Real edited image files are allowed; invented image data or file links are not. The browser upload is only the fallback.
* Uniform product images (Pro): photos on a plain light background are brought to the shop standard automatically – exactly 1600×1200 px at 4:3 (1200×1600 at 3:4, 1600×1600 at 1:1), product centred at about 82 % with at least 8 % margin, never stretched. A greyish, yellowish or vignetted light background becomes pure white like a studio photo; soft shadows stay, the product itself is not changed. Phone photos on white paper in indoor light are now accepted instead of rejected.
* Clearer results (Pro): setting a product image returns a preview link, the actual size, file type and file size, and reports "saved" only after checking that the image really is linked to the product (main image or gallery; the gallery is kept). Images from the shop's own media library are linked directly without downloading them again. An expired download link from the chat gets a clear message.
* AI assistant log (Pro and Free): every rejected AI tool call is written to the shop log (Kontor Shop → Log, level "warning", channel "agents") with tool, access, message and in which form an image arrived – without texts or download links. So a failed upload can be traced.

= 2.9.3 =
* Product images get clean names: when you save a product, images uploaded through the shop (product page or AI assistant) are titled after the product and its product number, for example "Club scarf (SCH-1)", gallery images "– image 2", variant images with the variant ("Hoodie M / Red (HD-1-M)"). The alternative text follows the product too. So you find every image in the media library by product name or product number. Titles you changed yourself in the media library stay as they are.
* New uploads also get a clean file name ("club-scarf-sch-1.jpg" instead of "IMG_4711.jpg"), including all image sizes. Images that are already online keep their address, so caches and search engines never point to missing images. Images chosen from the media library (logo, blog images) are never renamed.

= 2.9.2 =
* New (Pro): the AI assistant checks every product photo against the shop photo standard. What only concerns background, framing or the file is fixed right away – a transparent background becomes white, photos on a plain light background are framed to the shop's image format with the product centred, very large images are scaled down and PNGs are saved as JPEG. If a photo needs real editing (busy or dark background, product cut off at the edge, too small), it is not set yet: the assistant explains what is wrong and offers to rework it, asks for a better photo, or keeps it if you want.
* New (Pro): the AI assistant page shows which WordPress user each access belongs to; in the team list for administrators with user name and role.
* Better AI search: if a product search finds nothing, the shop suggests up to five products with similar names (for example "Schneidbrett" finds "Schneidebrett aus Holz", typos included), and the assistant asks which one is meant instead of guessing.
* The ChatGPT setup guide follows ChatGPT's new menu: custom MCP servers are now added under "Plugins" in the sidebar.

= 2.9.1 =
* Faster AI assistant access: product search, category lists and product pages load images and galleries for all products at once – the number of database queries no longer grows with the size of the catalog (a search now needs about 10 queries instead of one or more per product and image). The public category pages (Pro) benefit as well.
* Fewer steps for the AI assistant: a search with exactly one match (shop products, and in Pro back-office products and orders) returns the full details right away, so the assistant needs no second call. In Pro the assistant knows the user's roles from the start and no longer has to ask first.
* Smaller answers to the AI assistant (compact JSON, about 40 % shorter), which saves time and tokens on every call.
* The request limit of the AI access uses the object cache (Redis/Memcached) when available and otherwise writes to the database only once per request.

= 2.9.0 =
* New (Pro): the AI assistant takes a whole product list, for example an inventory list – it checks every row first (new product, already in the shop, or what is missing such as price, stock or category), shows the plan as a table, asks for the gaps, and only after your OK creates the categories and products (hidden) and counts the stock of existing products.
* New (Pro): the AI assistant can set product photos directly – photos you upload in the chat or edit there (for example with ChatGPT) are taken over, very large images are scaled to web size, named after the product and linked as main or gallery image. The assistant gets the shop’s photo standard (size, format, background) for editing.

= 2.8.0 =
* New: PDF invoices (Settings → Invoices) with continuous numbers per year, created when the payment is recorded, when the order is shipped or by hand. Customers get them by email as an attachment and on their order page; open invoices carry the bank details and the transfer QR code.
* New: cancellations and returns of invoiced goods create a credit note automatically; invoices are never changed.
* New (Pro): own number format (prefix, digits, yearly or continuous), club logo and footer on invoices.
* New (Pro): match the bank statement – upload the camt.053/052 file from online banking, the shop suggests the order for each incoming payment (payment reference, order number, amount, payer name), the cashier confirms, and the orders are booked as paid with the bank’s booking date. The file itself is not stored.
* New (Pro): group orders – a product such as club clothing can be ordered until a deadline, even without stock. The shop shows the deadline on the product; afterwards the back office lists per size how many pieces to order from the supplier (also as CSV).
* New for developers: filter kontor_shop/stock/free to adjust the quantity that can be ordered per product or variant.
* Fixed: order times, status history and stock ledger used the clock of the database server, which is often UTC – “Ordered on” could be one or two hours off. New entries now use the time zone of the website.
* Fixed: in themes with a narrow content column (e.g. Twenty Twenty-Five) the checkout squeezed the order summary next to the form; it now switches to two columns only when there is enough room.

= 2.4.0 =
* New: QR code for the bank transfer (“GiroCode”, EPC standard) on the order page – customers scan it with their banking app, and recipient, IBAN, amount and payment reference are filled in. The order email points to it. Can be switched off under Settings → Payment.
* Fixed: payment details and product lines in emails were German on every site – they now follow the site language.

= 2.3.0 =
* New: blocks for the block editor – “Shop” (with products per page and, with Pro, a shop area), “Cart”, “Checkout” and “Cart icon” in the block category “Kontor Shop”. They show the same as the shortcodes, which keep working.

= 2.2.0 =
* New (Pro): recognize members without an add-on – by WordPress user role (also works with Ultimate Member and other plugins that assign roles), Paid Memberships Pro or MemberPress. Members get member prices, free shipping and payment on invoice after logging in; their profile details are prefilled at checkout.

= 2.1.2 =
* Fixed: checkbox texts at checkout, the order button and the VAT exemption note were German on every site – they now follow the site language (your own texts stay as saved).
* Fixed: returns, warranty and delivery time on the product page (Pro) are shown in the site language.
* Fixed: products can only be deleted permanently from the archive, also via single links; bulk delete reports skipped products.
* Changed: error numbers start with ERR- so they can’t be mixed up with payment references (KS-…); older numbers stay searchable in the log.
* Improved: clearer labels – menu “Products” and “Warehouses”, “Cancel selected products”, “Back to stock overview”, account holder on the confirmation page, note what happens to products when a category is switched off, warranty note without the claim about search data.

= 2.1.1 =
* New: cash on pickup as a payment method (Settings → Payment, only shown with self pickup). The goods are set aside right away; recording the pickup books the cash payment too. No deadline, reminders or automatic cancellation.
* New: “Ready for pickup” button in pickup orders – the customer gets an email with what is ready and the warehouse address (field “Address and pickup times”).
* New: “Undo payment” for orders marked as paid by mistake (as long as nothing has been shipped).
* Changed (Pro): automatic payment reminders and auto-cancellation now start switched off – nothing is sent or cancelled until you switch it on under Settings → Payment.
* Changed: spam protection allows 20 orders per internet connection in 15 minutes (was 5) and is adjustable under Settings → Legal; logged-in customers count per account – no more blocked orders at club evenings on shared Wi-Fi.
* Fixed: deleting an order now books products that were not shipped back into stock.
* Fixed: bulk “Mark as paid” / “shipped” reports failed bookings with order number and reason instead of counting them as done.
* Fixed: cashiers without stock rights no longer see the “Stock” menu item; the order actions heading reads “Actions”.

= 2.1.0 =
* New: Help centre (Kontor Shop → Help) with step-by-step guides for every task – what a setting is for and what happens when you use it. Search, a “First steps” list that stays after setup, Pro features explained and marked. The help tab on each page links to the matching guide.
* New: Design settings with live preview of your real shop page (computer and phone): basic style, colour scheme light/dark, corners, products per row, image format (landscape, square, portrait), font of product names, custom CSS and a ready-made CSS block for web developers.
* New: the shop takes colours, heading font and corners from block themes (theme.json) and recognises dark themes.
* New: text on buttons automatically turns white or dark so it stays readable on light club colours, also for category colours.
* New (Pro): shop types can have their own layout (basic style, products per row, image format).
* Fixed: saving the Design tab no longer stores a fixed main colour that overrides your theme. If an earlier version saved one, the Design tab shows a note with “Use theme color”.
* Improved: all status colours (done, note, error) can be changed with CSS variables.

= 2.0.12 =
* New: Kontor Shop Pro shows a small PRO badge in the admin menu (crossed out when the licence has expired).

= 2.0.11 =
* New: after buying Pro, Pro installs itself in the background on the next admin page and the free copy is removed – only one plugin remains, your data stays.
* New: automatic updates are switched on from the start (you can switch them off in the plugin list).
* Improved: plugin name in German, no more download instructions from the licence service, one clear dialog when deactivating.
* Fixed: after confirming the e-mail or entering a licence key you land on the Kontor Shop overview – no more “not allowed” page.

= 2.0.9 =
* Fixed: after entering a licence key you now always land on “Plans & pricing”, which shows the next step or a clear message.

= 2.0.8 =
* Improved: more reliable update check, with the result shown in the plugin list.

= 2.0.7 =
* Maintenance release (automatic update check).

= 2.0.6 =
* Improved: one package for every installation.

= 2.0.5 =
* New: enter a Pro licence key right on “Plans & pricing”.
* New: after buying, every Kontor Shop page shows the next step – confirm the e-mail, then Pro installs itself in the background.
* New: a welcome for Pro with the first steps and your licence at a glance.
* Fixed: licence and upgrade messages now appear in German on sites using de_AT, de_CH and other German variants.
* Fixed: a broken Pro installation (for example from two tabs at once) no longer blocks the next attempt.

= 2.0.4 =
* Improved: release and update pipeline.

= 2.0.3 =
* New: “Plans & pricing” in German with Pro advantages, Free/Pro comparison and live prices; buying opens the Freemius checkout in your dashboard and Pro installs itself afterwards.
* New: after the first activation a welcome with the first steps.
* Fixed: the upgrade page could stay empty for a day if it was opened before the plans were published.
* Prices shown plus VAT.

= 2.0.2 =
* First version for the WordPress plugin directory.
* English as source language, German (Germany, Austria, Switzerland) included.
* New: e-mails go through WordPress and any SMTP plugin; undelivered mails wait in an outbox and are retried automatically.
* New: AI assistants (MCP) – customers’ assistants can search products, prices and availability (off by default).
* New: menu item “AI assistant” shows what Kontor Shop Pro can do with ChatGPT, Claude and Gemini, with plans and prices.
* New: optional line “Club shop with Kontor Shop” under e-mails and the order confirmation – only if you agree.
* New: report a problem with a prepared diagnostics report.
* Changed: member prices, several warehouses, discount codes, back-in-stock requests, automatic reminders, CSV export, key figures, change log, cashier API and search-engine extras are now part of Kontor Shop Pro. Your data stays when you switch between Free and Pro.

== Upgrade Notice ==

= 2.8.0 =
New: PDF invoices (switch on under Settings → Invoices). Pro: bank statement matching and group orders. Fixes order times on servers whose database runs in UTC.

= 2.1.1 =
Pro: automatic payment reminders and auto-cancellation are now off until you switch them on (Settings → Payment). New: cash on pickup and “Ready for pickup” email.

= 2.1.0 =
New help centre and design settings with live preview. If your shop ignores your theme colour, open Settings → Design and click “Use theme color”.

= 2.0.3 =
New plans & pricing page; fixes an empty upgrade page.

= 2.0.2 =
First release in the plugin directory. Several features moved to Kontor Shop Pro; your data is kept.
