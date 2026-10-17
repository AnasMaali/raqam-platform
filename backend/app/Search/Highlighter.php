<?php

namespace App\Search;

/** Wraps matched words of the ORIGINAL text in <mark>, using the offsets Meilisearch found in the normalised copy. */
final class Highlighter
{
    /** @param  list<array{start: int, length: int}>  $matches  from `_matchesPosition` of the normalised field */
    public static function mark(string $original, array $matches): string
    {
        $ranges = NormalizedText::of($original)->originalRanges($matches);
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);

        // Diacritics that follow the last matched letter belong to the word, so they stay inside the mark.
        $characters = mb_str_split($original);
        $merged = [];
        foreach ($ranges as [$first, $last]) {
            while (isset($characters[$last + 1]) && preg_match('/^[\x{064B}-\x{065F}\x{0670}\x{0640}]$/u', $characters[$last + 1])) {
                $last++;
            }
            if ($merged && $first <= end($merged)[1] + 1) {
                $merged[array_key_last($merged)][1] = max(end($merged)[1], $last);
            } else {
                $merged[] = [$first, $last];
            }
        }

        $html = '';
        $cursor = 0;
        foreach ($merged as [$first, $last]) {
            $html .= e(implode('', array_slice($characters, $cursor, $first - $cursor)))
                .'<mark>'.e(implode('', array_slice($characters, $first, $last - $first + 1))).'</mark>';
            $cursor = $last + 1;
        }

        return $html.e(implode('', array_slice($characters, $cursor)));
    }
}
