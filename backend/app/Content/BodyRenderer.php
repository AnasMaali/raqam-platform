<?php

namespace App\Content;

use App\Content\Blocks\BlockRegistry;

/**
 * Turns a stored body into the HTML a reader sees: the text as it is, each custom block replaced by what it
 * shows today (a picture with its sizes, a gallery, the links of "read also"). The stored body only keeps
 * references, so nothing here can show a picture that has been removed.
 */
final class BodyRenderer
{
    public function __construct(private BlockRegistry $blocks) {}

    public function html(string $body): string
    {
        if (! str_contains($body, 'customBlock')) {
            return $body;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$body.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($document->getElementsByTagName('div')) as $div) {
            $block = $this->blocks->find($div->getAttribute('data-id'));
            $config = json_decode($div->getAttribute('data-config'), true);

            $html = $block !== null && is_array($config) ? $block::render($config) : '';
            $replacement = $this->fragment($document, $html);
            $div->parentNode?->replaceChild($replacement, $div);
        }

        $out = '';
        foreach ($document->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $out .= $document->saveHTML($node);
        }

        return $out;
    }

    private function fragment(\DOMDocument $document, string $html): \DOMNode
    {
        $fragment = $document->createDocumentFragment();

        if (trim($html) !== '') {
            $previous = libxml_use_internal_errors(true);
            $fragment->appendXML($this->xml($html));
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $fragment;
    }

    /** appendXML wants well-formed XML; the block HTML is ours and uses void tags, so close them. */
    private function xml(string $html): string
    {
        return preg_replace('~<(img|source|br|hr)([^>]*?)\s*/?>~i', '<$1$2/>', $html);
    }
}
