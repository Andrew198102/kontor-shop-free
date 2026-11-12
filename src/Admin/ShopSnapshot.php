<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Admin\Settings\Section;
use Kontor\Shop\Core\Capabilities;
use Kontor\Shop\Domain\Health\Check;
use Kontor\Shop\Domain\Health\ShopHealth;
use Kontor\Shop\Domain\Order\PaymentTerms;
use Kontor\Shop\Domain\Order\WorkQueue;
use Kontor\Shop\Domain\Stock\StockSignals;
use Kontor\Shop\Infrastructure\Catalog\PublishCandidates;
use Kontor\Shop\Infrastructure\Checkout\ReservationCleanup;
use Kontor\Shop\Infrastructure\Logging\Logger;
use Kontor\Shop\Infrastructure\Notifications\MailerFactory;
use Kontor\Shop\Infrastructure\Notifications\MailHealth;
use Kontor\Shop\Infrastructure\Notifications\MailOutbox;
use Kontor\Shop\Infrastructure\Repositories\OrderRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\StockRepository;
use Kontor\Shop\Infrastructure\Repositories\VariantRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Settings\Repository;

/**
 * Eine Datenquelle fuer Shop-Uebersicht und Dashboard-Widget
 * (docs/PLAN_dashboard.md): Betriebsampel und "Heute zu tun" - jeweils nur,
 * was die Rolle des Benutzers betrifft. Jede Kachel bringt einen Link mit,
 * der direkt die passende Liste oeffnet (mit Pro die Packansicht).
 * Erweiterungen bekommen die Daten ueber queues(), stock_rows(),
 * warehouses() und warehouse_scope() (z. B. Pro: "Das haengt", Verlauf und
 * die Lager-Karte ueber kontor_shop/dashboard/cards).
 */
final class ShopSnapshot
{
    public readonly bool $can_manage;
    public readonly bool $can_pay;
    public readonly bool $can_ship;
    public readonly bool $can_stock;
    /** @var array<int, int> */
    public readonly array $my_warehouse_ids;

    private PaymentTerms $terms;
    private string $today;

    /** @var array<int, WorkQueue>|null */
    private ?array $queues = null;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $stock_rows = null;
    /** @var array<int, array{name: string, color: string, manager: string}>|null */
    private ?array $warehouses = null;

    public function __construct(private readonly Repository $settings)
    {
        $this->can_manage = Capabilities::can_manage();
        $this->can_pay    = $this->can_manage || Capabilities::is_cashier();

        $ids = $this->can_manage ? [] : (Capabilities::warehouse_scope() ?? []);

        $this->my_warehouse_ids = $ids;
        $this->can_ship         = $this->can_manage || $ids !== [];
        $this->can_stock        = $this->can_ship;
        $this->terms            = (new \Kontor\Shop\Modules\Payments\PaymentOptions($settings))->terms();
        $this->today            = current_time('Y-m-d');
    }

    /** Lager-Scope fuer Bestand/Versand (null = alle). */
    private function ship_scope(): ?array
    {
        return $this->can_manage ? null : $this->my_warehouse_ids;
    }

    /**
     * Lager-Scope des Benutzers fuer Bestand/Versand (null = alle) - fuer
     * Erweiterungen (z. B. Pro: "Das haengt", kontor_shop/dashboard/stalled_items).
     *
     * @return array<int, int>|null
     */
    public function warehouse_scope(): ?array
    {
        return $this->ship_scope();
    }

    /**
     * Sichtbare Bestellungen samt Arbeitsstand (Lager-Scope der Rolle).
     *
     * @return array<int, WorkQueue>
     */
    public function queues(): array
    {
        return $this->queues ??= (new OrdersList(new OrderRepository(), new WarehouseRepository(), $this->terms))
            ->load([], $this->can_pay ? null : $this->my_warehouse_ids);
    }

