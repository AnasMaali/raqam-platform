<?php

namespace App\Mail;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans the body of an e-mail template before it is saved (20.7): paragraphs, emphasis, lists, headings and links
 * (https or mailto only, since a mail has no "relative" address). No styles, images, scripts or forms: the branded
 * frame around the text is ours, the text is the admin's.
 */
final class EmailBodySanitizer
{
    public const MAX_LENGTH = 20_000;

    /** @throws \LengthException */
    public static function clean(string $html): string
    {
        if (strlen($html) > self::MAX_LENGTH) {
            throw new \LengthException('نص الرسالة طويل جداً.');
        }

        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(self::MAX_LENGTH + 1)
            ->allowElement('p')->allowElement('br')
            ->allowElement('h2')->allowElement('h3')
            ->allowElement('strong')->allowElement('em')->allowElement('u')
            ->allowElement('ul')->allowElement('ol')->allowElement('li')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'mailto'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->dropElement('script')->dropElement('style')->dropElement('iframe')->dropElement('object')->dropElement('embed')
            ->dropElement('form')->dropElement('img')->dropElement('svg')->dropElement('math')
            ->blockElement('span')->blockElement('div')->blockElement('font')->blockElement('b')->blockElement('i')
            ->blockElement('blockquote')->blockElement('h1')->blockElement('h4');

        return trim((new HtmlSanitizer($config))->sanitize($html));
    }
}
