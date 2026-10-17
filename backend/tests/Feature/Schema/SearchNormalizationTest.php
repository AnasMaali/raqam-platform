<?php

use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// normalize_ar() (SQL) and ArabicNormalizer::normalize() (PHP) must stay identical (decision 10.2).
// The cases are shared with database/tests/normalizer_parity.py.

require_once dirname(__DIR__, 4).'/database/php/ArabicNormalizer.php';

dataset('normalizer cases', fn () => array_map(
    fn (array $case) => [$case['input'], $case['expected']],
    json_decode(file_get_contents(dirname(__DIR__, 4).'/database/tests/normalizer_cases.json'), true),
));

test('SQL and PHP normalise Arabic identically', function (string $input, string $expected) {
    expect(DB::selectOne('select normalize_ar(?) as v', [$input])->v)->toBe($expected)
        ->and(ArabicNormalizer::normalize($input))->toBe($expected);
})->with('normalizer cases');

test('organizations.search_name is maintained by the database', function () {
    $id = DB::table('organizations')->insertGetId(['name' => 'مصنع الأمانة للصابون', 'slug' => 'الامانة']);

    expect(DB::table('organizations')->find($id)->search_name)->toBe(ArabicNormalizer::normalize('مصنع الأمانة للصابون'));

    DB::table('organizations')->where('id', $id)->update(['name' => 'شركة إعمار نابلس']);

    expect(DB::table('organizations')->find($id)->search_name)->toBe('شركه اعمار نابلس');
});

test('the fallback search finds a misspelt organisation (pg_trgm)', function () {
    DB::table('organizations')->insert(['name' => 'مصنع الأمانة للصابون', 'slug' => 'الامانة']);

    $found = DB::table('organizations')
        ->whereRaw('word_similarity(normalize_ar(?), search_name) > 0.5', ['الامانه للصابن'])
        ->exists();

    expect($found)->toBeTrue();
});