    /**
     * Bestandszeilen im Lager-Scope (StockOverview::rows()), leer ohne Bestandsrecht.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stock_rows(): array
    {
        if (!$this->can_stock) {
            return [];
        }

        return $this->stock_rows ??= (new StockOverview(new ProductRepository(), new WarehouseRepository(), new StockRepository(), new VariantRepository()))
            ->rows($this->ship_scope());
    }

    /**
     * @return array<int, array{name: string, color: string, manager: string}>
     */
    public function warehouses(): array
    {
        if ($this->warehouses === null) {
            $this->warehouses = [];

            foreach ((new WarehouseRepository())->all() as $warehouse) {
                /**
                 * Filter kontor_shop/warehouses/manager_name: wer fuer ein
                 * Lager zustaendig ist (z. B. Pro: der Lagerverwalter) - fuer
                 * die Karten der Uebersicht (Pro: Lager, "Das haengt").
                 * Standard: '' (niemand genannt).
                 *
                 * @param string                              $name
                 * @param \Kontor\Shop\Domain\Catalog\Warehouse $warehouse
                 */
                $manager = (string) apply_filters('kontor_shop/warehouses/manager_name', '', $warehouse);

                $this->warehouses[(int) $warehouse->id] = [
                    'name'    => $warehouse->name,
                    'color'   => $warehouse->display_color(),
                    'manager' => $manager,
                ];
            }
        }

        return $this->warehouses;
    }

    /* ==================== 1. Betriebsampel ==================== */

