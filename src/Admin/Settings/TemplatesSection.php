<?php

declare(strict_types=1);

namespace Kontor\Shop\Admin\Settings;

use Kontor\Shop\Admin\TemplatesPage;
use Kontor\Shop\Admin\Ui\Flash;
use Kontor\Shop\Settings\Repository;

/**
 * E-Mail-Vorlagen: welche Mails der Shop verschickt und was darin steht
 * (Inhalt und Speichern macht Admin\TemplatesPage).
 */
final class TemplatesSection extends Section
{
    private TemplatesPage $templates;

    public function __construct(Repository $settings)
    {
        parent::__construct($settings);
        $this->templates = new TemplatesPage($settings);
    }

    public function key(): string
    {
        return 'templates';
    }

    public function label(): string
    {
        return __('Email templates', 'kontor-shop');
    }

    public function group(): string
    {
        return self::GROUP_TALK;
    }

    public function handle_request(): void
    {
        $notice = $this->templates->handle_submit();

        if ($notice !== null) {
            Flash::redirect(TemplatesPage::url(TemplatesPage::current_event()), $notice);
        }
    }

    public function render(): void
    {
        $this->templates->render();
    }
}
