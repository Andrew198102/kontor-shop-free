<?php

declare(strict_types=1);

namespace Kontor\Shop\Infrastructure\Notifications;

/**
 * Schlichte HTML-Huelle fuer alle Shop-Mails: Kopfzeile mit Shopnamen in der
 * Shop-Farbe, weisser Inhaltsbereich, Fusszeile. Tabellen-Layout mit
 * Inline-Styles, damit es auch in Outlook & Co. ordentlich aussieht.
 */
final class MailLayout
{
    public static function wrap(string $content, string $shop_name, string $accent, string $footer = ''): string
    {
        $accent  = self::accent($accent);
        $name    = esc_html($shop_name);
        $content = self::style_content($content, $accent);

        return '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $name . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#1d2327;">'
            . '<tr><td style="background:' . $accent . ';color:#ffffff;padding:18px 28px;font-size:19px;font-weight:bold;">' . $name . '</td></tr>'
            . '<tr><td style="padding:26px 28px 12px;">' . $content . '</td></tr>'
            . '<tr><td style="padding:14px 28px 22px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;">'
            . ($footer !== '' ? $footer : $name)
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /**
     * Ueberschriften/Links im Inhalt in der Akzentfarbe (Inline-Styles, kein
     * <style> noetig) - auch fuer fremde Huellen (Versandwege aus Add-ons).
     */
    public static function style_content(string $content, string $accent): string
    {
        $accent = self::accent($accent);

        $content = preg_replace('/<h([1-6])>/i', '<h$1 style="color:' . $accent . ';font-size:17px;margin:22px 0 8px;">', $content) ?? $content;
        $content = preg_replace('/<a (?![^>]*style=)/i', '<a style="color:' . $accent . ';" ', $content) ?? $content;

        return preg_replace('/<p>/i', '<p style="margin:0 0 14px;">', $content) ?? $content;
    }

    private static function accent(string $accent): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1 ? $accent : '#2271b1';
    }
}
