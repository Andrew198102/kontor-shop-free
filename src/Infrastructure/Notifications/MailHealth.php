<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Notifications;

/**
 * Selbstueberwachung des Mailversands, damit das Plugin auch ohne
 * Gegenstelle allein funktioniert und ein kaputter Mailversand nicht
 * unbemerkt bleibt: merkt sich den letzten Fehler von wp_mail()
 * (Hook wp_mail_failed), erkennt ein SMTP-Plugin und kann eine Testmail
 * senden. Hinweis: wp_mail() meldet nur, ob der Server die Mail
 * angenommen hat - ob sie ankommt (Spam!), laesst sich nicht pruefen.
 */
final class MailHealth
{
    private const OPTION = 'kontor_shop_last_mail_failure';

    private const WP_MAIL_SMTP_FILE = 'wp-mail-smtp/wp_mail_smtp.php';

    public static function register(): void
    {
        add_action('wp_mail_failed', [self::class, 'record_failure']);
    }

    /**
     * @param \WP_Error $error
     */
    public static function record_failure($error): void
    {
        $message = is_object($error) && method_exists($error, 'get_error_message') ? (string) $error->get_error_message() : '';

        update_option(self::OPTION, [
            'time'    => time(),
            'message' => $message !== '' ? $message : 'unbekannter Fehler',
        ], false);

        \Kontor\Shop\Infrastructure\Logging\Logger::error('mail', 'E-Mail konnte nicht gesendet werden: ' . ($message !== '' ? $message : 'unbekannter Fehler'));
    }

    public static function record_success(): void
    {
        if (get_option(self::OPTION, false) !== false) {
            delete_option(self::OPTION);
        }
    }

    /**
     * @return array{time: int, message: string}|null
     */
    public static function last_failure(): ?array
    {
        $data = get_option(self::OPTION, false);

        return is_array($data) && isset($data['time'], $data['message'])
            ? ['time' => (int) $data['time'], 'message' => (string) $data['message']]
            : null;
    }

    /**
     * Name eines erkannten SMTP-/Mail-Plugins oder null.
     */
    public static function smtp_plugin(): ?string
    {
        $known = [
            'WP Mail SMTP'  => static fn (): bool => function_exists('wp_mail_smtp') || class_exists('WPMailSMTP\\Core'),
            'Post SMTP'     => static fn (): bool => class_exists('PostmanWpMail') || class_exists('Post_SMTP'),
            'FluentSMTP'    => static fn (): bool => class_exists('FluentMail\\App\\App') || defined('FLUENTMAIL'),
            'Easy WP SMTP'  => static fn (): bool => class_exists('EasyWPSMTP') || function_exists('swpsmtp_init_smtp'),
            'SMTP Mailer'   => static fn (): bool => class_exists('SMTP_MAILER') || function_exists('smtp_mailer_init'),
        ];

        foreach ($known as $name => $detect) {
            if ($detect()) {
                return $name;
            }
        }

        // Ein Plugin kann wp_mail() komplett ersetzen (pluggable function).
        if (function_exists('wp_mail')) {
            $file = (new \ReflectionFunction('wp_mail'))->getFileName();

            if (is_string($file) && !str_ends_with(str_replace('\\', '/', $file), 'wp-includes/pluggable.php')) {
                return __('Plugin with its own wp_mail() function', 'kontor-shop');
            }
        }

        return null;
    }

    /**
     * Braucht der gewaehlte Versandweg ein SMTP-Plugin, und ist eines
     * einsatzbereit? Nur "Standard (wp_mail)" haengt davon ab - bei einem
     * anderen Adapter (z. B. aus einem Add-on) waere eine SMTP-Warnung irrefuehrend.
     *
     * @return array{status: string, message: string, action_url: string, action_label: string}
     */
    public static function smtp_status(string $mailer_key): array
    {
        $result = static fn (string $status, string $message, string $url = '', string $label = ''): array => [
            'status' => $status, 'message' => $message, 'action_url' => $url, 'action_label' => $label,
        ];

        // Unbekannter Schluessel (z. B. Add-on deaktiviert): MailerFactory nimmt dann wp_mail(), also wie "Standard" pruefen.
        $mailers = MailerFactory::available();

        if ($mailer_key !== MailerFactory::WP_MAILER && isset($mailers[$mailer_key])) {
            return $result('ok', sprintf(
                /* translators: %s: name of the sending method */
                __('not needed – emails are sent via “%s”. Only if that method fails does sending fall back to the WordPress default.', 'kontor-shop'),
                $mailers[$mailer_key]
            ));
        }

        $plugin = self::smtp_plugin();

        if ($plugin === 'WP Mail SMTP' && !self::wp_mail_smtp_configured()) {
            return $result(
                'warn',
                __('WP Mail SMTP is active but not set up yet (mailer “Default PHP”) – emails still go straight through the server and often end up in spam.', 'kontor-shop'),
                admin_url('admin.php?page=wp-mail-smtp'),
                __('Set up WP Mail SMTP', 'kontor-shop')
            );
        }

        if ($plugin !== null) {
            /* translators: %s: name of the SMTP plugin */
            return $result('ok', sprintf(__('detected: %s', 'kontor-shop'), $plugin));
        }

        if (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::WP_MAIL_SMTP_FILE)) {
            return $result(
                'warn',
                __('WP Mail SMTP is installed but not activated – emails go straight through the server and often end up in spam.', 'kontor-shop'),
                admin_url('plugins.php'),
                __('Open plugins', 'kontor-shop')
            );
        }

