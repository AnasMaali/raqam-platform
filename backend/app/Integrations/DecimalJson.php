<?php

namespace App\Integrations;

use JsonException;

/**
 * Decodes JSON from an outside service with every number kept as the exact text it was sent as, so a rate such as
 * 3.106 never passes through a binary float on its way to a numeric column (decision 11: no float).
 * Strings are left alone; only bare numbers outside strings are quoted before decoding.
 */
final class DecimalJson
{
    /**
     * @return array<mixed>
     *
     * @throws JsonException when the text is not JSON or nests deeper than $depth
     */
    public static function decode(string $json, int $depth = 16): array
    {
        $out = '';
        $inString = false;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                $out .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $out .= $json[++$i];
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $out .= $char;

                continue;
            }

            if (($char === '-' || ctype_digit($char))
                && preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $json, $m, 0, $i) === 1) {
                $out .= '"'.$m[0].'"';
                $i += strlen($m[0]) - 1;

                continue;
            }

            $out .= $char;
        }

        $data = json_decode($out, true, $depth, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new JsonException('not an object or a list');
        }

        return $data;
    }
}
