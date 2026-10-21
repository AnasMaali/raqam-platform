<?php

namespace App\Search;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The few Meilisearch HTTP calls the platform needs; no SDK, so there is one dependency less to audit.
 * The key travels only in the Authorization header, and every failure leaves this class as SearchUnavailable
 * with a message of our own, so neither the key nor a raw engine answer reaches a log, a page or a visitor.
 */
final class MeiliClient
{
    public function __construct(private readonly string $host, private readonly string $key) {}

    public static function fromConfig(): self
    {
        return new self(rtrim((string) config('raqam.search.host'), '/'), (string) config('raqam.search.key'));
    }

    /** The engine is switched on: a key is configured. Without one nothing is indexed and search runs on PostgreSQL. */
    public static function configured(): bool
    {
        return filled(config('raqam.search.key'));
    }

    public function isReachable(): bool
    {
        try {
            return $this->http(2)->get('/health')->json('status') === 'available';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether this key may list the API keys. Only the master key (or a key made far too wide) can; the
     * application's own key is scoped to its indexes and is refused. search:setup refuses to run with such a key.
     */
    public function canListKeys(): bool
    {
        try {
            return $this->http(5)->get('/keys', ['limit' => 1])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function version(): string
    {
        return (string) $this->call(fn () => $this->http(5)->get('/version'))->json('pkgVersion');
    }

    /**
     * Creates the application's key (master key only): the actions indexing and searching need, on the indexes
     * that start with the prefix and nothing else. Returns the key itself; nothing else ever sees it.
     *
     * @param  list<string>  $indexPatterns
     */
    public function createKey(string $description, array $indexPatterns): string
    {
        return (string) $this->call(fn () => $this->http(10)->post('/keys', [
            'description' => $description,
            'actions' => self::APP_ACTIONS,
            'indexes' => $indexPatterns,
            'expiresAt' => null,
        ]))->json('key');
    }

    /** What the application's key may do: index, search, read its tasks. Nothing global (keys, dumps, snapshots). */
    public const APP_ACTIONS = [
        'search', 'documents.add', 'documents.get', 'documents.delete',
        'indexes.create', 'indexes.get', 'indexes.update', 'indexes.delete',
        'settings.get', 'settings.update', 'tasks.get',
    ];

    /** @return list<string> uids of the indexes this key can see */
    public function indexes(): array
    {
        $uids = [];
        $offset = 0;
        do {
            $page = $this->call(fn () => $this->http(10)->get('/indexes', ['limit' => 100, 'offset' => $offset]))->json();
            $uids = [...$uids, ...array_column($page['results'] ?? [], 'uid')];
            $offset += 100;
        } while ($offset < ($page['total'] ?? 0));

        return $uids;
    }

    public function createIndex(string $uid): void
    {
        $task = $this->call(fn () => $this->http()->post('/indexes', ['uid' => $uid, 'primaryKey' => 'id']))->json('taskUid');
        try {
            $this->wait((int) $task);
        } catch (SearchUnavailable $e) {
            if (! str_contains($e->getMessage(), 'index_already_exists')) {
                throw $e;
            }
        }
    }

    public function deleteIndex(string $uid): void
    {
        $response = $this->http()->delete("/indexes/{$uid}");
        if ($response->status() === 404) {
            return;
        }
        $this->wait((int) $this->check($response)->json('taskUid'));
    }

    /** @param  array<string, mixed>  $settings */
    public function updateSettings(string $uid, array $settings, bool $wait = true): int
    {
        $task = (int) $this->call(fn () => $this->http()->patch("/indexes/{$uid}/settings", $settings))->json('taskUid');
        $wait && $this->wait($task);

        return $task;
    }

    /** @return array<string, mixed> */
    public function settings(string $uid): array
    {
        return $this->call(fn () => $this->http()->get("/indexes/{$uid}/settings"))->json();
    }

    /** @param  list<array<string, mixed>>  $documents */
    public function addDocuments(string $uid, array $documents, bool $wait = false): int
    {
        $task = (int) $this->call(fn () => $this->http()->post("/indexes/{$uid}/documents", $documents))->json('taskUid');
        $wait && $this->wait($task);

        return $task;
    }

    /** @param  list<int>  $ids */
    public function deleteDocuments(string $uid, array $ids, bool $wait = false): int
    {
        $task = (int) $this->call(fn () => $this->http()->post("/indexes/{$uid}/documents/delete-batch", array_values($ids)))->json('taskUid');
        $wait && $this->wait($task);

        return $task;
    }

    /** @return list<int> ids of every document in the index */
    public function documentIds(string $uid): array
    {
        $ids = [];
        $offset = 0;
        do {
            $page = $this->call(fn () => $this->http()->post("/indexes/{$uid}/documents/fetch", ['limit' => 1000, 'offset' => $offset, 'fields' => ['id']]))->json();
            $ids = [...$ids, ...array_map('intval', array_column($page['results'] ?? [], 'id'))];
            $offset += 1000;
        } while ($offset < ($page['total'] ?? 0));

        return $ids;
    }

    /**
     * Several searches in one round trip. Search calls use the short timeout: a visitor waits at most that
     * long before the PostgreSQL fallback answers.
     *
     * @param  list<array<string, mixed>>  $queries
     * @return list<array<string, mixed>>
     */
    public function multiSearch(array $queries): array
    {
        return $this->call(fn () => $this->http((float) config('raqam.search.timeout'))->post('/multi-search', ['queries' => $queries]))->json('results');
    }

    public function wait(int $taskUid, int $seconds = 30): void
    {
        $deadline = microtime(true) + $seconds;

        do {
            $task = $this->call(fn () => $this->http()->get("/tasks/{$taskUid}"))->json();

            if ($task['status'] === 'succeeded') {
                return;
            }
            if (in_array($task['status'], ['failed', 'canceled'], true)) {
                throw new SearchUnavailable("Meilisearch task {$taskUid} {$task['status']}: ".($task['error']['code'] ?? 'unknown'));
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);

        throw new SearchUnavailable("Meilisearch task {$taskUid} did not finish in {$seconds}s.");
    }

    /** Waits until the index has no queued or running task. */
    public function settle(string $uid, int $seconds = 30): void
    {
        $deadline = microtime(true) + $seconds;

        do {
            $busy = $this->call(fn () => $this->http()->get('/tasks', ['indexUids' => $uid, 'statuses' => 'enqueued,processing', 'limit' => 1]))->json('total');
            if ($busy === 0) {
                return;
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);

        throw new SearchUnavailable("Meilisearch index {$uid} still busy after {$seconds}s.");
    }

    /** Runs one call and turns every failure into SearchUnavailable with the engine's error code only. */
    private function call(\Closure $request): Response
    {
        try {
            return $this->check($request());
        } catch (ConnectionException) {
            throw new SearchUnavailable('Meilisearch cannot be reached.');
        } catch (RequestException $e) {
            throw new SearchUnavailable('Meilisearch refused the request ('.$e->response->status().').');
        }
    }

    private function check(Response $response): Response
    {
        if ($response->failed()) {
            throw new SearchUnavailable('Meilisearch refused the request ('.$response->status().': '.preg_replace('/[^a-z_]/', '', (string) $response->json('code')).').');
        }

        return $response;
    }

    private function http(float $timeout = 0): PendingRequest
    {
        $timeout = $timeout > 0 ? $timeout : (float) config('raqam.search.index_timeout');

        return Http::baseUrl($this->host)->withToken($this->key)->acceptJson()->asJson()
            ->connectTimeout(min($timeout, 2))->timeout($timeout)->withoutRedirecting();
    }
}