        return $result(
            'warn',
            __('No SMTP plugin found – with the server\'s default email sending, order confirmations often end up in spam or don\'t arrive at all. Recommended: install and set up WP Mail SMTP, then send a test email under “System”.', 'kontor-shop'),
            admin_url('plugin-install.php?s=WP+Mail+SMTP&tab=search&type=term'),
            __('Install WP Mail SMTP', 'kontor-shop')
        );
    }

    /**
     * WP Mail SMTP ist erst eingerichtet, wenn ein anderer Versand als
     * "Standard PHP" (mailer = mail) gewaehlt ist - per Konstante oder Option.
     */
    private static function wp_mail_smtp_configured(): bool
    {
        if (defined('WPMS_ON') && WPMS_ON && defined('WPMS_MAILER')) {
            $mailer = (string) constant('WPMS_MAILER');
        } else {
            $options = get_option('wp_mail_smtp', []);
            $mailer  = is_array($options) ? (string) ($options['mail']['mailer'] ?? 'mail') : 'mail';
        }

        return $mailer !== '' && $mailer !== 'mail';
    }

    /**
     * Erzwingt WP Mail SMTP eine feste Absenderadresse? Dann ueberschreibt es
     * den From-Header des Shops - Hinweis im Reiter "Mail".
     */
    public static function smtp_forced_from(): ?string
    {
        if (self::smtp_plugin() !== 'WP Mail SMTP') {
            return null;
        }

        $options = get_option('wp_mail_smtp', []);
        $mail    = is_array($options) && is_array($options['mail'] ?? null) ? $options['mail'] : [];

        return !empty($mail['from_email_force']) && is_email((string) ($mail['from_email'] ?? '')) ? (string) $mail['from_email'] : null;
    }

    /**
     * Admin-Hinweis, wenn der gewaehlte Versandweg ein SMTP-Plugin braucht
     * und keines einsatzbereit ist; sonst leer.
     */
    public static function smtp_notice_html(string $mailer_key): string
    {
        $status = self::smtp_status($mailer_key);

        if ($status['status'] === 'ok') {
            return '';
        }

        $link = $status['action_url'] !== '' && current_user_can('install_plugins')
            ? ' <a href="' . esc_url($status['action_url']) . '">' . esc_html($status['action_label']) . '</a>'
            : '';

        return '<div class="notice notice-warning"><p><strong>' . esc_html__('Email sending:', 'kontor-shop') . '</strong> '
            . esc_html($status['message']) . $link . '</p></div>';
    }

    /**
     * Sendet eine Testmail direkt ueber wp_mail() und meldet das Ergebnis.
     *
     * @return array{ok: bool, message: string}
     */
    public static function send_test(string $to, string $shop_name): array
    {
        $captured = '';
        $capture  = static function ($error) use (&$captured): void {
            if (is_object($error) && method_exists($error, 'get_error_message')) {
                $captured = (string) $error->get_error_message();
            }
        };

        add_action('wp_mail_failed', $capture);

        $sent = wp_mail(
            $to,
            sprintf(
                /* translators: %s: shop name */
                __('Test email from %s', 'kontor-shop'),
                $shop_name
            ),
            __('This test email confirms that your shop\'s email sending works in principle.

If it doesn\'t arrive, check your spam folder and set up an SMTP plugin.', 'kontor-shop')
        );

        remove_action('wp_mail_failed', $capture);

        if ($sent) {
            self::record_success();

            return ['ok' => true, 'message' => __('The server accepted the test email. Please check your inbox now (including spam).', 'kontor-shop')];
        }

        return ['ok' => false, 'message' => $captured !== '' ? $captured : __('wp_mail() reports an error.', 'kontor-shop')];
    }

    public static function notice_html(): string
    {
        $failure = self::last_failure();

        if ($failure === null) {
            return '';
        }

        return '<div class="notice notice-error"><p><strong>' . esc_html__('Email sending disrupted:', 'kontor-shop') . '</strong> '
            . esc_html(sprintf(
                /* translators: 1: error message, 2: time */
                __('The last email could not be sent (%1$s, %2$s). Customers may not receive a confirmation - see Settings -> System.', 'kontor-shop'),
                $failure['message'],
                wp_date('d.m.Y H:i', $failure['time'])
            ))
            . '</p></div>';
    }
}
