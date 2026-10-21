<?php

namespace App\Search;

use App\Support\ArabicNormalizer;

/**
 * The form a text is searched in: ArabicNormalizer::normalize() (hamza, ta marbuta, alef maqsura, diacritics,
 * digits), then the definite article taken off every word that keeps at least three letters (ال، وال، بال، فال،
 * كال، لل). Documents are indexed in this form and queries are sent in it, so «الشركة» and «شركة» meet.
 * normalize_ar() and ArabicNormalizer stay untouched: the article is a search layer, not normalisation.
 *
 * Every character of the form remembers the original character it came from, because Meilisearch reports
 * matches as byte offsets into the form while the visitor reads the original (with its diacritics).
 */
final class SearchForm
{
    /** Longest first, so «وال» is not read as «و» + «ال». */
    public const ARTICLES = ['وال', 'فال', 'بال', 'كال', 'لل', 'ال'];

    public const MIN_STEM = 3;

    /**
     * @param  list<string>  $characters  the form, one character each
     * @param  list<int>  $origin  original character index of each form character
     * @param  list<string>  $original  the original text, one character each
     */
    private function __construct(public readonly string $text, private readonly array $characters, private readonly array $origin, private readonly array $original) {}

    public static function of(?string $original): self
    {
        $original = mb_str_split((string) $original);
        [$characters, $origin] = self::normalise($original);
        [$characters, $origin] = self::stripArticles($characters, $origin);
        if (config('raqam.search.feminine_plural')) {
            [$characters, $origin] = self::foldFemininePlural($characters, $origin);
        }

        return new self(implode('', $characters), $characters, $origin, $original);
    }

    /** The search form of a text, without the map. */
    public static function text(?string $original): string
    {
        return self::of($original)->text;
    }

    /** @return list<string> the words of the form (runs of letters and digits) */
    public function words(): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $this->text, $m);

        return $m[0];
    }

    /**
     * Original character ranges [first, last] covered by matches given as byte offsets into the form.
     *
     * @param  list<array{start: int, length: int}>  $matches
     * @return list<array{0: int, 1: int}>
     */
    public function originalRanges(array $matches): array
    {
        $ranges = [];
        foreach ($matches as $match) {
            $first = mb_strlen(substr($this->text, 0, (int) $match['start']));
            $last = $first + mb_strlen(substr($this->text, (int) $match['start'], (int) $match['length'])) - 1;

            if ($last >= $first && isset($this->origin[$first], $this->origin[$last])) {
                $ranges[] = [$this->origin[$first], $this->origin[$last]];
            }
        }

        return $ranges;
    }

    /**
     * Where the words of the form start with one of the terms, as byte offsets (the shape Meilisearch uses).
     * The PostgreSQL fallback has no match positions of its own; this gives it the same highlighting.
     *
     * @param  list<string>  $terms  query words, already in the search form
     * @return list<array{start: int, length: int}>
     */
    public function matchesOf(array $terms): array
    {
        $terms = array_values(array_filter($terms, fn (string $t) => $t !== ''));
        if ($terms === []) {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $this->text, $m, PREG_OFFSET_CAPTURE);
        $matches = [];
        foreach ($m[0] as [$word, $start]) {
            foreach ($terms as $term) {
                if (str_starts_with($word, $term)) {
                    $matches[] = ['start' => $start, 'length' => strlen($word)];
                    break;
                }
            }
        }

        return $matches;
    }

    /** @return list<string> */
    public function original(): array
    {
        return $this->original;
    }

    /**
     * Normalises character by character (the result is the same as normalising the whole text: every rule of
     * ArabicNormalizer works on single characters, except collapsing and trimming whitespace, done here too).
     *
     * @param  list<string>  $original
     * @return array{0: list<string>, 1: list<int>}
     */
    private static function normalise(array $original): array
    {
        $characters = [];
        $origin = [];

        foreach ($original as $index => $character) {
            if (preg_match('/^\s$/u', $character)) {
                if ($characters !== [] && end($characters) !== ' ') {
                    $characters[] = ' ';
                    $origin[] = $index;
                }

                continue;
            }

            foreach (mb_str_split(ArabicNormalizer::normalize($character)) as $normalised) {
                $characters[] = $normalised;
                $origin[] = $index;
            }
        }

        if ($characters !== [] && end($characters) === ' ') {
            array_pop($characters);
            array_pop($origin);
        }

        return [$characters, $origin];
    }

    /**
     * On by default since phase 6 (SEARCH_FEMININE_PLURAL=false switches it off): the sound feminine plural becomes its
     * singular, «شركات» → «شركه», which is how «شركة» is already normalised. Only when three letters stay before
     * «ات». The «ا» becomes the «ه» (so it still points at the original «ا») and the «ت» goes.
     *
     * @param  list<string>  $characters
     * @param  list<int>  $origin
     * @return array{0: list<string>, 1: list<int>}
     */
    private static function foldFemininePlural(array $characters, array $origin): array
    {
        $isLetter = fn (int $i) => isset($characters[$i]) && preg_match('/^[\p{L}\p{N}]$/u', $characters[$i]) === 1;
        $drop = [];
        for ($i = 0, $n = count($characters); $i < $n; $i++) {
            if (! $isLetter($i)) {
                continue;
            }
            $start = $i;
            while ($isLetter($i + 1)) {
                $i++;
            }
            if ($i - $start + 1 - 2 >= self::MIN_STEM && $characters[$i - 1] === 'ا' && $characters[$i] === 'ت') {
                $characters[$i - 1] = 'ه';
                $drop[$i] = true;
            }
        }
        $kept = array_values(array_filter(array_keys($characters), fn (int $k) => ! isset($drop[$k])));

        return [array_map(fn ($k) => $characters[$k], $kept), array_map(fn ($k) => $origin[$k], $kept)];
    }

    /**
     * Takes the article off the start of every word (a run of letters and digits) when at least MIN_STEM letters
     * stay. Words are runs of letters, not of non-space, so «(الشركة)» loses its article like «الشركة».
     *
     * @param  list<string>  $characters
     * @param  list<int>  $origin
     * @return array{0: list<string>, 1: list<int>}
     */
    private static function stripArticles(array $characters, array $origin): array
    {
        $keep = array_fill(0, count($characters), true);
        $isLetter = fn (int $i) => isset($characters[$i]) && preg_match('/^[\p{L}\p{N}]$/u', $characters[$i]) === 1;

        for ($i = 0, $n = count($characters); $i < $n; $i++) {
            if (! $isLetter($i)) {
                continue;
            }
            $end = $i;
            while ($isLetter($end + 1)) {
                $end++;
            }
            $word = implode('', array_slice($characters, $i, $end - $i + 1));
            foreach (self::ARTICLES as $article) {
                $length = mb_strlen($article);
                if (str_starts_with($word, $article) && mb_strlen($word) - $length >= self::MIN_STEM) {
                    for ($k = $i; $k < $i + $length; $k++) {
                        $keep[$k] = false;
                    }
                    break;
                }
            }
            $i = $end;
        }

        $kept = array_keys(array_filter($keep));

        return [array_map(fn ($k) => $characters[$k], $kept), array_map(fn ($k) => $origin[$k], $kept)];
    }
}
