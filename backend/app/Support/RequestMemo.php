<?php

namespace App\Support;

use Closure;

/**
 * What one answer reads once and uses many times (QA-5: a list asks for every row): kept on the request, under one key,
 * so it ends with the request, in a test as on the server.
 *
 * A queue worker keeps one request object for every job it runs: flush() is called before each job (Queue::before in
 * AppServiceProvider), so a job never sees what an earlier one read. Only this memo is emptied: the request's other
 * attributes (the reason a session ended, …) are left alone, which matters when a sync job runs inside a web request.
 */
final class RequestMemo
{
    private const KEY = 'raqam.memo';

    /**
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public static function remember(string $key, Closure $read): mixed
    {
        $memo = request()->attributes->get(self::KEY, []);
        if (! array_key_exists($key, $memo)) {
            $memo[$key] = $read();
            request()->attributes->set(self::KEY, $memo);
        }

        return $memo[$key];
    }

    /** @param  mixed  $value  replaces what is kept under $key */
    public static function put(string $key, mixed $value): void
    {
        request()->attributes->set(self::KEY, [$key => $value] + request()->attributes->get(self::KEY, []));
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, request()->attributes->get(self::KEY, []));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return request()->attributes->get(self::KEY, [])[$key] ?? $default;
    }

    /** After a change in the same request: the next reading of $key reads it again. */
    public static function forget(string ...$keys): void
    {
        request()->attributes->set(self::KEY, array_diff_key(request()->attributes->get(self::KEY, []), array_flip($keys)));
    }

    /** Empty, before each job of a worker (see the class comment). */
    public static function flush(): void
    {
        request()->attributes->remove(self::KEY);
    }
}
