<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Lists of names come back in the database's order (ORDER BY name): the authors and guests of the API, the panel's
 * pickers and its tables. That order must be an Arabic reader's, and the same on every server: the alef with or
 * without a hamza or madda (آ أ إ ا) is one letter, so «إبراهيم» comes before «أحمد». A database made with the C
 * locale (the local ones) or with en_US.utf8 (CI's image) puts every أ before every إ. So the columns themselves carry
 * ICU's Arabic collation (decision QA-7). The list below is what ar-x-icu returns on PostgreSQL 16 (collation
 * version 153.121.44.8), checked before it was written: آمنة first, then إبراهيم, then أحمد.
 */
const ARABIC_READER_ORDER = ['آمنة', 'إبراهيم', 'أحمد', 'بسام', 'حسن', 'خالد', 'زياد', 'سامي', 'شادي', 'عمر', 'غسان', 'يوسف'];

test('the API lists authors in an Arabic reader\'s order, whatever the server', function () {
    $shuffled = ARABIC_READER_ORDER;
    mt_srand(48);
    shuffle($shuffled);
    foreach ($shuffled as $i => $name) {
        DB::table('authors')->insert(['name' => $name, 'slug' => "collation-{$i}"]);
    }

    $names = collect($this->getJson('/api/v1/authors')->assertOk()->json('data'))->pluck('name')->all();
    $collation = DB::selectOne('SELECT datcollate, datlocprovider FROM pg_database WHERE datname = current_database()');

    expect($names)->toBe(ARABIC_READER_ORDER, "database collation: {$collation->datcollate} ({$collation->datlocprovider})");
});

/*
 * The names and titles lists are ordered by carry the ICU Arabic collation on the column itself, so the order does not
 * depend on how the database was made. Slugs, codes and e-mails keep the database's (byte) order: they are compared,
 * not read. After an upgrade of the system's ICU library their indexes are rebuilt (docs/deployment-requirements.md).
 */
const ARABIC_ORDERED_COLUMNS = [
    'ad_placements.name_ar', 'authors.name', 'categories.name_ar', 'cities.name_ar', 'guests.name', 'organizations.name',
    'pages.title', 'playlists.name_ar', 'services.name_ar', 'tags.name_ar', 'users.name',
];

test('exactly the names and titles lists are ordered by carry the Arabic collation', function () {
    $collated = collect(DB::select("SELECT table_name, column_name FROM information_schema.columns
                                    WHERE table_schema = 'public' AND collation_name = 'ar-x-icu' ORDER BY 1, 2"))
        ->map(fn ($c) => "{$c->table_name}.{$c->column_name}")->all();

    expect($collated)->toBe(ARABIC_ORDERED_COLUMNS);
});
