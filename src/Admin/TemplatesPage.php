<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Domain\Notifications\DefaultTemplates;
use Kontor\Shop\Domain\Notifications\Recipients;
use Kontor\Shop\Domain\Notifications\Template;
use Kontor\Shop\Modules\Notifications\NotificationsModule;
use Kontor\Shop\Settings\Repository;

/**
 * Mail-Vorlagen-Editor (Abschnitt 9/10), eingebettet als Reiter
 * "E-Mail-Vorlagen" in Admin\SettingsPage: je Mail-Ereignis ein Schalter
 * (Mail an/aus), Betreff und HTML-Text (WordPress-Editor), Platzhalter per
 * Klick einfuegen, Testmail mit Beispielwerten, Zuruecksetzen auf Standard.
 */
final class TemplatesPage
{
    public const NONCE_FIELD = 'kontor_shop_templates_nonce';

    private const NONCE_ACTION = 'kontor_shop_templates_save';
    private const EDITOR_ID    = 'ks_tpl_body';

    private Repository $settings;

    public function __construct(Repository $settings)
    {
        $this->settings = $settings;
    }

    public static function url(string $event = ''): string
    {
        return admin_url('admin.php?page=kontor-shop-settings&tab=templates' . ($event !== '' ? '&event=' . $event : ''));
    }

    /**
     * Aktuell gewaehltes Ereignis (GET beim Anzeigen, POST beim Speichern).
     */
    public static function current_event(): string
    {
        $events    = DefaultTemplates::events();
        $requested = sanitize_key((string) ($_POST['event'] ?? $_GET['event'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- nur Auswahl gegen Whitelist; beim Speichern Nonce geprueft in TemplatesPage::handle_submit

        return in_array($requested, $events, true) ? $requested : $events[0];
    }

    /**
     * Speichern/Testmail/Zuruecksetzen - laeuft vor jeder Ausgabe (SettingsPage::handle_request).
     */
    public function handle_submit(): ?string
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) !== 'POST' || !isset($_POST[self::NONCE_FIELD])) {
            return null;
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        if (!current_user_can(Menu::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $event   = self::current_event();
        $action  = sanitize_key((string) ($_POST['template_action'] ?? 'save'));
        $enabled = !empty($_POST['enabled']);

        if ($action === 'reset') {
            $default = DefaultTemplates::all()[$event];
            $this->save($event, $default['subject'], $default['body'], $enabled);

            return __('Template reset to the default text.', 'kontor-shop');
        }

        $this->save(
            $event,
            sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? ''))),
            wp_kses_post(wp_unslash((string) ($_POST['body'] ?? ''))),
            $enabled
        );

        $hint = in_array($event, Recipients::configurable(), true) ? $this->save_recipients($event) : '';

        if ($action !== 'test') {
            return trim(__('Template saved.', 'kontor-shop') . ' ' . $hint);
        }

        $to = (string) wp_get_current_user()->user_email;

        if (!is_email($to)) {
            return __('Template saved. Your user account has no valid email address for the test email.', 'kontor-shop');
        }

        (new NotificationsModule($this->settings))->send_test($event, $to);

        return sprintf(
            /* translators: %s: email address */
            __('Template saved and test email (with sample data) sent to %s. Please check the spam folder too.', 'kontor-shop'),
            $to
        );
    }

