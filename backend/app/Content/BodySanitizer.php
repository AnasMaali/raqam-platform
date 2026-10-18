<?php

namespace App\Content;

use App\Content\Blocks\BlockRegistry;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans the HTML of an article or a page before it is saved (decision 3, the editor rules). Only an allow-list
 * of elements and attributes gets through: no scripts, no styles, no event handlers, no iframes, no forms and no
 * <img> at all. Pictures, galleries and the like are "custom blocks" that carry only a reference (a media id,
 * a post id), which is re-checked here against the database; the block's HTML is made at display time.
 */
final class BodySanitizer
{
    public const MAX_LENGTH = 1_000_000;

    private const RENAME = ['b' => 'strong', 'i' => 'em', 'strike' => 's', 'del' => 's', 'h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];

    public function __construct(private BlockRegistry $blocks) {}

    /** @throws \LengthException when the text is longer than we are willing to keep */
    public function clean(string $html, bool $allowBlocks = true): string
    {
        if (strlen($html) > self::MAX_LENGTH) {
            throw new \LengthException('النص طويل جداً.');
        }

        $clean = $this->sanitizer($allowBlocks)->sanitize($html);

        return $this->finish($clean, $allowBlocks);
    }

    private function sanitizer(bool $allowBlocks): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(self::MAX_LENGTH + 1)
            ->allowElement('p')->allowElement('br')->allowElement('hr')
            ->allowElement('h2')->allowElement('h3')->allowElement('h4')
            ->allowElement('strong')->allowElement('em')->allowElement('u')->allowElement('s')
            ->allowElement('b')->allowElement('i')->allowElement('strike')->allowElement('del')
            ->allowElement('h1')->allowElement('h5')->allowElement('h6')
            ->allowElement('ul')->allowElement('ol')->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('a', ['href', 'target', 'title'])
            ->allowElement('table')->allowElement('thead')->allowElement('tbody')->allowElement('tfoot')->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan'])->allowElement('td', ['colspan', 'rowspan'])
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->dropElement('script')->dropElement('style')->dropElement('iframe')->dropElement('object')->dropElement('embed')
            ->dropElement('form')->dropElement('input')->dropElement('button')->dropElement('textarea')->dropElement('select')
            // Wrappers that come with pasted text: the box goes, the words stay.
            ->blockElement('span')->blockElement('font')->blockElement('section')->blockElement('article')->blockElement('header')
            ->blockElement('footer')->blockElement('main')->blockElement('aside')->blockElement('center')->blockElement('small')
            ->blockElement('sub')->blockElement('sup')->blockElement('mark')->blockElement('code')->blockElement('pre')->blockElement('kbd')
            ->blockElement('abbr')->blockElement('cite')->blockElement('figure')->blockElement('figcaption')->blockElement('label')
            ->blockElement('address')->blockElement('dl')->blockElement('dt')->blockElement('dd')->blockElement('details')->blockElement('summary')
            ->dropElement('svg')->dropElement('math')->dropElement('img')->dropElement('video')->dropElement('audio');

        if ($allowBlocks) {
            $config = $config->allowElement('div', ['data-type', 'data-id', 'data-config']);
        }

        return new HtmlSanitizer($config);
    }

    /** Second pass on the sanitised HTML: custom blocks are validated and rebuilt, links are made safe. */
    private function finish(string $html, bool $allowBlocks): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        // Old and loose tags become the ones the site styles; the page title is the only h1.
        foreach (self::RENAME as $from => $to) {
            foreach (iterator_to_array($document->getElementsByTagName($from)) as $element) {
                $this->rename($document, $element, $to);
            }
        }

        foreach (iterator_to_array($document->getElementsByTagName('a')) as $link) {
            $this->tidyLink($link);
        }

        foreach (iterator_to_array($document->getElementsByTagName('div')) as $div) {
            if (! $allowBlocks || ! $this->rebuildBlock($div)) {
                $this->unwrap($div);   // an ordinary <div> (pasted text, say) loses its box, not its words; a bad block is dropped by rebuildBlock's own caller
            }
        }

        $out = '';
        foreach ($body->childNodes as $node) {
            $out .= $document->saveHTML($node);
        }

        return trim($out);
    }

    private function rename(\DOMDocument $document, \DOMElement $element, string $to): void
    {
        $new = $document->createElement($to);
        while ($element->firstChild !== null) {
            $new->appendChild($element->firstChild);
        }
        $element->parentNode?->replaceChild($new, $element);
    }

    /** Replaces an element by its children. A custom block that failed its check has data-type set and is dropped whole. */
    private function unwrap(\DOMElement $element): void
    {
        if ($element->getAttribute('data-type') === 'customBlock') {
            $element->parentNode?->removeChild($element);

            return;
        }

        while ($element->firstChild !== null) {
            $element->parentNode?->insertBefore($element->firstChild, $element);
        }
        $element->parentNode?->removeChild($element);
    }

    private function tidyLink(\DOMElement $link): void
    {
        $href = trim($link->getAttribute('href'));
        if ($href === '') {
            $link->removeAttribute('href');
        }

        // Only "open in a new tab" is allowed as a target, and it always comes with rel=noopener.
        if ($link->getAttribute('target') !== '_blank') {
            $link->removeAttribute('target');
        }
        $link->setAttribute('rel', 'noopener noreferrer');
    }

    /** @return bool false when the block is not one of ours or its content does not check out */
    private function rebuildBlock(\DOMElement $div): bool
    {
        if ($div->getAttribute('data-type') !== 'customBlock') {
            return false;
        }

        $block = $this->blocks->find($div->getAttribute('data-id'));
        $config = json_decode($div->getAttribute('data-config'), true);

        if ($block === null || ! is_array($config) || ($normal = $block::normalize($config)) === null) {
            return false;
        }

        while ($div->attributes->length > 0) {
            $div->removeAttributeNode($div->attributes->item(0));
        }
        while ($div->firstChild !== null) {
            $div->removeChild($div->firstChild);   // a block has no content of its own; the editor shows it from its config
        }
        $div->setAttribute('data-type', 'customBlock');
        $div->setAttribute('data-id', $block::getId());
        $div->setAttribute('data-config', json_encode($normal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return true;
    }
}
