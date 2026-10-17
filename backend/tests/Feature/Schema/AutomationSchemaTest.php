<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Cross-cutting automation from database/schema/11-triggers-updated-at.sql.

test('updated_at is refreshed by the database on every update', function () {
    $post = insertPost(['updated_at' => DB::raw("now() - interval '1 day'")]);

    DB::table('posts')->where('id', $post)->update(['title' => 'عنوان جديد']);

    expect(DB::selectOne('select updated_at = now() as fresh from posts where id = ?', [$post])->fresh)->toBeTrue();
});

test('updated_at cannot be set to an old value by a query', function () {
    $post = insertPost();

    DB::table('posts')->where('id', $post)->update(['title' => 'x', 'updated_at' => DB::raw("now() - interval '1 year'")]);

    expect(DB::selectOne('select updated_at = now() as fresh from posts where id = ?', [$post])->fresh)->toBeTrue();
});

test('every table with updated_at has the trigger', function () {
    $missing = DB::select(<<<'SQL'
        SELECT c.table_name
        FROM information_schema.columns c
        JOIN information_schema.tables t USING (table_schema, table_name)
        WHERE c.table_schema = 'public' AND c.column_name = 'updated_at' AND t.table_type = 'BASE TABLE'
          AND NOT EXISTS (SELECT 1 FROM pg_trigger tr
                          WHERE tr.tgrelid = format('%I', c.table_name)::regclass
                            AND tr.tgfoid = 'trg_set_updated_at'::regproc)
        SQL);

    expect($missing)->toBeEmpty();
});

test('every foreign key has an index that starts with its columns', function () {
    $unindexed = DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS tbl, c.conname
        FROM pg_constraint c
        WHERE c.contype = 'f' AND c.connamespace = 'public'::regnamespace
          AND NOT EXISTS (
            SELECT 1 FROM pg_index i
            WHERE i.indrelid = c.conrelid
              AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] @> c.conkey
              AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] <@ c.conkey)
        SQL);

    expect($unindexed)->toBeEmpty();
});
