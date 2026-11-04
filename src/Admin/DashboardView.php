<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Domain\Health\Check;
use Kontor\Shop\Domain\Health\ShopHealth;

/**
 * Gemeinsame Bausteine von Shop-Uebersicht und Dashboard-Widget: die
 * Betriebsampel (docs/PLAN_dashboard.md). Beide lesen aus ShopSnapshot,
 * damit sie immer dieselben Zahlen zeigen.
 */
final class DashboardView
{
    /**
     * Ampel: eine Zeile mit Gesamtstatus; mit $details aufklappbar zu allen
     * Pruefungen (Shop-Admin). Andere Rollen sehen nur, ob etwas nicht stimmt.
     *
     * @param array<int, Check> $checks
     */
    public static function health(array $checks, bool $details, bool $compact = false): string
    {
        $overall  = ShopHealth::overall($checks);
        $problems = array_values(array_filter($checks, static fn (Check $c): bool => $c->is_problem()));

        [$icon, $title, $sub] = match ($overall) {
            Check::BAD  => ['!', __('The shop has a problem', 'kontor-shop'), $details && $problems !== []
                ? sprintf(
                    /* translators: %s: problem */
                    __('%s – please fix this first.', 'kontor-shop'),
                    $problems[0]->title
                )
                : __('The shop owner has been notified.', 'kontor-shop')],
            Check::WARN => ['!', sprintf(
                /* translators: %d: count */
                _n('%d thing needs attention', '%d things need attention', count($problems), 'kontor-shop'),
                count($problems)
            ), $details && $problems !== [] ? $problems[0]->title . ($compact ? '' : ' – ' . __('the shop is running but should be checked.', 'kontor-shop')) : __('The shop is running.', 'kontor-shop')],
            default     => ['✓', __('All good', 'kontor-shop'), __('Emails, background tasks and checkout are working.', 'kontor-shop')],
        };

        $bar = '<span class="ks-health__dot" aria-hidden="true">' . $icon . '</span><span class="ks-health__text"><span class="ks-health__title">' . esc_html($title)
            . '</span><span class="ks-health__sub">' . esc_html($sub) . '</span></span>';

        if (!$details) {
            return '<div class="ks-health ks-health--' . $overall . ($compact ? ' ks-health--compact' : '') . '"><div class="ks-health__bar">' . $bar . '</div></div>';
        }

        $list = '<ul class="ks-health__checks">';

        foreach ($checks as $check) {
            $list .= '<li><span class="ks-health__icon ks-health__icon--' . esc_attr($check->status) . '" aria-hidden="true">'
                . ($check->status === Check::OK ? '✓' : ($check->status === Check::INFO ? 'i' : '!')) . '</span>'
                . '<span><strong>' . esc_html($check->title) . '</strong><br /><span class="ks-muted">' . esc_html($check->detail) . '</span></span>'
                . ($check->action_url !== '' ? '<a class="button' . ($check->status === Check::BAD ? ' button-primary' : '') . '" href="' . esc_url($check->action_url) . '">' . esc_html($check->action_label) . '</a>' : '<span></span>')
                . '</li>';
        }

        $list .= '</ul>';

        return '<details class="ks-health ks-health--' . $overall . ($compact ? ' ks-health--compact' : '') . '"' . ($overall !== Check::OK && !$compact ? ' open' : '') . '>'
            . '<summary class="ks-health__bar">' . $bar . '<span class="ks-health__more">' . esc_html(sprintf(
                /* translators: %d: count */
                _n('%d check', '%d checks', count($checks), 'kontor-shop'),
                count($checks)
            )) . '</span></summary>' . $list . '</details>';
    }
}
