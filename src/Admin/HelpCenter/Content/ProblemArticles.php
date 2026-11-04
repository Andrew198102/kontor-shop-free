<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\HelpCenter\Content;

use Kontor\Shop\Admin\LogPage;
use Kontor\Shop\Admin\OrdersPage;
use Kontor\Shop\Admin\ReportPage;
use Kontor\Shop\Admin\SettingsPage;

/**
 * Artikel des Hilfe-Centers: Probleme & Fragen (Mails, Aussehen, fehlende
 * Bestellung, Problem melden). Schema: Admin\HelpCenter\HelpCenter.
 */
final class ProblemArticles
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function articles(): array
    {
        $log_url = admin_url('admin.php?page=' . LogPage::SLUG);

        return [
            [
                'id'         => 'problem-emails',
                'group'      => 'problems',
                'title'      => __('Emails don’t arrive', 'kontor-shop'),
                'summary'    => __('Order emails missing or in spam: SMTP plugin, outbox, test email', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('email mail not arriving missing spam junk smtp wp mail smtp fluentsmtp outbox test email confirmation undelivered sender', 'kontor-shop'),
                'what'       => __('When customers get no order confirmation, or your “New order” emails don’t arrive. Usually the cause is the way your website sends emails.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Send a test email: Kontor Shop → Settings → System → “Send test email”. It goes to the shop owner address.', 'kontor-shop'), 'url' => SettingsPage::url('system')],
                    __('Look in your inbox and in the spam folder. If the test email is missing or lands in spam, your website needs an SMTP plugin.', 'kontor-shop'),
                    __('Install an SMTP plugin such as WP Mail SMTP or FluentSMTP (Plugins → Add New Plugin) and connect it to your club’s mailbox. Kontor Shop detects it and shows the status under Settings → Email sending.', 'kontor-shop'),
                    ['text' => __('Under Settings → Email sending, use a sender address on your own website’s domain. Senders like @gmail.com end up in spam.', 'kontor-shop'), 'url' => SettingsPage::url('mail')],
                    ['text' => __('Check the “Outbox” on the same page. Emails that could not be sent wait there. Once the cause is fixed, click “Resend now”.', 'kontor-shop'), 'url' => SettingsPage::url('mail') . '#ks-outbox'],
                    __('Send the test email again. If it arrives, resend missing order confirmations from the order list: ⋯ → “Resend confirmation”.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('Emails in the outbox are retried automatically after 5 minutes, 30 minutes, 2, 6 and 24 hours. After that they count as abandoned and are only sent when you click “Resend now”.', 'kontor-shop'),
                    __('As long as emails are waiting in the outbox, the dashboard shows a warning.', 'kontor-shop'),
                    __('Abandoned emails are deleted after 30 days.', 'kontor-shop'),
                ],
                'faq'        => [
                    ['q' => __('Only one customer gets no emails.', 'kontor-shop'), 'a' => __('Check the customer’s address in the order details for typos, and ask them to look in their spam folder. In the order details you can resend the confirmation, also to another address (“Forward to this address”).', 'kontor-shop')],
                    ['q' => __('One kind of email is never sent, e.g. “Payment received”.', 'kontor-shop'), 'a' => __('Check under Settings → Email templates that this email is switched on. Internal emails also need at least one recipient.', 'kontor-shop')],
                    ['q' => __('Why does the shop need an SMTP plugin?', 'kontor-shop'), 'a' => __('Without one, your web server sends the emails itself, and many mail providers distrust that. An SMTP plugin sends through a real mailbox, just like your email program.', 'kontor-shop')],
                ],
                'related'    => ['setup-mail', 'setup-templates', 'setup-system', 'problem-order-missing', 'problem-report'],
            ],
            [
                'id'         => 'problem-shop-looks-wrong',
                'group'      => 'problems',
                'title'      => __('The shop looks wrong', 'kontor-shop'),
                'summary'    => __('Colors, spacing or layout don’t fit your website', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('layout broken design theme css colors wrong looks strange cache old design mobile phone font spacing', 'kontor-shop'),
                'what'       => __('When the shop doesn’t fit the rest of your website: wrong colors, odd spacing, different buttons, or still the old look after a change.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Settings → Design and choose the basic style “Like my theme”. Check the preview, also with “Phone”.', 'kontor-shop'), 'url' => SettingsPage::url('design')],
                    __('If only the color is wrong, set the main shop color to “from the theme” or pick a fixed color under “own color”, then click “Save”.', 'kontor-shop'),
                    __('Empty the cache: if your website uses a cache plugin (e.g. WP Rocket, LiteSpeed Cache) or your host caches pages, visitors may still see the old design.', 'kontor-shop'),
                    __('Open the shop in a private browser window. If it looks right there, only your browser still had the old version; reload the page with Ctrl+F5 (Mac: Cmd+Shift+R).', 'kontor-shop'),
                    __('If it still looks wrong, ask whoever looks after your website. The guide for web developers (linked below) explains how to fit the shop into any theme.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('Design settings apply to all visitors as soon as you save and the cache is emptied.', 'kontor-shop'),
                    __('Your theme and your products stay unchanged.', 'kontor-shop'),
                ],
                'faq'        => [
                    ['q' => __('The shop page looks fine, but cart or checkout behave strangely.', 'kontor-shop'), 'a' => __('Cart and checkout must not be cached. Exclude these pages in your cache plugin’s settings, then check under Settings → System that the shop pages exist and are published.', 'kontor-shop')],
                    ['q' => __('The shop page is empty or only shows text in square brackets.', 'kontor-shop'), 'a' => __('The page has to contain the shop block or the shortcode, and the plugin has to be active. The related guide below explains how to set up the pages.', 'kontor-shop')],
                    ['q' => __('On the phone the shop looks different.', 'kontor-shop'), 'a' => __('On phones the shop always shows one product per row. Check it with the preview “Phone” under Settings → Design.', 'kontor-shop')],
                ],
                'related'    => ['design', 'design-css', 'shop-pages'],
            ],
            [
                'id'         => 'problem-order-missing',
                'group'      => 'problems',
                'title'      => __('A customer ordered, but there is no order', 'kontor-shop'),
                'summary'    => __('The order doesn’t show up in the back office', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('order missing not found lost customer ordered no order checkout error spam protection reservation session expired too many orders reference', 'kontor-shop'),
                'what'       => __('When a customer says they ordered but you can’t find the order. Usually the order was not completed, or it is in a different view of the list.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Orders, choose the tab “All” and search for the customer’s name or email address. “All” also includes cancelled orders.', 'kontor-shop'), 'url' => OrdersPage::url(['view' => 'all'])],
                    __('If the order is there but the customer got no email, resend the confirmation (⋯ → “Resend confirmation”) and read “Emails don’t arrive”.', 'kontor-shop'),
                    __('Ask the customer whether they saw the page “Thank you – we’ve received your order!” with an order number (B-…). Without that page, the order was not placed.', 'kontor-shop'),
                    ['text' => __('If the customer saw an error message with an error number (ERR- and six characters), search for it in Kontor Shop → Logs.', 'kontor-shop'), 'url' => $log_url],
                    __('Ask the customer to order again. If the checkout showed a message, their entries are usually still filled in.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title' => __('Why an order may not go through', 'kontor-shop'),
                        'list'  => [
                            __('The checkout was open for a very long time: the customer sees “Your session has expired” and only has to submit the form again.', 'kontor-shop'),
                            __('Spam protection: the form was sent within a few seconds, or from an old copy of the page. The customer sees “Please reload the page and submit the form again.”', 'kontor-shop'),
                            __('More than 5 orders within 15 minutes from the same internet connection: the customer sees “Too many orders in a short time.” This protects against bots.', 'kontor-shop'),
                            __('The goods ran out meanwhile. While the checkout is open, the goods are held for 15 minutes by default (Settings → Payment & sales tax → “Reservation at checkout”). If someone else bought the last item after that, the customer is told there is not enough stock.', 'kontor-shop'),
                            __('With Kontor Shop Pro and several warehouses, one cart can become several orders with their own order numbers.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('Many people order at the same time at our club event and see “Too many orders”.', 'kontor-shop'), 'a' => __('Everyone in the same Wi-Fi uses the same internet connection, so the limit of 5 orders in 15 minutes is reached quickly. Ask people to order via mobile data, or wait a few minutes.', 'kontor-shop')],
                    ['q' => __('The customer has paid, but I can’t find the order.', 'kontor-shop'), 'a' => __('Search under “All” for the payment reference (KS-…) from the bank transfer, or for the name of the person who sent the money. With Kontor Shop Pro, the change log also shows whether an order was deleted.', 'kontor-shop')],
                ],
                'related'    => ['problem-emails', 'problem-report', 'orders-overview', 'setup-payment', 'pro-audit'],
            ],
            [
                'id'         => 'problem-report',
                'group'      => 'problems',
                'title'      => __('Report a problem', 'kontor-shop'),
                'summary'    => __('Logs and a ready-made report for support', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('error bug problem support log logs report github diagnostics reference help contact not working', 'kontor-shop'),
                'help_pages' => ['logs'],
                'what'       => __('When something in the shop doesn’t work and you need help. The log shows what went wrong in the background; the report collects everything support needs into one message.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Logs. You see errors and warnings there, e.g. an email that could not be sent.', 'kontor-shop'), 'url' => $log_url],
                    __('If a customer gave you an error number (ERR-…), enter it in the search field and click “Filter”.', 'kontor-shop'),
                    ['text' => __('Click “Report a problem” at the top of the page.', 'kontor-shop'), 'url' => ReportPage::url()],
                    __('If you have an error number (ERR-…), enter it and click “Add to report”. The matching entry is then included in full.', 'kontor-shop'),
                    __('Read the report. Then click “Report on GitHub” and describe what happened, or click “Copy report” and send it by email with a short description.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('The report contains versions, settings and the latest warnings and errors, but no customer data. Email addresses, IBANs, IP addresses, tokens and your website address are removed automatically.', 'kontor-shop'),
                    __('Reports on GitHub are public. If you prefer not to post there, send the copied report by email; the address is shown below the buttons.', 'kontor-shop'),
                    __('Viewing the log or creating the report changes nothing in the shop.', 'kontor-shop'),
                ],
                'faq'        => [
                    ['q' => __('The log is empty, but something doesn’t work.', 'kontor-shop'), 'a' => __('The log only records unexpected errors. Describe in the report what you did, what you expected and what happened instead; that helps the most.', 'kontor-shop')],
                    ['q' => __('Who can see the log?', 'kontor-shop'), 'a' => __('Only users who may change the settings, usually the administrators. Shop managers and cashiers don’t see this menu item.', 'kontor-shop')],
                    ['q' => __('How long are entries kept?', 'kontor-shop'), 'a' => __('30 days by default, and at most 5,000 entries. You can change the days under “Keep (days)” at the bottom of the log page.', 'kontor-shop')],
                ],
                'related'    => ['problem-emails', 'problem-order-missing', 'setup-system'],
            ],
        ];
    }
}
