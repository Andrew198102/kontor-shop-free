<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\HelpCenter\Content;

use Kontor\Shop\Admin\CategoriesPage;
use Kontor\Shop\Admin\Settings\Section;
use Kontor\Shop\Admin\WarehousesPage;

/**
 * Artikel des Hilfe-Centers (Schema: Admin\HelpCenter\HelpCenter).
 * Gruppe "setup": je Einstellungsbereich ein Artikel (Hilfe-Schluessel
 * settings-<tab>), dazu Kategorien, Lager und die Einstellungsseite selbst.
 */
final class SetupArticles
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function articles(): array
    {
        return [
            self::payment(),
            self::shipping(),
            self::legal(),
            self::membership(),
            self::mail(),
            self::templates(),
            self::roles(),
            self::system(),
            self::agents(),
            self::categories(),
            self::warehouses(),
            self::settings(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payment(): array
    {
        return [
            'id'         => 'setup-payment',
            'group'      => 'setup',
            'title'      => __('Payment & sales tax', 'kontor-shop'),
            'summary'    => __('Bank accounts, payment methods, payment deadlines and VAT.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'iban bic bank account transfer prepayment invoice cash payment deadline overdue reservation vat tax rate exempt small business',
            'what'       => __('Here you decide how customers pay and which bank account they transfer the money to. You also set the payment deadlines and whether the shop shows VAT.', 'kontor-shop'),
            'steps'      => [
                ['text' => __('Open Kontor Shop → Settings → Payment & sales tax.', 'kontor-shop'), 'url' => Section::url('payment')],
                __('Under “Bank accounts for prepayment”, fill in the empty row: label, account holder, IBAN and BIC.', 'kontor-shop'),
                __('Under “Payment methods”, tick “Offer prepayment (bank transfer)”, “Offer purchase on invoice” or both.', 'kontor-shop'),
                __('Check the “Payment deadline for prepayment” and, if you offer invoices, the “Payment due date”.', 'kontor-shop'),
                __('Click “Save” in the bar at the bottom of the form.', 'kontor-shop'),
                __('Further down under “VAT”, enter your tax rates or leave the field empty. This form has its own “Save” button.', 'kontor-shop'),
            ],
            'effect'     => [
                __('After ordering, the confirmation page and the order confirmation email show the amount, account holder, IBAN, BIC, payment reference and deadline (“please transfer by …”).', 'kontor-shop'),
                __('Checkout only offers the payment methods you ticked. If there are two, each one has a short explanation for the customer.', 'kontor-shop'),
                __('When the deadline has passed and an order is still unpaid, it counts as “overdue” in the order list. The free version never cancels an order on its own.', 'kontor-shop'),
                __('With tax rates, checkout, emails and the order page show the VAT included in the price. Without tax rates, VAT appears nowhere and checkout shows your notice for tax exemption.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title'      => __('Bank accounts', 'kontor-shop'),
                    'paragraphs' => [
                        __('The account with the dot in the “Default” column is the default account. Every warehouse without its own account uses it.', 'kontor-shop'),
                        __('IBAN and BIC are checked when you save. If the length or the checksum is wrong, you see a warning. The account is saved anyway, so compare the number with your bank statement.', 'kontor-shop'),
                        __('To add another account, fill in the last, empty row and save. An account can only be removed if no warehouse and no past order uses it. This keeps old orders correct.', 'kontor-shop'),
                        __('Grayed-out accounts come from an extension. You can only change them there.', 'kontor-shop'),
                    ],
                ],
                [
                    'title' => __('Payment methods', 'kontor-shop'),
                    'list'  => [
                        __('Prepayment (bank transfer): the customer transfers the money first. You ship or hand over the goods after the payment has arrived and you have marked the order as paid.', 'kontor-shop'),
                        __('On invoice: the warehouse gets the shipping order right away, without waiting for the payment. The customer pays within the payment term after shipping or pickup. A shipped order is never cancelled. Unpaid invoices only get reminders.', 'kontor-shop'),
                        __('At least one payment method must be open to every buyer. If you untick both, prepayment stays on.', 'kontor-shop'),
                        __('With Kontor Shop Pro, you can offer “On invoice” to recognized members only.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('Cash on pickup', 'kontor-shop'),
                    'paragraphs' => [
                        __('Tick “Offer cash payment on pickup” under Payment methods. Customers see “Cash on pickup” at checkout only when they choose self pickup (Shipping & returns must allow it).', 'kontor-shop'),
                        __('The warehouse gets the order to set the goods aside right away. Click “Ready for pickup” in the order when everything is ready. When the customer collects and pays, record the pickup – the cash payment is booked at the same time. There is no payment deadline, no reminder and no automatic cancellation.', 'kontor-shop'),
                    ],
                ],
                [
                    'title' => __('Reservation and deadlines', 'kontor-shop'),
                    'list'  => [
                        __('Reservation at checkout (5 to 60 minutes, default 15): while a customer has checkout open, the goods are held for them. This prevents two people from buying the last item. If they don’t order, the goods are released again.', 'kontor-shop'),
                        __('Payment deadline for prepayment (default 14 days, 0 = no deadline): appears on the confirmation page and in the order email. After that, an unpaid order counts as “overdue”. The goods stay reserved until you cancel the order.', 'kontor-shop'),
                        __('Payment due date for invoices (default 14 days, 0 = no term): counted from the day the whole order is shipped or picked up. It appears in the shipping email and on the customer’s order page.', 'kontor-shop'),
                        __('Automatic payment reminders and automatic cancellation of unpaid orders come with Kontor Shop Pro.', 'kontor-shop'),
                    ],
                ],
                [
                    'title' => __('VAT', 'kontor-shop'),
                    'list'  => [
                        __('All prices you enter are final prices including VAT. The shop calculates the VAT from them.', 'kontor-shop'),
                        __('Tax rates (%): all rates you need, separated by spaces or semicolons, e.g. “20 10”. Leave the field empty if your club doesn’t charge VAT, e.g. under the small business exemption.', 'kontor-shop'),
                        __('Default for new products: the rate that is preselected when you create a product. You can change it per product. The list only appears after the tax rates are saved.', 'kontor-shop'),
                        __('VAT on shipping cost: “Proportional” splits shipping by the goods value per tax rate. You can also choose a fixed rate or no VAT on shipping.', 'kontor-shop'),
                        __('Notice for tax exemption: shown at checkout when there are products without a tax rate in the cart.', 'kontor-shop'),
                        __('Agree on the tax rates and the wording of the notice with your tax advisor.', 'kontor-shop'),
                    ],
                ],
            ],
            'faq'        => [
                ['q' => __('What is the QR code on the order page?', 'kontor-shop'), 'a' => __('A “GiroCode” with all payment details. Customers scan it with their banking app, and recipient, IBAN, amount and payment reference are filled in – nobody mistypes the reference, so payments are easier to match. You can switch it off under Payment methods. It needs a valid IBAN and works for transfers in euros.', 'kontor-shop')],
                ['q' => __('Do I need a payment provider like PayPal?', 'kontor-shop'), 'a' => __('No. Customers transfer the money to your club account, and you mark the order as paid once it has arrived. The shop doesn’t look into your bank account itself.', 'kontor-shop')],
                ['q' => __('I see a warning about my IBAN. Was it saved?', 'kontor-shop'), 'a' => __('Yes. The warning only means that the length or the checksum looks wrong. Correct the IBAN and save again.', 'kontor-shop')],
                ['q' => __('Which account does a customer transfer to?', 'kontor-shop'), 'a' => __('To the account of the warehouse the products come from. Warehouses without their own account use the default account. If a cart has products for different accounts, it is split into several orders, each with its own bank details.', 'kontor-shop')],
                ['q' => __('Why can’t I remove an account?', 'kontor-shop'), 'a' => __('A warehouse or a past order still uses it. Assign a different account to the warehouse first. Accounts used by past orders stay, so these orders remain correct.', 'kontor-shop')],
            ],
            'related'    => ['setup-warehouses', 'setup-shipping', 'orders-paid', 'pro-reminders'],
            'help_pages' => ['settings-payment'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function shipping(): array
    {
        return [
            'id'         => 'setup-shipping',
            'group'      => 'setup',
            'title'      => __('Shipping & pickup', 'kontor-shop'),
            'summary'    => __('Shipping costs, free shipping, pickup, countries and checkout fields.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'shipping cost flat rate free shipping threshold self pickup collect countries phone billing address order status link split orders returns delivery time',
            'what'       => __('Here you set what shipping costs, when it is free, whether customers may pick up their order, and what checkout asks for.', 'kontor-shop'),
            'steps'      => [
                ['text' => __('Open Kontor Shop → Settings → Shipping. With Pro, the section is called “Shipping & returns”.', 'kontor-shop'), 'url' => Section::url('shipping')],
                __('Enter the “Flat shipping rate” per order, e.g. 5.90.', 'kontor-shop'),
                __('Optional: enter an amount under “Free shipping from”.', 'kontor-shop'),
                __('Tick “Self pickup” if customers may collect their order.', 'kontor-shop'),
                __('Tick the countries you deliver to under “Shipping countries”.', 'kontor-shop'),
                __('Click “Save” at the bottom.', 'kontor-shop'),
            ],
            'effect'     => [
                __('Checkout calculates shipping right away: pickup costs nothing, orders from the free-shipping amount cost nothing, all others pay the flat rate.', 'kontor-shop'),
                __('With self pickup ticked, checkout offers the “Pickup” option.', 'kontor-shop'),
                __('For delivery, customers can only choose a country you ticked. The postal code is checked to match the country.', 'kontor-shop'),
                __('Every email to the customer contains a personal link to the status of their order. It works while the order is in progress and for the number of days you set afterwards.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title'      => __('How shipping is calculated', 'kontor-shop'),
                    'paragraphs' => [__('The shop checks these rules from top to bottom and uses the first one that fits:', 'kontor-shop')],
                    'list'       => [
                        __('Self pickup chosen: €0.', 'kontor-shop'),
                        __('With Pro, if turned on: members pay no shipping.', 'kontor-shop'),
                        __('The goods value reaches “Free shipping from”: €0.', 'kontor-shop'),
                        __('Otherwise: the flat shipping rate.', 'kontor-shop'),
                        __('0 as flat rate means free shipping for everyone. 0 under “Free shipping from” means there is no such threshold.', 'kontor-shop'),
                    ],
                ],
                [
                    'title' => __('Fields at checkout', 'kontor-shop'),
                    'list'  => [
                        __('Phone number at checkout: don’t ask, optional or required. Useful for questions about the delivery.', 'kontor-shop'),
                        __('Billing address: lets customers enter a different billing address or a company with VAT ID.', 'kontor-shop'),
                        __('Order status link for customers: how many days after shipping, pickup or cancellation the link in the emails still works (1 to 365, default 30).', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('Shipping for split orders', 'kontor-shop'),
                    'paragraphs' => [
                        __('If a cart contains products from warehouses with different bank accounts, the shop splits it into one order per account. With only one bank account, this never happens.', 'kontor-shop'),
                        __('“Separate”: each order calculates its own shipping, so the customer may pay twice. “Unified”: shipping is calculated once for the whole cart. “Custom price”: a fixed flat rate you set for split orders. With “Unified” and “Custom price”, shipping is charged on the first order only.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('Returns & delivery time (Pro)', 'kontor-shop'),
                    'paragraphs' => [__('With Kontor Shop Pro, this section has a second form “Returns & delivery time”. What you enter and approve there appears on the product pages and in the data for Google and AI search.', 'kontor-shop')],
                ],
            ],
            'faq'        => [
                ['q' => __('Can I charge more for heavy items or for other countries?', 'kontor-shop'), 'a' => __('No. The shop knows one flat rate per order, a free-shipping amount and pickup. Choose a flat rate that covers your usual parcel.', 'kontor-shop')],
                ['q' => __('Does pickup depend on the shipping countries?', 'kontor-shop'), 'a' => __('No. Pickup works independently of the countries you ticked.', 'kontor-shop')],
                ['q' => __('Why did a customer get two orders?', 'kontor-shop'), 'a' => __('Their cart had products from warehouses with different bank accounts. Each account gets its own order, so the money goes to the right account. Shipping then follows “Shipping for split orders”.', 'kontor-shop')],
            ],
            'related'    => ['setup-payment', 'setup-warehouses', 'orders-ship', 'pro-members', 'pro-seo'],
            'help_pages' => ['settings-shipping'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function legal(): array
    {
        return [
            'id'         => 'setup-legal',
            'group'      => 'setup',
            'title'      => __('Legal texts & checkout confirmations', 'kontor-shop'),
            'summary'    => __('Link terms, withdrawal and privacy pages, set checkbox and button texts.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'legal terms conditions agb withdrawal privacy policy imprint legal notice checkbox consent order button price note retention gdpr spam protection bots warranty',
            'what'       => __('Here you link your legal texts to checkout and decide what customers must confirm before they order. The texts themselves are normal WordPress pages that your club writes.', 'kontor-shop'),
            'steps'      => [
                __('Create the pages under Pages → Add New in WordPress, e.g. terms and conditions, right of withdrawal, privacy policy and legal notice, and publish them.', 'kontor-shop'),
                ['text' => __('Open Kontor Shop → Settings → Legal.', 'kontor-shop'), 'url' => Section::url('legal')],
                __('Under “1. Link legal texts”, choose the matching page in each list.', 'kontor-shop'),
                __('Under “2. Confirmations in the checkout”, check the required checkboxes and their texts.', 'kontor-shop'),
                __('Under “3. Texts at the order button”, check the “Order button text”.', 'kontor-shop'),
                __('Click “Save” at the bottom.', 'kontor-shop'),
            ],
            'effect'     => [
                __('Checkout shows the checkboxes with links to your pages. A customer can only order after ticking them. The time of consent is saved with the order.', 'kontor-shop'),
                __('The last button in checkout shows your text.', 'kontor-shop'),
                __('As soon as the terms and privacy pages are selected, the “open” marker next to Legal disappears.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title'      => __('Placeholders in the checkbox texts', 'kontor-shop'),
                    'paragraphs' => [__('These placeholders become links to the pages you selected. If a page isn’t selected, the word appears without a link.', 'kontor-shop')],
                    'list'       => [
                        __('{agb}: terms and conditions', 'kontor-shop'),
                        __('{widerruf}: right of withdrawal', 'kontor-shop'),
                        __('{datenschutz}: privacy policy', 'kontor-shop'),
                        __('{impressum}: legal notice', 'kontor-shop'),
                        __('Example: I accept the {agb} and have read the {widerruf}.', 'kontor-shop'),
                    ],
                ],
                [
                    'title' => __('Texts at the order button', 'kontor-shop'),
                    'list'  => [
                        __('Additional price text: an optional line above the order button. The VAT line is added automatically from “Payment & sales tax”.', 'kontor-shop'),
                        __('Order button text: in Austria, the button must make clear that the order has to be paid, e.g. “Order with obligation to pay”.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('Privacy & retention', 'kontor-shop'),
                    'paragraphs' => [
                        __('Order data are accounting records, in Austria usually for 7 years. The shop never deletes anything on its own. If someone asks for erasure via WordPress → Tools → Erase Personal Data, their orders are only anonymized after the retention period.', 'kontor-shop'),
                        __('Suggested text for your privacy policy is in WordPress under Settings → Privacy.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('Spam protection', 'kontor-shop'),
                    'paragraphs' => [__('Checkout protects itself against bots without a captcha and without settings: a hidden field that only bots fill in, a check that the form wasn’t sent within seconds, and at most 5 orders from the same internet connection within 15 minutes.', 'kontor-shop')],
                ],
                [
                    'title'      => __('Warranty (Pro)', 'kontor-shop'),
                    'paragraphs' => [__('With Kontor Shop Pro, this section is called “Legal & warranty” and has a second form “Warranty”. Your warranty details then appear on the product pages and in the data for Google and AI search.', 'kontor-shop')],
                ],
            ],
            'faq'        => [
                ['q' => __('Does Kontor Shop write the legal texts for me?', 'kontor-shop'), 'a' => __('No. The texts come from your club or your legal counsel. The shop only links them and asks for consent.', 'kontor-shop')],
                ['q' => __('Can I turn off the checkboxes?', 'kontor-shop'), 'a' => __('Yes, untick them under “Required checkboxes”. Then customers order without confirming. Ask your legal counsel whether that is allowed for you.', 'kontor-shop')],
                ['q' => __('I use Polylang or WPML. Which page do I choose?', 'kontor-shop'), 'a' => __('The page in the main language. Visitors automatically get the translated page linked, if there is one.', 'kontor-shop')],
                ['q' => __('A customer sees “Too many orders in a short time”.', 'kontor-shop'), 'a' => __('That is the spam protection. It allows at most 5 orders from the same internet connection within 15 minutes. This limit can be reached when many people order over the same Wi-Fi, e.g. at a club event. Ask the customer to try again a few minutes later.', 'kontor-shop')],
            ],
            'related'    => ['shop-pages', 'setup-payment', 'pro-seo'],
            'help_pages' => ['settings-legal'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function membership(): array
    {
        return [
            'id'         => 'setup-membership',
            'group'      => 'setup',
            'title'      => __('Membership & member benefits', 'kontor-shop'),
            'summary'    => __('In the free version everyone is a guest. Pro recognizes members.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'membership member price discount login provider fee paid grace period guest benefits',
            'what'       => __('Members can get benefits in the shop: a member price, free shipping and payment after receipt. For that, the shop must know who is a member. This needs Kontor Shop Pro.', 'kontor-shop'),
            'steps'      => [
                __('Decide whether members should get benefits at all. If not, there is nothing to set up.', 'kontor-shop'),
                ['text' => __('If yes: get Kontor Shop Pro.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-pricing'), 'link' => __('Plans & pricing', 'kontor-shop')],
                __('Open Kontor Shop → Settings → Membership. This section only exists with Pro.', 'kontor-shop'),
                __('Choose the “Membership provider” your club uses for member logins.', 'kontor-shop'),
                __('Choose who gets benefits under “Membership benefits apply to” and click “Save”.', 'kontor-shop'),
                __('Set the member prices on the products, and the member options under Shipping and Payment.', 'kontor-shop'),
            ],
            'effect'     => [
                __('Free version: nobody counts as a member. Everyone pays the normal price and normal shipping and sees the payment methods you set.', 'kontor-shop'),
                __('With Pro and a provider: logged-in members see their member price, and the benefits you turned on apply at checkout.', 'kontor-shop'),
                __('Their name, email, phone and address are prefilled at checkout. Only what is sent with the order is saved.', 'kontor-shop'),
                __('Visitors who aren’t logged in see “Member? Log in now” in the cart and at checkout, as long as the login notice is on.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title' => __('The fields with Pro', 'kontor-shop'),
                    'list'  => [
                        __('Membership provider: how the shop knows whether someone is a member. The shop has no login of its own. “No membership provider” means everyone is a guest.', 'kontor-shop'),
                        __('Membership benefits apply to: all active members, or only members whose fee for the current year is marked as paid.', 'kontor-shop'),
                        __('Previous year’s fee is still valid until: only for “paid fee”. Example 31.03.: until the end of March, last year’s fee is enough.', 'kontor-shop'),
                        __('Login notice and login page: the link in the cart and at checkout. Leave the login page empty to use the provider’s own login page.', 'kontor-shop'),
                        __('The shop checks the membership status again at most every 5 minutes. A cancelled membership or a missing fee takes effect within 5 minutes.', 'kontor-shop'),
                    ],
                ],
            ],
            'faq'        => [
                ['q' => __('Can I give members a discount in the free version?', 'kontor-shop'), 'a' => __('Not automatically, because the free version doesn’t know who is a member. Member prices and discount codes come with Kontor Shop Pro.', 'kontor-shop')],
                ['q' => __('Do members need a separate shop account?', 'kontor-shop'), 'a' => __('No. Members log in where your club already manages them, through the membership provider. The shop then recognizes them.', 'kontor-shop')],
            ],
            'related'    => ['pro-members', 'pro-coupons', 'setup-payment', 'setup-shipping'],
            'help_pages' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mail(): array
    {
        return [
            'id'         => 'setup-mail',
            'group'      => 'setup',
            'title'      => __('Email sending', 'kontor-shop'),
            'summary'    => __('Sender, SMTP plugin, recipients of “New order” and the outbox.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'email mail smtp wp mail smtp sender from reply-to spam not arriving new order notification outbox retry powered by',
            'what'       => __('The shop sends emails to customers and to you. Here you set where they come from and who hears about new orders, so that the emails arrive and don’t end up in spam.', 'kontor-shop'),
            'steps'      => [
                ['text' => __('Open Kontor Shop → Settings → Email sending.', 'kontor-shop'), 'url' => Section::url('mail')],
                __('If the notice under “Email sending method” recommends it, install and set up an SMTP plugin such as WP Mail SMTP.', 'kontor-shop'),
                __('Enter a “Sender name”, e.g. the name of your club shop.', 'kontor-shop'),
                __('Enter a “Sender address” on your website’s domain and a “Reply to” address that someone reads.', 'kontor-shop'),
                __('Under “Shop owner address”, enter who gets new orders. Separate several addresses with commas.', 'kontor-shop'),
                ['text' => __('Click “Save”, then send a test email under Settings → System.', 'kontor-shop'), 'url' => Section::url('system')],
            ],
            'effect'     => [
                __('Customers see your sender name and address in their inbox. Their replies go to the “Reply to” address.', 'kontor-shop'),
                __('The shop owner address gets “New order” and warnings about serious errors. If it is empty, the email address of the WordPress administrator is used.', 'kontor-shop'),
                __('If an email can’t be sent, it waits in the “Outbox” at the bottom and is retried automatically after 5 minutes, 30 minutes, 2, 6 and 24 hours.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title'      => __('Why an SMTP plugin?', 'kontor-shop'),
                    'paragraphs' => [
                        __('Without one, WordPress sends emails straight from the web server. Many mailboxes treat them as spam or reject them. An SMTP plugin sends through a real mailbox of your club instead.', 'kontor-shop'),
                        __('The section shows whether an SMTP plugin was found. If you may install plugins, you also get a link to install or set up WP Mail SMTP.', 'kontor-shop'),
                    ],
                ],
                [
                    'title' => __('Sender and reply address', 'kontor-shop'),
                    'list'  => [
                        __('Sender name: how the sender appears in the inbox. Empty uses the default of WordPress or the SMTP plugin.', 'kontor-shop'),
                        __('Sender address: use an address on your own domain that your SMTP account may send from. Addresses like @gmail.com usually end up in spam. The section warns you if the domain doesn’t match.', 'kontor-shop'),
                        __('If “Force From Email” is on in WP Mail SMTP, its address always wins. The section then tells you.', 'kontor-shop'),
                        __('Reply to: optional. Useful when the sender is a no-reply address.', 'kontor-shop'),
                    ],
                ],
                [
                    'title' => __('Outbox', 'kontor-shop'),
                    'list'  => [
                        __('Emails that no sending method accepted are listed here with the last error.', 'kontor-shop'),
                        __('After the last automatic attempt, an email counts as “abandoned”.', 'kontor-shop'),
                        __('“Resend now” tries all listed emails again, e.g. after you fixed the SMTP plugin.', 'kontor-shop'),
                        __('“Discard” removes a single email. Abandoned emails are deleted after 30 days.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('The line “Club shop with Kontor Shop”', 'kontor-shop'),
                    'paragraphs' => [__('In the free version, you can show a small line “Club shop with Kontor Shop” with a link under your emails and on the order confirmation. It is voluntary and only appears if you agree. Untick it under “4. Support Kontor Shop” to remove it. Pro never shows this line.', 'kontor-shop')],
                ],
            ],
            'faq'        => [
                ['q' => __('Customers say they got no order confirmation.', 'kontor-shop'), 'a' => __('Ask them to check their spam folder. Then look at the outbox in this section and at “Last email sent” in Settings → System. The most common causes are a missing SMTP plugin or a sender address from another domain.', 'kontor-shop')],
                ['q' => __('Can several people get the “New order” email?', 'kontor-shop'), 'a' => __('Yes. Enter several addresses separated by commas, or add users and addresses under Settings → Email templates → “New order”.', 'kontor-shop')],
                ['q' => __('Where do I change what the emails say?', 'kontor-shop'), 'a' => __('Under Kontor Shop → Settings → Email templates.', 'kontor-shop')],
            ],
            'related'    => ['setup-templates', 'setup-system', 'problem-emails'],
            'help_pages' => ['settings-mail'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function templates(): array
    {
        return [
            'id'         => 'setup-templates',
            'group'      => 'setup',
            'title'      => __('Email templates', 'kontor-shop'),
            'summary'    => __('Which emails the shop sends and what they say.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'email template text subject placeholder order confirmation new order reminder shipped cancelled recipients test email reset default',
            'what'       => __('The shop comes with ready-made texts for every email. Here you switch single emails on or off, change their texts and choose who gets the internal ones.', 'kontor-shop'),
            'steps'      => [
                ['text' => __('Open Kontor Shop → Settings → Email templates.', 'kontor-shop'), 'url' => Section::url('templates')],
                __('Click an email in the list on the left, e.g. “Order confirmation”.', 'kontor-shop'),
                __('Change the subject and the text. Click a placeholder to insert it where the cursor is.', 'kontor-shop'),
                __('For internal emails, choose the people under “Recipients”.', 'kontor-shop'),
                __('Click “Save & send test email to me” to see the email with sample data.', 'kontor-shop'),
                __('Click “Save” when you are happy with it.', 'kontor-shop'),
            ],
            'effect'     => [
                __('From the next email on, your text is used. The placeholders are replaced with the data of the real order.', 'kontor-shop'),
                __('An email with “Send this email” unticked isn’t sent anymore. The list shows it as “off”.', 'kontor-shop'),
                __('“Reset to default” brings back the original subject and text of this one email. Your changes to it are lost.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title' => __('The emails', 'kontor-shop'),
                    'list'  => [
                        __('Order confirmation: to the customer right after ordering, with amount, IBAN, payment reference and deadline.', 'kontor-shop'),
                        __('New order: internal, for every new order.', 'kontor-shop'),
                        __('Payment reminder and invoice payment reminder: to the customer when you click “Send payment reminder” in the order.', 'kontor-shop'),
                        __('Payment received: to the customer when you mark the order as paid.', 'kontor-shop'),
                        __('Shipping order to warehouse: internal, one email per warehouse with its products. For prepayment after the payment, for “On invoice” right after ordering.', 'kontor-shop'),
                        __('Shipped / picked up: to the customer when the order is marked as shipped or picked up.', 'kontor-shop'),
                        __('Ready for pickup: to the customer of a pickup order when you click “Ready for pickup” in the order, with the warehouse address.', 'kontor-shop'),
                        __('Order cancelled, Product cancelled and Return received: to the customer, with the refund or the new amount.', 'kontor-shop'),
                        __('Cancellation / refund: internal, e.g. so the cashier takes care of the refund.', 'kontor-shop'),
                        __('Pro adds more emails, e.g. for back-in-stock requests.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('Placeholders', 'kontor-shop'),
                    'paragraphs' => [
                        __('Placeholders look like {{order_number}}. Each email only offers the placeholders that fit it, with plain names like “Order number” or “Payment details (bank, deadline)”.', 'kontor-shop'),
                        __('Don’t change the text between the curly brackets, otherwise it isn’t replaced anymore.', 'kontor-shop'),
                        __('Sender and reply address aren’t set here but under Email sending.', 'kontor-shop'),
                    ],
                ],
            ],
            'faq'        => [
                ['q' => __('Can I turn off the order confirmation?', 'kontor-shop'), 'a' => __('Yes, but then the customer only sees the amount, bank details and payment reference on the confirmation page in the browser. The shop warns you about this. “Resend confirmation” in the order still works.', 'kontor-shop')],
                ['q' => __('Where does the test email go?', 'kontor-shop'), 'a' => __('To the email address of your own WordPress user. It contains sample data, not a real order.', 'kontor-shop')],
                ['q' => __('I messed up a template. How do I get the original back?', 'kontor-shop'), 'a' => __('Open the template and click “Reset to default”. Only this one email is reset.', 'kontor-shop')],
            ],
            'related'    => ['setup-mail', 'problem-emails', 'orders-paid'],
            'help_pages' => ['settings-templates'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function roles(): array
    {
        return [
            'id'         => 'setup-roles',
            'group'      => 'setup',
            'title'      => __('Roles & permissions', 'kontor-shop'),
            'summary'    => __('Who may do what: administrator, shop manager, cashier.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'roles permissions rights users cashier treasurer shop manager administrator warehouse manager access team',
            'what'       => __('Not everyone in the club needs full access. Here you make people cashiers, so they can record payments without changing products or settings.', 'kontor-shop'),
            'steps'      => [
                __('If the person has no WordPress user yet, create one under Users → Add New.', 'kontor-shop'),
                __('For a shop manager: open the user under Users, choose the role “Shop manager” and save.', 'kontor-shop'),
                ['text' => __('For a cashier: open Kontor Shop → Settings → Roles.', 'kontor-shop'), 'url' => Section::url('roles')],
                __('Tick “Cashier” next to the person. If the list is long, use the search field.', 'kontor-shop'),
                __('Click “Save”.', 'kontor-shop'),
            ],
            'effect'     => [
                __('From their next page load, the person sees the Kontor Shop menu with the areas their role allows.', 'kontor-shop'),
                __('If you untick someone, they lose these permissions right away.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title' => __('Who sees what', 'kontor-shop'),
                    'list'  => [
                        __('Administrator: everything, including settings, email templates and logs. Administrators always have all permissions; their row shows “automatic”.', 'kontor-shop'),
                        __('Shop manager (a WordPress role): orders, products, stock, categories and warehouse. No settings, no email templates, no logs.', 'kontor-shop'),
                        __('Cashier: sees the dashboard and the orders. Can mark orders as paid, send payment reminders, cancel orders and record returns and refunds. Can’t change products, book stock or ship orders.', 'kontor-shop'),
                        __('Warehouse manager: comes with Kontor Shop Pro. The person responsible for a warehouse only sees its orders and stock and packs its parcels.', 'kontor-shop'),
                    ],
                ],
            ],
            'faq'        => [
                ['q' => __('Why can’t a cashier mark an order as shipped?', 'kontor-shop'), 'a' => __('Shipping changes the stock. That is for administrators and shop managers, and with Pro also for the warehouse manager of the warehouse.', 'kontor-shop')],
                ['q' => __('Why can’t I tick or untick some people?', 'kontor-shop'), 'a' => __('They are administrators. Administrators always have all permissions.', 'kontor-shop')],
                ['q' => __('Can a shop manager change the bank account?', 'kontor-shop'), 'a' => __('No. Bank accounts are in the settings, which only administrators can open.', 'kontor-shop')],
            ],
            'related'    => ['orders-paid', 'pro-warehouses', 'pro-audit'],
            'help_pages' => ['settings-roles'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function system(): array
    {
        return [
            'id'         => 'setup-system',
            'group'      => 'setup',
            'title'      => __('System check, test email & uninstall', 'kontor-shop'),
            'summary'    => __('Check that the shop runs, send a test email, choose what happens on deletion.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'system check status health test email cron database pages shop cart checkout uninstall delete data backup',
            'what'       => __('Here you see in one place whether the shop is ready: database, background tasks, emails, shop pages and the most important settings. You also decide what happens to your data if the plugin is ever deleted.', 'kontor-shop'),
            'steps'      => [
                ['text' => __('Open Kontor Shop → Settings → System.', 'kontor-shop'), 'url' => Section::url('system')],
                __('Go through the “System check”. A tick means fine, a warning sign means please check, a cross means something doesn’t work.', 'kontor-shop'),
                __('Click “Send test email”. It goes to the first recipient of “New order”.', 'kontor-shop'),
                __('Check that inbox, including the spam folder.', 'kontor-shop'),
                __('Under “Uninstall”, choose whether the data is kept or removed when the plugin is deleted, and click “Save”.', 'kontor-shop'),
            ],
            'effect'     => [
                __('The notices in the system check don’t block anything. Customers can order even if there is a warning.', 'kontor-shop'),
                __('After the test email you see whether the server accepted it. Only your inbox shows whether it really arrived.', 'kontor-shop'),
                __('The uninstall choice only matters when the plugin is deleted in the plugin list. Deactivating never changes any data.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title' => __('What the system check shows', 'kontor-shop'),
                    'list'  => [
                        __('Database tables, database engine and cron: the technical basics. If tables are missing or the cron isn’t scheduled, deactivate and reactivate the plugin once.', 'kontor-shop'),
                        __('Email: whether WordPress can send, whether an SMTP plugin is found, who gets “New order” and whether the last email failed.', 'kontor-shop'),
                        __('Error log (24 h): errors of the last day. The details are under Kontor Shop → Logs.', 'kontor-shop'),
                        __('Shop page, cart page, checkout page: whether the three shop pages exist and are published.', 'kontor-shop'),
                        __('Warehouse, bank account, legal texts and VAT: whether the basic settings are in place.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('Shop pages', 'kontor-shop'),
                    'paragraphs' => [
                        __('When the plugin is activated, it creates the pages Shop, Cart and Checkout with the matching shortcodes.', 'kontor-shop'),
                        __('If a page shows “missing or not published”, look under Pages → Trash in WordPress and restore it, or publish it again. If you deleted it permanently, deactivate and reactivate the plugin: missing pages are created again.', 'kontor-shop'),
                    ],
                ],
                [
                    'title' => __('Uninstall', 'kontor-shop'),
                    'list'  => [
                        __('“Remove only the plugin – keep data”: products, orders, warehouses, settings, pages and images stay. After a reinstall, everything is back. This is the safe choice.', 'kontor-shop'),
                        __('“Remove the plugin and all data”: deletes all shop tables, settings, the shop pages, images uploaded only for the shop, the “Shop manager” role and all shop permissions. This can’t be undone, so make a backup first.', 'kontor-shop'),
                        __('The same question also appears when you deactivate the plugin.', 'kontor-shop'),
                        __('If KONTOR_SHOP_UNINSTALL_PURGE_ALL is set in wp-config.php, all data is always removed and the choice is locked.', 'kontor-shop'),
                    ],
                ],
            ],
            'faq'        => [
                ['q' => __('The “Send test email” button is grayed out.', 'kontor-shop'), 'a' => __('There is no valid recipient for “New order”. Enter an address under Settings → Email sending → “Shop owner address”, and check the recipients under Email templates → “New order”.', 'kontor-shop')],
                ['q' => __('The server accepted the test email, but it didn’t arrive.', 'kontor-shop'), 'a' => __('The email was lost on the way. Check the spam folder, then set up an SMTP plugin (see Email sending).', 'kontor-shop')],
                ['q' => __('The cron line says “WP-Cron is turned off”.', 'kontor-shop'), 'a' => __('Your host has turned off the built-in WordPress timer. Ask them for a server cron that calls wp-cron.php regularly. Otherwise reservations aren’t released and failed emails aren’t retried.', 'kontor-shop')],
            ],
            'related'    => ['setup-mail', 'problem-emails', 'shop-pages'],
            'help_pages' => ['settings-system'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function agents(): array
    {
        return [
            'id'         => 'setup-agents',
            'group'      => 'setup',
            'title'      => __('AI assistants: shop access', 'kontor-shop'),
            'summary'    => __('Let your customers’ AI assistants find your products.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'ai assistant chatgpt claude gemini mcp connector chatbot search products',
            'what'       => __('Customers can ask AI assistants such as ChatGPT, Claude or Gemini about your shop. With shop access on, the assistant searches your shop directly and gets correct prices and availability.', 'kontor-shop'),
            'steps'      => [
                ['text' => __('Open Kontor Shop → Settings → AI assistants.', 'kontor-shop'), 'url' => Section::url('agents')],
                __('Tick “Shop access”.', 'kontor-shop'),
                __('Click “Save”.', 'kontor-shop'),
                __('Click “Copy” next to “Address (MCP server URL)”.', 'kontor-shop'),
                __('Share the address, e.g. on your shop page, with a note that customers can add it as a connector in their assistant.', 'kontor-shop'),
            ],
            'effect'     => [
                __('The address now answers AI assistants. Before that, it is switched off.', 'kontor-shop'),
                __('Assistants only see what every visitor sees: products, regular prices and whether something is in stock. No customer data, no orders, no stock figures.', 'kontor-shop'),
                __('If you untick shop access and save, it stops right away.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title'      => __('How customers connect', 'kontor-shop'),
                    'paragraphs' => [__('The section lists the steps for Claude, ChatGPT and Gemini. The menu names of these apps change from time to time; their help pages under “custom connector” or “MCP” have the current steps.', 'kontor-shop')],
                ],
                [
                    'title'      => __('More with Pro', 'kontor-shop'),
                    'paragraphs' => [__('With Kontor Shop Pro, the assistant can also fill the cart and give the customer a ready checkout link. Your team can manage products, images, stock and orders by chat, each person with their own permissions.', 'kontor-shop')],
                ],
            ],
            'faq'        => [
                ['q' => __('Can the assistant place orders or see member prices?', 'kontor-shop'), 'a' => __('Not with the shop access of the free version. It only searches and sees regular prices.', 'kontor-shop')],
                ['q' => __('Can an assistant overload my website?', 'kontor-shop'), 'a' => __('No. Each sender may make at most 600 requests in 10 minutes.', 'kontor-shop')],
            ],
            'related'    => ['pro-agents'],
            'help_pages' => ['settings-agents'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function categories(): array
    {
        return [
            'id'         => 'setup-categories',
            'group'      => 'setup',
            'title'      => __('Categories', 'kontor-shop'),
            'summary'    => __('The filters in the shop: create, sort, color, sub-categories.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'category categories filter sort order drag accent color subcategory parent hide inactive delete',
            'what'       => __('Categories group your products, e.g. “Jerseys”, “Caps” and “Accessories”. In the shop they appear as filter buttons above the products.', 'kontor-shop'),
            'steps'      => [
                ['text' => __('Open Kontor Shop → Categories.', 'kontor-shop'), 'url' => CategoriesPage::url()],
                __('Click “Add new category”.', 'kontor-shop'),
                __('Enter a name. Optional: choose a “Parent category” and an “Accent color”.', 'kontor-shop'),
                __('Click “Add category”.', 'kontor-shop'),
                __('In the list, drag a row or use the arrows to change the order.', 'kontor-shop'),
                __('Assign the category to your products in the product form.', 'kontor-shop'),
            ],
            'effect'     => [
                __('The shop shows “All” and a filter button for each category that has visible products. Empty categories get no button.', 'kontor-shop'),
                __('The order in the list is the order of the buttons in the shop. It applies right away.', 'kontor-shop'),
                __('The accent color colors the buttons and highlights on the product cards of this category. Without it, the main color from Design is used.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title' => __('The fields', 'kontor-shop'),
                    'list'  => [
                        __('Name: appears on the filter button and on the product page.', 'kontor-shop'),
                        __('Parent category: makes this a sub-category. It sets the breadcrumbs above the product page (Shop › Category › Subcategory › Product). With Kontor Shop Pro, every category also gets its own page.', 'kontor-shop'),
                        __('Accent color: for buttons and highlights of this category. Tick “Use default color” to use the shop color.', 'kontor-shop'),
                        __('Status: untick “Category can be selected in the catalog” to hide the category. Its products then disappear from the shop as well, but stay in the backend.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('Deleting a category', 'kontor-shop'),
                    'paragraphs' => [__('A category can only be deleted if no product is assigned to it, archived ones included. Move the products first (product list, bulk action “Assign category …”) or set the category to inactive.', 'kontor-shop')],
                ],
            ],
            'faq'        => [
                ['q' => __('I created a category but don’t see it in the shop.', 'kontor-shop'), 'a' => __('A button only appears once at least one visible product is in the category. Also check that the category is active.', 'kontor-shop')],
                ['q' => __('Products suddenly disappeared from the shop.', 'kontor-shop'), 'a' => __('Check whether their category was set to inactive. Products of inactive categories aren’t shown in the shop.', 'kontor-shop')],
                ['q' => __('Can a product be in several categories?', 'kontor-shop'), 'a' => __('No, each product has exactly one category. To group several categories, give them a common parent category.', 'kontor-shop')],
            ],
            'related'    => ['products-create', 'design', 'pro-seo', 'pro-shop-types'],
            'help_pages' => ['categories'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function warehouses(): array
    {
        return [
            'id'         => 'setup-warehouses',
            'group'      => 'setup',
            'title'      => __('Warehouse', 'kontor-shop'),
            'summary'    => __('Where products are shipped from and which account gets the money.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'warehouse storage location sender return address bank account color inactive several warehouses warehouse manager',
            'what'       => __('Every product belongs to a warehouse: the place where it is stored and shipped from. The warehouse also decides which bank account customers transfer the money to.', 'kontor-shop'),
            'steps'      => [
                ['text' => __('Open Kontor Shop → Warehouses.', 'kontor-shop'), 'url' => WarehousesPage::url()],
                __('Click “Add new warehouse”, or “Edit” on your existing warehouse.', 'kontor-shop'),
                __('Enter a name, e.g. “Main warehouse”, and a contact name if you like.', 'kontor-shop'),
                __('Enter the “Sender/return address”.', 'kontor-shop'),
                __('Choose a “Bank account (prepayment)” or leave “default account”.', 'kontor-shop'),
                __('Click “Create warehouse” or “Save”.', 'kontor-shop'),
            ],
            'effect'     => [
                __('You can now assign products to this warehouse. Without a warehouse, you can’t create products.', 'kontor-shop'),
                __('Customers who buy products from this warehouse transfer the money to its bank account.', 'kontor-shop'),
                __('The color helps you tell warehouses apart, e.g. in the “Record shipment” dialog.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title' => __('The fields', 'kontor-shop'),
                    'list'  => [
                        __('Name and contact name: for you and your team. The contact name is optional.', 'kontor-shop'),
                        __('Sender/return address: where your parcels are sent from and where returns go.', 'kontor-shop'),
                        __('Bank account (prepayment): the account for products from this warehouse. “Default account” uses the default account from Payment & sales tax. You manage the accounts there.', 'kontor-shop'),
                        __('Color: an identifying color in the backend.', 'kontor-shop'),
                        __('Status: inactive warehouses are kept along with their products.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('One warehouse in the free version', 'kontor-shop'),
                    'paragraphs' => [
                        __('The free version manages one warehouse, the main warehouse. Several warehouses, each with its own warehouse manager who gets the shipping orders, come with Kontor Shop Pro.', 'kontor-shop'),
                        __('If you switch from Pro back to the free version, the other warehouses stay visible and their products stay in the shop, but you can only view them.', 'kontor-shop'),
                        __('A warehouse can only be deleted if no product belongs to it, archived ones included.', 'kontor-shop'),
                    ],
                ],
            ],
            'faq'        => [
                ['q' => __('Why can’t I add a second warehouse?', 'kontor-shop'), 'a' => __('The free version has one warehouse. Several warehouses come with Kontor Shop Pro.', 'kontor-shop')],
                ['q' => __('Do I need a separate bank account per warehouse?', 'kontor-shop'), 'a' => __('No. Leave “default account”, and all money goes to the default account. Choose another account only if, e.g., a department of the club manages its own money.', 'kontor-shop')],
            ],
            'related'    => ['setup-payment', 'pro-warehouses', 'products-create', 'stock-delivery'],
            'help_pages' => ['warehouses'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(): array
    {
        return [
            'id'         => 'setup-settings',
            'group'      => 'setup',
            'title'      => __('The settings page', 'kontor-shop'),
            'summary'    => __('How the settings are organized and where to start.', 'kontor-shop'),
            'pro'        => false,
            'keywords'   => 'settings overview sections groups open marker save bar unsaved changes order setup phone',
            'what'       => __('All shop settings are on one page, Kontor Shop → Settings. This guide shows how the page is organized and in which order to set it up.', 'kontor-shop'),
            'steps'      => [
                ['text' => __('Open Kontor Shop → Settings.', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-settings')],
                __('Choose a section in the navigation on the left. On your phone, choose it from the “Area” list at the top.', 'kontor-shop'),
                __('Start with the sections marked “open”. Something required is still missing there.', 'kontor-shop'),
                __('Change the fields, then click “Save” in the bar at the bottom of the form.', 'kontor-shop'),
                __('Recommended order: Payment & sales tax, Shipping, Legal, Email sending, Design.', 'kontor-shop'),
            ],
            'effect'     => [
                __('Changes take effect in the shop right after saving.', 'kontor-shop'),
                __('While a form has changes that aren’t saved yet, its save bar shows “Unsaved changes”.', 'kontor-shop'),
                __('Some sections have several forms, e.g. Payment and VAT. Each “Save” button only saves its own form.', 'kontor-shop'),
            ],
            'sections'   => [
                [
                    'title' => __('The four groups', 'kontor-shop'),
                    'list'  => [
                        __('Sell: Payment & sales tax, Shipping, Legal. With Pro also Membership.', 'kontor-shop'),
                        __('Communication: Email sending, Email templates. With Pro also Back-in-stock requests.', 'kontor-shop'),
                        __('Shop appearance: Design. With Pro also Shop types.', 'kontor-shop'),
                        __('Technical: Roles, AI assistants, System. With Pro also APIs.', 'kontor-shop'),
                    ],
                ],
                [
                    'title'      => __('What “open” means', 'kontor-shop'),
                    'paragraphs' => [
                        __('“open” appears next to a section when a required setup step is missing there: under Payment & sales tax when there is no bank account with an IBAN, under Legal when the terms and privacy pages aren’t linked. It disappears as soon as the step is done.', 'kontor-shop'),
                        __('Only administrators can open the settings. Shop managers and cashiers don’t see them.', 'kontor-shop'),
                    ],
                ],
            ],
            'faq'        => [
                ['q' => __('I clicked “Save”, but my change is gone.', 'kontor-shop'), 'a' => __('You probably saved a different form. Sections like Payment & sales tax have two forms, each with its own “Save” button. Save each form before you change the next one.', 'kontor-shop')],
                ['q' => __('Where are the categories and the warehouse?', 'kontor-shop'), 'a' => __('They aren’t part of the settings. You find them in the Kontor Shop menu under Categories and Warehouse.', 'kontor-shop')],
            ],
            'related'    => ['first-steps', 'setup-payment', 'setup-shipping', 'setup-legal', 'setup-mail', 'design'],
            'help_pages' => ['settings'],
        ];
    }
}
