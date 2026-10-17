<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Rules enforced by database/schema/10-seo-analytics-audit.sql, ported from database/tests/schema_tests.sql.

function insertAudit(string $occurredAt = 'now()'): int
{
    $actor = insertUser('staff', 'admin-'.uniqid().'@raqam.ps');
    $audit = DB::table('audit_log')->insertGetId([
        'actor_user_id' => $actor, 'action' => 'publish', 'subject_table' => 'posts', 'subject_id' => 2,
        'occurred_at' => DB::raw($occurredAt),
    ]);
    DB::table('audit_log_changes')->insert(['audit_id' => $audit, 'field' => 'status', 'old_value' => 'draft', 'new_value' => 'published']);

    return $audit;
}

function headerMenu(): int
{
    ensure('menus', ['key' => 'header', 'name_ar' => 'القائمة الرئيسية']);

    return DB::table('menus')->where('key', 'header')->value('id');
}

describe('redirects and search', function () {
    test('a 410 has no target and a redirect has one', function (int $status, ?string $to) {
        expect(rejected(fn () => DB::table('redirects')->insert(['from_path' => '/old', 'to_path' => $to, 'status_code' => $status])))
            ->toThrow(QueryException::class, '"redirects_check"');
    })->with([
        'gone with a target' => [410, '/new'],
        'moved without a target' => [301, null],
    ]);

    test('a path cannot redirect to itself', function () {
        expect(rejected(fn () => DB::table('redirects')->insert(['from_path' => '/old', 'to_path' => '/old'])))
            ->toThrow(QueryException::class, '"redirects_check1"');
    });

    test('a redirect starts from a site path', function () {
        expect(rejected(fn () => DB::table('redirects')->insert(['from_path' => 'https://other.site/x', 'to_path' => '/new'])))
            ->toThrow(QueryException::class, '"redirects_from_path_check"');
    });

    test('zero-result searches cannot exceed searches', function () {
        $term = DB::table('search_terms')->insertGetId(['normalized_term' => 'سعر الدولار']);

        expect(rejected(fn () => DB::table('search_stats_daily')->insert(['term_id' => $term, 'stat_date' => '2026-10-16', 'searches' => 3, 'zero_result_searches' => 4])))
            ->toThrow(QueryException::class, '"search_stats_daily_check"');
    });
});

describe('analytics', function () {
    test('post_view_totals sums daily views (refreshed concurrently)', function () {
        $post = insertPost();
        DB::table('post_views_daily')->insert([
            ['post_id' => $post, 'stat_date' => DB::raw('current_date - 1'), 'views' => 40],
            ['post_id' => $post, 'stat_date' => DB::raw('current_date'), 'views' => 60],
        ]);

        DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY post_view_totals');

        expect(DB::table('post_view_totals')->where('post_id', $post)->value('total_views'))->toBe(100);
    });

    test('unique visitors cannot exceed page views', function () {
        expect(rejected(fn () => DB::table('site_stats_daily')->insert(['stat_date' => '2026-10-16', 'page_views' => 10, 'unique_visitors' => 11])))
            ->toThrow(QueryException::class, '"site_stats_daily_check"');
    });

    test('a missing path is last seen after it is first seen', function () {
        expect(rejected(fn () => DB::table('not_found_paths')->insert(['path' => '/x', 'first_seen_at' => now(), 'last_seen_at' => now()->subDay()])))
            ->toThrow(QueryException::class, '"not_found_paths_check"');
    });
});

