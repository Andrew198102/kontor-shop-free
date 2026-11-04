<?php

declare (strict_types = 1);
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Freemius: Lizenzen und Updates der Pro-Version, Upgrade-Seite und - nur mit
 * Zustimmung beim Opt-in - anonyme Nutzungsdaten (readme.txt, "External
 * services"). Die Free-Version aus dem WordPress-Verzeichnis baut Freemius
 * beim Hochladen selbst: Es setzt is_premium auf false und entfernt alle
 * Dateien, Ordner und Funktionen mit dem Suffix __premium_only. Pro-Code liegt
 * deshalb nur in src/Pro__premium_only/ (Namensraum Kontor\Shop\Pro) und
 * meldet sich ausschliesslich ueber kontor_shop_load_pro__premium_only() an.
 * Dass Pro nach Ablauf der Lizenz weiterlaeuft (nur Updates/Support enden),
 * wird im Freemius-Dashboard am Plan eingestellt, nicht hier.
 *
 * Deinstallieren laeuft ueber den Freemius-Hook after_uninstall statt ueber
 * eine uninstall.php - nur so bekommt Freemius das Deinstallieren mit.
 */
if ( !function_exists( 'kontor_shop_fs' ) ) {
    function kontor_shop_fs() : Freemius {
        global $kontor_shop_fs;
        if ( !isset( $kontor_shop_fs ) ) {
            require_once KONTOR_SHOP_PATH . 'vendor/freemius/start.php';
            $kontor_shop_fs = fs_dynamic_init( [
                'id'               => '43895',
                'slug'             => 'kontor-shop',
                'premium_slug'     => 'kontor-shop-premium',
                'type'             => 'plugin',
                'public_key'       => 'pk_b44be9b9f80980763ba9d0019dafd',
                'is_premium'       => false,
                'premium_suffix'   => 'Pro',
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'menu'             => [
                    'slug'       => 'kontor-shop',
                    'contact'    => false,
                    'support'    => false,
                    'first-path' => 'admin.php?page=kontor-shop&kontor_shop_welcome=1',
                ],
                'is_live'          => true,
            ] );
        }
        return $kontor_shop_fs;
    }

    kontor_shop_fs();
    kontor_shop_fs()->add_action( 'after_uninstall', [\Kontor\Shop\Core\Uninstaller::class, 'run'] );
    // Symbol auf Upgrade-, Konto- und Opt-in-Seite (sonst zeigt Freemius einen Stecker).
    kontor_shop_fs()->add_filter( 'plugin_icon', static fn(): string => KONTOR_SHOP_PATH . 'assets/admin/plugin-icon.png' );
    do_action( 'kontor_shop_fs_loaded' );
    /*
     * Pro-Angebot (Admin\Upgrade\ProOffer) fuer "Pläne & Preise" und die
     * Free-Seite "KI-Assistent": Pakete, Preise, Geld-zurueck-Frist und ob Pro
     * nach Ablauf abschaltet - live aus Freemius (pricing.json), eine Stunde
     * zwischengespeichert, nur beim Oeffnen dieser Seiten. Gekauft wird im
     * Admin (Freemius-Checkout, auf Deutsch, wenn der Admin Deutsch nutzt);
     * danach aktiviert Freemius die Lizenz und installiert Pro selbst.
     */
    add_filter( 'kontor_shop/pro_offer', static function ( array $offer ) : array {
        $pricing = get_transient( 'kontor_shop_fs_pricing_v2' );
        if ( !is_array( $pricing ) ) {
            try {
                $result = kontor_shop_fs()->get_api_plugin_scope()->get( 'pricing.json?is_enriched=true', true );
                $ok = is_object( $result ) && !isset( $result->error );
                $pricing = [
                    'money_back_period' => ( $ok ? (int) ($result->plugin->money_back_period ?? 0) : 0 ),
                    'plans'             => array_map( static fn( $plan ): array => [
                        'id'                => (int) ($plan->id ?? 0),
                        'is_featured'       => !empty( $plan->is_featured ),
                        'is_block_features' => !empty( $plan->is_block_features ),
                        'pricing'           => array_map( static fn( $price ): array => [
                            'licenses'       => $price->licenses ?? 1,
                            'annual_price'   => $price->annual_price ?? null,
                            'lifetime_price' => $price->lifetime_price ?? null,
                            'currency'       => $price->currency ?? 'eur',
                        ], (array) ($plan->pricing ?? []) ),
                    ], ( $ok ? (array) ($result->plans ?? []) : [] ) ),
                ];
            } catch ( \Throwable $e ) {
                $pricing = [];
            }
            set_transient( 'kontor_shop_fs_pricing_v2', $pricing, HOUR_IN_SECONDS );
        }
        $language = ( str_starts_with( get_user_locale(), 'de' ) ? 'de' : '' );
        $buy = static fn( int $plan_id, int $licenses, string $cycle ): string => kontor_shop_fs()->checkout_url( $cycle, false, array_filter( [
            'plan_id'  => $plan_id,
            'licenses' => $licenses,
            'language' => $language,
        ] ) );
        $offer = \Kontor\Shop\Admin\Upgrade\ProOffer::with_pricing(
            $offer,
            $pricing,
            kontor_shop_fs()->get_upgrade_url(),
            $buy
        );
        $offer['account_url'] = 'https://users.freemius.com/';
        return $offer;
    } );
    // Upgrade-Seite: unsere "Pläne & Preise" (deutsch, eigenes Design) statt der englischen Freemius-Seite.
    kontor_shop_fs()->add_filter( 'templates/pricing.php', static function () : string {
        wp_dequeue_script( 'freemius-pricing' );
        return \Kontor\Shop\Admin\Upgrade\PricingPage::html( \Kontor\Shop\Admin\Upgrade\ProOffer::get() );
    } );
}