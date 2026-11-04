=== Kontor Shop – Club Shop with Bank Transfer & Pickup ===
Contributors: veyraassistant
Tags: club shop, shop, bank transfer, stock, association
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The club shop for WordPress: products with sizes, stock, bank-transfer checkout, pickup and a simple cashier workflow. No payment provider needed.

== Description ==

Kontor Shop is a lean shop for clubs and associations. It covers what a club shop needs and leaves out what it does not: no payment provider contract, no transaction fees, no extension maze.

Members and guests order online, pay by bank transfer or in cash on pickup, and your treasurer marks orders as paid with one click. Whoever packs the parcels sees exactly what to ship.

= What you get =

* **Products with variants:** sizes, colours, pictures, categories and search, each product with its own page and address.
* **Cart and checkout:** guest orders without an account, stock is reserved while the customer checks out.
* **Payment without a provider:** bank transfer (prepayment) and cash on pickup. Your bank details appear in the confirmation and the e-mail.
* **Shipping:** flat rate, free shipping above an amount, self pickup.
* **Stock:** one warehouse with a full stock ledger, so every change can be traced.
* **Orders:** clear status workflow (open, paid, shipped, picked up, cancelled) and a cashier role that can mark payments.
* **E-mails:** editable templates for every step. Mails go through WordPress, so any SMTP plugin (WP Mail SMTP, FluentSMTP, Post SMTP …) works automatically. Undelivered mails wait in an outbox and are retried automatically.
* **Legal and privacy:** legal notice block in checkout, spam protection without captcha, personal data export and erasure through the WordPress privacy tools.
* **Design:** picks up your theme colours, one main colour setting, shop pages are created on activation. Works with classic and block themes.

= Kontor Shop Pro =

Pro adds what growing clubs need: member prices, several warehouses with their own warehouse managers, packing view on the phone straight from the order mail with partial shipments, discount codes and campaigns, back-in-stock notifications, automatic payment reminders and cancellation of unpaid orders, CSV export, reorder hints, a dashboard with key figures and alarm mail, a tamper-proof change log, a cashier API, search-engine extras (structured product data, category pages) – and AI assistants for the whole team: create products with images, book stock and process orders by chat, each person with their own permissions. Pro shows no “powered by” line.

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

== Screenshots ==

1. Shop on a phone: products with sizes and colours.
2. Checkout with bank transfer and pickup.
3. Orders in the back office with status and payment.
4. Stock ledger with every movement.
5. E-mail templates with placeholders.
6. Settings: payment, shipping and e-mail.

== Changelog ==

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

= 2.0.3 =
New plans & pricing page; fixes an empty upgrade page.

= 2.0.2 =
First release in the plugin directory. Several features moved to Kontor Shop Pro; your data is kept.
