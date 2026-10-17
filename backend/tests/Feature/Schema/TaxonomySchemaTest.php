<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/04-taxonomy-people.sql, ported from database/tests/schema_tests.sql.

test('categories have only one sub-level', function () {
    $sub = insertCategory('اختبار-فرعي', insertCategory('اختبار-رئيسي'));

    expect(rejected(fn () => insertCategory('مستوى-ثالث', $sub)))
        ->toThrow(QueryException::class, 'Sub-categories cannot have children (one level only)');
});

test('a category with sub-categories cannot become a sub-category', function () {
    $parent = insertCategory('اختبار-رئيسي');
    insertCategory('اختبار-فرعي', $parent);
    $other = insertCategory('اختبار-آخر');

    expect(rejected(fn () => DB::table('categories')->where('id', $parent)->update(['parent_id' => $other])))
        ->toThrow(QueryException::class, 'A category with sub-categories cannot become a sub-category');
});

test('a category cannot be its own parent', function () {
    $id = insertCategory('اختبار-رئيسي');

    expect(rejected(fn () => DB::table('categories')->where('id', $id)->update(['parent_id' => $id])))
        ->toThrow(QueryException::class, '"categories_check"');
});

test('a tag slug uses the slug domain', function () {
    expect(rejected(fn () => DB::table('tags')->insert(['name_ar' => 'اقتصاد', 'slug' => 'اقْتِصَاد'])))
        ->toThrow(QueryException::class, '"slug_text_check"');
});

test('a section has a known key', function () {
    expect(rejected(fn () => DB::table('sections')->insert(['key' => 'sports', 'name_ar' => 'رياضة', 'slug' => 'sports'])))
        ->toThrow(QueryException::class, '"sections_key_check"');
});

test('the team page order cannot be negative', function () {
    expect(rejected(fn () => DB::table('authors')->insert(['name' => 'x', 'slug' => 'x', 'team_sort_order' => -1])))
        ->toThrow(QueryException::class, '"authors_team_sort_order_check"');
});

test('an era cannot end before it starts', function () {
    expect(rejected(fn () => DB::table('eras')->insert(['name_ar' => 'العهد', 'year_from' => 1900, 'year_to' => 1850])))
        ->toThrow(QueryException::class, '"eras_check"');
});

test('an author link must be a web address', function () {
    ensure('social_platforms', ['code' => 'facebook', 'name' => 'Facebook', 'sort_order' => 1]);
    $author = DB::table('authors')->insertGetId(['name' => 'محرر', 'slug' => 'editor']);

    expect(rejected(fn () => DB::table('author_links')->insert([
        'author_id' => $author, 'platform_code' => 'facebook', 'url' => 'facebook.com/raqam',
    ])))->toThrow(QueryException::class, '"url_text_check"');
});

test('people and tags are searchable by their normalised name', function () {
    $tag = DB::table('tags')->insertGetId(['name_ar' => 'الطاقة الشمسية', 'slug' => 'طاقة-شمسية']);
    $author = DB::table('authors')->insertGetId(['name' => 'أسامة', 'slug' => 'osama']);
    $guest = DB::table('guests')->insertGetId(['name' => 'إيمان', 'slug' => 'iman']);

    expect(DB::table('tags')->find($tag)->search_name)->toBe('الطاقه الشمسيه')
        ->and(DB::table('authors')->find($author)->search_name)->toBe('اسامه')
        ->and(DB::table('guests')->find($guest)->search_name)->toBe('ايمان');
});
