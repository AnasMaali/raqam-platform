<?php

use App\Support\ArabicSlug;
use App\Support\UniqueSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Every slug the platform writes is one ArabicSlug::make would write (QA-8 follow-up): the panel's forms and services
 * through UniqueSlug::for or ArabicSlug::make, and the fixed lists of the install (sections, pages, characters, cities,
 * services) written out in the code. So no slug holds a dot, which the website would read as a file's address and
 * answer 404 before any page; the slug_text domain refuses one as well.
 */
const SLUG_TABLES = ['authors', 'categories', 'characters', 'cities', 'guests', 'organizations', 'pages', 'playlists', 'posts', 'sections', 'sectors', 'services', 'tags'];

test('every slug the install writes is already as ArabicSlug::make writes it, with no dot', function () {
    $this->artisan('raqam:install', ['--no-migrate' => true])->assertSuccessful();

    $tables = collect(DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND domain_name = 'slug_text' ORDER BY 1"))
        ->pluck('table_name')->all();
    expect($tables)->toBe(SLUG_TABLES);

    $written = 0;
    $changed = [];
    foreach ($tables as $table) {
        foreach (DB::table($table)->pluck('slug') as $slug) {
            $written++;
            if (ArabicSlug::make($slug) !== $slug || str_contains($slug, '.')) {
                $changed[] = "{$table}: {$slug}";
            }
        }
    }

    expect($written)->toBeGreaterThan(30)->and($changed)->toBe([]);
});

test('a slug typed with dots in a form is written with dashes', function () {
    expect(UniqueSlug::for('tags', 'تقرير.2026'))->toBe('تقرير-2026')
        ->and(UniqueSlug::for('pages', 'about.html'))->toBe('about-html');
});
