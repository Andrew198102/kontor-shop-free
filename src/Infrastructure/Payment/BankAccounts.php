<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Payment;

use Kontor\Shop\Domain\Payment\BankAccount;
use Kontor\Shop\Settings\Repository;

/**
 * Zugriff auf die in den Plugin-Einstellungen (payment.accounts) gepflegten
 * Bankkonten. Das erste Konto ist das Standardkonto: es gilt fuer Lager ohne
 * eigene Zuordnung und als Rueckfall, falls ein zugeordnetes Konto spaeter
 * geloescht wurde.
 */
final class BankAccounts
{
    public function __construct(private readonly Repository $settings)
    {
    }

    /**
     * @return array<int, BankAccount>
     */
    public function all(): array
    {
        return self::from_list($this->settings->get('payment.accounts', []));
    }

    /**
     * @param mixed $list Rohwert aus den Einstellungen
     * @return array<int, BankAccount>
     */
    public static function from_list(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $accounts = [];

        foreach ($list as $row) {
            if (is_array($row) && !empty($row['id'])) {
                $accounts[] = BankAccount::from_array($row);
            }
        }

        return $accounts;
    }

    public function find(?string $id): ?BankAccount
    {
        if ($id === null || $id === '') {
            return null;
        }

        foreach ($this->all() as $account) {
            if ($account->id === $id) {
                return $account;
            }
        }

        return null;
    }

    public function default(): ?BankAccount
    {
        return $this->all()[0] ?? null;
    }

    /**
     * Konto fuer eine Zuordnung (z. B. Lager): das gewaehlte, sonst das Standardkonto.
     */
    public function resolve(?string $id): ?BankAccount
    {
        return $this->find($id) ?? $this->default();
    }
}
