<?php

namespace App\Chat;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The chatbot's answers are shown inside the site's pages, so they pass an allow-list (18.3, 18.12): paragraphs,
 * emphasis, lists and links (https, a path of the site, phone or e-mail), nothing else. Cleaned when saved, and again
 * when shown.
 */
final class BotAnswerSanitizer
{
    public const MAX_LENGTH = 5000;

    public static function clean(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(self::MAX_LENGTH * 4)
            ->allowElement('p')->allowElement('br')->allowElement('strong')->allowElement('em')
            ->allowElement('ul')->allowElement('ol')->allowElement('li')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->dropElement('script')->dropElement('style')->dropElement('iframe')->dropElement('object')->dropElement('embed')
            ->dropElement('form')->dropElement('img')->dropElement('svg')->dropElement('math')->dropElement('video')->dropElement('audio')
            ->blockElement('span')->blockElement('div')->blockElement('b')->blockElement('i')->blockElement('u')->blockElement('h2')->blockElement('h3');

        $clean = trim((new HtmlSanitizer($config))->sanitize($html));

        // A relative link must be a path of this site: "//host" would leave it.
        return (string) preg_replace('~href="(//|/\\\\|\\\\)[^"]*"~i', 'href="#"', $clean);
    }

    /** Plain text (an FAQ answer) as safe paragraphs. */
    public static function fromText(string $text): string
    {
        return '<p>'.nl2br(e(trim($text)), false).'</p>';
    }
}
