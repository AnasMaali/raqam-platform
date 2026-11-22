<?php

namespace App\Panel;

use Illuminate\Support\HtmlString;

/**
 * An alert's text as HTML: escaped like any text, with each command it names (queue:work, schedule:run) as code
 * read left to right, so the name keeps its order inside the Arabic sentence and is not read as a word of it.
 */
final class AlertText
{
    /** A command's name: lower-case words joined by dashes, a colon, the same again; never part of an address. */
    private const COMMAND = '~(?<![\w/.:-])([a-z]+(?:-[a-z]+)*:[a-z]+(?:-[a-z]+)*)(?![\w/:-])~';

    public static function html(string $text): HtmlString
    {
        $parts = preg_split(self::COMMAND, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $i => $part) {
            $html .= $i % 2 === 1 ? '<code dir="ltr">'.e($part).'</code>' : e($part);
        }

        return new HtmlString($html);
    }
}
