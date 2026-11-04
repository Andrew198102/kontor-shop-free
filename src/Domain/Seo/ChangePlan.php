<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

use Kontor\Shop\Domain\Catalog\Product;

/**
 * Was passiert, wenn ein Artikel geaendert wird: welche Kategorien danach
 * gelten und ob das fuer Suchmaschinen sichtbar ist (-> content_updated_at
 * steigt, der Meridian-Cache wird geleert). Reine Logik; das Schreiben
 * uebernimmt ProductRepository::commit().
 */
final class ChangePlan
{
    /**
     * @param array<int, int> $categories Kategorien nach der Aenderung
     */
    private function __construct(
        public readonly array $categories,
        public readonly bool $public_change,
    ) {
    }

    /**
     * @param array<string, mixed> $columns   Spalte => neuer Wert
     * @param array<int, int>      $old_categories
     * @param array<int, int>|null $category_ids null = Zuordnung nur bei geaenderter Hauptkategorie anpassen
     */
    public static function for(Product $old, array $old_categories, array $columns, ?array $category_ids = null): self
    {
        $old_row = get_object_vars($old);
        $new_row = array_merge($old_row, $columns);
        $new     = $category_ids;

        if ($new === null && array_key_exists('category_id', $columns)) {
            $new = array_diff($old_categories, [(int) ($old->category_id ?? 0)]);

            if ((int) ($columns['category_id'] ?? 0) > 0) {
                $new[] = (int) $columns['category_id'];
            }
        }

        $new = array_values(array_unique(array_map('intval', $new ?? $old_categories)));

        $was_public = $old->is_sellable();
        $is_public  = !empty($new_row['active']) && empty($new_row['archived']);

        // Aenderungen an unsichtbaren Artikeln sieht niemand - erst die Aktivierung zaehlt.
        $changed = ($was_public || $is_public)
            && ContentChange::differs(ContentChange::snapshot($old_row, $old_categories), ContentChange::snapshot($new_row, $new));

        return new self($new, $changed);
    }
}
