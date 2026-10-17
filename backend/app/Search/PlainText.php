<?php

namespace App\Search;

final class PlainText
{
    /** The visible text of the sanitised post body, with block boundaries kept as spaces. */
    public static function fromHtml(string $html): string
    {
        $html = preg_replace('#<(/?(p|div|br|li|ul|ol|h[1-6]|blockquote|tr|td|th)\b[^>]*)>#i', ' <$1>', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
