<?php

namespace App\Media;

use enshrined\svgSanitize\Sanitizer;

/**
 * Cleans an SVG with a well-known library (enshrined/svg-sanitize) after our own gate: no DOCTYPE or
 * entities at all (so no XXE or "billion laughs"), then scripts, event handlers, foreignObject, external
 * references and javascript: links are removed. If nothing usable is left the file is refused.
 */
final class SvgSanitizer
{
    /** @throws MediaRejected */
    public function clean(string $svg): string
    {
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $svg)) {
            throw new MediaRejected('ملف SVG غير مقبول: يحتوي على تعريفات خارجية.');
        }

        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->removeXMLTag(true);
        $sanitizer->minify(false);

        $clean = $sanitizer->sanitize($svg);

        if (! is_string($clean) || ! preg_match('/<svg[\s>]/i', $clean)) {
            throw new MediaRejected('ملف SVG غير صالح.');
        }

        return $clean;
    }
}
