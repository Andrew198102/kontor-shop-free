<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin;

use Kontor\Shop\Infrastructure\Payment\BankAccounts;
use Kontor\Shop\Infrastructure\Repositories\CategoryRepository;
use Kontor\Shop\Infrastructure\Repositories\ProductRepository;
use Kontor\Shop\Infrastructure\Repositories\WarehouseRepository;
use Kontor\Shop\Settings\Repository;

/**
 * Einrichtungsschritte, bis der Shop bestellbereit ist. Gemeinsame Quelle fuer
 * die Checkliste der Uebersichtsseite, das WP-Dashboard-Widget und den Hinweis
 * im Backend.
 */
final class SetupChecklist
{
    public function __construct(private readonly Repository $settings)
    {
    }

    /**
     * @return array<int, array{title: string, hint: string, done: bool, url: string, required: bool}>
     */
    public function steps(): array
    {
        $settings_url = static fn (string $tab): string => admin_url('admin.php?page=kontor-shop-settings&tab=' . $tab);

        $has_bank = false;

        foreach ((new BankAccounts($this->settings))->all() as $account) {
            $has_bank = $has_bank || $account->iban !== '';
        }

        $legal_ok = (int) $this->settings->get('legal.terms_page_id', 0) > 0 && (int) $this->settings->get('legal.privacy_page_id', 0) > 0;

        return [
            $this->step(__('Create warehouse', 'kontor-shop'), __('Every product belongs to a warehouse.', 'kontor-shop'), (new WarehouseRepository())->all() !== [], WarehousesPage::url(['do' => 'new']), true),
            $this->step(__('Add category', 'kontor-shop'), __('Categories are the filters in the shop.', 'kontor-shop'), (new CategoryRepository())->all() !== [], CategoriesPage::url(['do' => 'new']), true),
            $this->step(__('Add your first product', 'kontor-shop'), __('With price, image, and initial stock.', 'kontor-shop'), (new ProductRepository())->counts()['active'] > 0, ProductsPage::url(['do' => 'new']), true),
            $this->step(__('Add a bank account for prepayment', 'kontor-shop'), __('Otherwise customers won’t know where to transfer the money.', 'kontor-shop'), $has_bank, $settings_url('payment'), true),
            $this->step(__('Link legal pages (terms and conditions, privacy policy)', 'kontor-shop'), __('They are linked automatically at checkout.', 'kontor-shop'), $legal_ok, $settings_url('legal'), true),
            $this->step(__('Check VAT', 'kontor-shop'), __('Without tax rates, the shop is treated as exempt from sales tax.', 'kontor-shop'), (array) $this->settings->get('tax.rates', []) !== [], $settings_url('payment'), false),
            $this->step(__('Send test email (system check)', 'kontor-shop'), __('Checks whether order emails go out.', 'kontor-shop'), false, $settings_url('system'), false),
        ];
    }

    /**
     * Noch offene Pflichtschritte.
     *
     * @return array<int, array{title: string, hint: string, done: bool, url: string, required: bool}>
     */
    public function open_required(): array
    {
        return array_values(array_filter($this->steps(), static fn (array $step): bool => $step['required'] && !$step['done']));
    }

    /**
     * @return array{title: string, hint: string, done: bool, url: string, required: bool}
     */
    private function step(string $title, string $hint, bool $done, string $url, bool $required): array
    {
        return ['title' => $title, 'hint' => $hint, 'done' => $done, 'url' => $url, 'required' => $required];
    }
}
