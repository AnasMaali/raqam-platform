<?php

namespace App\Search;

use App\Support\ArabicNormalizer;

/**
 * ArabicNormalizer::normalize() output together with, for every normalised character, the
 * character of the original text it came from. Diacritics and tatweel disappear and whitespace
 * collapses, so offsets Meilisearch reports in the normalised text cannot be used on the original
 * without this map. A test pins the result to ArabicNormalizer so the two cannot drift apart.
 */
final class NormalizedText
{
    /** @param  list<int>  $origin  original character index of each normalised character */
    private function __construct(public readonly string $text, private readonly array $origin, private readonly int $originalLength) {}

    public static function of(string $original): self
    {
        $text = [];
        $origin = [];
        $characters = mb_str_split($original);

        foreach ($characters as $index => $character) {
            if (preg_match('/^\s$/u', $character)) {
                if ($text !== [] && end($text) !== ' ') {
                    $text[] = ' ';
                    $origin[] = $index;
                }

                continue;
            }

            foreach (mb_str_split(ArabicNormalizer::normalize($character)) as $normalized) {
                $text[] = $normalized;
                $origin[] = $index;
            }
        }

        if ($text !== [] && end($text) === ' ') {
            array_pop($text);
            array_pop($origin);
        }

        return new self(implode('', $text), $origin, count($characters));
    }

    /**
     * Original character ranges [first, last] covered by matches given as byte offsets into the normalised text.
     *
     * @param  list<array{start: int, length: int}>  $matches
     * @return list<array{0: int, 1: int}>
     */
    public function originalRanges(array $matches): array
    {
        $ranges = [];

        foreach ($matches as $match) {
            $first = mb_strlen(substr($this->text, 0, $match['start']));
            $last = $first + mb_strlen(substr($this->text, $match['start'], $match['length'])) - 1;

            if (isset($this->origin[$first], $this->origin[$last])) {
                $ranges[] = [$this->origin[$first], $this->origin[$last]];
            }
        }

        return $ranges;
    }

    public function originalLength(): int
    {
        return $this->originalLength;
    }
}
