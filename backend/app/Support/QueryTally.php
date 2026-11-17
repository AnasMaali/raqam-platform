<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * The database queries of the answer being made, for the QA environment's measurements (config raqam.qa, refused in
 * production). One listener for the life of the application, added by the first answer made with the report on (so
 * none exists otherwise); QueryReport starts and ends each count, so several requests on one application (a test)
 * are each counted on their own.
 */
final class QueryTally
{
    private bool $listening = false;

    private int $count = 0;

    private bool $counting = false;

    /** @var list<array{sql: string, bindings: list<mixed>, ms: float}> */
    private array $queries = [];

    public function start(): void
    {
        if (! $this->listening) {
            DB::listen($this->record(...));
            $this->listening = true;
        }
        $this->count = 0;
        $this->queries = [];
        $this->counting = true;
    }

    /** @return array{count: int, queries: list<array{sql: string, bindings: list<mixed>, ms: float}>} */
    public function stop(): array
    {
        $this->counting = false;

        return ['count' => $this->count, 'queries' => $this->queries];
    }

    public function record(QueryExecuted $query): void
    {
        if (! $this->counting) {
            return;
        }
        $this->count++;
        if (filled(config('raqam.qa.query_log'))) {
            $this->queries[] = ['sql' => $query->sql, 'bindings' => array_values($query->bindings), 'ms' => (float) $query->time];
        }
    }
}
