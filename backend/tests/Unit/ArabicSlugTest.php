<?php

use App\Support\ArabicSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

dataset('titles', [
    'plain arabic' => ['أسعار الذهب اليوم في نابلس', 'أسعار-الذهب-اليوم-في-نابلس'],
    'diacritics and tatweel' => ['اقتِصَادُ فلسطيـــن', 'اقتصاد-فلسطين'],
    'punctuation the domain refuses' => ['هل يرتفع الدولار؟ / 50% #سوق & أخبار', 'هل-يرتفع-الدولار-50-سوق-أخبار'],
    'latin is lower-cased' => ['Raqam Podcast EP 12', 'raqam-podcast-ep-12'],
    'arabic-indic digits' => ['الحلقة ١٢', 'الحلقة-١٢'],
    'only punctuation' => ['؟؟ !!', ''],
]);

it('builds the slug', function (string $title, string $slug) {
    expect(ArabicSlug::make($title))->toBe($slug);
})->with('titles');

it('truncates to the column length without a trailing dash', function () {
    $slug = ArabicSlug::make(str_repeat('كلمة ', 80));

    expect(mb_strlen($slug))->toBeLessThanOrEqual(200)->and($slug)->not->toEndWith('-');
});

it('produces values the slug_text domain accepts', function (string $title) {
    $slug = ArabicSlug::make($title);
    if ($slug === '') {
        expect($title)->toMatch('/^[\s\p{P}]+$/u');   // nothing usable: the form refuses an empty slug

        return;
    }

    DB::transaction(fn () => DB::table('categories')->insert(['name_ar' => 'اختبار', 'slug' => $slug]));

    expect(DB::table('categories')->where('slug', $slug)->exists())->toBeTrue();
})->with('titles');

it('is not Str::slug, which turns Arabic into unreadable Latin', function () {
    expect(Str::slug('أسعار الذهب'))->toBe('asaaar-althhb')
        ->and(ArabicSlug::make('أسعار الذهب'))->toBe('أسعار-الذهب');
});