    /**
     * Pruefungen (nur fuer Shop-Admins; andere Rollen sehen nur die Ampel-Farbe).
     *
     * @return array<int, Check>
     */
    public function checks(): array
    {
        $checks = [];
        $system = admin_url('admin.php?page=kontor-shop-settings&tab=system');

        $open = (new SetupChecklist($this->settings))->open_required();
        $checks[] = $open === []
            ? new Check(Check::OK, __('Orderable', 'kontor-shop'), __('Warehouse, products, bank account, and legal texts are set up.', 'kontor-shop'))
            : new Check(Check::BAD, __('Not orderable yet', 'kontor-shop'), sprintf(
                /* translators: %s: open steps */
                __('Still missing: %s.', 'kontor-shop'),
                implode(', ', array_column($open, 'title'))
            ), __('Set up', 'kontor-shop'), $open[0]['url']);

        $pages = [];

        foreach (['shop_page_id' => __('Shop', 'kontor-shop'), 'cart_page_id' => __('Cart', 'kontor-shop'), 'checkout_page_id' => __('Checkout', 'kontor-shop')] as $key => $label) {
            $id = absint($this->settings->get('general.' . $key, 0));

            if ($id <= 0 || get_post_status($id) !== 'publish') {
                $pages[] = $label;
            }
        }

        if ($pages !== []) {
            $checks[] = new Check(Check::BAD, __('Shop pages missing', 'kontor-shop'), sprintf(
                /* translators: %s: pages */
                __('Not published: %s – customers can’t order this way.', 'kontor-shop'),
                implode(', ', $pages)
            ), __('Check pages', 'kontor-shop'), $system);
        }

        $outbox = (new MailOutbox($this->settings))->counts();

        if ($outbox['failed'] > 0) {
            $checks[] = new Check(Check::BAD, __('Emails not delivered', 'kontor-shop'), sprintf(
                /* translators: %d: number of emails */
                _n('%d email could not be sent even after several attempts.', '%d emails could not be sent even after several attempts.', $outbox['failed'], 'kontor-shop'),
                $outbox['failed']
            ), __('Open outbox', 'kontor-shop'), Section::url('mail') . '#ks-outbox');
        } elseif ($outbox['pending'] > 0) {
            $checks[] = new Check(Check::WARN, __('Emails in the outbox', 'kontor-shop'), sprintf(
                /* translators: %d: number of emails */
                _n('%d email is waiting for a new delivery attempt.', '%d emails are waiting for a new delivery attempt.', $outbox['pending'], 'kontor-shop'),
                $outbox['pending']
            ), __('Open outbox', 'kontor-shop'), Section::url('mail') . '#ks-outbox');
        }

        $failure = MailHealth::last_failure();
        $smtp    = MailHealth::smtp_status((string) $this->settings->get('notifications.mailer', MailerFactory::WP_MAILER));

        if ($failure !== null && time() - $failure['time'] < ShopHealth::MAIL_FAILURE_WINDOW) {
            $checks[] = new Check(Check::BAD, __('Email sending disrupted', 'kontor-shop'), sprintf(
                /* translators: 1: time, 2: error */
                __('Last email failed on %1$s (“%2$s”). Customers may not be getting confirmations.', 'kontor-shop'),
                wp_date('d.m. H:i', $failure['time']),
                $failure['message']
            ), __('Check now', 'kontor-shop'), $system);
        } elseif (($smtp['status'] ?? 'ok') !== 'ok') {
            $checks[] = new Check(Check::WARN, __('Email sending uncertain', 'kontor-shop'), (string) ($smtp['message'] ?? ''), (string) ($smtp['action_label'] ?? __('Set up', 'kontor-shop')), (string) ($smtp['action_url'] ?? $system));
        } else {
            $checks[] = new Check(Check::OK, __('Email sending', 'kontor-shop'), (string) ($smtp['message'] ?? __('set up, no known errors', 'kontor-shop')));
        }

        $scheduled = wp_next_scheduled(ReservationCleanup::HOOK);
        $last_run  = (int) get_option(ReservationCleanup::LAST_RUN_OPTION, 0);

        if ($scheduled === false) {
            $checks[] = new Check(Check::BAD, __('Background tasks stalled', 'kontor-shop'), __('The hourly tasks (email retries, cleanup) are not scheduled. Deactivate and reactivate the plugin once.', 'kontor-shop'), __('System check', 'kontor-shop'), $system);
        } elseif ($last_run > 0 && time() - $last_run > ShopHealth::CRON_STALE) {
            $checks[] = new Check(Check::BAD, __('Background tasks stalled', 'kontor-shop'), sprintf(
                /* translators: %s: time span */
                __('Last ran %s ago. Scheduled tasks (email retries, cleanup) aren’t running.', 'kontor-shop'),
                human_time_diff($last_run)
            ) . (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? ' ' . __('WP-Cron is disabled – the server cron must call wp-cron.php.', 'kontor-shop') : ''), __('System check', 'kontor-shop'), $system);
        } else {
            $checks[] = new Check(Check::OK, __('Background tasks', 'kontor-shop'), $last_run > 0
                ? sprintf(
                    /* translators: %s: time span */
                    __('Last ran %s ago.', 'kontor-shop'),
                    human_time_diff($last_run)
                )
                : __('Scheduled, first run still pending.', 'kontor-shop'));
        }

        $critical = Logger::count_since(Logger::CRITICAL, DAY_IN_SECONDS);
        $errors   = Logger::count_since(Logger::ERROR, DAY_IN_SECONDS);
        $log_url  = admin_url('admin.php?page=kontor-shop-log&level=error');

        $checks[] = match (true) {
            $critical > 0 => new Check(Check::BAD, __('Critical errors', 'kontor-shop'), sprintf(
                /* translators: 1: critical, 2: all errors */
                __('%1$d critical of %2$d errors in the last 24 hours.', 'kontor-shop'),
                $critical,
                $errors
            ), __('Log', 'kontor-shop'), $log_url),
            $errors > 0 => new Check(Check::WARN, __('Errors in the log', 'kontor-shop'), sprintf(
                /* translators: 1: critical, 2: all errors */
                _n('%d error in the last 24 hours, none critical.', '%d errors in the last 24 hours, none critical.', $errors, 'kontor-shop'),
                $errors
            ), __('Log', 'kontor-shop'), $log_url),
            default => new Check(Check::OK, __('Error log', 'kontor-shop'), __('No errors in the last 24 hours.', 'kontor-shop')),
        };

        /**
         * Filter kontor_shop/health/checks: weitere Pruefungen der
         * Betriebsampel (Uebersicht, Dashboard-Widget und - mit Pro - die
         * Alarm-Mail), z. B. Pro: "SEO-Anbindung" (Meridian SEO zu alt).
         * Sortiert wird danach (rot, gelb, Hinweis, ok). Standard: die
         * eingebauten Pruefungen.
         *
         * @param array<int, Check> $checks
         * @param Repository        $settings
         */
        $checks = array_values(array_filter(
            (array) apply_filters('kontor_shop/health/checks', $checks, $this->settings),
            static fn ($check): bool => $check instanceof Check
        ));

        return ShopHealth::sorted($checks);
    }

    /* ==================== 2. Heute zu tun ==================== */

