<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\HelpCenter;

use Kontor\Shop\Admin\HelpCenter\Content\DailyArticles;
use Kontor\Shop\Admin\HelpCenter\Content\ProArticles;
use Kontor\Shop\Admin\HelpCenter\Content\ProblemArticles;
use Kontor\Shop\Admin\HelpCenter\Content\SetupArticles;
use Kontor\Shop\Admin\HelpCenter\Content\ShopArticles;
use Kontor\Shop\Admin\HelpCenter\Content\StartArticles;
use Kontor\Shop\Admin\SetupChecklist;
use Kontor\Shop\Core\Edition;
use Kontor\Shop\Settings\Repository;

/**
 * Hilfe-Center: eigener Menuepunkt "Hilfe" mit Anleitungen fuer Laien.
 * Jeder Artikel hat dieselben Teile - Wofuer? / So geht's / Was passiert
 * dann? / Haeufige Fragen - plus optional laengere Abschnitte (z. B. die
 * CSS-Anleitung fuer Webmaster). Pro-Artikel stehen auch in Free (nur Text,
 * kein Pro-Code) und zeigen dort, was man dafuer braucht.
 *
 * Inhalte kommen aus Content\*Articles (uebersetzbar, englische Quelltexte)
 * und dem Filter kontor_shop/admin/help_articles. Die Kontexthilfe oben
 * rechts (Admin\Help) verlinkt per "help_pages" auf passende Artikel.
 *
 * Artikel-Schema (alle Texte sind Klartext, werden escaped):
 *   id, group (start|daily|setup|shop|pro|problems), title, summary,
 *   pro (bool), keywords (Suchbegriffe), what (Satz "Wofuer?"),
 *   steps: Liste von string oder {text, url?, link?},
 *   effect: Liste von string, faq: Liste von {q, a},
 *   sections: Liste von {title, paragraphs?: string[], list?: string[],
 *             code?: string, table?: {head: string[], rows: string[][]}},
 *   related: Liste von Artikel-IDs, help_pages: Hilfe-Schluessel (Admin\Help)
 */
final class HelpCenter
{
    public const SLUG = 'kontor-shop-help';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $articles = null;

    public function __construct(private readonly Repository $settings)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public static function url(string $article = '', array $args = []): string
    {
        $query = ['page' => self::SLUG] + ($article !== '' ? ['article' => $article] : []) + $args;

        return add_query_arg(array_map('rawurlencode', $query), admin_url('admin.php'));
    }

    /**
     * Kleines "?" neben einem Feld, das den passenden Artikel oeffnet.
     */
    public static function link(string $article, string $label = ''): string
    {
        $label = $label !== '' ? $label : __('Help on this', 'kontor-shop');

        return '<a class="ks-help-q" href="' . esc_url(self::url($article)) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '">?</a>';
    }

    /**
     * @return array<string, string> Gruppe => Ueberschrift, in Anzeigereihenfolge
     */
    public static function groups(): array
    {
        return [
            'start'    => __('Getting started', 'kontor-shop'),
            'daily'    => __('Daily work', 'kontor-shop'),
            'setup'    => __('Setting up', 'kontor-shop'),
            'shop'     => __('Shop appearance', 'kontor-shop'),
            'pro'      => __('Pro features', 'kontor-shop'),
            'problems' => __('Problems & questions', 'kontor-shop'),
        ];
    }

    /**
     * Alle Artikel, nach ID.
     *
     * @return array<string, array<string, mixed>>
     */
    public function articles(): array
    {
        if ($this->articles !== null) {
            return $this->articles;
        }

        $list = array_merge(
            StartArticles::articles(),
            DailyArticles::articles(),
            SetupArticles::articles(),
            ShopArticles::articles(),
            ProArticles::articles(),
            ProblemArticles::articles()
        );

        /**
         * Filter kontor_shop/admin/help_articles: Artikel des Hilfe-Centers
         * ergaenzen oder aendern (Schema siehe HelpCenter). Erweiterungen
         * haengen eigene Artikel an, z. B. mit group "setup".
         *
         * @param array<int, array<string, mixed>> $list
         */
        $list = (array) apply_filters('kontor_shop/admin/help_articles', $list);

        $this->articles = [];

        foreach ($list as $article) {
            if (is_array($article) && isset($article['id'], $article['title']) && is_string($article['id'])) {
                $this->articles[sanitize_key($article['id'])] = $article + ['group' => 'problems', 'summary' => '', 'pro' => false, 'keywords' => ''];
            }
        }

        return $this->articles;
    }

