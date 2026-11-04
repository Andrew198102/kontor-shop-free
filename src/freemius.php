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
    // Beim Deaktivieren nur unser Dialog (Admin\UninstallChoice: Daten behalten oder loeschen) -
    // nicht zusaetzlich Freemius' Feedback- und Abo-Kuendigen-Fenster.
    kontor_shop_fs()->add_filter( 'show_deactivation_feedback_form', '__return_false' );
    kontor_shop_fs()->add_filter( 'show_deactivation_subscription_cancellation', '__return_false' );
    // Kurzer Name in Freemius-Hinweisen statt "Kontor Shop – Club Shop with Bank Transfer & Pickup".
    kontor_shop_fs()->add_filter( 'plugin_title', static fn(): string => 'Kontor Shop' );
    // Freemius bringt nur freemius-de_DE.mo mit. Seit WP 6.5 gilt eine Uebersetzung nur fuer die
    // Sprache, fuer die sie geladen wird - also fuer de_AT, de_CH & Co. ausdruecklich mit deren Locale laden.
    add_action( 'plugins_loaded', static function () : void {
        $locale = determine_locale();
        if ( is_admin() && str_starts_with( $locale, 'de_' ) && $locale !== 'de_DE' ) {
            load_textdomain( 'freemius', KONTOR_SHOP_PATH . 'vendor/freemius/languages/freemius-de_DE.mo', $locale );
        }
    }, 2 );
    /*
     * Nach Opt-in-Bestaetigung oder Lizenz-Aktivierung: ist die Lizenz aktiv, Pro aber noch nicht
     * installiert, auf die Uebersicht - dort installiert die Karte "Pro wird installiert …" Pro.
     * Nicht auf Pläne & Preise: die blendet Freemius mit gueltiger Lizenz aus ("nicht berechtigt").
     */
    kontor_shop_fs()->add_filter( 'after_connect_url', static function ( string $url ) : string {
        return ( kontor_shop_fs()->can_use_premium_code() && !kontor_shop_fs()->is_premium() ? admin_url( 'admin.php?page=kontor-shop' ) : $url );
    } );
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
    /*
     * Stand von Kauf/Lizenz fuer den Hinweis auf allen Kontor-Seiten (Admin\Upgrade\PricingPage):
     * wartet auf die Bestaetigungs-Mail von Freemius, oder Lizenz aktiv, aber noch Free
     * (dann installiert Freemius Pro auf der Konto-Seite mit auto_install).
     */
    add_filter( 'kontor_shop/pro_status', static function ( array $status ) : array {
        $fs = kontor_shop_fs();
        $install = $fs->is_registered() && $fs->can_use_premium_code() && !$fs->is_premium();
        $license = ( $fs->is_premium() && $fs->is_registered() ? $fs->_get_license() : null );
        return [
            'pending_email' => ( $fs->is_pending_activation() ? (string) wp_get_current_user()->user_email : '' ),
            'install_url'   => ( $install ? $fs->get_account_url( false, [
                'auto_install' => 'true',
            ] ) : '' ),
            'install_ajax'  => ( $install ? [
                'action' => 'kontor_shop_pro_install',
                'nonce'  => wp_create_nonce( 'kontor_shop_pro_install' ),
            ] : [] ),
            'licence'       => ( is_object( $license ) ? [
                'plan'        => (string) $fs->get_plan_title(),
                'sites'       => (string) ($license->quota ?? ''),
                'expires'     => (string) ($license->expiration ?? ''),
                'account_url' => $fs->get_account_url(),
            ] : [] ),
        ];
    } );
    // Unsere Karten (Admin\Upgrade\ProStatus) ersetzen die Freemius-Hinweise zu Download-Anleitung und
    // "W00t! Premium ... activated" ueberall, den zur Bestaetigungs-Mail auf Kontor-Seiten.
    kontor_shop_fs()->add_filter(
        'show_admin_notice',
        static function ( $show, $msg ) {
            $id = (string) ($msg['id'] ?? '');
            // "Lade Pro herunter und lade es hoch" und "W00t!": nirgends - Pro installiert sich selbst, Willkommen kommt von uns.
            if ( in_array( $id, ['plan_upgraded', 'premium_activated'], true ) ) {
                return false;
            }
            $screen = ( function_exists( 'get_current_screen' ) ? get_current_screen() : null );
            return ( is_object( $screen ) && str_contains( (string) $screen->id, 'kontor-shop' ) && $id === 'activation_pending' ? false : $show );
        },
        10,
        2
    );
    /*
     * Nach dem Upgrade nur noch EIN Plugin: laeuft Pro (Ordner kontor-shop-premium) mit gueltiger
     * Lizenz, die abgeschaltete Free-Kopie (Ordner kontor-shop) still entfernen - nur die Dateien,
     * ohne Deinstallation (die wuerde je nach Einstellung Shop-Daten loeschen). Laeuft die Lizenz
     * spaeter aus, arbeitet Pro als normaler Shop weiter; Free wird nicht mehr gebraucht. Ein
     * Test-Build oder die Entwicklungs-Kopie laufen selbst aus kontor-shop und sind nie betroffen.
     */
    add_action( 'admin_init', static function () : void {
        $fs = kontor_shop_fs();
        $free = 'kontor-shop/kontor-shop.php';
        if ( !$fs->is_premium() || dirname( KONTOR_SHOP_BASENAME ) === 'kontor-shop' || !$fs->can_use_premium_code() || !current_user_can( 'delete_plugins' ) || !is_readable( WP_PLUGIN_DIR . '/' . $free ) ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $header = get_file_data( WP_PLUGIN_DIR . '/' . $free, [
            'domain' => 'Text Domain',
        ] );
        if ( ($header['domain'] ?? '') !== 'kontor-shop' || is_plugin_active( $free ) || !WP_Filesystem() ) {
            return;
        }
        global $wp_filesystem;
        $wp_filesystem->delete( WP_PLUGIN_DIR . '/kontor-shop', true );
        wp_clean_plugins_cache( false );
    } );
    // Automatische Updates fuer Pro von Anfang an an (einmal - schaltet man sie spaeter aus, bleibt das so).
    add_action( 'admin_init', static function () : void {
        if ( !kontor_shop_fs()->is_premium() || get_option( 'kontor_shop_auto_update_set' ) === KONTOR_SHOP_BASENAME ) {
            return;
        }
        $auto = (array) get_site_option( 'auto_update_plugins', [] );
        if ( !in_array( KONTOR_SHOP_BASENAME, $auto, true ) ) {
            $auto[] = KONTOR_SHOP_BASENAME;
            update_site_option( 'auto_update_plugins', array_values( array_unique( $auto ) ) );
        }
        update_option( 'kontor_shop_auto_update_set', KONTOR_SHOP_BASENAME, false );
    } );
    // Pro wurde gerade aktiviert: einmal "Willkommen bei Kontor Shop Pro" zeigen (ProStatus).
    kontor_shop_fs()->add_action( 'after_premium_version_activation', static function () : void {
        update_option( \Kontor\Shop\Admin\Upgrade\ProStatus::WELCOME, time(), false );
    } );
    /*
     * Pro installieren und aktivieren (Download von Freemius). Eine Sperre verhindert, dass zwei
     * Aufrufe gleichzeitig in denselben Ordner kopieren; ein Pro-Ordner von einem abgebrochenen
     * Versuch wird vorher entfernt (sonst bricht WordPress mit "Ordner existiert" ab).
     * Rueckgabe: null = erledigt (oder nichts zu tun), 'running' = laeuft schon, sonst Fehlertext.
     */
    function kontor_shop_install_pro() : ?string {
        $fs = kontor_shop_fs();
        if ( $fs->is_premium() || !$fs->can_use_premium_code() ) {
            return null;
        }
        if ( get_transient( 'kontor_shop_pro_installing' ) ) {
            return 'running';
        }
        set_transient( 'kontor_shop_pro_installing', 1, 5 * MINUTE_IN_SECONDS );
        try {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $folder = WP_PLUGIN_DIR . '/' . dirname( $fs->premium_plugin_basename() );
            if ( is_dir( $folder ) && !is_plugin_active( $fs->premium_plugin_basename() ) && WP_Filesystem() ) {
                global $wp_filesystem;
                $wp_filesystem->delete( $folder, true );
            }
            $result = FS_Plugin_Updater::instance( $fs )->install_and_activate_plugin();
        } catch ( \Throwable $e ) {
            $result = [
                'message' => $e->getMessage(),
            ];
        } finally {
            delete_transient( 'kontor_shop_pro_installing' );
        }
        if ( is_array( $result ) && !empty( $result['message'] ) ) {
            $error = wp_strip_all_tags( (string) $result['message'] );
            set_transient( 'kontor_shop_pro_install_failed', $error, HOUR_IN_SECONDS );
            return $error;
        }
        delete_transient( 'kontor_shop_pro_install_failed' );
        return null;
    }

    // Karte "Pro wird installiert …" (pro-status.js): Installation per AJAX, Ergebnis als JSON.
    add_action( 'wp_ajax_kontor_shop_pro_install', static function () : void {
        check_ajax_referer( 'kontor_shop_pro_install', 'nonce' );
        if ( !current_user_can( 'install_plugins' ) || !current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( [
                'code'    => 'forbidden',
                'message' => __( 'No permission.', 'kontor-shop' ),
            ], 403 );
        }
        $error = kontor_shop_install_pro();
        if ( $error === 'running' ) {
            wp_send_json_error( [
                'code'    => 'install_running',
                'message' => '',
            ] );
        }
        ( $error === null ? wp_send_json_success( [
            'state' => 'done',
        ] ) : wp_send_json_error( [
            'code'    => 'failed',
            'message' => $error,
        ] ) );
    } );
    /*
     * Lizenz aktiv, aber noch Free: beim naechsten Admin-Seitenaufruf - egal welche Seite - Pro
     * selbst installieren und zur Kontor-Uebersicht ("Willkommen bei Pro") wechseln. Ist der
     * letzte Versuch gescheitert, nicht bei jedem Aufruf erneut (die Karte bietet "Nochmal versuchen").
     */
    add_action( 'admin_init', static function () : void {
        $fs = kontor_shop_fs();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Lesen: Freemius-Aktion laeuft gerade
        if ( wp_doing_ajax() || wp_doing_cron() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || isset( $_GET['fs_action'] ) || !current_user_can( 'install_plugins' ) || !current_user_can( 'activate_plugins' ) || $fs->is_premium() || !$fs->is_registered() || !$fs->can_use_premium_code() || get_transient( 'kontor_shop_pro_install_failed' ) ) {
            return;
        }
        if ( kontor_shop_install_pro() === null ) {
            wp_safe_redirect( admin_url( 'admin.php?page=kontor-shop' ) );
            exit;
        }
    }, 99 );
    /*
     * "Pro schon gekauft?" auf Pläne & Preise: Lizenzschluessel in der Free-Version
     * aktivieren (Freemius bietet das Feld erst in Pro an). Danach installiert
     * Freemius Pro auf der Konto-Seite selbst (auto_install). Fehler gehen fuer
     * fuenf Minuten in einen Transient und zurueck auf die Seite.
     */
    add_action( 'admin_post_' . \Kontor\Shop\Admin\Upgrade\PricingPage::ACTIVATE, static function () : void {
        check_admin_referer( \Kontor\Shop\Admin\Upgrade\PricingPage::ACTIVATE );
        if ( !current_user_can( 'install_plugins' ) ) {
            wp_die( esc_html__( 'No permission.', 'kontor-shop' ) );
        }
        $key = sanitize_text_field( wp_unslash( (string) ($_POST['license_key'] ?? '') ) );
        $back = \Kontor\Shop\Admin\Upgrade\PricingPage::url( [
            \Kontor\Shop\Admin\Upgrade\PricingPage::LICENCE_RESULT => 1,
        ] ) . '#ks-pp-bought';
        $error = '';
        if ( $key !== '' ) {
            try {
                $result = kontor_shop_fs()->activate_migrated_license( $key );
                $error = ( !empty( $result['success'] ) ? null : (string) ($result['error'] ?? __( 'Unknown error.', 'kontor-shop' )) );
            } catch ( \Throwable $e ) {
                $error = $e->getMessage();
            }
        }
        // Angenommen, aber weder verbunden noch wartend auf die Bestaetigungs-Mail und ohne Pro-Recht:
        // dann klar sagen, statt ins Leere zu fuehren (z. B. Testlizenz aus dem Sandkasten auf einer echten Seite).
        if ( $error === null && !kontor_shop_fs()->can_use_premium_code() && !kontor_shop_fs()->is_pending_activation() ) {
            $error = __( 'The key was accepted, but it does not unlock Pro on this site. Is it a test licence from the sandbox, or already used on another website?', 'kontor-shop' );
        }
        // Die Uebersicht zeigt den Stand: E-Mail bestaetigen oder Pro wird im Hintergrund installiert.
        // (Pläne & Preise blendet Freemius mit gueltiger Lizenz aus.)
        if ( $error === null ) {
            wp_safe_redirect( admin_url( 'admin.php?page=kontor-shop' ) );
            exit;
        }
        set_transient( \Kontor\Shop\Admin\Upgrade\PricingPage::LICENCE_RESULT . '_' . get_current_user_id(), $error, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( $back );
        exit;
    } );
    // Upgrade-Seite: unsere "Pläne & Preise" (deutsch, eigenes Design) statt der englischen Freemius-Seite.
    kontor_shop_fs()->add_filter( 'templates/pricing.php', static function () : string {
        wp_dequeue_script( 'freemius-pricing' );
        return \Kontor\Shop\Admin\Upgrade\PricingPage::html( \Kontor\Shop\Admin\Upgrade\ProOffer::get() );
    } );
}