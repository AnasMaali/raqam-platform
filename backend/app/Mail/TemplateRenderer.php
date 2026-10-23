<?php

namespace App\Mail;

use App\Support\VisitorText;

/**
 * Fills a template with its values (20.7). Each {variable} is replaced once, in a single pass, so a value that itself
 * looks like {الرمز} stays as typed and is never filled again; and every value goes in by its kind (EmailCatalog):
 * text is escaped, so whatever a visitor typed can never become HTML in somebody's mailbox.
 * The template's text is never run through Blade: it is data, not a view.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string, scalar|null>  $vars
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(string $key, array $vars): array
    {
        $template = EmailTemplates::find($key);
        $kinds = EmailCatalog::variables($key);

        $line = [];
        $html = [];
        foreach ($kinds as $name => $kind) {
            $value = (string) ($vars[$name] ?? '');
            $line['{'.$name.'}'] = $kind === 'html' ? strip_tags($value) : $value;
            $html['{'.$name.'}'] = self::html($kind, $value);
        }

        $body = strtr($template->body_html, $html);

        return [
            // Filled first, then made one line: neither the template nor a value can split the header.
            'subject' => mb_substr(self::line(strtr($template->subject, $line)), 0, 250),
            'html' => $body,
            'text' => self::text($body),
        ];
    }

    /** One line: no line breaks or control characters (a header can never be split into two). */
    public static function line(string $value): string
    {
        return VisitorText::clean((string) preg_replace('/[\x{2028}\x{2029}]+/u', ' ', $value), keepNewlines: false);
    }

    private static function html(string $kind, string $value): string
    {
        $clean = $kind === 'html' ? $value : VisitorText::clean($value);

        return match ($kind) {
            'code' => '<span dir="ltr" style="display:inline-block;font-size:32px;letter-spacing:8px;font-weight:bold">'.e(self::line($clean)).'</span>',
            'url' => preg_match('~^https?://~i', $clean) === 1
                ? '<a href="'.e($clean).'" style="color:#b45309" rel="noopener noreferrer">'.e($clean).'</a>'
                : e($clean),
            'html' => $value,
            default => nl2br(e(trim($clean)), false),
        };
    }

    /** The plain-text copy of a message (20.6), made from its HTML. */
    public static function text(string $html): string
    {
        $text = (string) preg_replace_callback('~<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>~su', function (array $m) {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5));

            return $label === $href || $label === '' ? $href : $label.' ('.$href.')';
        }, $html);
        $text = (string) preg_replace(['~<br\s*/?>~i', '~</(p|h2|h3|ul|ol|tr|table)>~i', '~<li[^>]*>~i', '~</li>~i', '~</td>~i'], ["\n", "\n\n", '- ', "\n", '  '], $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