    /**
     * Artikel-ID fuer einen Hilfe-Schluessel der Kontexthilfe (erster Treffer).
     */
    public function article_for_page(string $page): ?string
    {
        foreach ($this->articles() as $id => $article) {
            if (in_array($page, (array) ($article['help_pages'] ?? []), true)) {
                return $id;
            }
        }

        return null;
    }

    public function enqueue(): void
    {
        wp_enqueue_style('kontor-shop-help', KONTOR_SHOP_URL . 'assets/admin/help.css', [], KONTOR_SHOP_VERSION);
        wp_enqueue_script('kontor-shop-help', KONTOR_SHOP_URL . 'assets/admin/help.js', [], KONTOR_SHOP_VERSION, true);
    }

    public function render(): void
    {
        $id      = isset($_GET['article']) ? sanitize_key(wp_unslash((string) $_GET['article'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
        $query   = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige-Parameter
        $article = $id !== '' ? ($this->articles()[$id] ?? null) : null;

        echo '<div class="wrap ks-helpcenter">';

        if ($article !== null) {
            $this->render_article($id, $article);
        } else {
            $this->render_index($query);
        }

        echo '</div>';
    }

    private function search_text(array $article): string
    {
        $parts = [$article['title'] ?? '', $article['summary'] ?? '', $article['keywords'] ?? '', $article['what'] ?? ''];

        foreach ((array) ($article['steps'] ?? []) as $step) {
            $parts[] = is_array($step) ? (string) ($step['text'] ?? '') : (string) $step;
        }

        foreach ((array) ($article['effect'] ?? []) as $line) {
            $parts[] = (string) $line;
        }

        foreach ((array) ($article['faq'] ?? []) as $faq) {
            $parts[] = (string) ($faq['q'] ?? '') . ' ' . (string) ($faq['a'] ?? '');
        }

        foreach ((array) ($article['sections'] ?? []) as $section) {
            $parts[] = (string) ($section['title'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($section['paragraphs'] ?? []))) . ' ' . implode(' ', array_map('strval', (array) ($section['list'] ?? [])));
        }

        $text = implode(' ', array_map('strval', $parts));

        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }

    private function render_index(string $query): void
    {
        echo '<h1 class="wp-heading-inline">' . esc_html__('Help for Kontor Shop', 'kontor-shop') . '</h1><hr class="wp-header-end" />';
        echo '<p class="ks-help__lead">' . esc_html__('Step-by-step guides for everything you do in the shop – what a setting is for and what happens when you use it. Pro features are marked; you can read them without Pro.', 'kontor-shop') . '</p>';

        echo '<form class="ks-help__search" method="get" action="' . esc_url(admin_url('admin.php')) . '" role="search">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';
        echo '<label class="screen-reader-text" for="ks-help-q">' . esc_html__('Search help', 'kontor-shop') . '</label>';
        echo '<input type="search" id="ks-help-q" name="q" value="' . esc_attr($query) . '" placeholder="' . esc_attr__('What are you looking for? e.g. “cash payment”, “sizes”, “email”', 'kontor-shop') . '" data-ks-help-search autocomplete="off" />';
        echo '<button type="submit" class="button">' . esc_html__('Search', 'kontor-shop') . '</button>';
        echo '</form>';

        $needle = function_exists('mb_strtolower') ? mb_strtolower($query) : strtolower($query);
        $groups = self::groups();
        $by     = [];

        foreach ($this->articles() as $id => $article) {
            $text = $this->search_text($article);

            foreach ($needle !== '' ? preg_split('/\s+/', trim($needle)) ?: [] : [] as $word) {
                if ($word !== '' && !preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($word, '/') . '/u', $text)) {
                    continue 2;
                }
            }

            $by[(string) $article['group']][$id] = ['article' => $article, 'text' => $text];
        }

        echo '<p class="ks-help__none" data-ks-help-none' . ($by === [] ? '' : ' hidden') . '>' . esc_html__('Nothing found. Try a different word, or browse the topics.', 'kontor-shop') . '</p>';

        foreach ($groups + array_fill_keys(array_keys(array_diff_key($by, $groups)), __('More', 'kontor-shop')) as $group => $label) {
            if (empty($by[$group])) {
                continue;
            }

            echo '<section class="ks-help__group" data-ks-help-group><h2>' . esc_html($label) . '</h2><div class="ks-help__tiles">';

            foreach ($by[$group] as $id => $entry) {
                $article = $entry['article'];

                echo '<a class="ks-help__tile" href="' . esc_url(self::url($id)) . '" data-ks-help-text="' . esc_attr($entry['text']) . '">'
                    . '<strong>' . esc_html((string) $article['title']) . (!empty($article['pro']) ? ' <span class="ks-help__pro">PRO</span>' : '') . '</strong>'
                    . ($article['summary'] !== '' ? '<span>' . esc_html((string) $article['summary']) . '</span>' : '')
                    . '</a>';
            }

            echo '</div></section>';
        }
    }

    /**
     * @param array<string, mixed> $article
     */
    private function render_article(string $id, array $article): void
    {
        $groups = self::groups();

        echo '<p class="ks-help__crumbs"><a href="' . esc_url(self::url()) . '">' . esc_html__('Help', 'kontor-shop') . '</a> › '
            . esc_html($groups[(string) $article['group']] ?? '') . '</p>';
        echo '<h1 class="wp-heading-inline">' . esc_html((string) $article['title'])
            . (!empty($article['pro']) ? ' <span class="ks-help__pro">PRO</span>' : '') . '</h1><hr class="wp-header-end" />';

        echo '<article class="ks-help__article">';

        if (!empty($article['what'])) {
            echo '<p class="ks-help__what"><strong>' . esc_html__('What is it for?', 'kontor-shop') . '</strong> ' . esc_html((string) $article['what']) . '</p>';
        }

        if (!empty($article['pro'])) {
            if (Edition::is_pro()) {
                echo '<div class="ks-help__box ks-help__box--pro"><p>' . esc_html__('Included in your Kontor Shop Pro license.', 'kontor-shop') . '</p></div>';
            } else {
                echo '<div class="ks-help__box ks-help__box--pro"><p>' . esc_html__('This needs Kontor Shop Pro. You can read the guide anyway, so you know what is possible.', 'kontor-shop')
                    . ' <a href="' . esc_url(admin_url('admin.php?page=kontor-shop-pricing')) . '">' . esc_html__('Plans & pricing', 'kontor-shop') . '</a></p></div>';
            }
        }

        if ($id === 'first-steps') {
            $this->render_checklist();
        }

        if (!empty($article['steps'])) {
            echo '<h2>' . esc_html__('How to do it', 'kontor-shop') . '</h2><ol class="ks-help__steps">';

            foreach ((array) $article['steps'] as $step) {
                $text = is_array($step) ? (string) ($step['text'] ?? '') : (string) $step;
                $url  = is_array($step) ? (string) ($step['url'] ?? '') : '';

                echo '<li>' . esc_html($text)
                    . ($url !== '' ? ' <a class="ks-help__open" href="' . esc_url($url) . '">' . esc_html(is_array($step) && !empty($step['link']) ? (string) $step['link'] : __('Open', 'kontor-shop')) . ' →</a>' : '')
                    . '</li>';
            }

            echo '</ol>';
        }

        if (!empty($article['effect'])) {
            echo '<h2>' . esc_html__('What happens then?', 'kontor-shop') . '</h2><div class="ks-help__box ks-help__box--effect"><ul>';

            foreach ((array) $article['effect'] as $line) {
                echo '<li>' . esc_html((string) $line) . '</li>';
            }

            echo '</ul></div>';
        }

        foreach ((array) ($article['sections'] ?? []) as $section) {
            $this->render_section((array) $section);
        }

        if (!empty($article['faq'])) {
            echo '<h2>' . esc_html__('Frequently asked questions', 'kontor-shop') . '</h2><div class="ks-help__faq">';

            foreach ((array) $article['faq'] as $faq) {
                echo '<details><summary>' . esc_html((string) ($faq['q'] ?? '')) . '</summary><p>' . esc_html((string) ($faq['a'] ?? '')) . '</p></details>';
            }

            echo '</div>';
        }

        $related = array_values(array_filter((array) ($article['related'] ?? []), fn ($rel): bool => is_string($rel) && isset($this->articles()[$rel])));

        if ($related !== []) {
            echo '<h2>' . esc_html__('Related', 'kontor-shop') . '</h2><ul class="ks-help__related">';

            foreach ($related as $rel) {
                echo '<li><a href="' . esc_url(self::url($rel)) . '">' . esc_html((string) $this->articles()[$rel]['title']) . '</a></li>';
            }

            echo '</ul>';
        }

        echo '</article>';
        echo '<p><a href="' . esc_url(self::url()) . '">← ' . esc_html__('All help topics', 'kontor-shop') . '</a></p>';
    }

    /**
     * @param array<string, mixed> $section
     */
    private function render_section(array $section): void
    {
        if (!empty($section['title'])) {
            echo '<h2>' . esc_html((string) $section['title']) . '</h2>';
        }

        foreach ((array) ($section['paragraphs'] ?? []) as $paragraph) {
            echo '<p>' . esc_html((string) $paragraph) . '</p>';
        }

        if (!empty($section['list'])) {
            echo '<ul class="ks-help__list">';

            foreach ((array) $section['list'] as $item) {
                echo '<li>' . esc_html((string) $item) . '</li>';
            }

            echo '</ul>';
        }

        if (!empty($section['table']) && is_array($section['table'])) {
            echo '<div class="ks-help__table"><table class="widefat striped"><thead><tr>';

            foreach ((array) ($section['table']['head'] ?? []) as $cell) {
                echo '<th>' . esc_html((string) $cell) . '</th>';
            }

            echo '</tr></thead><tbody>';

            foreach ((array) ($section['table']['rows'] ?? []) as $row) {
                echo '<tr>';

                foreach ((array) $row as $index => $cell) {
                    echo $index === 0 ? '<td><code>' . esc_html((string) $cell) . '</code></td>' : '<td>' . esc_html((string) $cell) . '</td>';
                }

                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }

        if (!empty($section['code'])) {
            echo '<pre class="ks-help__code">' . esc_html((string) $section['code']) . '</pre>';
        }
    }

    private function render_checklist(): void
    {
        echo '<h2>' . esc_html__('Your progress', 'kontor-shop') . '</h2><ul class="ks-help__checklist">';

        foreach ((new SetupChecklist($this->settings))->steps() as $step) {
            echo '<li class="' . ($step['done'] ? 'is-done' : '') . '"><span aria-hidden="true">' . ($step['done'] ? '✓' : '○') . '</span> '
                . '<span><strong>' . esc_html($step['title']) . '</strong>'
                . ($step['required'] ? '' : ' <em>(' . esc_html__('optional', 'kontor-shop') . ')</em>')
                . '<br />' . esc_html($step['hint']) . '</span> '
                . '<a href="' . esc_url($step['url']) . '">' . esc_html($step['done'] ? __('Check', 'kontor-shop') : __('Open', 'kontor-shop')) . ' →</a>'
                . '<span class="screen-reader-text">' . esc_html($step['done'] ? __('done', 'kontor-shop') : __('open', 'kontor-shop')) . '</span></li>';
        }

        echo '</ul><p class="description">' . esc_html__('Unlike the checklist on the dashboard, this list stays here after setup.', 'kontor-shop') . '</p>';
    }
}