    /**
     * Empfaenger interner Mails speichern; liefert einen Hinweis bei
     * ungueltigen Adressen oder wenn niemand mehr die Mail bekommt.
     */
    private function save_recipients(string $event): string
    {
        $raw_extra = sanitize_textarea_field(wp_unslash((string) ($_POST['recipients_extra'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in TemplatesPage::handle_submit
        $extra     = Recipients::parse_list($raw_extra);
        $allowed   = array_map(static fn (\WP_User $user): int => (int) $user->ID, self::selectable_users());
        $users     = array_values(array_intersect(array_map('absint', (array) ($_POST['recipients_users'] ?? [])), $allowed)); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in TemplatesPage::handle_submit
        $default   = !empty($_POST['recipients_default']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in TemplatesPage::handle_submit

        $this->settings->save(['notifications' => ['templates' => [$event => ['recipients' => [
            'default' => $default,
            'users'   => $users,
            'extra'   => implode(', ', $extra),
        ]]]]]);

        $hints   = [];
        $entered = array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw_extra) ?: []));

        if (count($entered) > count($extra)) {
            $hints[] = __('Invalid email addresses were removed.', 'kontor-shop');
        }

        if (!$default && $users === [] && $extra === []) {
            $hints[] = __('Warning: No recipient is selected – this email goes to nobody.', 'kontor-shop');
        }

        return implode(' ', $hints);
    }

    /**
     * Benutzer, die fuer den Shop arbeiten (Admins, Shop-Manager, Kassiere und alle mit Shop-Zugang, z. B. Lagerverwalter).
     *
     * @return array<int, \WP_User>
     */
    private static function selectable_users(): array
    {
        return get_users([
            'capability__in' => ['manage_options', Capabilities::MANAGE, Capabilities::ACCESS, Capabilities::CASHIER],
            'orderby'        => 'display_name',
            'number'         => 300,
        ]);
    }

    private function save(string $event, string $subject, string $body, bool $enabled): void
    {
        $values = ['notifications' => ['templates' => [$event => ['subject' => $subject, 'body' => $body]]]];

        // "Neue Bestellung" nutzt den bestehenden Schalter (Empfaenger stehen im Reiter "Mail").
        if ($event === DefaultTemplates::NEW_ORDER) {
            $values['notifications']['admin_notify_enabled'] = $enabled;
        } else {
            $values['notifications']['templates'][$event]['enabled'] = $enabled;
        }

        $this->settings->save($values);
    }

    public function render(): void
    {
        $event  = self::current_event();
        $module = new NotificationsModule($this->settings);

        wp_enqueue_editor();

        echo '<div class="ks-help-box"><p>' . esc_html__(
            'Here you define which emails the shop sends and what they say. Placeholders like {{order_number}} are replaced automatically when sending – just click a placeholder to insert it. You set the sender and reply-to address in the “Email sending” section.',
            'kontor-shop'
        ) . '</p></div>';

        echo '<div class="ks-tpl">';
        $this->render_list($module, $event);
        $this->render_form($module, $event);
        echo '</div>';
    }

    private function render_list(NotificationsModule $module, string $active): void
    {
        $labels = DefaultTemplates::labels();

        echo '<nav class="ks-tpl__list" aria-label="' . esc_attr__('Email templates', 'kontor-shop') . '"><ul>';

        foreach (DefaultTemplates::events() as $event) {
            $on    = $module->is_enabled($event);
            $class = 'ks-tpl__item' . ($event === $active ? ' is-active' : '');

            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url(self::url($event)) . '"'
                . ($event === $active ? ' aria-current="page"' : '') . '>'
                . '<span class="ks-tpl__name">' . esc_html($labels[$event] ?? $event) . '</span>'
                . '<span class="ks-tpl__meta">' . esc_html(DefaultTemplates::is_customer_mail($event) ? __('to customers', 'kontor-shop') : __('internal', 'kontor-shop')) . '</span>'
                . '<span class="ks-badge ks-badge--' . ($on ? 'ok' : 'muted') . '">' . esc_html($on ? __('on', 'kontor-shop') : __('off', 'kontor-shop')) . '</span>'
                . '</a></li>';
        }

        echo '</ul></nav>';
    }

    private function render_form(NotificationsModule $module, string $event): void
    {
        $template = $module->template($event);
        $body     = Template::is_html($template['body']) ? $template['body'] : Template::text_to_html($template['body']);
        $enabled  = $module->is_enabled($event);
        $labels   = DefaultTemplates::labels();

        echo '<form method="post" action="' . esc_url(self::url($event)) . '" class="ks-tpl__form" data-ks-dirty>';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="event" value="' . esc_attr($event) . '" />';

        echo '<h2>' . esc_html($labels[$event] ?? $event) . '</h2>';
        echo '<p class="description">' . esc_html(DefaultTemplates::descriptions()[$event] ?? '') . '</p>';

        echo '<p class="ks-tpl__switch"><label><input type="checkbox" name="enabled" value="1" ' . checked($enabled, true, false) . ' data-ks-tpl-enabled /> <strong>'
            . esc_html__('Send this email', 'kontor-shop') . '</strong></label></p>';

        if ($event === DefaultTemplates::ORDER_CONFIRMATION) {
            echo '<div class="notice notice-warning inline" data-ks-tpl-off-warning' . ($enabled ? ' hidden' : '') . '><p>' . esc_html__(
                'Warning: Without an order confirmation, the customer sees the amount, bank details, and payment reference only on the confirmation page in the browser. “Resend confirmation” in the order still works.',
                'kontor-shop'
            ) . '</p></div>';
        }

        if (in_array($event, Recipients::configurable(), true)) {
            $this->render_recipients($module, $event);
        }

        echo '<p><label for="ks-tpl-subject"><strong>' . esc_html__('Subject', 'kontor-shop') . '</strong></label><br />';
        echo '<input type="text" id="ks-tpl-subject" name="subject" class="large-text" value="' . esc_attr($template['subject']) . '" data-ks-tpl-target /></p>';

        echo '<p><strong>' . esc_html__('Text', 'kontor-shop') . '</strong></p>';

        wp_editor($body, self::EDITOR_ID, [
            'textarea_name' => 'body',
            'textarea_rows' => 18,
            'media_buttons' => false,
            'teeny'         => false,
            'tinymce'       => [
                'toolbar1'      => 'formatselect,bold,italic,underline,forecolor,bullist,numlist,link,unlink,alignleft,aligncenter,hr,removeformat,undo,redo',
                'toolbar2'      => '',
                'block_formats' => __('Paragraph', 'kontor-shop') . '=p;' . __('Heading', 'kontor-shop') . '=h3',
            ],
        ]);

        $this->render_placeholders($event);

        echo '<p class="ks-tpl__actions">';
        echo '<button type="submit" name="template_action" value="save" class="button button-primary">' . esc_html__('Save', 'kontor-shop') . '</button> ';
        echo '<button type="submit" name="template_action" value="test" class="button">' . esc_html__('Save & send test email to me', 'kontor-shop') . '</button> ';
        echo '<button type="submit" name="template_action" value="reset" class="button button-link-delete" data-ks-confirm-click="'
            . esc_attr__('Reset the subject and text of this template to the default? Your changes will be lost.', 'kontor-shop') . '">'
            . esc_html__('Reset to default', 'kontor-shop') . '</button>';
        echo '</p>';

        echo '</form>';
    }

    private function render_recipients(NotificationsModule $module, string $event): void
    {
        $config = $module->recipient_config($event);
        $users  = self::selectable_users();

        echo '<fieldset class="ks-tpl__recipients"><legend>' . esc_html__('Recipients', 'kontor-shop') . '</legend>';

        // Versandauftrag mit eigenem Hauptempfaenger je Lager (z. B. Pro: Lagerverwalter) - die Auswahl unten geht dann in Kopie.
        $per_warehouse = $event === DefaultTemplates::PAYMENT_CONFIRMED && has_filter('kontor_shop/notifications/ship_order_recipients') !== false;
        $label         = sprintf(
            /* translators: %s: email address(es) */
            __('Shop owner (%s)', 'kontor-shop'),
            implode(', ', $module->operator_emails()) ?: '—'
        );

        /**
         * Filter kontor_shop/templates/default_recipient_label: Beschriftung
         * der Checkbox "Standard-Empfaenger" im Vorlagen-Editor, z. B. Pro:
         * "Lagerverwalter des jeweiligen Lagers" beim Versandauftrag.
         * Standard: "Shop-Betreiber (Adresse)".
         *
         * @param string $label
         * @param string $event Mail-Ereignis
         */
        $label = (string) apply_filters('kontor_shop/templates/default_recipient_label', $label, $event);

        echo '<p><label><input type="checkbox" name="recipients_default" value="1" ' . checked($config['default'], true, false) . ' /> ' . esc_html($label) . '</label>'
            . ($per_warehouse ? '' : ' <a href="' . esc_url(admin_url('admin.php?page=kontor-shop-settings&tab=mail')) . '">' . esc_html__('Change address', 'kontor-shop') . '</a>') . '</p>';

        echo '<p><strong>' . esc_html(!$per_warehouse ? __('Additional users', 'kontor-shop') : __('Copy users', 'kontor-shop')) . '</strong></p>';

        if ($users === []) {
            echo '<p class="description">' . esc_html__('No shop users found.', 'kontor-shop') . '</p>';
        } else {
            if (count($users) > 8) {
                echo '<input type="search" class="regular-text" placeholder="' . esc_attr__('Search users …', 'kontor-shop') . '" data-ks-filter-list="#ks-tpl-users-' . esc_attr($event) . '" /> ';
            }

            echo '<ul class="ks-tpl__users" id="ks-tpl-users-' . esc_attr($event) . '">';

            foreach ($users as $user) {
                echo '<li><label><input type="checkbox" name="recipients_users[]" value="' . esc_attr((string) $user->ID) . '" '
                    . checked(in_array((int) $user->ID, $config['users'], true), true, false) . ' /> ' . esc_html($user->display_name)
                    . ' <span class="ks-muted">' . esc_html($user->user_email) . '</span></label></li>';
            }

            echo '</ul>';
        }

        echo '<p><label for="ks-tpl-extra-' . esc_attr($event) . '"><strong>' . esc_html(!$per_warehouse
            ? __('Additional email addresses', 'kontor-shop')
            : __('Copy additional email addresses', 'kontor-shop')) . '</strong></label><br />';
        echo '<input type="text" id="ks-tpl-extra-' . esc_attr($event) . '" name="recipients_extra" class="large-text" placeholder="' . esc_attr__('e.g. treasurer@club.com, chair@club.com', 'kontor-shop') . '" value="'
            . esc_attr($config['extra']) . '" />';
        echo '<span class="description">' . esc_html__('Separate multiple addresses with commas. Each recipient gets a separate email.', 'kontor-shop') . '</span></p>';
        echo '</fieldset>';
    }

    private function render_placeholders(string $event): void
    {
        $names = self::placeholder_labels();

        echo '<div class="ks-tpl__placeholders"><p><strong>' . esc_html__('Insert placeholder', 'kontor-shop') . '</strong> '
            . '<span class="description">' . esc_html__('(Click inserts it at the cursor position in the subject or text)', 'kontor-shop') . '</span></p>';

        foreach (DefaultTemplates::placeholders()[$event] ?? [] as $placeholder) {
            echo '<button type="button" class="ks-chip" data-ks-placeholder="{{' . esc_attr($placeholder) . '}}" data-ks-editor="' . esc_attr(self::EDITOR_ID) . '" title="{{'
                . esc_attr($placeholder) . '}}">' . esc_html($names[$placeholder] ?? $placeholder) . '</button> ';
        }

        echo '</div>';
    }

    /**
     * @return array<string, string>
     */
    private static function placeholder_labels(): array
    {
        return [
            'shop_name'              => __('Shop name', 'kontor-shop'),
            'customer_name'          => __('Customer name', 'kontor-shop'),
            'customer_email'         => __('Customer email', 'kontor-shop'),
            'customer_phone'         => __('Phone', 'kontor-shop'),
            'warehouse_manager_name' => __('Warehouse manager', 'kontor-shop'),
            'warehouse_name'         => __('Warehouse name', 'kontor-shop'),
            'order_number'           => __('Order number', 'kontor-shop'),
            'payment_reference'      => __('Payment reference', 'kontor-shop'),
            'payment_due_date'       => __('Payment deadline', 'kontor-shop'),
            'items'                  => __('Product list', 'kontor-shop'),
            'items_total'            => __('Goods value after discount', 'kontor-shop'),
            'goods_total'            => __('Goods value before discount', 'kontor-shop'),
            'discount_line'          => __('Discount line (only with a discount code)', 'kontor-shop'),
            'shipping_cost'          => __('Shipping cost', 'kontor-shop'),
            'total'                  => __('Total', 'kontor-shop'),
            'payment_method_label'   => __('Payment method', 'kontor-shop'),
            'payment_details'        => __('Payment details (bank, deadline)', 'kontor-shop'),
            'order_status_url'       => __('Order status page link', 'kontor-shop'),
            'confirmation_url'       => __('Order overview link (same as status link)', 'kontor-shop'),
            'orders_url'             => __('Orders link (backend)', 'kontor-shop'),
            'billing_info'           => __('Billing address', 'kontor-shop'),
            'delivery_info'          => __('Delivery/address', 'kontor-shop'),
            'next_step'              => __('Next step', 'kontor-shop'),
            'shipping_note'          => __('Shipping note', 'kontor-shop'),
            'pickup_info'            => __('Pickup location', 'kontor-shop'),
            'refund_note'            => __('Refund note', 'kontor-shop'),
            'cancelled_items'        => __('Cancelled products', 'kontor-shop'),
            'cancel_reason'          => __('Cancellation reason', 'kontor-shop'),
            'cancel_scope'           => __('Scope (full/partial)', 'kontor-shop'),
            'amount_note'            => __('Amount/refund', 'kontor-shop'),
            'order_url'              => __('Link to order (backend)', 'kontor-shop'),
        ];
    }
}
