<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\HelpCenter\Content;

use Kontor\Shop\Core\Edition;

/**
 * Artikel des Hilfe-Centers (Schema: Admin\HelpCenter\HelpCenter).
 *
 * Gruppe "Pro features": Anleitungen zu den Pro-Funktionen. Sie stehen auch
 * in Free (nur Text und Admin-Adressen, kein Pro-Code - docs/PRO.md Regel 2);
 * dort zeigt das Hilfe-Center automatisch den Kasten "braucht Pro". Die
 * Adressen der Pro-Seiten sind feste Slugs (z. B. kontor-shop-coupons), in
 * Free fuehren sie ins Leere, der Kasten verlinkt dann Plaene & Preise.
 * Deutsch: docs/i18n/help-pro.json.
 */
final class ProArticles
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function articles(): array
    {
        return [
            [
                'id'       => 'pro-overview',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('What Kontor Shop Pro adds', 'kontor-shop'),
                'summary'  => __('All Pro features at a glance, how to activate Pro and what happens when the licence ends.', 'kontor-shop'),
                'keywords' => __('pro upgrade premium licence license key buy activate plans pricing features downgrade expire freemius', 'kontor-shop'),
                'what'     => __('Kontor Shop Pro adds features for larger clubs and for less manual work to the free shop. Your products, orders and settings stay as they are; Pro builds on them.', 'kontor-shop'),
                'steps'    => [
                    // Mit gueltiger Lizenz blendet Freemius "Plaene & Preise" aus - dann kein Link ins Leere.
                    ['text' => __('Open Kontor Shop → Plans & pricing.', 'kontor-shop'), 'url' => Edition::is_pro() ? '' : admin_url('admin.php?page=kontor-shop-pricing')],
                    __('Choose a plan and click “Get Pro now”. You pay in the Freemius checkout by credit card or PayPal.', 'kontor-shop'),
                    __('Already have a licence key? Enter it under “Already bought Pro?” and click “Activate licence”.', 'kontor-shop'),
                    __('If the shop asks you to, confirm your email address with the button in the email from Freemius.', 'kontor-shop'),
                    __('Open any admin page. Pro installs itself in the background and then welcomes you with the first steps.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('Pro replaces the free plugin. Afterwards there is only one plugin, and products, orders, customers and settings stay as they are.', 'kontor-shop'),
                    __('Payment reminders and auto-cancellation start switched off – switch them on under Settings → Payment & sales tax. The alarm email is on from the start; you find it under Settings → System.', 'kontor-shop'),
                    __('Everything else, such as member prices, warehouse managers or the AI assistant, only does something once you set it up.', 'kontor-shop'),
                    __('The PRO badge next to Kontor Shop in the admin menu shows the licence: gold while it is active, crossed out when it has expired.', 'kontor-shop'),
                ],
                'sections' => [
                    [
                        'title'      => __('What Pro adds, sorted by need', 'kontor-shop'),
                        'list'       => [
                            __('Selling to members: member prices, free shipping and payment on invoice for members only. Settings → Membership.', 'kontor-shop'),
                            __('Several warehouses: as many warehouses as you need, each with its own warehouse manager. Kontor Shop → Warehouses.', 'kontor-shop'),
                            __('Packing: a packing view for the phone, straight from the order email, with partial shipments. Orders → Pack.', 'kontor-shop'),
                            __('Discounts and demand: discount codes and campaigns (Kontor Shop → Discounts) and emails when sold-out products are back (Kontor Shop → Requests).', 'kontor-shop'),
                            __('Automation: payment reminders and the cancellation of unpaid orders run by themselves. Settings → Payment & sales tax.', 'kontor-shop'),
                            __('Numbers: key figures on the dashboard, an alarm email for disruptions, CSV export of orders and stock, and a reorder list that shows how long your stock lasts. Dashboard, Orders, Stock.', 'kontor-shop'),
                            __('Traceability: a tamper-proof log of who changed what. Logs → Changes.', 'kontor-shop'),
                            __('Interfaces: a cashier API, so your treasurer can book payments in another program. Settings → APIs.', 'kontor-shop'),
                            __('Shop and search engines: separate shop areas with their own color (Settings → Shop types), product data for Google, category pages and notes on returns and warranty.', 'kontor-shop'),
                            __('AI: your team runs the shop by chat with ChatGPT, Claude or Gemini, and customers’ assistants can fill the cart. Kontor Shop → AI assistant.', 'kontor-shop'),
                            __('No “powered by” line under emails and the order confirmation.', 'kontor-shop'),
                        ],
                    ],
                    [
                        'title'      => __('When the licence ends', 'kontor-shop'),
                        'paragraphs' => [
                            __('Plans & pricing shows what happens for your plan. Either Pro keeps working and only updates and support stop, or the Pro features switch off.', 'kontor-shop'),
                            __('If the Pro features switch off, your shop keeps running as the free version. No data is deleted: member discounts, discount codes, requests, warehouses and the change log stay in the database and are back as soon as Pro is active again.', 'kontor-shop'),
                            __('Until then, nobody counts as a member, discount codes can’t be redeemed and only the main warehouse can be edited. Personal data from Pro features, such as back-in-stock requests, can still be exported or deleted for data protection requests.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'      => [
                    [
                        'q' => __('Do I lose data when I switch to Pro or back?', 'kontor-shop'),
                        'a' => __('No. Free and Pro use the same database. Products, orders, customers and settings stay in both directions.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Do I have to install Pro myself?', 'kontor-shop'),
                        'a' => __('No. After the licence is activated, Pro installs itself on the next admin page and removes the free copy. If that fails, the card offers “Try again” or “Install via Freemius instead”.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Where do I find my licence key?', 'kontor-shop'),
                        'a' => __('In the purchase email and in your Freemius account.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['pro-members', 'pro-reminders', 'pro-agents', 'first-steps'],
            ],
            [
                'id'       => 'pro-members',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Member prices and membership', 'kontor-shop'),
                'summary'  => __('Members pay less, ship for free or buy on invoice as soon as the shop recognizes them.', 'kontor-shop'),
                'keywords' => __('member members membership member price discount provider login club fee free shipping invoice benefits recognized', 'kontor-shop'),
                'what'     => __('Club members get benefits in the shop: a member price per product, free shipping and payment on invoice. For that, the shop has to recognize members. A membership provider does this by connecting the shop to your member management.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Settings → Membership and choose how the shop recognizes members: “WordPress user roles” (e.g. a role “Member”, also with Ultimate Member), Paid Memberships Pro or MemberPress.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=membership')],
                    __('With roles, tick the roles that count as members. With Paid Memberships Pro or MemberPress, enter the level IDs that count, or leave the field empty for every active level.', 'kontor-shop'),
                    __('With the paid fee rule, enter until which day last year’s fee still counts, for example 31.03. Then save.', 'kontor-shop'),
                    ['text' => __('Open a product and set the “Member discount” in the “Price & tax” card, in percent or in euros. Save the product.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-products')],
                    ['text' => __('Optional: under Settings → Shipping & returns, tick “Members don’t pay shipping”.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=shipping')],
                    ['text' => __('Optional: under Settings → Payment & sales tax, tick “only for recognized members” for payment on invoice.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=payment')],
                ],
                'effect'   => [
                    __('Logged-in members see the member price in the catalog and on the product page. Everyone else sees the regular price.', 'kontor-shop'),
                    __('In the cart and at checkout, members read “Logged in as a member – member benefits apply.” Visitors who aren’t logged in see “Member? Log in now” and come back to checkout after logging in.', 'kontor-shop'),
                    __('The member’s name, email, phone and address are filled in at checkout and can be changed there. Only what is sent with the order is saved.', 'kontor-shop'),
                    __('The shop checks the membership again at most every 5 minutes. A cancelled membership or a missing fee takes effect after 5 minutes at the latest.', 'kontor-shop'),
                    __('Each order remembers whether the customer was a member. Search engines only ever see the regular price.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('Which provider should I choose?', 'kontor-shop'),
                        'a' => __('If your members already have a WordPress account with their own role, choose “WordPress user roles”. If you manage memberships with Paid Memberships Pro or MemberPress, choose that plugin – it appears in the list once it is active. Club software with its own login can connect through an add-on.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('A member is logged in but doesn’t get the member price.', 'kontor-shop'),
                        'a' => __('Check the rule under “Membership benefits apply to”. With the paid fee rule, the fee for the current year must be marked as paid in the member management. The member then sees a note at checkout that no benefits apply right now.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Can members use a discount code on top?', 'kontor-shop'),
                        'a' => __('Yes. A discount code is calculated after the member price.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['setup-membership', 'pro-coupons', 'setup-payment', 'setup-shipping', 'products-create'],
                'help_pages' => ['settings-membership'],
            ],
            [
                'id'       => 'pro-warehouses',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Several warehouses with warehouse managers', 'kontor-shop'),
                'summary'  => __('Each warehouse ships its own products, and its warehouse manager takes care of it.', 'kontor-shop'),
                'keywords' => __('warehouse warehouses warehouse manager location storage regional group split order bank account shipping order permission', 'kontor-shop'),
                'what'     => __('With Pro you can create as many warehouses as you need, for example one per regional group. Each warehouse can have a warehouse manager who books its stock and ships its orders.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Kontor Shop → Warehouses and click “Add new warehouse”.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-warehouses')],
                    __('Enter the name, the contact name and the sender address.', 'kontor-shop'),
                    __('Under “Warehouse manager”, choose the WordPress user who looks after this warehouse.', 'kontor-shop'),
                    __('If prepayments for this warehouse go to a different account, choose it under “Bank account (prepayment)”.', 'kontor-shop'),
                    __('Click “Create warehouse”. Then choose this warehouse in the products that are stored there.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('The warehouse manager gets the permission automatically. They book stock for their warehouse and ship its orders. If you remove them or delete the warehouse, the permission is revoked again.', 'kontor-shop'),
                    __('As soon as an order can be shipped (paid, or right away on invoice), each warehouse gets its own “Shipping order to warehouse” email with only its products. It goes to the warehouse manager. The recipients chosen in the email template get a copy.', 'kontor-shop'),
                    __('If a cart contains products from warehouses with different bank accounts, the order is split into several orders, one per account, each with its own payment details. You set how shipping is charged under Settings → Shipping & returns → “Shipping for split orders”.', 'kontor-shop'),
                    __('The dashboard shows a “Warehouse” card: per warehouse the packages to pack, how long the oldest has been waiting, low or empty products and the managers.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('Can one person manage several warehouses?', 'kontor-shop'),
                        'a' => __('Yes. Choose the same user for each warehouse. Settings → Roles shows who manages which warehouse.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('What if a warehouse has no warehouse manager?', 'kontor-shop'),
                        'a' => __('Then its shipping orders only go to the copy recipients of the email template. If there are none, they go to the shop operator.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('What happens to extra warehouses without Pro?', 'kontor-shop'),
                        'a' => __('They stay visible and their products stay in the shop, but only the main warehouse can be edited. Warehouse managers no longer have their own access.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['setup-warehouses', 'pro-packing', 'orders-ship', 'setup-roles', 'stock-delivery'],
            ],
            [
                'id'       => 'pro-packing',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Packing on the phone and partial shipments', 'kontor-shop'),
                'summary'  => __('Pack straight from the order email, tick off products and ship what is there.', 'kontor-shop'),
                'keywords' => __('pack packing phone mobile ship shipment partial shipment tracking number pick list shelf warehouse email carrier pickup', 'kontor-shop'),
                'what'     => __('The packing view is made for the phone in the stockroom. It shows what to get from the shelf. You tick off each product, enter the tracking number and record the shipment in one go. If something is missing, you ship the rest later.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open the “Shipping order to warehouse” email and tap the link next to “Pack now (also on your phone)”. Or open Orders and click “Pack”.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-orders')],
                    __('Log in if WordPress asks you to. Warehouse managers only see the orders of their warehouse.', 'kontor-shop'),
                    __('Get the products listed under “Get from the shelf”.', 'kontor-shop'),
                    __('Tick off each product as soon as it is in the package.', 'kontor-shop'),
                    __('Choose the carrier and enter the tracking number from the shipping label. Leave “Notify customer by email (with tracking)” ticked.', 'kontor-shop'),
                    __('Tap the save button, for example “All packed – save as shipped” or “Ship 2 of 3 – the rest stays open”.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('The ticked products count as shipped, and the customer gets the shipping email with the tracking number.', 'kontor-shop'),
                    __('Products you didn’t tick stay open. The order is marked “Remaining” and shows up in the packing view again for the rest.', 'kontor-shop'),
                    __('For pickup orders, you tick off what was picked up and save. There is no tracking number.', 'kontor-shop'),
                    __('“Everything to pack” collects all open orders on one page, with a shelf list per warehouse.', 'kontor-shop'),
                    __('A partial shipment also works in the order list with “Record shipment …”. The rest is then recorded with “Record remaining shipment …”.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('The link shows “This order was not found or doesn’t belong to your warehouse.”', 'kontor-shop'),
                        'a' => __('You are logged in with a user who doesn’t manage this warehouse. Log in as the warehouse manager or ask a shop manager.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Does packing change the stock?', 'kontor-shop'),
                        'a' => __('No new booking is made. Stock already went down with the order. The shipment only records that the goods have left the warehouse.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Can I pack on a computer too?', 'kontor-shop'),
                        'a' => __('Yes. The view works in any browser. It is just laid out for small screens.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['orders-ship', 'pro-warehouses', 'orders-overview', 'orders-paid'],
            ],
            [
                'id'       => 'pro-coupons',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Discount codes and campaigns', 'kontor-shop'),
                'summary'  => __('Codes for newsletters, flyers or the club party: a percentage or a fixed amount off the cart.', 'kontor-shop'),
                'keywords' => __('discount coupon voucher code promo campaign series percent fixed amount checkout redeem expire flyer newsletter', 'kontor-shop'),
                'what'     => __('A discount code lowers the price of the whole cart, by a percentage or by a fixed amount. You can create one code for everyone, a series of one-time codes for flyers, or a personal code for one person.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Kontor Shop → Discounts and click “+ New campaign”.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-coupons')],
                    __('Choose the occasion, for example “One code for everyone” or “One-time codes to hand out”. This fills in suitable presets.', 'kontor-shop'),
                    __('Set the discount: “Percent of order value” or “Fixed amount”, and optionally a minimum order value.', 'kontor-shop'),
                    __('Set how long the code is valid and how often it can be redeemed, for example “Only once per email address”.', 'kontor-shop'),
                    __('Enter the code, or for a series a prefix and the number of codes.', 'kontor-shop'),
                    __('Check the summary and click “Add discount code” or “Generate … codes”.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('Customers enter the code at checkout under “Apply discount code”. The discount appears as its own line in the order and in the emails.', 'kontor-shop'),
                    __('The discount applies to the products, never to shipping. The free shipping threshold is checked after the discount. Members get the discount on top of the member price.', 'kontor-shop'),
                    __('A code counts as redeemed with the completed order. If the order is cancelled, the redemption is released again.', 'kontor-shop'),
                    __('Redeemed codes can’t be deleted, only switched off, because they appear in orders.', 'kontor-shop'),
                    __('The page of a campaign shows redemptions, discount granted and revenue. There you can extend all codes at once, generate more codes or download the list as CSV.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('What if a code is no longer valid when the customer orders?', 'kontor-shop'),
                        'a' => __('The shop removes it, tells the customer why, for example expired or used up, and asks them to check the new total before ordering again.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('How do I see codes that run out soon?', 'kontor-shop'),
                        'a' => __('Click the figure “Expires in 14 days” at the top of the Discounts page. It shows the codes you should extend soon.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Is there something faster than the five steps?', 'kontor-shop'),
                        'a' => __('Yes. Click “Compact form” to have all fields on one page. The shop remembers your choice.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['pro-members', 'pro-export', 'orders-cancel', 'pro-agents'],
                'help_pages' => ['coupons'],
            ],
            [
                'id'       => 'pro-stock-requests',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Back-in-stock requests', 'kontor-shop'),
                'summary'  => __('Customers ask to be notified about sold-out products and get an email when they are back.', 'kontor-shop'),
                'keywords' => __('back in stock notify notification sold out request waiting list availability email restock discontinued double opt-in', 'kontor-shop'),
                'what'     => __('For sold-out products, the shop shows the button “Notify me when available”. Customers leave their email address and get an email as soon as the product is back, or when it won’t come back. You see how many people are waiting for what.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Settings → Back-in-stock requests and check that “Customers can ask to be notified about sold-out products” is ticked.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=stock_requests')],
                    __('Leave “Double opt-in” and “Notify automatically” ticked, and save.', 'kontor-shop'),
                    ['text' => __('Open Kontor Shop → Requests to see who is waiting. “By product” shows the totals per product.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-stock-requests')],
                    __('Book the delivery as usual. As soon as stock rises from 0, the waiting customers get the “Back in stock” email.', 'kontor-shop'),
                    __('If a product won’t come back, tick its requests and choose “Notify: no longer coming …”. You can add a note, for example an alternative.', 'kontor-shop'),
                    __('Someone asked by phone or at the counter? Click “Record request” and enter it yourself.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('With double opt-in, the customer first gets a confirmation email. The request only becomes “Open” after they click the link. By default, unconfirmed requests are deleted after 7 days.', 'kontor-shop'),
                    __('The “Back in stock” email only goes out when the product is visible and in stock. Each request is notified once and then shows “Notified: back in stock”.', 'kontor-shop'),
                    __('Customers can unsubscribe with the link in the email. The request then shows “Unsubscribed by customer”.', 'kontor-shop'),
                    __('Waiting requests also put the product on the reorder list, so you order enough.', 'kontor-shop'),
                    __('By default, completed requests are deleted after 365 days. Open requests stay as long as they are open.', 'kontor-shop'),
                    __('Products marked “Discontinued product” don’t show the button at all.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('I booked stock, but nobody got an email.', 'kontor-shop'),
                        'a' => __('Check that the product is visible, that “Notify automatically” is on and that the email template is switched on. You can also tick the requests and choose “Notify: back in stock” yourself.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Where do I change the email texts?', 'kontor-shop'),
                        'a' => __('Under Settings → Email templates: “Notification …”, “Back in stock”, “No longer available” and “New back-in-stock request”.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('What about data protection?', 'kontor-shop'),
                        'a' => __('Customers tick a privacy checkbox. In its text, {datenschutz} becomes a link to your privacy policy. Mention the notifications in your privacy policy.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['stock-delivery', 'pro-reorder', 'setup-templates', 'problem-emails'],
                'help_pages' => ['stock-requests', 'settings-stock_requests'],
            ],
            [
                'id'       => 'pro-reminders',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Automatic payment reminders and cancellation', 'kontor-shop'),
                'summary'  => __('Unpaid orders get reminders by themselves, and unpaid prepayment orders are cancelled after a grace period.', 'kontor-shop'),
                'keywords' => __('payment reminder dunning overdue unpaid cancel cancellation auto-cancellation grace period deadline invoice prepayment bank transfer', 'kontor-shop'),
                'what'     => __('Nobody has to chase unpaid orders by hand. The shop reminds customers of open prepayment orders every day, sends a final reminder on the last day of the payment deadline and cancels the order after a grace period. Orders on invoice get reminders without cancellation.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Settings → Payment & sales tax.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=payment')],
                    __('Check the “Payment deadline for prepayment”, for example 14 days.', 'kontor-shop'),
                    __('At “Payment reminder”, set after how many days the first reminder goes out and at what time. 0 days switches the automatic reminder off.', 'kontor-shop'),
                    __('At “Auto-cancellation”, decide whether unpaid prepayment orders are cancelled, and set the grace period in days.', 'kontor-shop'),
                    __('If you offer payment on invoice, set in that part how many days lie between reminders. 0 means only one reminder.', 'kontor-shop'),
                    __('Save. You edit the texts under Settings → Email templates: “Payment reminder”, “Final payment reminder” and “Invoice payment reminder”.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('Prepayment: from the set day on, the customer gets the “Payment reminder” with the payment details every day at the set hour, until the order is paid or cancelled. A reminder you send by hand counts as that day’s reminder.', 'kontor-shop'),
                    __('With auto-cancellation, the daily reminders stop the day before the deadline ends. On the last day, the customer gets the “Final payment reminder” with the cancellation date.', 'kontor-shop'),
                    __('During the grace period nothing happens. If the order is still unpaid after that, it is cancelled with the reason “not paid”: the goods go back to the warehouse, the customer gets the cancellation email and the cashier the internal one.', 'kontor-shop'),
                    __('Example with a 14-day deadline and 2 days of grace: final reminder on day 14, cancellation on day 17.', 'kontor-shop'),
                    __('Invoice: the first reminder goes out the day after the payment term, then at the set interval. There is no cancellation, because the goods are already with the customer.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('Is this switched on right after activating Pro?', 'kontor-shop'),
                        'a' => __('No. Reminders for prepayment and auto-cancellation start switched off, so nothing is sent or cancelled without your decision. Good values to start with: first reminder 2 days after ordering at 9 o’clock, grace period 2 days. Reminders for orders on invoice run as soon as you offer payment on invoice.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Can a customer who paid on time be cancelled?', 'kontor-shop'),
                        'a' => __('The grace period protects against that. Bank transfers often take one to two business days, and the cashier still has to record the payment. Choose the grace period so your cashier can check the account in time.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('A customer gets no reminder. Why?', 'kontor-shop'),
                        'a' => __('Check that the email template is switched on and that emails are being sent (Settings → Email sending). Reminders go out once a day, during the hour you set.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['orders-paid', 'orders-cancel', 'setup-payment', 'setup-templates', 'problem-emails'],
            ],
            [
                'id'       => 'pro-reorder',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Reorder list and stock reach', 'kontor-shop'),
                'summary'  => __('See how long your stock lasts and get a ready list for the next order from your supplier.', 'kontor-shop'),
                'keywords' => __('reorder restock supplier purchase order reach coverage lasts for minimum stock sold out low list copy delivery', 'kontor-shop'),
                'what'     => __('The stock list shows for each product how long the stock will probably last. The reorder list collects everything that is low or requested and suggests quantities, so you can send your supplier an order in a minute.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Kontor Shop → Stock.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-stock')],
                    __('Look at the “Lasts for” column. Click its heading to sort by it.', 'kontor-shop'),
                    __('Click “Reorder list”. The number on the button shows how many products should be reordered.', 'kontor-shop'),
                    __('Check the quantities in the “To order” column and change them if needed. 0 removes a row.', 'kontor-shop'),
                    __('To add more products, tick them in the list and click “On reorder list”.', 'kontor-shop'),
                    __('Click “Copy text” and paste it into your email to the supplier. When the goods arrive, click “Has arrived → Record delivery”.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('“Lasts for” is estimated from the sales of the last 30 days, for example “~3 weeks”. With too few sales it says “too few sales”.', 'kontor-shop'),
                    __('A product lands on the list by itself when its stock is at or below the minimum stock, or when customers are waiting for it with a back-in-stock request.', 'kontor-shop'),
                    __('The suggestion fills up to double the minimum stock, or to the minimum stock plus the last 30 days of sales, and at least as much as customers have requested.', 'kontor-shop'),
                    __('“Has arrived → Record delivery” opens the delivery form with the quantities filled in. Stock only changes when you book it there.', 'kontor-shop'),
                    __('On the dashboard, the tile for low and sold-out products is called “Reorder” and opens the list. Products marked “Discontinued product” are left out.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('A product is missing from the list.', 'kontor-shop'),
                        'a' => __('Products only land on the list by themselves if they have a minimum stock or waiting requests. Set a minimum stock, or add the product with “On reorder list”.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Does the shop send anything to my supplier?', 'kontor-shop'),
                        'a' => __('No. The shop only prepares the text. You send it yourself.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['stock-min', 'stock-delivery', 'pro-stock-requests', 'pro-dashboard'],
            ],
            [
                'id'       => 'pro-dashboard',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Key figures and alarm email', 'kontor-shop'),
                'summary'  => __('The dashboard shows what is stuck and how the shop is doing. An email warns you about disruptions.', 'kontor-shop'),
                'keywords' => __('dashboard key figures stuck trend revenue orders last 30 days alarm alert email shop health red warning widget', 'kontor-shop'),
                'what'     => __('On the dashboard, Pro shows what has been waiting longer than usual and how orders and revenue have developed. If the shop has a serious problem, for example emails aren’t being sent, the alarm email tells you before customers complain.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Kontor Shop → Dashboard.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop')],
                    __('Check the “Stuck” card. Each entry says how long it has been waiting and has a button that opens exactly those orders or products.', 'kontor-shop'),
                    __('Look at “Last 30 days”: orders and revenue compared with the 30 days before, with small curves for the last 8 weeks.', 'kontor-shop'),
                    ['text' => __('Open Settings → System. Under “Alert email for disruptions”, check that the alarm email is ticked.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=system')],
                    __('Click “Test alert email” to see what an alarm looks like and whether it arrives.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('“Stuck” lists, for example, paid packages not yet shipped per warehouse, waiting pickups, overdue payments, pending refunds, products that are visible but sold out, and an unusually long time without an order. Everyone only sees what concerns their role.', 'kontor-shop'),
                    __('“Last 30 days” doesn’t count cancelled orders. The WordPress dashboard widget also shows how many things are stuck.', 'kontor-shop'),
                    __('The alarm email goes to the WordPress admin address when the shop health turns red: email sending disrupted, background tasks stuck, critical errors, or the shop can’t take orders.', 'kontor-shop'),
                    __('You get one email when it happens, at most one reminder a day while it lasts, and an all-clear when everything works again. The shop checks this every hour.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('The alarm goes to the wrong address.', 'kontor-shop'),
                        'a' => __('It goes to the administration email address of WordPress. Change it in WordPress under Settings → General.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('The test alarm doesn’t arrive.', 'kontor-shop'),
                        'a' => __('Then a real alarm wouldn’t arrive either. Check Settings → Email sending and send a test email there.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('When is something “stuck”?', 'kontor-shop'),
                        'a' => __('When it waits longer than usual, for example a payment after its deadline. A pause in orders only counts once it is three times the usual gap and at least 14 days.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['pro-reorder', 'pro-warehouses', 'problem-emails', 'setup-mail', 'setup-system'],
            ],
            [
                'id'       => 'pro-export',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('CSV export of orders and stock', 'kontor-shop'),
                'summary'  => __('Download the orders for the treasurer or the stock list for a spreadsheet.', 'kontor-shop'),
                'keywords' => __('export csv excel spreadsheet download orders stock accounting treasurer bookkeeping list report', 'kontor-shop'),
                'what'     => __('The CSV export gives you orders or stock as a file you can open in Excel, LibreOffice or Numbers, for example for the treasurer’s bookkeeping or the annual report.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Kontor Shop → Orders.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-orders')],
                    __('Filter the list if needed, for example by status, search term or period.', 'kontor-shop'),
                    __('Click “Export CSV”. To export only certain orders, tick them and click “CSV of selection”.', 'kontor-shop'),
                    ['text' => __('For stock, open Kontor Shop → Stock, filter if needed and click “Export CSV”.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-stock')],
                    __('Open the downloaded file in your spreadsheet program.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('The orders file has one line per order: number, payment reference, date, status, member yes or no, customer, contact, addresses, items, amounts with discount, VAT and shipping, payment method, bank account and the dates of payment, shipping and cancellation.', 'kontor-shop'),
                    __('At the top, the file states when it was created and which filter was used.', 'kontor-shop'),
                    __('The stock file has one line per product and size: warehouse, stock, on shelf, minimum stock, sales in the last 30 days, how long it lasts, waiting requests and status.', 'kontor-shop'),
                    __('The file uses semicolons as separators and is saved so that Excel shows umlauts correctly.', 'kontor-shop'),
                    __('Orders can be exported by shop managers and cashiers.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('The file contains personal data. What should I watch out for?', 'kontor-shop'),
                        'a' => __('It contains names, addresses and email addresses. Only store it where your club allows it, and delete it when you no longer need it.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Is there a stock count list without Pro?', 'kontor-shop'),
                        'a' => __('Yes. The count list for printing is part of the free version.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['orders-overview', 'stock-count', 'pro-coupons'],
            ],
            [
                'id'       => 'pro-audit',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Change log', 'kontor-shop'),
                'summary'  => __('See who changed what and when, protected against later tampering.', 'kontor-shop'),
                'keywords' => __('audit log change log changes history who changed tamper-proof trace protocol retention check', 'kontor-shop'),
                'what'     => __('The change log records important changes in the shop: products, categories, warehouses, orders, manual stock bookings, discount codes, settings, permissions and API accesses. Entries can’t be edited or deleted, so you can always trace who did what.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Kontor Shop → Logs and switch to the “Changes” view.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-audit')],
                    __('Filter by area, by date or with a search term, such as a product, a user or a value.', 'kontor-shop'),
                    __('Click “History #…” on an entry to see all changes to that object. In the product form, the link “Change log” takes you there directly.', 'kontor-shop'),
                    __('Click “Check log for tampering” to check the whole log.', 'kontor-shop'),
                    __('Under “Keep (days)”, set how long entries are kept, and save.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('Each entry shows the time, who made the change, the action, the object, old and new values, and the source, for example shop admin, API, automatic or website. Changes made by an AI assistant carry its name.', 'kontor-shop'),
                    __('Each entry is chained to the one before and signed. The check shows whether entries were changed, removed or inserted.', 'kontor-shop'),
                    __('Customer data and secrets such as tokens and passwords are not stored in the log.', 'kontor-shop'),
                    __('Older entries are removed automatically once a day: by default after 730 days, at least after 180 and at most after 3650. The cleanup and every change to this setting are logged themselves.', 'kontor-shop'),
                    __('Only users who may change the settings see the log.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('The page shows a tip about KONTOR_SHOP_AUDIT_KEY. What do I do?', 'kontor-shop'),
                        'a' => __('For stronger protection, your webmaster can set a key of your own in wp-config.php. Until then, the WordPress salts are used. Once the key changes, older entries stay readable but can no longer be recalculated.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('The check reports inconsistencies.', 'kontor-shop'),
                        'a' => __('Something changed the database directly, outside the shop. Note the entries listed and ask your webmaster to compare them with a backup.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Why don’t I see every sale as a stock booking?', 'kontor-shop'),
                        'a' => __('The log records manual stock bookings. Sales and cancellations from orders are in the stock history of each product.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['setup-roles', 'setup-system', 'pro-agents', 'pro-api'],
                'help_pages' => ['audit'],
            ],
            [
                'id'       => 'pro-api',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Cashier API', 'kontor-shop'),
                'summary'  => __('Your treasurer books payments in another program, for example the club management software.', 'kontor-shop'),
                'keywords' => __('api interface cashier treasurer token rest integration club software payment booking refund cancellation connected system developer', 'kontor-shop'),
                'what'     => __('The cashier API connects the shop to another program, such as your club management software. There, the treasurer sees open payments and refunds and books “paid”, “cancelled” or “refund done” without opening the shop admin. The payment status itself stays in the shop.', 'kontor-shop'),
                'steps'    => [
                    __('Ask whoever looks after your club software whether it supports the Kontor Shop cashier API. A developer sets up that side.', 'kontor-shop'),
                    ['text' => __('Open Settings → APIs.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=api')],
                    __('Under “Add new access”, enter the name of the connected system, for example “Club cash register”, and choose the permissions: cashier or read only.', 'kontor-shop'),
                    __('Click “Create access and generate token”.', 'kontor-shop'),
                    __('Copy the token right away, because it is shown only once. Pass it to the developer through a safe channel, together with the “API address” shown on the page.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('Every booking through the API runs through the same steps as in the admin: status change, stock booking on cancellation, emails to the customer and an entry in the order history.', 'kontor-shop'),
                    __('The order shows which connected system made the booking. Each connected system gets its own access, so you can block one without affecting the others.', 'kontor-shop'),
                    __('If the connected system takes over payments, the page says “Payments are booked in …” and the shop hides “Mark as paid” and “Refund done”. WordPress administrators keep an emergency exit on the order page.', 'kontor-shop'),
                    __('“Generate new token” makes the old token invalid immediately. “Remove” blocks the access immediately.', 'kontor-shop'),
                    __('The API only accepts requests over HTTPS.', 'kontor-shop'),
                ],
                'sections' => [
                    [
                        'title'      => __('For developers', 'kontor-shop'),
                        'paragraphs' => [
                            __('The settings page links to the developer documentation. In short: REST endpoints under /wp-json/kontor-shop/v1/cashier, the token as a Bearer header, JSON, and an Idempotency-Key header for every booking.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'      => [
                    [
                        'q' => __('Do I need this?', 'kontor-shop'),
                        'a' => __('Only if your treasurer works in another program that supports this API. Otherwise, mark payments as paid in the order list as usual.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('The token is lost.', 'kontor-shop'),
                        'a' => __('The shop doesn’t keep the token itself and can’t show it again. Click “Generate new token” and enter the new one in the connected system.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('How do I switch “Payments are booked in …” off again?', 'kontor-shop'),
                        'a' => __('Switch off the payment source in the connected system, or remove the access in the shop.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['orders-paid', 'orders-cancel', 'pro-audit', 'setup-roles'],
                'help_pages' => ['settings-api'],
            ],
            [
                'id'       => 'pro-shop-types',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Shop types: separate shop areas', 'kontor-shop'),
                'summary'  => __('Several shops on one website, each with its own page and accent color.', 'kontor-shop'),
                'keywords' => __('shop type shop types area section several shops accent color shortcode page catalog switcher', 'kontor-shop'),
                'what'     => __('A shop type is a separate shop area, for example “Hunting” and “Regular”, with its own accent color and its own page. Categories belong to a shop type, and products to categories. In the catalog, customers can switch between the areas.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Settings → Shop types and click “Create new shop type”.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=shop_types')],
                    __('Enter the name customers will see and choose a color, or tick “Use the shop’s primary color”.', 'kontor-shop'),
                    __('Leave “Create the page with the shortcode automatically” ticked, or place the shortcode from the list on a page of your own.', 'kontor-shop'),
                    __('Optional: under “Design of this shop area”, set the style, products per row and image format, for example portrait images for clothing.', 'kontor-shop'),
                    ['text' => __('Open Kontor Shop → Categories and choose the shop type for each category.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-categories')],
                    __('Change the order of the shop types by dragging or with the arrows.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('The page with the shortcode [kontor_shop_catalog shop="…"] shows only this area, in its color.', 'kontor-shop'),
                    __('On the general shop page, customers switch between the areas with the buttons “All shops” and the area names.', 'kontor-shop'),
                    __('Products without a category color of their own take the color of their shop type.', 'kontor-shop'),
                    __('A switched-off shop type hides its categories and products everywhere in the shop, also for AI assistants.', 'kontor-shop'),
                    __('If you delete a shop type, its page stays and you can remove it yourself.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('The catalog is empty.', 'kontor-shop'),
                        'a' => __('Probably no shop type is active. Switch at least one on under Settings → Shop types.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('A subcategory can’t be saved.', 'kontor-shop'),
                        'a' => __('The parent category has to be in the same shop type.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('What happens without Pro?', 'kontor-shop'),
                        'a' => __('The catalog shows all categories together. Shop types and the assignment of categories are kept.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['setup-categories', 'design', 'shop-pages', 'design-css'],
                'help_pages' => ['settings-shop_types'],
            ],
            [
                'id'       => 'pro-agents',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('AI assistants for your team', 'kontor-shop'),
                'summary'  => __('Manage products, stock, orders and discount codes by chat with ChatGPT, Claude or Gemini.', 'kontor-shop'),
                'keywords' => __('ai assistant chatgpt claude gemini mcp chat connector agent access key team artificial intelligence', 'kontor-shop'),
                'what'     => __('With Pro, everyone on the shop team can connect their own AI assistant to the shop. You simply write what needs doing, for example “Book 20 caps as goods receipt”, and the assistant does it with your permissions. Customers’ assistants can also fill a cart and hand over a ready checkout link.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('An administrator opens Settings → AI assistants, ticks “Team access” and saves.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=agents')],
                    ['text' => __('Each person opens Kontor Shop → AI assistant and clicks “Create access”.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-ai')],
                    __('Choose your app and what the assistant may do: “Look only” or “Look and change”. Give the access a name, for example “ChatGPT on my phone”.', 'kontor-shop'),
                    __('Click “Copy address”. The address is shown only once. Treat it like a password.', 'kontor-shop'),
                    __('Add the address in your app as a connector. “Connect your app” on the same page shows the steps for ChatGPT, Claude and Gemini.', 'kontor-shop'),
                    __('Start a chat, switch on the shop connector and write what you need. “Ideas to try” gives you first examples.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('The assistant works only with your permissions: a cashier can book payments, a warehouse manager only their own warehouse. An access with “Look only” changes nothing.', 'kontor-shop'),
                    __('Before deleting a product or cancelling an order, the assistant has to show a summary and ask you. Nothing happens until you agree.', 'kontor-shop'),
                    __('Every change is logged with your name and “AI assistant” (Logs → Changes).', 'kontor-shop'),
                    __('New products created by chat start hidden unless you ask for them to be visible, so you can check them first.', 'kontor-shop'),
                    __('Settings, users and roles stay in the admin. The assistant can’t change them.', 'kontor-shop'),
                    __('If you revoke an access, it stops working immediately. Administrators can revoke the accesses of the whole team, for example when someone leaves.', 'kontor-shop'),
                ],
                'sections' => [
                    [
                        'title'      => __('For customers: what Free already does and what Pro adds', 'kontor-shop'),
                        'paragraphs' => [
                            __('In the free version, you can switch on the shop access for customers under Settings → AI assistants. Customers’ assistants can then search your products and see regular prices and whether something is in stock. They see only what any visitor sees on the website, without logging in.', 'kontor-shop'),
                            __('With Pro, the customer’s assistant can also fill the cart, prepare a checkout link that is valid for 24 hours, and look up the order status with the order number and email address. The customer always places the order themselves.', 'kontor-shop'),
                            __('Both accesses stay off until you switch them on.', 'kontor-shop'),
                        ],
                    ],
                    [
                        'title'      => __('Data protection', 'kontor-shop'),
                        'paragraphs' => [
                            __('When you ask about orders, customer data such as names and addresses goes to the AI provider. Only use providers your club has approved for this, and mention it in your privacy policy.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'      => [
                    [
                        'q' => __('Which apps work?', 'kontor-shop'),
                        'a' => __('Claude, also on the free plan. ChatGPT with developer mode, depending on your plan. Gemini where custom apps are available. And other apps that support MCP over HTTP.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('I lost my phone.', 'kontor-shop'),
                        'a' => __('Open Kontor Shop → AI assistant and click “Revoke” on that access. It stops working immediately.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Can the assistant place orders for customers?', 'kontor-shop'),
                        'a' => __('No. It can fill the cart and prepare a checkout link, but the customer places the order.', 'kontor-shop'),
                    ],
                ],
                'related'  => ['pro-overview', 'pro-audit', 'setup-roles', 'pro-coupons'],
                'help_pages' => ['agents', 'settings-agents'],
            ],
            [
                'id'       => 'pro-seo',
                'group'    => 'pro',
                'pro'      => true,
                'title'    => __('Search engine extras', 'kontor-shop'),
                'summary'  => __('Product data for Google, category pages and approved notes on returns, delivery time and warranty.', 'kontor-shop'),
                'keywords' => __('seo google search engine structured data json-ld schema category pages meridian returns warranty delivery time sitemap', 'kontor-shop'),
                'what'     => __('Pro helps search engines and AI search understand your products. Product pages get structured data with price and availability, each category gets its own page, and approved notes on returns, delivery time and warranty appear on product pages.', 'kontor-shop'),
                'steps'    => [
                    ['text' => __('Open Settings → Shipping & returns and scroll down to “Returns & delivery time”.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=shipping')],
                    __('Enter the return period in days, who pays for return shipping, the processing time and the delivery time.', 'kontor-shop'),
                    __('Enter the two-letter country code, for example AT, tick “This information has been reviewed by the club and may be published” and save.', 'kontor-shop'),
                    ['text' => __('Under Settings → Legal & warranty, enter the warranty in months and an optional note, and save.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=legal')],
                    ['text' => __('Under Settings → Design, set “Products per category page”.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings&tab=design')],
                    __('If you use the Meridian SEO plugin, keep it at version 0.7.0 or newer.', 'kontor-shop'),
                ],
                'effect'   => [
                    __('Each product page carries structured data for Google: name, regular price, availability, and product number, brand and GTIN if you entered them. There are no made-up ratings.', 'kontor-shop'),
                    __('Search engines only ever see the regular price. The member price is shown only to logged-in members.', 'kontor-shop'),
                    __('Each category gets its own page under /shop/kategorie/…, including its subcategories and split into pages.', 'kontor-shop'),
                    __('Returns and delivery time appear on product pages and in the structured data only after approval and with a country. The warranty appears only as text on the product page.', 'kontor-shop'),
                    __('With Meridian SEO, the shop hands its products to Meridian for the sitemap and search data, and Meridian takes care of titles and descriptions. Without Meridian, the shop outputs its own title, description and canonical address.', 'kontor-shop'),
                ],
                'faq'      => [
                    [
                        'q' => __('Why are there no default values for returns?', 'kontor-shop'),
                        'a' => __('These details bind your club. They must match your terms and the cancellation policy, so the shop publishes nothing until you enter and approve them.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Meridian SEO shows a notice that it is too old.', 'kontor-shop'),
                        'a' => __('Update Meridian SEO to version 0.7.0 or newer. Until then, the shop hands no search engine data to it.', 'kontor-shop'),
                    ],
                    [
                        'q' => __('Which product details help most?', 'kontor-shop'),
                        'a' => __('A clear name, a description, an image with alt text, and if you have them the product number, brand and GTIN (EAN).', 'kontor-shop'),
                    ],
                ],
                'related'  => ['shop-pages', 'setup-legal', 'setup-shipping', 'products-create', 'pro-members'],
            ],
        ];
    }
}