    /**
     * @return array<int, array{key: string, count: int, label: string, url: string}>
     */
    public function today(): array
    {
        $count = fn (string $view): int => count(array_filter($this->queues(), static fn (WorkQueue $q): bool => $q->matches($view)));
        $tiles = [];

        if ($this->can_pay) {
            $tiles[] = ['key' => 'pay', 'count' => $count(WorkQueue::VIEW_PAY), 'label' => __('Check payment', 'kontor-shop'), 'url' => OrdersPage::url(['view' => WorkQueue::VIEW_PAY])];
        }

        if ($this->can_ship) {
            $pack    = $this->pack_url(WorkQueue::VIEW_SHIP);
            $tiles[] = ['key' => 'ship', 'count' => $count(WorkQueue::VIEW_SHIP), 'label' => $pack !== '' ? __('Pack', 'kontor-shop') : __('To ship', 'kontor-shop'), 'url' => $pack !== '' ? $pack : OrdersPage::url(['view' => WorkQueue::VIEW_SHIP])];
            $tiles[] = ['key' => 'pickup', 'count' => $count(WorkQueue::VIEW_PICKUP), 'label' => __('Pickup', 'kontor-shop'), 'url' => OrdersPage::url(['view' => WorkQueue::VIEW_PICKUP])];
        }

        if ($this->can_pay) {
            $tiles[] = ['key' => 'refund', 'count' => $count(WorkQueue::VIEW_REFUND), 'label' => __('Refund', 'kontor-shop'), 'url' => OrdersPage::url(['view' => WorkQueue::VIEW_REFUND])];
        }

        if ($this->can_stock) {
            $reorder = count(array_filter($this->stock_rows(), static fn (array $r): bool => $r['signals']->sellable && !$r['product']->discontinued && ($r['signals']->is_out() || $r['signals']->is_low())));
            $tiles[] = ['key' => 'reorder', 'count' => $reorder, 'label' => __('low/empty', 'kontor-shop'), 'url' => StockPage::url(['view' => StockSignals::VIEW_ATTENTION])];
        }

        // Fertig eingepflegt, aber noch versteckt - leicht zu vergessen; nur zeigen, wenn es welche gibt.
        if ($this->can_manage) {
            $ready = count(PublishCandidates::scan()['ready']);

            if ($ready > 0) {
                $tiles[] = ['key' => 'publish', 'count' => $ready, 'label' => __('Ready to go online', 'kontor-shop'), 'url' => ProductsPage::url(['view' => 'ready'])];
            }
        }

        /**
         * Filter kontor_shop/dashboard/today_tiles: Kacheln "Heute zu tun"
         * (Uebersicht; das Dashboard-Widget liest pay/ship/refund/reorder
         * daraus), z. B. Pro: "Nachbestellen" mit Link auf die
         * Nachbestellliste. Standard: die eingebauten Kacheln je Rolle.
         *
         * @param array<int, array{key: string, count: int, label: string, url: string}> $tiles
         * @param ShopSnapshot $snapshot
         */
        $tiles = (array) apply_filters('kontor_shop/dashboard/today_tiles', $tiles, $this);

        return array_values(array_filter($tiles, static fn ($tile): bool => is_array($tile) && isset($tile['key'], $tile['count'], $tile['label'], $tile['url'])));
    }

    /** "Packen" direkt auf die zu versendenden Bestellungen ('' ohne Packansicht). */
    private function pack_url(string $view): string
    {
        $ids = array_map(static fn (WorkQueue $q): int => (int) $q->order->id, array_filter($this->queues(), static fn (WorkQueue $q): bool => $q->matches($view)));

        return $ids === [] ? OrdersPage::pack_url(['pack' => 'all']) : OrdersPage::pack_url(['pack' => 'sel', 'ids' => implode(',', $ids)]);
    }

    /**
     * Zahlen fuer das Widget, je Rolle: wartende Kunden im eigenen Bereich
     * oder null, wenn es keine Wartelisten gibt (ohne Erweiterung).
     */
    public function waiting_customers(): ?int
    {
        if (has_filter('kontor_shop/dashboard/waiting_customers') === false) {
            return null;
        }

        /**
         * Filter kontor_shop/dashboard/waiting_customers: Zahl der Kunden,
         * die auf einen Artikel warten (z. B. offene Verfuegbarkeitsanfragen),
         * fuer das Dashboard-Widget. Ohne Zuhoerer zeigt das Widget statt
         * dessen "knapp/leer". Standard: 0.
         *
         * @param int                  $count
         * @param array<int, int>|null $warehouse_scope Lager des Benutzers (null = alle)
         */
        return max(0, (int) apply_filters('kontor_shop/dashboard/waiting_customers', 0, $this->ship_scope()));
    }
}
