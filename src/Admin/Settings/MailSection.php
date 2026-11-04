<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Admin\TemplatesPage;
use Kontor\Shop\Infrastructure\Notifications\MailHealth;
use Kontor\Shop\Infrastructure\Notifications\MailOutbox;
use Kontor\Shop\Infrastructure\Notifications\MailerFactory;
use Kontor\Shop\Modules\Notifications\PoweredBy;

/**
 * Versandweg, Absender, Benachrichtigung an den Betreiber und Postausgang
 * (nicht zugestellte Mails, Infrastructure\Notifications\MailOutbox).
 */
final class MailSection extends Section
{
    public function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return __('Email sending', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_TALK;
    }

    public function handles(): array
    {
        return ['mail', 'mail_outbox'];
    }

    public function save(string $tab): ?string
    {
        return match ($tab) {
            'mail' => $this->save_mail(),
            'mail_outbox' => $this->save_outbox(),
            default => null,
        };
    }

    public function render(): void
    {
        $this->render_mail_tab();
        $this->render_outbox();
    }

    private function save_outbox(): ?string
    {
        $outbox  = new MailOutbox($this->settings);
        $discard = absint($_POST['discard'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        if ($discard > 0) {
            $outbox->discard($discard);

            return __('Email removed from the outbox.', 'kontor-shop');
        }

        $result = $outbox->retry_all();

        if ($result['left'] === 0) {
            return __('All emails from the outbox have been sent.', 'kontor-shop');
        }

        $this->warnings[] = sprintf(
            /* translators: 1: emails sent, 2: remaining emails */
            __('%1$d sent, %2$d still undeliverable. Check the sending method and SMTP plugin above, then send a test email.', 'kontor-shop'),
            $result['sent'],
            $result['left']
        );

        return null;
    }

    /**
     * Mails, die kein Versandweg angenommen hat. Ohne Eintraege nur ein Satz,
     * damit klar ist, dass es den Sicherheitsweg gibt.
     */
    private function render_outbox(): void
    {
        $outbox = new MailOutbox($this->settings);
        $counts = $outbox->counts();

        echo '<div id="ks-outbox">';
        $this->group_title(__('Outbox', 'kontor-shop'));

        if ($counts['pending'] + $counts['failed'] === 0) {
            echo '<p class="description">' . esc_html__('Empty. If an email can’t be sent, it lands here and is retried automatically (after 5 minutes, 30 minutes, 2, 6 and 24 hours).', 'kontor-shop') . '</p></div>';

            return;
        }

        $labels = \Kontor\Shop\Domain\Notifications\DefaultTemplates::labels();

        $this->form_open('mail_outbox');
        echo '<p>' . esc_html__('No sending method accepted these emails. Open emails are retried automatically; abandoned ones only when you click. Abandoned emails are deleted after 30 days.', 'kontor-shop') . '</p>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Created', 'kontor-shop') . '</th>'
            . '<th>' . esc_html__('Email', 'kontor-shop') . '</th>'
            . '<th>' . esc_html__('To', 'kontor-shop') . '</th>'
            . '<th>' . esc_html__('State', 'kontor-shop') . '</th>'
            . '<th>' . esc_html__('Last error', 'kontor-shop') . '</th>'
            . '<th></th></tr></thead><tbody>';

        foreach ($outbox->recent() as $row) {
            $failed = ($row['status'] ?? '') === MailOutbox::STATUS_FAILED;
            $next   = strtotime((string) $row['next_attempt_at'] . ' UTC');
            $state  = $failed
                ? __('abandoned', 'kontor-shop')
                : sprintf(
                    /* translators: 1: time, 2: number of attempts */
                    __('next attempt %1$s (so far %2$d)', 'kontor-shop'),
                    wp_date('d.m. H:i', $next !== false ? $next : time()),
                    (int) $row['attempts']
                );
            $event  = (string) ($row['event'] ?? '');
            $what   = $labels[$event] ?? (string) ($row['subject'] ?? '');
            $order  = (string) ($row['reference'] ?? '');
            $created = strtotime((string) $row['created_at'] . ' UTC');

            echo '<tr><td>' . esc_html(wp_date('d.m. H:i', $created !== false ? $created : time())) . '</td>'
                . '<td>' . esc_html($what) . ($order !== '' ? ' · ' . esc_html($order) : '') . '</td>'
                . '<td>' . esc_html((string) $row['recipient']) . '</td>'
                . '<td>' . ($failed ? '<span class="ks-badge ks-badge--bad">' . esc_html($state) . '</span>' : esc_html($state)) . '</td>'
                . '<td>' . esc_html((string) ($row['last_error'] ?? '') !== '' ? (string) $row['last_error'] : '–') . '</td>'
                . '<td><button type="submit" class="button-link button-link-delete" name="discard" value="' . esc_attr((string) $row['id']) . '">' . esc_html__('Discard', 'kontor-shop') . '</button></td></tr>';
        }

        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Resend now', 'kontor-shop') . '</button></p>';
        echo '</form></div>';
    }

    private function save_mail(): ?string
    {
        $mailer = sanitize_key((string) ($_POST['mailer'] ?? MailerFactory::WP_MAILER)); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request

        if (!array_key_exists($mailer, MailerFactory::available())) {
            $mailer = MailerFactory::WP_MAILER;
        }

        // Nur Free: Schalter "Vereinsshop mit Kontor Shop" (in Pro nicht im Formular, also nicht schreiben).
        $general = PoweredBy::save_setting($this->settings);

        $this->settings->save(($general !== [] ? ['general' => $general] : []) + ['notifications' => [
            'mailer'             => $mailer,
            'admin_notify_email' => $this->sanitize_email_list((string) wp_unslash($_POST['admin_notify_email'] ?? '')), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce geprueft in SettingsPage::handle_request; sanitize_email_list() prueft jede Adresse mit sanitize_email/is_email
            'from_name'          => sanitize_text_field(wp_unslash((string) ($_POST['from_name'] ?? ''))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'from_email'         => sanitize_email(wp_unslash((string) ($_POST['from_email'] ?? ''))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
            'reply_to'           => sanitize_email(wp_unslash((string) ($_POST['reply_to'] ?? ''))), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request
        ]]);

        return __('Settings saved.', 'kontor-shop');
    }

    private function render_mail_tab(): void
    {
        $current = (string) $this->settings->get('notifications.mailer', MailerFactory::WP_MAILER);
        $current = array_key_exists($current, MailerFactory::available()) ? $current : MailerFactory::WP_MAILER; // z. B. Add-on deaktiviert

        $this->form_open('mail');

        $this->intro(__(
            'The shop sends emails to customers (order confirmation, paid, shipped) and to you (new order). Here you choose how they are sent. “Default” is enough to get started – it works without any further setup.',
            'kontor-shop'
        ));

        echo '<h2>' . esc_html__('1. Sending method', 'kontor-shop') . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="ks-mailer">' . esc_html__('Email sending method', 'kontor-shop') . '</label></th><td>';
        echo '<select id="ks-mailer" name="mailer">';

        foreach (MailerFactory::available() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($current, $key, false) . '>' . esc_html($label) . '</option>';
        }

        echo '</select>';
        echo '<p class="description">' . wp_kses_post(sprintf(
            /* translators: %s: link “Email templates” */
            __('“Default” uses the regular WordPress email sending. You can choose which emails are sent and edit their texts under %s.', 'kontor-shop'),
            '<a href="' . esc_url(TemplatesPage::url()) . '">' . esc_html__('Email templates', 'kontor-shop') . '</a>'
        )) . '</p>';

        // SMTP-Selbstkontrolle nur fuer "Standard": admin.js blendet den Hinweis
        // beim Umschalten des Versandwegs sofort ein/aus (auch vor dem Speichern).
        $smtp = MailHealth::smtp_status(MailerFactory::WP_MAILER);
        $link = $smtp['action_url'] !== '' && current_user_can('install_plugins')
            ? ' <a href="' . esc_url($smtp['action_url']) . '">' . esc_html($smtp['action_label']) . '</a>'
            : '';

        echo '<div class="notice inline ' . ($smtp['status'] === 'ok' ? 'notice-success' : 'notice-warning') . '" data-ks-mailer-only="'
            . esc_attr(MailerFactory::WP_MAILER) . '"' . ($current === MailerFactory::WP_MAILER ? '' : ' hidden') . '><p><strong>'
            . esc_html__('SMTP plugin:', 'kontor-shop') . '</strong> ' . esc_html($smtp['message']) . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link ist aus esc_url/esc_html zusammengesetzt

        echo '</td></tr></tbody></table>';

        $this->render_sender_section($current);

        echo '<h2>' . esc_html__('3. Notification to you', 'kontor-shop') . '</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="ks-admin-notify">' . esc_html__('Shop owner address', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" id="ks-admin-notify" name="admin_notify_email" class="regular-text" placeholder="' . esc_attr((string) get_option('admin_email')) . '" value="'
            . esc_attr((string) $this->settings->get('notifications.admin_notify_email', '')) . '" />';
        echo '<p class="description">' . wp_kses_post(sprintf(
            /* translators: %s: link “Email templates” */
            __('Receives “New order” and warnings about serious errors. Separate multiple addresses with commas. Empty = the email address of the WordPress administrator (shown in gray). To set who else receives “New order”, go to %s → “New order”.', 'kontor-shop'),
            '<a href="' . esc_url(TemplatesPage::url(\Kontor\Shop\Domain\Notifications\DefaultTemplates::NEW_ORDER)) . '">' . esc_html__('Email templates', 'kontor-shop') . '</a>'
        )) . '</p></td></tr></tbody></table>';

        PoweredBy::render_setting($this->settings);

        $this->savebar();
        echo '</form>';
    }

    /**
     * Absender, Absendername und Antwortadresse der Shop-Mails.
     */
    private function render_sender_section(string $mailer): void
    {
        $host         = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $domain       = (string) preg_replace('/^www\./i', '', $host);
        $from_email   = (string) $this->settings->get('notifications.from_email', '');
        $forced       = MailHealth::smtp_forced_from();
        $from_domain  = str_contains($from_email, '@') ? strtolower(substr((string) strrchr($from_email, '@'), 1)) : '';

        echo '<h2>' . esc_html__('2. Sender', 'kontor-shop') . '</h2>';
        echo '<table class="form-table"><tbody>';

        echo '<tr><th><label for="ks-from-name">' . esc_html__('Sender name', 'kontor-shop') . '</label></th><td>';
        echo '<input type="text" id="ks-from-name" name="from_name" class="regular-text" placeholder="' . esc_attr($this->shop_display_name()) . '" value="'
            . esc_attr((string) $this->settings->get('notifications.from_name', '')) . '" />';
        echo '<p class="description">' . esc_html__('This is how the sender appears in the customer’s inbox, e.g. “Club Shop Example Club”. Empty = default from WordPress/SMTP plugin.', 'kontor-shop') . '</p></td></tr>';

        echo '<tr><th><label for="ks-from-email">' . esc_html__('Sender address', 'kontor-shop') . '</label></th><td>';
        echo '<input type="email" id="ks-from-email" name="from_email" class="regular-text" placeholder="' . esc_attr('shop@' . $domain) . '" value="' . esc_attr($from_email) . '" />';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: website domain */
            __('Empty = default from WordPress or the SMTP plugin. Use an address on your own domain (@%s) that your mail server/SMTP account is allowed to send from – other senders (e.g. @gmail.com) end up in spam or are rejected.', 'kontor-shop'),
            $domain
        )) . '</p>';

        if ($from_domain !== '' && $domain !== '' && $from_domain !== strtolower($domain) && !str_ends_with($from_domain, '.' . strtolower($domain))) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(
                /* translators: 1: sender address domain, 2: website domain */
                __('The sender address belongs to %1$s, the website to %2$s. This only works if your SMTP account is allowed to send for %1$s – otherwise emails end up in spam.', 'kontor-shop'),
                $from_domain,
                $domain
            )) . '</p></div>';
        }

        if ($forced !== null && $mailer === MailerFactory::WP_MAILER) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf(
                /* translators: %s: forced sender address */
                __('Note: “Force From Email” is active in WP Mail SMTP – all emails are always sent from %s there, so the setting here is overridden.', 'kontor-shop'),
                $forced
            )) . '</p></div>';
        }

        echo '</td></tr>';

        echo '<tr><th><label for="ks-reply-to">' . esc_html__('Reply to', 'kontor-shop') . '</label></th><td>';
        echo '<input type="email" id="ks-reply-to" name="reply_to" class="regular-text" value="' . esc_attr((string) $this->settings->get('notifications.reply_to', '')) . '" />';
        echo '<p class="description">' . esc_html__('Optional: if a customer replies to a shop email, the reply goes here (e.g. your club inbox) – even if the sender address is a no-reply account.', 'kontor-shop') . '</p></td></tr>';

        echo '</tbody></table>';
    }

    private function shop_display_name(): string
    {
        $name = (string) $this->settings->get('general.shop_name', '');

        return $name !== '' ? $name : (string) get_bloginfo('name');
    }

    private function sanitize_email_list(string $raw): string
    {
        $valid = [];

        foreach (preg_split('/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            $email = sanitize_email($candidate);

            if ($email !== '' && is_email($email)) {
                $valid[] = $email;
            }
        }

        return implode(', ', array_unique($valid));
    }
}
