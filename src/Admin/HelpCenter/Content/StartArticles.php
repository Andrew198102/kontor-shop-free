<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\HelpCenter\Content;

use Kontor\Shop\Admin\CategoriesPage;
use Kontor\Shop\Admin\OrdersPage;
use Kontor\Shop\Admin\ProductsPage;
use Kontor\Shop\Admin\SettingsPage;
use Kontor\Shop\Admin\WarehousesPage;

/**
 * Artikel des Hilfe-Centers: Einstieg (Erste Schritte, so funktioniert der
 * Shop). Schema: Admin\HelpCenter\HelpCenter. Ueber den Schritten von
 * "first-steps" zeigt das Hilfe-Center die echte Einrichtungs-Checkliste
 * (Admin\SetupChecklist) - die Schritte hier erklaeren Reihenfolge und Grund.
 */
final class StartArticles
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function articles(): array
    {
        return [
            [
                'id'         => 'first-steps',
                'group'      => 'start',
                'title'      => __('First steps', 'kontor-shop'),
                'summary'    => __('Set up the shop in the right order and try it with a test order', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('start setup set up checklist begin install new beginner first order test order ready', 'kontor-shop'),
                'help_pages' => ['dashboard'],
                'what'       => __('To get from a fresh installation to a shop where customers can order. The list above shows what is already done; the steps below explain the order and why.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Create the warehouse first. Every product belongs to a warehouse, and the warehouse is the sender of the parcels.', 'kontor-shop'), 'url' => WarehousesPage::url(['do' => 'new']), 'link' => __('Create warehouse', 'kontor-shop')],
                    ['text' => __('Add at least one category. Categories are the filters customers click in the shop, e.g. “Clothing” or “Accessories”.', 'kontor-shop'), 'url' => CategoriesPage::url(['do' => 'new']), 'link' => __('Add category', 'kontor-shop')],
                    ['text' => __('Under Settings → Payment & sales tax, enter the club’s bank account and check the VAT rates. Without a bank account, customers don’t know where to transfer the money.', 'kontor-shop'), 'url' => SettingsPage::url('payment')],
                    ['text' => __('Under Settings → Shipping, set the shipping costs and whether customers may pick up their order themselves.', 'kontor-shop'), 'url' => SettingsPage::url('shipping')],
                    ['text' => __('Under Settings → Legal, choose your pages for the terms and conditions and the privacy policy. The shop links them in the checkout.', 'kontor-shop'), 'url' => SettingsPage::url('legal')],
                    ['text' => __('Add your first products with price, image and initial stock. Then send a test email under Settings → System and place a test order yourself (see below).', 'kontor-shop'), 'url' => ProductsPage::url(['do' => 'new']), 'link' => __('Add new product', 'kontor-shop')],
                ],
                'effect'     => [
                    __('As soon as warehouse, category, product, bank account and legal pages are set up, the dashboard shows “Shop ready – customers can order.”', 'kontor-shop'),
                    __('The pages “Shop”, “Cart” and “Checkout” were created when you activated the plugin. Customers order there.', 'kontor-shop'),
                    __('The checklist on the dashboard disappears once all required steps are done. The list on this page stays.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title' => __('Try it with a test order', 'kontor-shop'),
                        'list'  => [
                            __('Open the shop page on your website and order a product with your own email address.', 'kontor-shop'),
                            __('Check that the order confirmation arrives with the bank details. If not, read “Emails don’t arrive”.', 'kontor-shop'),
                            __('In Kontor Shop → Orders, mark the order as paid and then record the shipment. You get the same emails as a customer.', 'kontor-shop'),
                            __('Afterwards, cancel the test order with “Back to the warehouse (goods are fine)”, so the stock is correct again. Then delete it in the order details.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('Do I have to follow this order?', 'kontor-shop'), 'a' => __('No, but it saves work: a product can’t be saved without a warehouse, and it is easier to choose the category while you create the product.', 'kontor-shop')],
                    ['q' => __('Can someone help with the shop without seeing the settings?', 'kontor-shop'), 'a' => __('Yes. Give them the WordPress role “Shop manager” (Users → edit the user → Role). They can manage products, orders and stock, but not the settings. A cashier only handles payments; you set that under Settings → Roles.', 'kontor-shop')],
                    ['q' => __('The “Shop”, “Cart” or “Checkout” page is missing.', 'kontor-shop'), 'a' => __('The system check under Settings → System shows whether the pages exist and are published. The related guide below explains how to set them up again.', 'kontor-shop')],
                ],
                'related'    => ['how-it-works', 'setup-warehouses', 'setup-categories', 'setup-payment', 'setup-shipping', 'setup-legal', 'setup-mail', 'products-create', 'orders-cancel'],
            ],
            [
                'id'         => 'how-it-works',
                'group'      => 'start',
                'title'      => __('How the shop works', 'kontor-shop'),
                'summary'    => __('From the order to the parcel: who does what, and which emails go out', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('process flow overview workflow roles treasurer cashier shop manager status emails order steps prepayment invoice cash pickup', 'kontor-shop'),
                'what'       => __('An overview for everyone who helps in the shop: what happens with an order, step by step, and who takes care of which step.', 'kontor-shop'),
                'steps'      => [
                    __('A customer puts products in the cart and opens the checkout. While they enter their address, the goods are held for them (15 minutes by default), so nobody else can buy the last item.', 'kontor-shop'),
                    __('The customer places the order and gets the order confirmation by email, with amount, bank details and payment reference. The stock goes down right away.', 'kontor-shop'),
                    ['text' => __('The customer transfers the money. The treasurer checks the bank account and clicks “Paid ✓” in Kontor Shop → Orders.', 'kontor-shop'), 'url' => OrdersPage::url(['view' => 'pay'])],
                    __('After the payment, the warehouse gets the shipping order by email. Whoever packs records the shipment with the tracking number, or the pickup when the customer collects the goods.', 'kontor-shop'),
                    __('If something goes wrong before shipping, the order can be cancelled. You decide per product whether the goods go back to the warehouse.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('Order confirmation: to the customer right after ordering, with amount, IBAN, payment reference and payment deadline.', 'kontor-shop'),
                    __('New order: to you (the shop owner address) for every order.', 'kontor-shop'),
                    __('Payment received: to the customer as soon as the order is marked as paid.', 'kontor-shop'),
                    __('Shipping order to warehouse: when a prepayment order is paid, or right away for an order on invoice.', 'kontor-shop'),
                    __('Shipped / ready for pickup: to the customer when the shipment or pickup is recorded, for parcels with the tracking link.', 'kontor-shop'),
                    __('Order cancelled: to the customer when you cancel and leave “Notify customer” ticked.', 'kontor-shop'),
                    __('You can switch each email on or off and change its text under Settings → Email templates.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title'      => __('Bank transfer, invoice or cash', 'kontor-shop'),
                        'paragraphs' => [
                            __('Prepayment (bank transfer): the customer pays first, and the goods leave after the money has arrived. Once the payment deadline has passed, the order shows as “overdue”.', 'kontor-shop'),
                            __('On invoice (if you switch it on under Settings → Payment & sales tax): the warehouse gets the shipping order right away, and the customer pays after receiving the goods.', 'kontor-shop'),
                            __('Cash at pickup: when the customer pays in cash, click “Paid ✓” and choose “cash”, then record the pickup. For an order on invoice, record the pickup first and the payment afterwards.', 'kontor-shop'),
                        ],
                    ],
                    [
                        'title' => __('Who does what', 'kontor-shop'),
                        'list'  => [
                            __('Administrator: everything, including settings, email templates and logs.', 'kontor-shop'),
                            __('Shop manager (a WordPress role): products, orders, stock, categories and warehouse, but no settings.', 'kontor-shop'),
                            __('Cashier: sees all orders, marks payments, sends payment reminders, cancels orders and records refunds. Doesn’t manage products or stock and doesn’t ship. Assigned under Settings → Roles.', 'kontor-shop'),
                            __('Warehouse manager (Kontor Shop Pro): sees only the orders and stock of their own warehouse, books stock and ships.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('When does the stock go down?', 'kontor-shop'), 'a' => __('When the order is placed. In Kontor Shop → Stock, the number shows what is still free to sell; “on shelf” also counts goods that are sold but not yet shipped or picked up.', 'kontor-shop')],
                    ['q' => __('What happens if a customer never pays?', 'kontor-shop'), 'a' => __('The order stays open and shows as “overdue” after the payment deadline. Send a payment reminder (⋯ → Send payment reminder) or cancel the order. Kontor Shop Pro can remind and cancel automatically.', 'kontor-shop')],
                    ['q' => __('Do I need a payment provider?', 'kontor-shop'), 'a' => __('No. Customers pay by bank transfer or in cash, and you record the payment yourself. There are no fees.', 'kontor-shop')],
                ],
                'related'    => ['first-steps', 'orders-overview', 'orders-paid', 'orders-ship', 'orders-cancel', 'setup-roles', 'setup-templates', 'pro-reminders'],
            ],
        ];
    }
}
