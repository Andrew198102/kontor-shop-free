<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Ui\PageHeader;
use Kontor\Shop\Domain\Support\IssueLink;
use Kontor\Shop\Infrastructure\Support\ProblemReport;
use Kontor\Shop\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Problem melden": zeigt den Diagnosebericht (Infrastructure\Support\ProblemReport)
 * zum Pruefen an und oeffnet damit ein vorausgefuelltes Issue im oeffentlichen
 * Support-Repo (IssueLink::REPOSITORY). Nichts wird automatisch gesendet - der
 * Admin sieht den Bericht und schickt ihn selbst ab, mit seinem GitHub-Konto
 * oder per E-Mail. Versteckte Unterseite, erreichbar aus "Protokolle".
 */
final class ReportPage
{
    public const SLUG = 'kontor-shop-report';

    public const SUPPORT_EMAIL = 'wordpress@veyra-assistant.com';

    public function __construct(private readonly Repository $settings)
    {
    }

    public static function url(string $reference = ''): string
    {
        $args = ['page' => self::SLUG];

        if ($reference !== '') {
            $args['ref'] = $reference;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    public function render(): void
    {
        if (!current_user_can(Menu::SETTINGS_CAPABILITY)) {
            wp_die(esc_html__('No permission.', 'kontor-shop'));
        }

        $reference = isset($_GET['ref']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_GET['ref']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
        $reference = preg_match('/^KS-[A-Z0-9]{4,16}$/', $reference) === 1 ? $reference : '';
        $report    = (new ProblemReport($this->settings))->markdown($reference);

        $repository = (string) apply_filters('kontor_shop/support/repository', IssueLink::REPOSITORY);
        $issue_url  = IssueLink::build(
            $repository,
            'bug_report.yml',
            '[Bug] ' . ($reference !== '' ? $reference . ': ' : ''),
            ['version' => KONTOR_SHOP_VERSION, 'diagnostics' => $report],
            'diagnostics',
            __('(Report shortened – please paste the full report from the plugin here.)', 'kontor-shop')
        );

        echo '<div class="wrap ks-wrap">';
        PageHeader::render(__('Report a problem', 'kontor-shop'), [
            ['label' => __('Back to logs', 'kontor-shop'), 'url' => admin_url('admin.php?page=kontor-shop-log')],
        ]);

        echo '<div class="ks-help-box"><p>' . esc_html__(
            'Something is not working? This report helps us find the cause quickly. It contains versions, settings and the latest warnings and errors from the log – no customer data. Email addresses, IBANs, IP addresses, tokens and the address of your website are removed automatically. Please read it before sending: reports on GitHub are public.',
            'kontor-shop'
        ) . '</p></div>';

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ks-report-ref">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';
        echo '<label for="ks-report-ref">' . esc_html__('Reference number (optional)', 'kontor-shop') . '</label> ';
        echo '<input type="text" id="ks-report-ref" name="ref" class="regular-text code" placeholder="KS-…" value="' . esc_attr($reference) . '" /> ';
        echo '<button type="submit" class="button">' . esc_html__('Add to report', 'kontor-shop') . '</button>';
        echo '<p class="description">' . esc_html__('Customers see a reference number (KS-…) when an error occurs. With it, the matching log entry appears first and in full.', 'kontor-shop') . '</p>';
        echo '</form>';

        echo '<h2>' . esc_html__('Diagnostics report', 'kontor-shop') . '</h2>';
        echo '<textarea id="ks-report" class="large-text code" rows="22" readonly>' . esc_textarea($report) . '</textarea>';

        echo '<p class="ks-report-actions">';
        echo '<a class="button button-primary" href="' . esc_url($issue_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Report on GitHub', 'kontor-shop') . '</a> ';
        echo '<button type="button" class="button" data-ks-copy="' . esc_attr($report) . '">' . esc_html__('Copy report', 'kontor-shop') . '</button>';
        echo '</p>';

        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: support email address */
            __('On GitHub you describe what happened; the report is already filled in. No GitHub account? Copy the report and send it with a short description to %s.', 'kontor-shop'),
            self::SUPPORT_EMAIL
        )) . '</p>';

        echo '</div>';
    }
}
