=== Kontor Shop – Club Shop with Bank Transfer & Pickup ===
Contributors: veyraassistant
Tags: club shop, shop, bank transfer, stock, association
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.9.0
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
