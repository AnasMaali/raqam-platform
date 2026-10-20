<?php

use App\Search\Highlighter;
use App\Search\SearchForm;
use App\Support\ArabicNormalizer;

// The form is ArabicNormalizer plus the article layer, nothing else: pinned on the shared normaliser cases.
test('without an article the search form is exactly ArabicNormalizer::normalize', function () {
    $cases = json_decode(file_get_contents(__DIR__.'/../../../../database/tests/normalizer_cases.json'), true);
    $reference = fn (string $normalised) => preg_replace_callback('/[\p{L}\p{N}]+/u', function ($m) {
        foreach (SearchForm::ARTICLES as $a) {
            if (str_starts_with($m[0], $a) && mb_strlen($m[0]) - mb_strlen($a) >= 3) {
                return mb_substr($m[0], mb_strlen($a));
            }
        }

        return $m[0];
    }, $normalised);

    expect($cases)->not->toBeEmpty();
    foreach ($cases as $case) {
        expect(SearchForm::text($case['input']))->toBe($reference(ArabicNormalizer::normalize($case['input'])), $case['input']);
    }
});

test('the article comes off every word that keeps three letters: ال وال بال فال كال لل', function (string $text, string $form) {
    expect(SearchForm::text($text))->toBe($form);
})->with([
    ['الشركة', 'شركه'],
    ['والشركات', 'شركات'],
    ['بالأسواق', 'اسواق'],
    ['فالاقتصاد', 'اقتصاد'],
    ['كالذهب', 'ذهب'],
    ['للتنمية', 'تنميه'],
    ['أسعار الذَّهَبِ اليومَ', 'اسعار ذهب يوم'],
    ['(الشركة) و«البنك»', '(شركه) و«بنك»'],
    // Fewer than three letters would stay: the word is left whole.
    ['الذي', 'الذي'],
    ['الله', 'الله'],
    ['لله', 'لله'],
    // Not at the start of the word: untouched.
    ['مالي', 'مالي'],
    ['شركة', 'شركه'],
]);

test('ranges found in the form map back to the original characters, diacritics and article included', function () {
    $original = 'أسعارُ الذَّهبِ';
    $form = SearchForm::of($original);
    expect($form->text)->toBe('اسعار ذهب');

    $start = strlen('اسعار ');
    [$first, $last] = $form->originalRanges([['start' => $start, 'length' => strlen('ذهب')]])[0];
    $characters = mb_str_split($original);

    expect(implode('', array_slice($characters, $first, $last - $first + 1)))->toBe('ذَّهب');
});

test('the highlight marks the whole original word, with its article and diacritics', function () {
    $form = SearchForm::of('ارتفاعُ أسعارِ الشَّركةِ');
    $matches = $form->matchesOf(['شركه']);

    expect(Highlighter::mark('ارتفاعُ أسعارِ الشَّركةِ', $matches))->toBe('ارتفاعُ أسعارِ <mark>الشَّركةِ</mark>');
});

test('a word started by the visitor is marked whole', function () {
    $matches = SearchForm::of('شركات الكهرباء')->matchesOf(['شرك']);

    expect(Highlighter::mark('شركات الكهرباء', $matches))->toBe('<mark>شركات</mark> الكهرباء');
});

test('several matches are marked, and punctuation next to a word stays outside the mark', function () {
    $text = '«الشركة» تبيع الذهبَ.';
    $matches = SearchForm::of($text)->matchesOf(['شركه', 'ذهب']);

    expect(Highlighter::mark($text, $matches))->toBe('«<mark>الشركة</mark>» تبيع <mark>الذهبَ</mark>.');
});

test('everything around and inside a mark is escaped', function () {
    $text = '<script>alert(1)</script> الشركة & "شركاء"';
    $html = Highlighter::mark($text, SearchForm::of($text)->matchesOf(['شركه']));

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('<mark>الشركة</mark>')
        ->and($html)->toContain('&amp;')
        ->and(strip_tags(str_replace(['<mark>', '</mark>'], '', $html)))->toBe(e($text));
});

test('match positions outside the text are ignored instead of breaking the highlight', function () {
    expect(Highlighter::mark('خبر', [['start' => 500, 'length' => 6]]))->toBe('خبر')
        ->and(Highlighter::mark(null, [['start' => 0, 'length' => 2]]))->toBe('');
});

// ---- experiment: the sound feminine plural (off by default) ------------------------------------------

test('the feminine plural is not folded unless the experiment is switched on', function () {
    expect(config('raqam.search.feminine_plural'))->toBeFalse()
        ->and(SearchForm::text('الشركات'))->toBe('شركات');
});

test('switched on, «ات» after three letters becomes «ه», the singular\'s form, and the highlight still marks the word', function (string $word, string $form) {
    config(['raqam.search.feminine_plural' => true]);

    expect(SearchForm::text($word))->toBe($form);
})->with([
    ['الشركات', 'شركه'],
    ['شركة', 'شركه'],
    ['للجامعات', 'جامعه'],
    ['نبات', 'نبات'],        // two letters before «ات»: left alone
    ['ذات', 'ذات'],
]);

test('switched on, the plural is marked whole in the original text', function () {
    config(['raqam.search.feminine_plural' => true]);
    $text = 'الشركاتُ الكبرى';

    expect(Highlighter::mark($text, SearchForm::of($text)->matchesOf(['شركه'])))->toBe('<mark>الشركاتُ</mark> الكبرى');
});