describe('audit log', function () {
    test('audit rows cannot be updated', function () {
        $audit = insertAudit();

        expect(rejected(fn () => DB::table('audit_log')->where('id', $audit)->update(['action' => 'update'])))
            ->toThrow(QueryException::class, 'The audit log is append-only (UPDATE refused)');
    });

    test('audit rows cannot be deleted', function () {
        $audit = insertAudit();

        expect(rejected(fn () => DB::table('audit_log')->delete($audit)))
            ->toThrow(QueryException::class, 'The audit log is append-only (DELETE refused)');
    });

    test('recorded field changes cannot be edited or removed', function () {
        $audit = insertAudit();
        $changes = fn () => DB::table('audit_log_changes')->where('audit_id', $audit);

        expect(rejected(fn () => $changes()->update(['new_value' => 'x'])))
            ->toThrow(QueryException::class, 'The audit log is append-only (UPDATE refused)')
            ->and(rejected(fn () => $changes()->delete()))
            ->toThrow(QueryException::class, 'The audit log is append-only (DELETE refused)');
    });

    test('pruning cannot remove rows younger than two years', function () {
        $audit = insertAudit("now() - interval '1 year'");

        expect(rejected(function () use ($audit) {
            DB::statement("SET LOCAL raqam.audit_prune = 'on'");
            DB::table('audit_log')->delete($audit);
        }))->toThrow(QueryException::class, 'Audit rows younger than 2 years cannot be pruned');
    });

    test('pruning cannot remove the field changes of a young row', function () {
        $audit = insertAudit("now() - interval '1 year'");

        expect(rejected(function () use ($audit) {
            DB::statement("SET LOCAL raqam.audit_prune = 'on'");
            DB::table('audit_log_changes')->where('audit_id', $audit)->delete();
        }))->toThrow(QueryException::class, 'Audit rows younger than 2 years cannot be pruned');
    });

    test('pruning removes rows older than two years with their changes', function () {
        $audit = insertAudit("now() - interval '3 years'");
        DB::statement("SET LOCAL raqam.audit_prune = 'on'");
        DB::table('audit_log')->delete($audit);

        expect(DB::table('audit_log')->where('id', $audit)->exists())->toBeFalse()
            ->and(DB::table('audit_log_changes')->where('audit_id', $audit)->exists())->toBeFalse();
    });

    test('an audit subject has both its table and its id', function () {
        expect(rejected(fn () => DB::table('audit_log')->insert(['action' => 'update', 'subject_table' => 'posts'])))
            ->toThrow(QueryException::class, '"audit_log_check"');
    });

    test('a user with audit history cannot be hard-deleted', function () {
        $audit = insertAudit();
        $actor = DB::table('audit_log')->where('id', $audit)->value('actor_user_id');

        expect(rejected(fn () => DB::table('users')->delete($actor)))
            ->toThrow(QueryException::class, '"audit_log_actor_user_id_fkey"');
    });
});

describe('navigation and home', function () {
    test('a menu item points at exactly one target', function (string $case) {
        ensurePostKinds();
        $targets = $case === 'two targets'
            ? ['url' => '/x', 'section_id' => DB::table('sections')->where('key', 'podcast')->value('id')]
            : [];

        expect(rejected(fn () => DB::table('menu_items')->insert(['menu_id' => headerMenu(), 'label_ar' => 'x', ...$targets])))
            ->toThrow(QueryException::class, '"menu_items_check"');
    })->with(['two targets', 'no target']);

    test('a menu link is a site path or a web address', function () {
        expect(rejected(fn () => DB::table('menu_items')->insert(['menu_id' => headerMenu(), 'label_ar' => 'x', 'url' => 'javascript:alert(1)'])))
            ->toThrow(QueryException::class, '"menu_items_url_check"');
    });

    test('a category block names its category', function () {
        expect(rejected(fn () => DB::table('home_blocks')->insert(['block_type' => 'category', 'sort_order' => 1])))
            ->toThrow(QueryException::class, '"home_blocks_check"');
    });

    test('home blocks can swap positions row by row (deferrable unique)', function () {
        $hero = DB::table('home_blocks')->insertGetId(['block_type' => 'hero', 'sort_order' => 1]);
        $latest = DB::table('home_blocks')->insertGetId(['block_type' => 'latest', 'sort_order' => 2]);

        DB::table('home_blocks')->where('id', $hero)->update(['sort_order' => 2]);
        DB::table('home_blocks')->where('id', $latest)->update(['sort_order' => 1]);
        DB::statement('SET CONSTRAINTS home_blocks_position_uq IMMEDIATE');

        expect(DB::table('home_blocks')->orderBy('sort_order')->pluck('id')->all())->toBe([$latest, $hero]);
    });

    test('two home blocks cannot keep the same position', function () {
        DB::table('home_blocks')->insert([['block_type' => 'hero', 'sort_order' => 1], ['block_type' => 'latest', 'sort_order' => 2]]);

        expect(rejected(function () {
            DB::statement('SET CONSTRAINTS home_blocks_position_uq IMMEDIATE');
            DB::table('home_blocks')->update(['sort_order' => 1]);
        }))->toThrow(QueryException::class, '"home_blocks_position_uq"');
    });
});
