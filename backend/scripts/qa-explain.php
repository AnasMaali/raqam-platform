<?php

/*
 * The heaviest read queries of the QA environment, with their plans (docs/qa/performance.md).
 *
 *   cd backend && APP_ENV=qa php scripts/qa-explain.php <queries.jsonl> [how many, 15]
 *
 * <queries.jsonl> is what the QA server wrote with RAQAM_QA_QUERY_LOG set (scripts/qa.sh serve) while the query
 * budget ran. Every distinct read query in it (SELECT or WITH; a list of values counts as one) is run three times under
 * EXPLAIN (ANALYZE, BUFFERS) inside a READ ONLY transaction that is rolled back, and the best time is kept. The
 * slowest are printed with their plan, then planned again with seq scans off: a table still read whole then has no index
 * that query can use. Run it on a quiet machine: other work on the database makes the times meaningless.
 *
 * Only raqam_qa: the script stops on any other database.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'raqam_qa') {
    fwrite(STDERR, "qa-explain: only against raqam_qa (APP_ENV=qa).\n");
    exit(1);
}
if (! is_readable($argv[1] ?? '')) {
    fwrite(STDERR, "qa-explain: name the query log (RAQAM_QA_QUERY_LOG of scripts/qa.sh serve).\n");
    exit(1);
}
$top = (int) ($argv[2] ?? 15);

/** @return array<string, mixed>|null the plan (JSON), or null when the query cannot be explained here */
$explain = function (string $sql, array $bindings, bool $analyze, bool $seqScans = true): ?array {
    DB::beginTransaction();
    try {
        DB::statement('SET TRANSACTION READ ONLY');
        $seqScans || DB::statement('SET LOCAL enable_seqscan = off');
        $rows = DB::select('EXPLAIN ('.($analyze ? 'ANALYZE, BUFFERS, ' : '').'FORMAT JSON) '.$sql, $bindings);

        return json_decode(array_values((array) $rows[0])[0], true)[0];
    } catch (Throwable) {
        return null;
    } finally {
        DB::rollBack();
    }
};

$seen = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $line) {
    $answer = json_decode($line, true);
    foreach ($answer['queries'] ?? [] as $q) {
        $sql = preg_replace('/\s+/', ' ', trim($q['sql']));
        if (preg_match('/^(select|with)\b/i', $sql) !== 1 || preg_match('/\bfor (update|share)\b/i', $sql) === 1) {
            continue;
        }
        $key = preg_replace('/\((\?, )+\?\)/', '(?…)', $sql);
        $seen[$key] ??= ['sql' => $sql, 'bindings' => $q['bindings'], 'runs' => 0];
        $seen[$key]['runs']++;
    }
}

$timed = [];
foreach ($seen as $query) {
    $best = null;
    for ($i = 0; $i < 3; $i++) {
        $plan = $explain($query['sql'], $query['bindings'], analyze: true);
        if ($plan !== null && ($best === null || $plan['Execution Time'] < $best['Execution Time'])) {
            $best = $plan;
        }
    }
    $best && $timed[] = $query + ['plan' => $best];
}
usort($timed, fn ($a, $b) => $b['plan']['Execution Time'] <=> $a['plan']['Execution Time']);

$nodes = function (array $node, int $depth = 0) use (&$nodes): array {
    $line = str_repeat('  ', $depth).$node['Node Type'].(isset($node['Relation Name']) ? ' on '.$node['Relation Name'] : '')
        .(isset($node['Index Name']) ? ' using '.$node['Index Name'] : '').' (rows '.($node['Actual Rows'] ?? '?').', loops '.($node['Actual Loops'] ?? '?').')';

    return [$line, ...array_merge(...array_map(fn ($child) => $nodes($child, $depth + 1), $node['Plans'] ?? []))];
};
$seqScans = function (array $node) use (&$seqScans): array {
    return array_merge($node['Node Type'] === 'Seq Scan' ? [$node['Relation Name']] : [], ...array_map($seqScans, $node['Plans'] ?? []));
};

printf("%d distinct read queries; the %d slowest:\n\n", count($timed), min($top, count($timed)));
foreach (array_slice($timed, 0, $top) as $i => $q) {
    $without = $explain($q['sql'], $q['bindings'], analyze: false, seqScans: false);
    printf("%d. %.2f ms (planning %.2f ms), %d in the log\n   %s\n", $i + 1, $q['plan']['Execution Time'], $q['plan']['Planning Time'], $q['runs'], $q['sql']);
    foreach (array_slice($nodes($q['plan']['Plan']), 0, 12) as $line) {
        echo '     '.$line."\n";
    }
    $whole = $without === null ? ['?'] : array_values(array_unique($seqScans($without['Plan'])));
    echo '   with seq scans off, still read whole: '.($whole === [] ? 'nothing' : implode(', ', $whole))."\n\n";
}
