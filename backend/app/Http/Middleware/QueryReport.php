<?php

namespace App\Http\Middleware;

use App\Support\QueryTally;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The QA environment's query budget (docs/qa/README.md): with QUERY_REPORT on, every answer says in X-Raqam-Queries how
 * many database queries it took, and a request sent with "X-Raqam-Cache: off" is answered from an empty cache of its
 * own, so the number is the one a first visit costs, whatever the shared cache holds. Nothing is changed or left
 * behind: the cache it then uses lives as long as the request. With QUERY_REPORT_LOG set, each query is also written to
 * that file with its time (one JSON line per answer), for EXPLAIN. Off, this does nothing; production refuses either.
 *
 * Queries made after the answer is sent (defer, terminate) are not in the number.
 */
class QueryReport
{
    public const COUNT_HEADER = 'X-Raqam-Queries';

    public const CACHE_HEADER = 'X-Raqam-Cache';

    /** The empty cache of a request sent with the cache off: an array store of its own, emptied first. */
    private const COLD_STORE = 'qa_cold';

    public function __construct(private readonly QueryTally $tally) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('raqam.qa.query_report')) {
            return $next($request);
        }

        // The default store goes back afterwards: a server that answers many requests in one process keeps the rest shared.
        $shared = Cache::getDefaultDriver();
        if (strtolower((string) $request->header(self::CACHE_HEADER)) === 'off') {
            config(['cache.stores.'.self::COLD_STORE => ['driver' => 'array', 'serialize' => true]]);
            Cache::store(self::COLD_STORE)->flush();
            Cache::setDefaultDriver(self::COLD_STORE);
        }

        $this->tally->start();
        try {
            $response = $next($request);
        } finally {
            $result = $this->tally->stop();
            Cache::setDefaultDriver($shared);
        }

        $response->headers->set(self::COUNT_HEADER, (string) $result['count']);
        $this->write($request, $response, $result['queries']);

        return $response;
    }

    /** @param  list<array{sql: string, bindings: list<mixed>, ms: float}>  $queries */
    private function write(Request $request, Response $response, array $queries): void
    {
        $file = config('raqam.qa.query_log');
        if (blank($file)) {
            return;
        }

        $line = json_encode([
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'cache' => strtolower((string) $request->header(self::CACHE_HEADER)) === 'off' ? 'off' : 'on',
            'queries' => $queries,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        file_put_contents((string) $file, $line."\n", FILE_APPEND | LOCK_EX);
    }
}
