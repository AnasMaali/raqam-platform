<?php

/*
 * The data of the QA environment checked against the schema's rules, and the views against numbers counted again in
 * PHP from the rows they read (docs/qa/database.md, "Integrity of the demo data").
 *
 *   cd backend && APP_ENV=qa php scripts/qa-integrity.php
 *
 * Everything runs in one READ ONLY, REPEATABLE READ transaction (one snapshot, one now()), which is rolled back:
 *   - no constraint left NOT VALID, no trigger switched off;
 *   - every foreign key looked up again row by row (no orphan), and every CHECK and every domain's CHECK evaluated
 *     again on every row;
 *   - the rules triggers enforce on each write, checked on what is there now (a booking's status is its last history
 *     row's, refunds within the payment, at most 3 attachments, ...);
 *   - every generated column and view, counted again here: post_view_totals, invoice_totals, rate_board (and
 *     effective_exchange_rates), live_ad_campaigns, video_usage, the search columns (with ArabicNormalizer), the
 *     booking and invoice numbers.
 * Prints one line per check and exits 1 when any is off. Only raqam_qa or a copy of it (raqam_qa_*, given as
 * DB_DATABASE=...): the script stops on any other database. scripts/qa-commerce-sample.sql fills a copy with the
 * bookings, payments and invoices the demo leaves empty.
 */

use App\Support\ArabicNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();
if ($database !== 'raqam_qa' && ! str_starts_with($database, 'raqam_qa_')) {
    fwrite(STDERR, "qa-integrity: only against raqam_qa or a copy of it (raqam_qa_*, APP_ENV=qa DB_DATABASE=...).\n");
    exit(1);
}

$failed = 0;
$checks = 0;
/** One line per check; $bad is the number of rows (or values) that break it, $detail a few of them. */
$report = function (string $label, int $bad, array $detail = []) use (&$failed, &$checks): void {
    $checks++;
    $bad > 0 && $failed++;
    printf("%-5s %s%s\n", $bad === 0 ? 'ok' : 'FAIL', $label, $bad === 0 ? '' : " — {$bad}: ".implode('; ', array_slice($detail, 0, 5)));
};
$count = fn (string $sql, array $bindings = []): int => (int) array_values((array) DB::selectOne($sql, $bindings))[0];

DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $now = DB::selectOne('SELECT now() AS t')->t;
    $rows = fn (string $table) => DB::table($table)->count();
    printf("%s at %s: %d posts, %d bookings, %d payments, %d invoices, %d exchange rates, %d daily view rows\n\n",
        $database, $now, $rows('posts'), $rows('bookings'), $rows('payments'), $rows('invoices'), $rows('exchange_rates'), $rows('post_views_daily'));

    // ---- the catalog: nothing switched off --------------------------------------------------------------------------
    $notValid = DB::select("SELECT conrelid::regclass::text AS t, conname FROM pg_constraint WHERE connamespace = 'public'::regnamespace AND NOT convalidated");
    $report('no constraint is left NOT VALID', count($notValid), array_map(fn ($c) => "{$c->t}.{$c->conname}", $notValid));
    $off = DB::select("SELECT tgrelid::regclass::text AS t, tgname FROM pg_trigger WHERE NOT tgisinternal AND tgenabled = 'D'");
    $report('no trigger is switched off', count($off), array_map(fn ($c) => "{$c->t}.{$c->tgname}", $off));

    // ---- every foreign key, looked up again --------------------------------------------------------------------------
    $fks = DB::select(<<<'SQL'
        SELECT c.conname, c.conrelid::regclass::text AS child, c.confrelid::regclass::text AS parent,
               array_agg(a.attname ORDER BY k.n) AS cols, array_agg(b.attname ORDER BY k.n) AS refs
        FROM pg_constraint c
        CROSS JOIN LATERAL unnest(c.conkey, c.confkey) WITH ORDINALITY AS k(col, ref, n)
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.col
        JOIN pg_attribute b ON b.attrelid = c.confrelid AND b.attnum = k.ref
        WHERE c.contype = 'f' AND c.connamespace = 'public'::regnamespace
        GROUP BY c.oid, c.conname, c.conrelid, c.confrelid
        SQL);
    $orphans = [];
    foreach ($fks as $fk) {
        $cols = str_getcsv(trim($fk->cols, '{}'));
        $refs = str_getcsv(trim($fk->refs, '{}'));
        $notNull = implode(' AND ', array_map(fn ($c) => "x.\"{$c}\" IS NOT NULL", $cols));
        $match = implode(' AND ', array_map(fn ($c, $r) => "p.\"{$r}\" = x.\"{$c}\"", $cols, $refs));
        $n = $count("SELECT count(*) FROM {$fk->child} x WHERE {$notNull} AND NOT EXISTS (SELECT 1 FROM {$fk->parent} p WHERE {$match})");
        $n > 0 && $orphans[] = "{$fk->conname}: {$n}";
    }
    $report('no orphan row: every one of '.count($fks).' foreign keys finds its parent', count($orphans), $orphans);

    // ---- every CHECK and every domain's CHECK, evaluated again -------------------------------------------------------
    $checkRules = DB::select(<<<'SQL'
        SELECT conrelid::regclass::text AS t, conname, pg_get_expr(conbin, conrelid) AS expr
        FROM pg_constraint WHERE connamespace = 'public'::regnamespace AND contype = 'c' AND conrelid <> 0
        SQL);
    $broken = [];
    foreach ($checkRules as $c) {
        // A CHECK passes when its expression is true or unknown (NULL): only "false" breaks it.
        $n = $count("SELECT count(*) FROM {$c->t} WHERE ({$c->expr}) IS FALSE");
        $n > 0 && $broken[] = "{$c->t}.{$c->conname}: {$n}";
    }
    $report('every row passes all '.count($checkRules).' CHECK constraints', count($broken), $broken);

    $domainColumns = DB::select(<<<'SQL'
        SELECT c.table_name, c.column_name, c.domain_name, pg_get_expr(k.conbin, 0) AS expr
        FROM information_schema.columns c
        JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
        JOIN pg_type d ON d.typname = c.domain_name AND d.typnamespace = 'public'::regnamespace
        JOIN pg_constraint k ON k.contypid = d.oid
        WHERE c.table_schema = 'public' AND c.domain_name IS NOT NULL
        SQL);
    $broken = [];
    foreach ($domainColumns as $d) {
        // The domain's expression speaks of VALUE; here it is the column, read as the domain's base type.
        $expr = preg_replace('/\bVALUE\b/', "(\"{$d->column_name}\")", $d->expr);
        $n = $count("SELECT count(*) FROM \"{$d->table_name}\" WHERE ({$expr}) IS FALSE");
        $n > 0 && $broken[] = "{$d->table_name}.{$d->column_name} ({$d->domain_name}): {$n}";
    }
    $report('every value of the '.count($domainColumns).' domain-typed columns passes its domain', count($broken), $broken);

    // ---- the rules the triggers enforce on each write, on what is there now ------------------------------------------
    $rule = function (string $label, string $sql) use ($report) {
        $bad = DB::select($sql);
        $report($label, count($bad), array_map(fn ($r) => implode(' ', (array) $r), $bad));
    };
    $rule('a booking\'s status is its last history row\'s', <<<'SQL'
        SELECT b.id, b.status_code, h.status_code AS last FROM bookings b
        LEFT JOIN LATERAL (SELECT status_code FROM booking_status_history WHERE booking_id = b.id ORDER BY created_at DESC, id DESC LIMIT 1) h ON true
        WHERE h.status_code IS DISTINCT FROM b.status_code
        SQL);
    $rule('every step of a booking\'s history is an allowed transition', <<<'SQL'
        SELECT booking_id, prev, status_code FROM (
          SELECT booking_id, status_code, lag(status_code) OVER (PARTITION BY booking_id ORDER BY created_at, id) AS prev
          FROM booking_status_history) h
        WHERE prev IS NOT NULL AND prev <> status_code
          AND NOT EXISTS (SELECT 1 FROM booking_status_transitions t WHERE t.from_code = h.prev AND t.to_code = h.status_code)
        SQL);
    $rule('a booking has at most 3 attachments', 'SELECT booking_id, count(*) FROM booking_attachments GROUP BY booking_id HAVING count(*) > 3');
    $rule('refunds (not failed) never exceed their payment', <<<'SQL'
        SELECT p.id, p.amount, sum(r.amount) FROM payments p JOIN refunds r ON r.payment_id = p.id AND r.status <> 'failed'
        GROUP BY p.id, p.amount HAVING sum(r.amount) > p.amount
        SQL);
    $rule('a category is at most one level deep', 'SELECT c.id FROM categories c JOIN categories p ON p.id = c.parent_id WHERE p.parent_id IS NOT NULL');
    $rule('a pegged currency has no rates of its own', 'SELECT e.currency_code, count(*) FROM exchange_rates e JOIN currency_pegs p USING (currency_code) GROUP BY 1');
    $rule('an enabled feature has its dependency enabled', <<<'SQL'
        SELECT f.key, f.depends_on FROM feature_flags f JOIN feature_flags d ON d.key = f.depends_on
        WHERE f.is_enabled AND NOT d.is_enabled
        SQL);
    $rule('only staff hold roles', 'SELECT r.user_id FROM user_roles r JOIN users u ON u.id = r.user_id WHERE u.user_type <> \'staff\'');
    $rule('a kind that needs a category has one outside draft', <<<'SQL'
        SELECT p.id, p.kind_code FROM posts p JOIN post_kinds k ON k.code = p.kind_code
        WHERE k.requires_category AND p.status <> 'draft' AND p.category_id IS NULL
        SQL);
    $subtypes = ['episode' => 'podcast_episodes', 'story' => 'success_stories', 'product' => 'products', 'history' => 'history_entries',
        'initiative' => 'initiatives', 'roundtable' => 'roundtable_sessions', 'awareness' => 'awareness_cards', 'infographic' => 'infographics'];
    $missing = [];
    foreach ($subtypes as $kind => $table) {
        foreach (DB::select("SELECT p.id FROM posts p WHERE p.kind_code = ? AND p.status <> 'draft' AND p.deleted_at IS NULL
                             AND NOT EXISTS (SELECT 1 FROM {$table} s WHERE s.post_id = p.id)", [$kind]) as $p) {
            $missing[] = "post {$p->id} ({$kind})";
        }
    }
    $report('a section post outside draft has its own row', count($missing), $missing);
    $rule('a published post has a publication time not in the future', <<<'SQL'
        SELECT id, published_at FROM posts WHERE status = 'published' AND deleted_at IS NULL AND (published_at IS NULL OR published_at > now())
        SQL);

    // ---- the generated columns, counted again ------------------------------------------------------------------------
    $off = [];
    foreach (['posts' => fn ($r) => $r->title.' '.($r->subtitle ?? '').' '.($r->excerpt ?? ''), 'tags' => fn ($r) => $r->name_ar,
        'authors' => fn ($r) => $r->name, 'guests' => fn ($r) => $r->name, 'organizations' => fn ($r) => $r->name] as $table => $source) {
        $column = $table === 'posts' ? 'search_text' : 'search_name';
        foreach (DB::table($table)->get() as $r) {
            if (ArabicNormalizer::normalize($source($r)) !== $r->{$column}) {
                $off[] = "{$table} {$r->id}";
            }
        }
    }
    $report('the search columns equal ArabicNormalizer of their text', count($off), $off);
    $off = [];
    foreach (DB::table('bookings')->get(['id', 'reference', 'reference_year', 'reference_seq']) as $b) {
        $b->reference === sprintf('RQ-%d-%05d', $b->reference_year, $b->reference_seq) || $off[] = "booking {$b->id}";
    }
    foreach (DB::table('invoices')->get(['id', 'number', 'number_year', 'number_seq']) as $i) {
        $i->number === sprintf('INV-%d-%05d', $i->number_year, $i->number_seq) || $off[] = "invoice {$i->id}";
    }
    $report('booking references and invoice numbers read as their year and number', count($off), $off);

    // ---- the views, counted again ------------------------------------------------------------------------------------
    $byHand = [];
    foreach (DB::table('post_views_daily')->get(['post_id', 'views']) as $v) {
        $byHand[$v->post_id] = ($byHand[$v->post_id] ?? 0) + $v->views;
    }
    $view = DB::table('post_view_totals')->pluck('total_views', 'post_id')->map(fn ($n) => (int) $n)->all();
    $diff = [];
    foreach ($byHand + $view as $post => $_) {
        ($byHand[$post] ?? null) === ($view[$post] ?? null) || $diff[] = "post {$post}: counted ".($byHand[$post] ?? 'none').', view '.($view[$post] ?? 'none');
    }
    $report('post_view_totals equals the daily views added up ('.count($byHand).' posts)', count($diff), $diff);

    // PostgreSQL's round() of a numeric: half away from zero.
    $round = function (string $n, int $places): string {
        $half = bcdiv('5', bcpow('10', (string) ($places + 1)), $places + 1);

        return bcadd(bccomp($n, '0') >= 0 ? bcadd($n, $half, $places + 1) : bcsub($n, $half, $places + 1), '0', $places);
    };
    $lines = DB::table('invoice_lines')->get()->groupBy('invoice_id');
    $diff = [];
    foreach (DB::table('invoices')->join('invoice_totals', 'invoice_totals.invoice_id', '=', 'invoices.id')->get(['invoices.id', 'invoices.tax_rate_bp', 'subtotal', 'tax_amount', 'total_amount']) as $i) {
        $subtotal = 0;
        foreach ($lines[$i->id] ?? [] as $l) {
            $subtotal += (int) $round(bcmul((string) $l->quantity, (string) $l->unit_amount, 6), 0);
        }
        $tax = $i->tax_rate_bp === null ? 0 : (int) $round(bcdiv((string) ($subtotal * $i->tax_rate_bp), '10000', 6), 0);
        [(int) $i->subtotal, (int) $i->tax_amount, (int) $i->total_amount] === [$subtotal, $tax, $subtotal + $tax]
            || $diff[] = "invoice {$i->id}: counted {$subtotal}/{$tax}, view {$i->subtotal}/{$i->tax_amount}";
    }
    $report('invoice_totals equals its lines added up, with the tax rounded half up', count($diff), $diff);

    // effective_exchange_rates and rate_board: the latest rate of each board currency (a pegged one from its anchor),
    // the spreads or the override in force, and the change against the last rate of an earlier Palestine day.
    $pegs = DB::table('currency_pegs')->get()->keyBy('currency_code');
    $rates = DB::table('exchange_rates')->orderBy('effective_at')->get(['currency_code', 'mid_rate', 'effective_at']);
    $series = [];
    foreach ($rates as $r) {
        $series[$r->currency_code][] = ['mid' => $r->mid_rate, 'at' => $r->effective_at];
        foreach ($pegs->where('anchor_code', $r->currency_code) as $peg) {
            $series[$peg->currency_code][] = ['mid' => bcdiv(bcadd(bcdiv($r->mid_rate, $peg->units_per_anchor, 12), '0.00000000005', 12), '1', 10), 'at' => $r->effective_at];
        }
    }
    $effective = $count('SELECT count(*) FROM effective_exchange_rates');
    $report('effective_exchange_rates holds every rate and every pegged one ('.$effective.')', abs($effective - array_sum(array_map('count', $series))));

    $palestineDay = fn (string $at) => CarbonImmutable::parse($at)->setTimezone('Asia/Hebron')->toDateString();
    $same = fn (?string $a, ?string $b) => $a === null || $b === null ? $a === $b : bccomp($a, $b, 10) === 0;
    $diff = [];
    $board = DB::table('rate_board')->get()->keyBy('currency_code');
    foreach (DB::table('rate_board_currencies')->where('is_active', true)->get() as $b) {
        $list = $series[$b->currency_code] ?? [];
        usort($list, fn ($x, $y) => strcmp(CarbonImmutable::parse($y['at'])->format('U.u'), CarbonImmutable::parse($x['at'])->format('U.u')));
        $latest = $list[0] ?? null;
        $override = DB::table('rate_overrides')->where('currency_code', $b->currency_code)->where('valid_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $now))->first();
        $previous = null;
        foreach ($list as $r) {
            if ($latest && $palestineDay($r['at']) < $palestineDay($latest['at'])) {
                $previous = $r;
                break;
            }
        }
        $buy = $override->buy_rate ?? ($latest ? $round(bcmul($latest['mid'], bcsub('1', bcdiv((string) $b->buy_spread_bp, '10000', 8), 8), 14), 6) : null);
        $sell = $override->sell_rate ?? ($latest ? $round(bcmul($latest['mid'], bcadd('1', bcdiv((string) $b->sell_spread_bp, '10000', 8), 8), 14), 6) : null);
        $change = $latest && $previous ? $round(bcmul(bcdiv(bcsub($latest['mid'], $previous['mid'], 12), $previous['mid'], 14), '100', 12), 2) : null;
        $row = $board[$b->currency_code] ?? null;
        if (! $row || ! $same($row->mid_rate, $latest['mid'] ?? null) || ! $same($row->buy_rate, $buy) || ! $same($row->sell_rate, $sell)
            || ! $same($row->previous_rate, $previous['mid'] ?? null) || ! $same($row->change_pct, $change) || (bool) $row->is_manual !== ($override !== null)
            || ($latest && $row->rate_date !== $palestineDay($latest['at']))) {
            $diff[] = "{$b->currency_code}: counted {$buy}/{$sell}/{$change}, view ".($row ? "{$row->buy_rate}/{$row->sell_rate}/{$row->change_pct}" : 'none');
        }
    }
    $report('rate_board equals the latest rate, its spreads or override and the change, for '.$board->count().' currencies', count($diff) + abs($board->count() - count(array_filter(DB::table('rate_board_currencies')->where('is_active', true)->pluck('currency_code')->all()))), $diff);

    $enabled = DB::table('ad_placements')->where('is_enabled', true)->pluck('code')->flip();
    $byHand = [];
    foreach (DB::table('ad_campaigns')->join('ad_campaign_placements', 'ad_campaign_placements.campaign_id', '=', 'ad_campaigns.id')
        ->get(['ad_campaigns.id', 'status', 'starts_at', 'ends_at', 'placement_code']) as $c) {
        if ($c->status === 'active' && isset($enabled[$c->placement_code]) && $c->starts_at <= $now && $c->ends_at > $now) {
            $byHand[] = "{$c->id}/{$c->placement_code}";
        }
    }
    $view = DB::table('live_ad_campaigns')->get(['id', 'placement_code'])->map(fn ($r) => "{$r->id}/{$r->placement_code}")->all();
    sort($byHand);
    sort($view);
    $report('live_ad_campaigns equals the active, current campaigns in enabled places ('.count($view).')', count(array_diff($byHand, $view)) + count(array_diff($view, $byHand)), array_merge(array_diff($byHand, $view), array_diff($view, $byHand)));

    $live = DB::table('posts')->whereNull('deleted_at')->pluck('id')->flip();
    $byHand = [];
    foreach (DB::table('posts')->whereNull('deleted_at')->whereNotNull('primary_video_id')->get(['id', 'primary_video_id']) as $p) {
        $byHand[] = "{$p->primary_video_id}/{$p->id}/primary";
    }
    foreach (DB::table('post_body_videos')->get(['video_id', 'post_id']) as $b) {
        isset($live[$b->post_id]) && $byHand[] = "{$b->video_id}/{$b->post_id}/body";
    }
    $view = DB::table('video_usage')->get()->map(fn ($r) => "{$r->video_id}/{$r->post_id}/{$r->usage}")->all();
    sort($byHand);
    sort($view);
    $report('video_usage equals the main and body videos of posts not in the bin ('.count($view).')', $byHand === $view ? 0 : max(1, abs(count($byHand) - count($view))), array_merge(array_diff($byHand, $view), array_diff($view, $byHand)));
} finally {
    DB::rollBack();
}

printf("\n%d checks, %d off\n", $checks, $failed);
exit($failed === 0 ? 0 : 1);
