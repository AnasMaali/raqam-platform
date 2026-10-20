<?php

namespace App\Search;

/**
 * Wraps the matched words of the ORIGINAL text in <mark>, from offsets found in its search form. The whole
 * original word is marked, article and diacritics included: a match on «شركه» marks «الشَّركةِ», and typing
 * «شرك» marks the word it starts. Everything else is escaped, so the result is safe to put in a page.
 */
final class Highlighter
{
    /** @param  list<array{start: int, length: int}>  $matches  byte offsets into SearchForm::of($original)->text */
    public static function mark(?string $original, array $matches): string
    {
        $form = SearchForm::of($original);
        $characters = $form->original();
        $isWord = fn (int $i) => isset($characters[$i]) && preg_match('/^[\p{L}\p{N}\p{M}\x{0640}]$/u', $characters[$i]) === 1;

        $ranges = [];
        foreach ($form->originalRanges($matches) as [$first, $last]) {
            while ($isWord($first - 1)) {
                $first--;
            }
            while ($isWord($last + 1)) {
                $last++;
            }
            $ranges[] = [$first, $last];
        }
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($ranges as [$first, $last]) {
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
