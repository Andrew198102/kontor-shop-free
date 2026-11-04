<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\HelpCenter\Content;

use Kontor\Shop\Admin\OrdersPage;
use Kontor\Shop\Admin\ProductsPage;
use Kontor\Shop\Admin\SettingsPage;
use Kontor\Shop\Admin\StockPage;

/**
 * Artikel des Hilfe-Centers: Tagesgeschaeft (Bestellungen, Artikel,
 * Bestand). Schema: Admin\HelpCenter\HelpCenter. Die Knopf- und
 * Menuenamen in den Texten muessen zu den Admin-Seiten passen
 * (OrdersList, OrderDetailView, ShipmentDialog, ProductForm,
 * ProductVariantsCard, ProductsList, StockOverview).
 */
final class DailyArticles
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function articles(): array
    {
        return array_merge(self::orders(), self::products(), self::stock());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function orders(): array
    {
        return [
            [
                'id'         => 'orders-overview',
                'group'      => 'daily',
                'title'      => __('Working with the order list', 'kontor-shop'),
                'summary'    => __('Statuses, the “To do” view, quick view and bulk actions', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('orders order list status open paid shipped picked up cancelled to do filter search quick view bulk overdue note', 'kontor-shop'),
                'help_pages' => ['orders'],
                'what'       => __('The order list shows what needs doing now. You can handle most orders right there, without opening them.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Orders. The tab “To do” shows everything that is waiting for you: payment open, to ship, pickup and refunds.', 'kontor-shop'), 'url' => OrdersPage::url(['view' => 'todo'])],
                    __('The tiles at the top count the orders per step. Click a tile, e.g. “Payment open”, to see only those orders.', 'kontor-shop'),
                    __('Click ▸ in front of the order number to open the quick view: items, delivery address with a “Copy” button, history and an internal note.', 'kontor-shop'),
                    __('The column “Next step” shows the button for what comes next, e.g. “Paid ✓” or “Record shipment …”. More actions are under ⋯: payment reminder, resend confirmation, cancel.', 'kontor-shop'),
                    __('To handle several orders at once, tick the boxes on the left. A bar with “Mark as paid” and “Mark as shipped/picked up” appears.', 'kontor-shop'),
                    ['text' => __('To find an older order, choose the tab “All” and search for number, name, email, payment reference or product.', 'kontor-shop'), 'url' => OrdersPage::url(['view' => 'all'])],
                ],
                'effect'     => [
                    __('Bulk actions only change orders where the step fits. The others are skipped, and the message says how many.', 'kontor-shop'),
                    __('“Mark as paid” and “Mark as shipped/picked up” send the customer the same emails as the buttons in a single row.', 'kontor-shop'),
                    __('Internal notes are only visible in the back office, never to the customer.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title' => __('What the statuses mean', 'kontor-shop'),
                        'list'  => [
                            __('Open: ordered, payment not received yet.', 'kontor-shop'),
                            __('Paid: the money has arrived, the goods can be packed.', 'kontor-shop'),
                            __('Shipped or picked up: the order is complete.', 'kontor-shop'),
                            __('Cancelled: the order was cancelled, and the stock was booked the way you chose.', 'kontor-shop'),
                            __('Overdue: the payment deadline (prepayment) or the payment due date (invoice) has passed.', 'kontor-shop'),
                            __('Partially out: part of the order has left, the rest is still open, e.g. with several warehouses (Kontor Shop Pro).', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('An order doesn’t show under “To do”.', 'kontor-shop'), 'a' => __('“To do” only shows orders that need something from you. Completed and cancelled orders, and invoices that are not due yet, are under “All”. Also check the filters above the list; “Reset filter” shows everything again.', 'kontor-shop')],
                    ['q' => __('Why don’t I see all orders?', 'kontor-shop'), 'a' => __('A warehouse manager only sees orders with products from their own warehouse. Everyone else sees all orders under “All”.', 'kontor-shop')],
                    ['q' => __('Can I delete an order?', 'kontor-shop'), 'a' => __('Only in the order details (click the order number), and only as administrator or shop manager. Order data usually has to be kept for several years, so only delete test orders.', 'kontor-shop')],
                ],
                'related'    => ['orders-paid', 'orders-ship', 'orders-cancel', 'how-it-works', 'pro-packing', 'pro-export'],
            ],
            [
                'id'         => 'orders-paid',
                'group'      => 'daily',
                'title'      => __('Mark an order as paid', 'kontor-shop'),
                'summary'    => __('The customer transferred the money or paid in cash', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('paid payment transfer bank account cash received treasurer cashier mark as paid payment reference reminder overdue', 'kontor-shop'),
                'what'       => __('As soon as the money has arrived, you mark the order as paid. Only then does the warehouse know that it may send the goods.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Orders and click the tile “Payment open”.', 'kontor-shop'), 'url' => OrdersPage::url(['view' => 'pay'])],
                    __('Compare the transfers in your bank account with the list: amount and payment reference (KS-…) must match. The search above the list also finds the payment reference.', 'kontor-shop'),
                    __('Click “Paid ✓” in the row of the order.', 'kontor-shop'),
                    __('In the window “Record payment”, choose how the money came in: bank transfer, cash, card or other.', 'kontor-shop'),
                    __('Check the date under “Payment received on”. If the money arrived yesterday, choose yesterday.', 'kontor-shop'),
                    __('Click “Mark as paid”.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('The customer gets the email “Payment received”.', 'kontor-shop'),
                    __('For prepayment, the warehouse gets the email “Shipping order to warehouse”, and the order moves to “To ship” or “Pickup”.', 'kontor-shop'),
                    __('The status history of the order shows who recorded the payment and when.', 'kontor-shop'),
                ],
                'faq'        => [
                    ['q' => __('The customer transferred too little.', 'kontor-shop'), 'a' => __('The shop doesn’t check amounts. Clarify it with the customer and only mark the order as paid when the full amount has arrived.', 'kontor-shop')],
                    ['q' => __('I marked the wrong order as paid.', 'kontor-shop'), 'a' => __('A payment can’t be undone with a button. Write an internal note in the quick view so nobody ships the order by mistake, and clarify it with the customer. If the order should not be delivered at all, cancel it and set the refund to 0.', 'kontor-shop')],
                    ['q' => __('How do I remind a customer who hasn’t paid?', 'kontor-shop'), 'a' => __('Click ⋯ → “Send payment reminder” in the order list. The customer gets the payment details again. Kontor Shop Pro can send reminders automatically.', 'kontor-shop')],
                    ['q' => __('Instead of “Paid ✓” I see “book in …”.', 'kontor-shop'), 'a' => __('Your shop is connected to a cashier system. Payments are booked there and then show up in the shop as “paid”.', 'kontor-shop')],
                ],
                'related'    => ['orders-overview', 'orders-ship', 'how-it-works', 'pro-reminders', 'setup-payment', 'setup-roles'],
            ],
            [
                'id'         => 'orders-ship',
                'group'      => 'daily',
                'title'      => __('Ship an order or hand it over at pickup', 'kontor-shop'),
                'summary'    => __('Record the shipment with tracking number, or the pickup', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('ship shipping parcel package tracking number carrier pickup collect hand over shipped picked up send dispatch label', 'kontor-shop'),
                'what'       => __('When the parcel leaves or the customer collects the goods, you record it. The customer is informed, and the order is complete.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Orders and click the tile “To ship” (parcels) or “Ready for pickup” (the customer collects).', 'kontor-shop'), 'url' => OrdersPage::url(['view' => 'ship'])],
                    __('Pack the products shown in the row. The quick view (▸) shows the delivery address with a “Copy” button for the shipping label.', 'kontor-shop'),
                    __('Click “Record shipment …”, or “Record pickup …” when the customer collects the goods.', 'kontor-shop'),
                    __('Choose the carrier and copy the tracking number from the shipping label. The tracking number is optional.', 'kontor-shop'),
                    __('Leave “Notify the customer by email” ticked and check the summary with “Next →”.', 'kontor-shop'),
                    __('Click “Save as shipped now” or “Save as picked up now”.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('The order changes to “shipped” or “picked up” and leaves the “To do” list.', 'kontor-shop'),
                    __('The customer gets the email “Shipped / ready for pickup”, for parcels with carrier, tracking number and tracking link.', 'kontor-shop'),
                    __('The stock doesn’t change again: it already went down when the order was placed.', 'kontor-shop'),
                    __('For an order on invoice, the payment term starts now, and the shipping email contains the payment details.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title'      => __('Partial shipments', 'kontor-shop'),
                        'paragraphs' => [
                            __('Without Kontor Shop Pro, all open products of an order are shipped together. If a product is missing, wait until everything is there, or cancel the missing product (⋯ → Cancel products …).', 'kontor-shop'),
                            __('With Kontor Shop Pro you can send part of an order now and the rest later, and pack on your phone straight from the order email.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('The button “Record shipment …” is missing.', 'kontor-shop'), 'a' => __('With prepayment, the order has to be paid first; look under “Payment open”. Cashiers can’t record shipments; an administrator, shop manager or warehouse manager does that.', 'kontor-shop')],
                    ['q' => __('I entered a wrong tracking number.', 'kontor-shop'), 'a' => __('A recorded shipment can’t be changed. Add the correct number as an internal note in the quick view and send it to the customer yourself.', 'kontor-shop')],
                    ['q' => __('Can I mark several orders as shipped at once?', 'kontor-shop'), 'a' => __('Yes: tick them in the list and click “Mark as shipped/picked up”. All open products go out, without tracking numbers, and the customers are notified.', 'kontor-shop')],
                    ['q' => __('The customer sends something back.', 'kontor-shop'), 'a' => __('Open the order and click “Record return …”. You choose per product whether it goes back to the warehouse, and the shop notes the refund.', 'kontor-shop')],
                ],
                'related'    => ['orders-overview', 'orders-paid', 'orders-cancel', 'pro-packing', 'setup-shipping'],
            ],
            [
                'id'         => 'orders-cancel',
                'group'      => 'daily',
                'title'      => __('Cancel an order, refund or delete it', 'kontor-shop'),
                'summary'    => __('Cancel orders or single products, book goods back, record refunds', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('cancel cancellation refund money back return delete remove test order back to stock withdrawal retention', 'kontor-shop'),
                'what'       => __('When a customer withdraws, doesn’t pay, or a product can’t be delivered. The shop books the stock the way you choose and keeps track of money you still have to pay back.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('In Kontor Shop → Orders, click ⋯ → “Cancel order …” in the row of the order.', 'kontor-shop'), 'url' => OrdersPage::url(['view' => 'all'])],
                    __('For each product, choose what happened: “Back to the warehouse (goods are fine)”, defective, lost or stolen, or no stock booking.', 'kontor-shop'),
                    __('Enter a reason if you like. It appears in the email to the customer.', 'kontor-shop'),
                    __('If the order is already paid, check the amount under “Refund to the customer”. 0 means no refund.', 'kontor-shop'),
                    __('Leave “Notify customer of the cancellation by email” ticked if the customer should be informed, then click “Cancel now”.', 'kontor-shop'),
                    ['text' => __('After you have transferred the money back, click “Refund done” in the tab “Refund”.', 'kontor-shop'), 'url' => OrdersPage::url(['view' => 'refund'])],
                ],
                'effect'     => [
                    __('The order changes to “cancelled”. Products booked “Back to the warehouse” can be ordered in the shop again right away.', 'kontor-shop'),
                    __('The customer gets the email “Order cancelled”, with the refund if there is one.', 'kontor-shop'),
                    __('The internal email “Cancellation / refund” goes to the recipients set in the email templates, e.g. the treasurer.', 'kontor-shop'),
                    __('A refund stays in the tab “Refund” until you click “Refund done”.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title'      => __('Cancel single products only', 'kontor-shop'),
                        'paragraphs' => [
                            __('If only one product can’t be delivered, open the order and click “Cancel products …”. Tick the products and choose the stock booking. For an unpaid order the amount due goes down; for a paid order a refund is noted.', 'kontor-shop'),
                        ],
                    ],
                    [
                        'title'      => __('After shipping: return instead of cancellation', 'kontor-shop'),
                        'paragraphs' => [
                            __('As soon as anything from the order has left, it can no longer be cancelled. Use “Record return …” in the order instead: enter how many pieces came back and whether they go back to the warehouse.', 'kontor-shop'),
                        ],
                    ],
                    [
                        'title'      => __('Deleting an order', 'kontor-shop'),
                        'paragraphs' => [
                            __('Order data usually has to be kept for several years because of tax and accounting rules. That is why you can only delete an order in its details: click the order number, then “Delete order”. Only administrators and shop managers see this button.', 'kontor-shop'),
                            __('Deleting doesn’t book any stock back. Cancel a test order with “Back to the warehouse (goods are fine)” first, then delete it.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('Can I undo a cancellation?', 'kontor-shop'), 'a' => __('No. If the customer wants the goods after all, they place a new order.', 'kontor-shop')],
                    ['q' => __('Are unpaid orders cancelled automatically?', 'kontor-shop'), 'a' => __('Not in the free version: overdue orders stay open until you cancel them. Kontor Shop Pro can remind customers and cancel unpaid orders automatically.', 'kontor-shop')],
                    ['q' => __('Where do I see which refunds are still open?', 'kontor-shop'), 'a' => __('In Kontor Shop → Orders, tile “Refund pending”. It also shows the total amount.', 'kontor-shop')],
                ],
                'related'    => ['orders-overview', 'orders-ship', 'pro-reminders', 'pro-audit', 'setup-templates'],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function products(): array
    {
        return [
            [
                'id'         => 'products-create',
                'group'      => 'daily',
                'title'      => __('Add a product', 'kontor-shop'),
                'summary'    => __('Name, price, VAT, images, category and initial stock', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('product new add create article item price vat tax image photo picture upload phone camera category warehouse stock visible', 'kontor-shop'),
                'help_pages' => ['products'],
                'what'       => __('Everything you sell is a product: a jersey, a mug, a scarf. Here you enter what the shop needs to sell it.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Product and click “Add new product”.', 'kontor-shop'), 'url' => ProductsPage::url(['do' => 'new']), 'link' => __('Add new product', 'kontor-shop')],
                    __('Under “Basics”, enter the name and a short description. The product number (SKU) is optional and only visible internally.', 'kontor-shop'),
                    __('Under “Images”, click the box “Drag image here” and choose a photo. On a phone you can take a picture or pick one from your photos. Further pictures go under “More images”.', 'kontor-shop'),
                    __('Under “Price & tax”, enter the gross price (what the customer pays, VAT included) and choose the VAT rate.', 'kontor-shop'),
                    __('Under “Warehouse & categories”, choose the main category. If you only have one warehouse, it is already selected.', 'kontor-shop'),
                    __('Enter the initial stock, leave “Visible in shop” switched on and click “Add product”.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('The product appears in the shop right away, under its category. With “Visible in shop” switched off it is saved, but customers don’t see it.', 'kontor-shop'),
                    __('The initial stock is booked as a stock-in called “Initial stock” and shows in Kontor Shop → Stock.', 'kontor-shop'),
                    __('With “Create & add another”, the form opens again, empty, for the next product.', 'kontor-shop'),
                    __('Images are saved in the WordPress media library. When you replace or remove an image, the shop asks whether to delete the old one.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title' => __('Tips', 'kontor-shop'),
                        'list'  => [
                            __('Take all photos in the same format, e.g. square, so the catalog looks tidy. JPG, PNG, WebP and GIF are allowed.', 'kontor-shop'),
                            __('★ makes an image the main image. Drag the images to change their order.', 'kontor-shop'),
                            __('If the product comes in sizes or colors, switch on “Variants” before saving (see “Sizes and colors (variants)”).', 'kontor-shop'),
                            __('“Show unit price” is only needed for goods sold by weight or volume, e.g. honey in 500 g jars.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('Which VAT rate do I choose?', 'kontor-shop'), 'a' => __('The rate that applies to the product. Clubs that don’t charge VAT choose “no VAT (tax-exempt)”. You set the available rates under Settings → Payment & sales tax.', 'kontor-shop')],
                    ['q' => __('The photo upload doesn’t work on my phone.', 'kontor-shop'), 'a' => __('The image box shows the largest allowed file size. Check that the photo is smaller and that your connection is stable. Otherwise upload the photo on a computer.', 'kontor-shop')],
                    ['q' => __('Do I have to enter the stock right away?', 'kontor-shop'), 'a' => __('No. Leave it empty and book the goods later in Kontor Shop → Stock with “+ Record delivery”. Until then, customers see the product as sold out.', 'kontor-shop')],
                    ['q' => __('Why does the shop ask for a warehouse?', 'kontor-shop'), 'a' => __('Every product ships from a warehouse. If none exists yet, create one first under Kontor Shop → Warehouse.', 'kontor-shop')],
                ],
                'related'    => ['products-variants', 'products-visibility', 'stock-delivery', 'setup-categories', 'setup-payment', 'setup-warehouses'],
            ],
            [
                'id'         => 'products-variants',
                'group'      => 'daily',
                'title'      => __('Sizes and colors (variants)', 'kontor-shop'),
                'summary'    => __('One product in several sizes or colors, each with its own stock and price', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('variant variants size sizes color colour s m l xl attribute combination stock per size price per variant image', 'kontor-shop'),
                'what'       => __('When a product comes in several versions, e.g. a T-shirt in S to XXL. Each version (variant) has its own stock and can have its own price and image.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open the product in Kontor Shop → Product, or add a new one.', 'kontor-shop'), 'url' => ProductsPage::url()],
                    __('Switch on “Variants”.', 'kontor-shop'),
                    __('Enter the attribute, e.g. “Size”, and its values separated by commas, e.g. S, M, L, XL. The template “Sizes” fills this in for you. You can combine up to three attributes, e.g. size and color.', 'kontor-shop'),
                    __('Click “Update variant table”. The table shows every combination.', 'kontor-shop'),
                    __('Only enter a price where it differs from the product price, e.g. for XXL. With “Price for” you set one price for several variants at once.', 'kontor-shop'),
                    __('For a new product, enter the initial stock of each variant in the column “Stock”. Then click “Save”.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('In the shop, customers choose size or color. A variant with stock 0 can’t be ordered; the shop shows “This variant is currently unavailable.”', 'kontor-shop'),
                    __('Stock is booked per variant. In Kontor Shop → Stock, click “▸ Variants” to see and book each size.', 'kontor-shop'),
                    __('In the stock list, the product counts as “Sold out” as soon as one single variant is empty.', 'kontor-shop'),
                    __('If you rename a value, e.g. “Red” to “Dark red”, stock, price and image are kept.', 'kontor-shop'),
                ],
                'faq'        => [
                    ['q' => __('What happens if I leave price or image empty?', 'kontor-shop'), 'a' => __('Then the product price and the product’s main image apply.', 'kontor-shop')],
                    ['q' => __('How do I take a single size out of the shop?', 'kontor-shop'), 'a' => __('Untick “Active” in its row and save. If you remove a value that already has stock or orders, the variant is only switched off and stays in the history.', 'kontor-shop')],
                    ['q' => __('I switched variants off by mistake.', 'kontor-shop'), 'a' => __('Their stock is kept. Switch “Variants” on again and save; the variants come back.', 'kontor-shop')],
                    ['q' => __('The stock page shows stock “without variant”.', 'kontor-shop'), 'a' => __('That stock is from before you switched on variants. Book it out on the product’s stock page and book it in again for the right sizes.', 'kontor-shop')],
                ],
                'related'    => ['products-create', 'stock-delivery', 'stock-min'],
            ],
            [
                'id'         => 'products-visibility',
                'group'      => 'daily',
                'title'      => __('Hide, archive, copy or delete a product', 'kontor-shop'),
                'summary'    => __('Take products out of the shop, copy them, change them quickly', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('hide hidden visible archive restore duplicate copy quick edit delete remove product bulk action discontinued', 'kontor-shop'),
                'what'       => __('To take a product out of the shop for a while, retire it for good, or create a similar product quickly.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Product.', 'kontor-shop'), 'url' => ProductsPage::url()],
                    __('To hide a product right away, click its switch in the column “Visible in shop”. The product stays saved, and you can switch it on again at any time.', 'kontor-shop'),
                    __('To change name, product number, price, category or minimum stock quickly, click “Quick edit” below the product name.', 'kontor-shop'),
                    __('“Duplicate” creates a copy, e.g. for the same shirt in another design. The copy is hidden and has stock 0 until you check and save it.', 'kontor-shop'),
                    __('“Archive” takes a product you no longer sell out of the shop and out of the working list. It stays in the tab “Archived”, together with its history.', 'kontor-shop'),
                    ['text' => __('To delete a product for good, open the tab “Archived” and click “Delete permanently”. This can’t be undone.', 'kontor-shop'), 'url' => ProductsPage::url(['view' => 'archive'])],
                ],
                'effect'     => [
                    __('Hidden and archived products disappear from the shop immediately. Existing orders keep the product name and price.', 'kontor-shop'),
                    __('If a hidden product is still in a customer’s cart, the checkout tells them it is no longer available.', 'kontor-shop'),
                    __('For several products at once, tick them, choose a “Bulk action …” and click “Apply”: make visible or hidden, assign a category, change the price by a percentage, duplicate or archive.', 'kontor-shop'),
                ],
                'faq'        => [
                    ['q' => __('Hide or archive – what’s the difference?', 'kontor-shop'), 'a' => __('Hidden products stay in the normal list, e.g. seasonal items. Archived products are out of the way for good; “Restore” brings them back.', 'kontor-shop')],
                    ['q' => __('Why can’t I delete a product directly?', 'kontor-shop'), 'a' => __('To protect against mistakes, a product has to be archived first. Old orders keep the product name either way.', 'kontor-shop')],
                    ['q' => __('What is a “Discontinued product”?', 'kontor-shop'), 'a' => __('A product that won’t be reordered. While stock lasts, the shop shows “while supplies last”; when it is sold out, the shop says it won’t be reordered. You find the setting in the product under “Stock”.', 'kontor-shop')],
                ],
                'related'    => ['products-create', 'products-variants', 'stock-min', 'setup-categories'],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function stock(): array
    {
        return [
            [
                'id'         => 'stock-delivery',
                'group'      => 'daily',
                'title'      => __('Book in a delivery', 'kontor-shop'),
                'summary'    => __('New goods have arrived: add them to the stock', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('delivery goods receipt book in stock in incoming restock add stock delivery note scan barcode ean correction book out', 'kontor-shop'),
                'help_pages' => ['stock'],
                'what'       => __('When new goods arrive, you book them in. Only booked goods can be sold in the shop.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Stock and click “+ Record delivery”.', 'kontor-shop'), 'url' => StockPage::url()],
                    __('If you like, enter the delivery note number under “Delivery note / note”. It applies to all rows.', 'kontor-shop'),
                    __('Add the products from the list “Add product or variant …”, or scan the product number or EAN with a barcode scanner and press Enter.', 'kontor-shop'),
                    __('Enter the quantity that arrived in each row. The column “then” shows the new stock.', 'kontor-shop'),
                    __('Click “Book delivery”.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('The stock goes up right away, and sold-out products or sizes can be ordered again.', 'kontor-shop'),
                    __('Each booking is listed in the product’s history with date, quantity, note and your name.', 'kontor-shop'),
                    __('With Kontor Shop Pro, customers who asked to be notified get an email that the product is available again.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title' => __('Other bookings', 'kontor-shop'),
                        'list'  => [
                            __('For a single product, click “+ Stock in” or “− Stock out” in its row and choose under “What happened?”, e.g. “Offline sale” at a club event or “Defective / damaged”.', 'kontor-shop'),
                            __('“Correction” fixes a wrong number. It needs a note, so the reason can be traced later.', 'kontor-shop'),
                            __('Tick several products and click “Goods receipt for selection” to book a delivery for exactly these products.', 'kontor-shop'),
                            __('The full history of a product is in the product under “Stock” → “Book / history”.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('I booked the wrong quantity.', 'kontor-shop'), 'a' => __('Bookings can’t be deleted, so the history stays complete. Book the difference as a “Correction” and write the reason in the note.', 'kontor-shop')],
                    ['q' => __('How do I book goods for a certain size?', 'kontor-shop'), 'a' => __('In the delivery window, choose the size directly from the list. In the stock list, “▸ Variants” shows each size with its own + and − buttons.', 'kontor-shop')],
                    ['q' => __('A warning says the stock will go negative.', 'kontor-shop'), 'a' => __('You are booking out more than there is. Check the number; negative stock usually means that a booking was forgotten.', 'kontor-shop')],
                ],
                'related'    => ['stock-count', 'stock-min', 'products-variants', 'pro-stock-requests', 'pro-warehouses'],
            ],
            [
                'id'         => 'stock-count',
                'group'      => 'daily',
                'title'      => __('Stock count (inventory)', 'kontor-shop'),
                'summary'    => __('Count what is on the shelf; only the differences are booked', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('stock count stocktaking inventory count list counting sheet csv print excel difference correction shelf annual', 'kontor-shop'),
                'what'       => __('Once a year, or when numbers look wrong, you count what is really on the shelf. The shop compares it with its own numbers and only corrects the differences.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Optional: print a counting list. In Kontor Shop → Stock, click “Stock count list”, choose warehouse and category and click “Download CSV”. Open the file in Excel or LibreOffice and print it.', 'kontor-shop'), 'url' => StockPage::url()],
                    ['text' => __('Click “Start stock count”.', 'kontor-shop'), 'url' => StockPage::url(['mode' => 'count'])],
                    __('Count each product and enter the number under “Counted”. Also count goods that are sold but still on the shelf; “Expected on shelf” includes them.', 'kontor-shop'),
                    __('Leave the field empty for products you haven’t counted. They stay unchanged.', 'kontor-shop'),
                    __('Check the column “Difference”, add a note if you like, e.g. “annual stock count”, and click “Book differences”.', 'kontor-shop'),
                ],
                'effect'     => [
                    __('Only products with a difference are booked, as a correction with the note “Stock count”.', 'kontor-shop'),
                    __('The stock in the shop is right again immediately.', 'kontor-shop'),
                    __('Each product’s history shows the correction, so the stock count can be traced later.', 'kontor-shop'),
                ],
                'faq'        => [
                    ['q' => __('Why is “Expected on shelf” higher than the stock in the list?', 'kontor-shop'), 'a' => __('The stock in the list is what is still free to sell. “Expected on shelf” also counts goods that are sold but not yet shipped or picked up, because they are still physically there.', 'kontor-shop')],
                    ['q' => __('Can I count in several rounds?', 'kontor-shop'), 'a' => __('Yes. Book what you have counted, then start a new stock count for the rest. Empty fields stay unchanged.', 'kontor-shop')],
                    ['q' => __('Should I leave out products with stock 0?', 'kontor-shop'), 'a' => __('Not for a full stock count. Keep them in the list, so you also find goods that the shop thinks are gone.', 'kontor-shop')],
                ],
                'related'    => ['stock-delivery', 'stock-min', 'pro-export'],
            ],
            [
                'id'         => 'stock-min',
                'group'      => 'daily',
                'title'      => __('Minimum stock, “low” and “sold out”', 'kontor-shop'),
                'summary'    => __('Get warned in time before a product runs out', 'kontor-shop'),
                'pro'        => false,
                'keywords'   => __('minimum stock min low sold out out of stock warning reorder on shelf for orders negative', 'kontor-shop'),
                'what'       => __('With a minimum stock, the shop tells you in time that a product is running low, so you can reorder before it is sold out.', 'kontor-shop'),
                'steps'      => [
                    ['text' => __('Open Kontor Shop → Stock and choose the tab “Without minimum stock”.', 'kontor-shop'), 'url' => StockPage::url(['view' => 'nomin'])],
                    __('Click “missing” (or the number) in the column “Minimum stock”, type a value, e.g. 3, and press Enter. A good value is what you sell while you wait for a reorder.', 'kontor-shop'),
                    __('For many products at once, tick them and click “Set minimum stock …”.', 'kontor-shop'),
                    ['text' => __('Check the tab “Needs action” or the dashboard regularly.', 'kontor-shop'), 'url' => StockPage::url(['view' => 'attention'])],
                ],
                'effect'     => [
                    __('“Low” means: the stock is at or below the minimum stock. The product appears in the tab “Low” and on the dashboard.', 'kontor-shop'),
                    __('“Sold out” means: 0 or less. For products with sizes, a single empty size is enough.', 'kontor-shop'),
                    __('Customers don’t see the minimum stock. For them the product is available until it is sold out.', 'kontor-shop'),
                ],
                'sections'   => [
                    [
                        'title' => __('What the numbers in the stock list mean', 'kontor-shop'),
                        'list'  => [
                            __('Stock: what is still free to sell. Orders reduce it right away.', 'kontor-shop'),
                            __('“on shelf … · … for orders”: what is physically on the shelf, including goods that are sold but not yet shipped or picked up.', 'kontor-shop'),
                            __('“Sold 30 d.”: pieces sold in the last 30 days, a hint for how much to reorder.', 'kontor-shop'),
                            __('Negative stock: more was booked out than was there, usually because a booking was forgotten. A stock count fixes it.', 'kontor-shop'),
                        ],
                    ],
                ],
                'faq'        => [
                    ['q' => __('Does the minimum stock apply to each size?', 'kontor-shop'), 'a' => __('Yes. The product’s minimum stock applies to each variant. If one size is at or below it, the product counts as “low”.', 'kontor-shop')],
                    ['q' => __('I don’t want a warning for a product.', 'kontor-shop'), 'a' => __('Leave the minimum stock empty; the product is then only flagged when it is sold out. Products marked as “Discontinued product” don’t appear under “Needs action”.', 'kontor-shop')],
                    ['q' => __('Can the shop tell me how much to reorder?', 'kontor-shop'), 'a' => __('Kontor Shop Pro shows reorder hints based on sales and can send you an alarm email.', 'kontor-shop')],
                ],
                'related'    => ['stock-delivery', 'stock-count', 'products-variants', 'pro-reorder', 'pro-dashboard'],
            ],
        ];
    }
}
