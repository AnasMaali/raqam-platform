<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Lists of names come back in the database's order (ORDER BY name): the authors and guests of the API, the panel's
 * pickers and its tables. That order must be an Arabic reader's, and the same on every server: the alef with or
 * without a hamza or madda (آ أ إ ا) is one letter, so «إبراهيم» comes before «أحمد». The order of plain code points
 * (a database made with the C locale) puts every أ before every إ, and a database made with another locale can give a
 * third answer; the local databases, the CI's and production's must not differ.
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
