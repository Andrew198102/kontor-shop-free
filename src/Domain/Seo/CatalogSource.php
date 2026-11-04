<?php

declare(strict_types=1);

namespace Kontor\Shop\Domain\Seo;

/**
 * Lesezugriff der Adress-Auswertung auf den Katalog. Jede Methode wirft
 * SourceUnavailable, wenn die Datenquelle nicht antwortet - "nicht erreichbar"
 * darf nie wie "gibt es nicht" aussehen (sonst 410 statt 503).
 */
interface CatalogSource
{
    /**
     * Der Artikel, wenn er oeffentlich sichtbar ist (aktiv, nicht archiviert,
     * Kategorie sichtbar) - auch ausverkauft.
     *
     * @throws SourceUnavailable
     */
    public function find_public(int $id): ?PublicProduct;

    /**
     * Gab es diese ID je (Zeile vorhanden, aber nicht oeffentlich, oder
     * geloescht mit Eintrag in product_tombstones)?
     *
     * @throws SourceUnavailable
     */
    public function existed(int $id): bool;
}
