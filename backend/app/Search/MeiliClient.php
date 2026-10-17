<?php

namespace App\Search;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The few Meilisearch HTTP calls the platform needs; no SDK, so there is one dependency less to audit. */
final class MeiliClient
{
    public function __construct(private readonly string $host, private readonly string $key) {}

    public static function fromConfig(): self
    {
        return new self((string) config('raqam.search.host'), (string) config('raqam.search.key'));
    }

    public function isReachable(): bool
    {
        try {
            return $this->http()->timeout(2)->get('/health')->json('status') === 'available';
        } catch (\Throwable) {
            return false;
        }
    }

    public function version(): string
    {
        return (string) $this->http()->get('/version')->throw()->json('pkgVersion');
    }

    public function createIndex(string $uid): void
    {
        $this->wait($this->http()->post('/indexes', ['uid' => $uid, 'primaryKey' => 'id'])->throw()->json('taskUid'));
    }

    public function deleteIndex(string $uid): void
    {
        $this->wait($this->http()->delete("/indexes/{$uid}")->throw()->json('taskUid'));
    }

    /** @param  array<string, mixed>  $settings */
    public function updateSettings(string $uid, array $settings): void
    {
        $this->wait($this->http()->patch("/indexes/{$uid}/settings", $settings)->throw()->json('taskUid'));
    }

    /** @param  list<array<string, mixed>>  $documents */
    public function addDocuments(string $uid, array $documents, bool $wait = true): int
    {
        $task = (int) $this->http()->post("/indexes/{$uid}/documents", $documents)->throw()->json('taskUid');
        $wait && $this->wait($task);

        return $task;
    }

    public function deleteDocument(string $uid, int $id, bool $wait = true): int
    {
        $task = (int) $this->http()->delete("/indexes/{$uid}/documents/{$id}")->throw()->json('taskUid');
        $wait && $this->wait($task);

        return $task;
    }

    public function deleteAllDocuments(string $uid): void
    {
        $this->wait($this->http()->delete("/indexes/{$uid}/documents")->throw()->json('taskUid'));
    }

    /** @return list<int> ids of every document in the index */
    public function documentIds(string $uid): array
    {
        return array_map('intval', array_column(
            $this->http()->post("/indexes/{$uid}/documents/fetch", ['limit' => 10000, 'fields' => ['id']])->throw()->json('results'),
            'id',
        ));
    }

    /**
     * @param  array<string, mixed>  $options  Meilisearch search parameters
     * @return array<string, mixed>
     */
    public function search(string $uid, string $query, array $options = []): array
    {
        return $this->http()->post("/indexes/{$uid}/search", ['q' => $query, ...$options])->throw()->json();
    }

    public function wait(int $taskUid, int $seconds = 30): void
    {
        $deadline = microtime(true) + $seconds;

        do {
            $task = $this->http()->get("/tasks/{$taskUid}")->throw()->json();

            if ($task['status'] === 'succeeded') {
                return;
            }
            if (in_array($task['status'], ['failed', 'canceled'], true)) {
                throw new RuntimeException("Meilisearch task {$taskUid} {$task['status']}: ".($task['error']['message'] ?? ''));
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Meilisearch task {$taskUid} did not finish in {$seconds}s.");
    }

    /** Waits until the index has no queued or running task (indexing from observers is not awaited). */
    public function settle(string $uid, int $seconds = 30): void
    {
        $deadline = microtime(true) + $seconds;

        do {
            $busy = $this->http()->get('/tasks', ['indexUids' => $uid, 'statuses' => 'enqueued,processing', 'limit' => 1])->throw()->json('total');
            if ($busy === 0) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Meilisearch index {$uid} still busy after {$seconds}s.");
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->host)->withToken($this->key)->acceptJson()->asJson()->timeout(30);
    }
}
