<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Core\Capabilities;

/**
 * Kassiere festlegen. Weitere Rollen-Spalten (z. B. Pro: "Lagerverwalter
 * fuer", die beim Lager vergeben werden) kommen ueber
 * kontor_shop/settings/roles/columns.
 */
final class RolesSection extends Section
{
    public function key(): string
    {
        return 'roles';
    }

    public function label(): string
    {
        return __('Roles', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_TECH;
    }

    public function save(string $tab): ?string
    {
        return match ($tab) {
            'roles' => $this->save_roles(),
            default => null,
        };
    }

    public function render(): void
    {
        $this->render_roles_tab();
    }

    private function save_roles(): ?string
    {
        $selected = array_map('absint', (array) ($_POST['cashiers'] ?? [])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce geprueft in SettingsPage::handle_request()

        foreach (get_users(['orderby' => 'display_name', 'number' => 500, 'fields' => ['ID']]) as $user) {
            $user_id = (int) $user->ID;

            if (user_can($user_id, 'manage_options')) {
                continue; // Administratoren haben ohnehin alle Rechte
            }

            if (in_array($user_id, $selected, true)) {
                Capabilities::grant($user_id, Capabilities::CASHIER);
            } else {
                Capabilities::revoke($user_id, Capabilities::CASHIER);
            }
        }

        return __('Roles saved.', 'kontor-shop');
    }

    private function render_roles_tab(): void
    {
        $users   = get_users(['orderby' => 'display_name', 'number' => 500]);
        $columns = self::columns();

        $this->intro(__(
            'Cashiers can set the payment status (paid, cancel) on all orders, but can’t manage stock. Administrators automatically have all permissions.',
            'kontor-shop'
        ), 'setup-roles');

        foreach ($columns as $column) {
            if (($column['hint'] ?? '') !== '') {
                $this->intro((string) $column['hint']);
            }
        }

        $this->form_open('roles');

        echo '<input type="search" class="ks-user-filter regular-text" data-ks-filter=".ks-users" placeholder="' . esc_attr__('Search users …', 'kontor-shop') . '" aria-label="' . esc_attr__('Search users', 'kontor-shop') . '" />';
        echo '<table class="widefat striped ks-users ks-settings-table"><thead><tr>';
        echo '<th>' . esc_html__('User', 'kontor-shop') . '</th>';
        echo '<th>' . esc_html__('WordPress role', 'kontor-shop') . '</th>';
        echo '<th class="ks-col-check">' . esc_html__('Cashier', 'kontor-shop') . '</th>';

        foreach ($columns as $column) {
            echo '<th>' . esc_html($column['label']) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($users as $user) {
            $is_admin = user_can($user, 'manage_options');

            echo '<tr>';
            echo '<td>' . esc_html($user->display_name) . ' <span class="ks-muted">(' . esc_html($user->user_email) . ')</span></td>';
            echo '<td>' . esc_html(implode(', ', array_map(static fn (string $role): string => translate_user_role(wp_roles()->roles[$role]['name'] ?? $role), $user->roles))) . '</td>';

            if ($is_admin) {
                echo '<td><span class="ks-muted" title="' . esc_attr__('Administrators automatically have all permissions.', 'kontor-shop') . '">' . esc_html__('automatic', 'kontor-shop') . '</span></td>';
            } else {
                echo '<td><input type="checkbox" name="cashiers[]" value="' . esc_attr((string) $user->ID) . '" '
                    . checked(user_can($user, Capabilities::CASHIER), true, false) . ' aria-label="' . esc_attr(sprintf(
                        /* translators: %s: user display name */
                        __('%s is a cashier', 'kontor-shop'),
                        $user->display_name
                    )) . '" /></td>';
            }

            foreach ($columns as $column) {
                echo '<td>' . wp_kses_post((string) ($column['cell'])($user)) . '</td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';

        $this->savebar();
        echo '</form>';
    }

    /**
     * Weitere Spalten der Rollen-Tabelle (nur zur Information).
     *
     * @return array<int, array{label: string, hint?: string, cell: callable}>
     */
    private static function columns(): array
    {
        /**
         * Filter kontor_shop/settings/roles/columns: weitere Spalten der
         * Rollen-Tabelle, z. B. Pro: "Lagerverwalter fuer". Standard: keine.
         *
         * @param array<int, array{label: string, hint?: string, cell: callable(\WP_User): string}> $columns
         *        hint: Erklaersatz ueber der Tabelle; cell liefert HTML (wp_kses_post)
         */
        $columns = (array) apply_filters('kontor_shop/settings/roles/columns', []);

        return array_values(array_filter($columns, static fn ($column): bool => is_array($column) && isset($column['label'], $column['cell']) && is_callable($column['cell'])));
    }
}
