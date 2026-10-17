<?php

use App\Search\Highlighter;
use App\Search\NormalizedText;
use App\Search\PlainText;
use App\Support\ArabicNormalizer;

dataset('texts', function () {
    $cases = json_decode(file_get_contents(dirname(__DIR__, 3).'/../database/tests/normalizer_cases.json'), true);
    $corpus = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/search/corpus.json'), true);

    foreach ($cases as $i => $case) {
        yield "case {$i}" => [$case['input']];
    }
    foreach ($corpus as $doc) {
        yield $doc['key'] => [$doc['title'].'  '.$doc['excerpt']];
    }
    yield 'edge whitespace' => ["  \t اقْتِصَادُ \n\n فِلَسْطِينَ  "];
});

it('keeps the same text as ArabicNormalizer', function (string $text) {
    expect(NormalizedText::of($text)->text)->toBe(ArabicNormalizer::normalize($text));
})->with('texts');

/** The mark Highlighter puts on $original when Meilisearch matches $word in the normalised copy. */
function marked(string $original, string $word): string
{
    $normalised = ArabicNormalizer::normalize($original);
    $start = strpos($normalised, ArabicNormalizer::normalize($word));

    return Highlighter::mark($original, [['start' => $start, 'length' => strlen(ArabicNormalizer::normalize($word))]]);
}

it('marks the original word, diacritics included', function () {
    expect(marked('اقْتِصَادُ فِلَسْطِينَ بَيْنَ', 'فلسطين'))->toBe('اقْتِصَادُ <mark>فِلَسْطِينَ</mark> بَيْنَ');
});

it('marks the original spelling, not the normalised one', function () {
    expect(marked('إنتاج الزيتون هذا الموسم', 'انتاج'))->toBe('<mark>إنتاج</mark> الزيتون هذا الموسم')
        ->and(marked('مدرسة ريادة الأعمال', 'مدرسه'))->toBe('<mark>مدرسة</mark> ريادة الأعمال');
});

it('copes with tatweel and repeated whitespace before the match', function () {
    expect(marked('فلسطيـــن    اليوم', 'اليوم'))->toBe('فلسطيـــن    <mark>اليوم</mark>');
});

it('merges adjacent matches and escapes HTML', function () {
    $text = 'سعر <b>الذهب</b> & الفضة';
    $normalised = ArabicNormalizer::normalize($text);
    $matches = [
        ['start' => strpos($normalised, 'سعر'), 'length' => strlen('سعر')],
        ['start' => strpos($normalised, 'الذهب'), 'length' => strlen('الذهب')],
    ];

    expect(Highlighter::mark($text, $matches))->toBe('<mark>سعر</mark> &lt;b&gt;<mark>الذهب</mark>&lt;/b&gt; &amp; الفضة');
});

it('returns the escaped original when nothing matched', function () {
    expect(Highlighter::mark('a < b', []))->toBe('a &lt; b');
});

it('extracts plain text from the post body', function () {
    expect(PlainText::fromHtml('<p>الأول</p><p>الثاني &amp; <strong>الثالث</strong></p>'))->toBe('الأول الثاني & الثالث');
});
