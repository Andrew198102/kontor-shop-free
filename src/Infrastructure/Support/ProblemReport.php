<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Support;

use Kontor\Shop\Admin\SystemCheck;
use Kontor\Shop\Domain\Support\Redactor;
use Kontor\Shop\Infrastructure\Checkout\ReservationCleanup;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Notifications\MailHealth;
use Kontor\Shop\Infrastructure\Notifications\MailOutbox;
use Kontor\Shop\Settings\Repository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Diagnosebericht fuer eine Fehlermeldung (Admin\ReportPage): Versionen,
 * Umgebung, aktive Plugins, Mailversand, Hintergrund-Aufgaben, auffaellige
 * Punkte der Systempruefung und die letzten Warnungen/Fehler aus dem
 * Protokoll - als Markdown fuer ein GitHub-Issue. Alles laeuft durch den
 * Redactor; keine Kundendaten, keine Adresse der Website.
 */
final class ProblemReport
{
    private const LOG_ROWS = 15;

    private Redactor $redactor;

    public function __construct(private readonly Repository $settings)
    {
        $this->redactor = new Redactor((string) wp_parse_url(home_url(), PHP_URL_HOST), ABSPATH);
    }

    /**
     * @param string $reference Fehlernummer (KS-…), deren Eintrag zuerst und vollstaendig erscheint
     */
    public function markdown(string $reference = ''): string
    {
        $lines = array_merge(
            ['### Environment', ''],
            $this->table($this->environment()),
            ['', '### Active plugins', ''],
            $this->plugins(),
            ['', '### Email and background tasks', ''],
            $this->table($this->mail_and_cron()),
            ['', '### System check (only items that are not OK)', ''],
            $this->system_check(),
            ['', '### Log' . ($reference !== '' ? ' (reference ' . $reference . ' first)' : '') . ' – latest warnings and errors', ''],
            $this->log($reference)
        );

        return $this->redactor->text(implode("\n", $lines));
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        global $wpdb;

        $edition = 'Free';

        if (function_exists('kontor_shop_fs') && kontor_shop_fs()->is_premium()) {
            $edition = kontor_shop_fs()->can_use_premium_code() ? 'Pro (licensed)' : 'Pro (no active licence)';
        }

        $theme = wp_get_theme();

        return [
            'Kontor Shop'   => KONTOR_SHOP_VERSION . ' · ' . $edition . ' · schema ' . (string) get_option('kontor_shop_schema_version', '?'),
            'WordPress'     => get_bloginfo('version') . (is_multisite() ? ' (multisite)' : '') . ' · ' . get_locale(),
            'PHP'           => PHP_VERSION . ' · memory ' . (string) ini_get('memory_limit') . ' · max_execution_time ' . (string) ini_get('max_execution_time'),
            'Database'      => (string) $wpdb->db_version(),
            'Theme'         => ($theme->get('Name') !== '' ? $theme->get('Name') . ' ' . $theme->get('Version') : '(none)') . ($theme->parent() ? ' (child of ' . $theme->parent()->get('Name') . ')' : ''),
            'Environment'   => wp_get_environment_type() . (defined('WP_DEBUG') && WP_DEBUG ? ' · WP_DEBUG on' : ''),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all    = get_plugins();
        $active = (array) get_option('active_plugins', []);
        $lines  = [];

        foreach ($active as $file) {
            $data    = $all[$file] ?? null;
            $lines[] = '- ' . ($data !== null ? $data['Name'] . ' ' . $data['Version'] : (string) $file);
        }

        return $lines !== [] ? $lines : ['- (none)'];
    }

    /**
     * @return array<string, string>
     */
    private function mail_and_cron(): array
    {
        $failure  = MailHealth::last_failure();
        $outbox   = (new MailOutbox($this->settings))->counts();
        $next     = wp_next_scheduled(ReservationCleanup::HOOK);
        $last_run = (int) get_option(ReservationCleanup::LAST_RUN_OPTION, 0);

        return [
            'Sending method'      => (string) $this->settings->get('notifications.mailer', 'wp'),
            'SMTP plugin'         => MailHealth::smtp_plugin() ?? 'none detected',
            'Outbox'              => sprintf('%d pending, %d given up', $outbox['pending'], $outbox['failed']),
            'Last mail error'     => $failure !== null ? gmdate('Y-m-d H:i', $failure['time']) . ' UTC: ' . $failure['message'] : 'none',
            'Hourly tasks'        => ($next !== false ? 'scheduled' : 'NOT scheduled') . ' · last run ' . ($last_run > 0 ? human_time_diff($last_run) . ' ago' : 'never')
                . (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? ' · DISABLE_WP_CRON' : ''),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function system_check(): array
    {
        $lines = [];

        foreach ((new SystemCheck($this->settings))->run() as $check) {
            if ($check['status'] !== SystemCheck::OK) {
                $lines[] = '- **' . strtoupper($check['status']) . '** ' . $check['label'] . ': ' . wp_strip_all_tags($check['message']);
            }
        }

        return $lines !== [] ? $lines : ['- all OK'];
    }

    /**
     * @return array<int, string>
     */
    private function log(string $reference): array
    {
        $rows = $reference !== '' ? Logger::recent(['q' => $reference], 3) : [];
        $seen = array_column($rows, 'id');

        foreach (Logger::recent(['level' => Logger::WARNING], self::LOG_ROWS) as $row) {
            if (!in_array($row['id'], $seen, true)) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return ['No warnings or errors logged.'];
        }

        $lines = ['```'];

        foreach (array_slice($rows, 0, self::LOG_ROWS) as $row) {
            $lines[] = sprintf(
                '%s %s %s%s: %s',
                (string) $row['created_at'],
                strtoupper((string) $row['level']),
                (string) $row['channel'],
                !empty($row['reference']) ? ' [' . $row['reference'] . ']' : '',
                (string) $row['message']
            );

            $context = $this->redactor->context(isset($row['context']) ? (string) $row['context'] : null, $row['reference'] === $reference && $reference !== '' ? 2000 : 400);

            if ($context !== '') {
                $lines[] = '    ' . $context;
            }
        }

        $lines[] = '```';

        return $lines;
    }

    /**
     * @param array<string, string> $rows
     * @return array<int, string>
     */
    private function table(array $rows): array
    {
        $lines = ['| | |', '|---|---|'];

        foreach ($rows as $label => $value) {
            $lines[] = '| ' . $label . ' | ' . str_replace('|', '\|', $value) . ' |';
        }

        return $lines;
    }
}
