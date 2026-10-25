<?php

namespace App\Content;

use App\Analytics\MissingPages;
use App\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Keeps old addresses working (decisions 23.2, 23.6): a changed slug becomes a 301 from the old address to the
 * new one, and a post (or any other page) deleted for good leaves a 410 ("gone") behind. Chains are never built: whatever pointed at
 * the old address is pointed at the new one, and an address that is live again stops being a redirect.
 */
final class RedirectManager
{
    /** The slug of a post changed: its old address now leads to the new one. */
    public function slugChanged(string $kind, int $id, string $oldSlug, string $newSlug, ?int $userId): void
    {
        if ($oldSlug !== $newSlug) {
            $this->moved(PostPath::for($kind, $id, $oldSlug), PostPath::for($kind, $id, $newSlug), $userId);
        }
    }

    /** A post was deleted for good: its addresses answer "gone" (410). */
    public function gone(string $kind, int $id, string $slug, ?int $userId): void
    {
        $this->pathsGone([PostPath::for($kind, $id, $slug), PostPath::bare($kind, $id)], $userId);
    }

    /** The post is back (restored from the trash before it was purged): its addresses are live again. */
    public function revived(string $kind, int $id, string $slug): void
    {
        $this->pathsRevived([PostPath::for($kind, $id, $slug), PostPath::bare($kind, $id)]);
    }

    /** Any page of the site moved (a post, a category, a tag …, 23.6): the old address leads to the new one, without chains. */
    public function moved(string $old, string $new, ?int $userId): void
    {
        if ($old === $new) {
            return;
        }
        DB::transaction(function () use ($old, $new, $userId) {
            // The new address is live now, so it cannot also be a redirect.
            DB::table('redirects')->where('from_path', $new)->delete();

            // Anything that led to the old address leads to the new one directly.
            DB::table('redirects')->where('to_path', $old)->update(['to_path' => $new]);

            $this->put($old, $new, 301, $userId);
        });
    }

    /** Pages that are gone for good answer 410, and so do the old addresses that led to them. @param list<string> $paths */
    public function pathsGone(array $paths, ?int $userId): void
    {
        DB::transaction(function () use ($paths, $userId) {
            foreach (DB::table('redirects')->where('status_code', 301)->whereIn('to_path', $paths)->pluck('from_path') as $from) {
                $paths[] = $from;
            }
            foreach (array_unique($paths) as $path) {
                $this->put($path, null, 410, $userId);
            }
        });
    }

    /** Addresses that are live again stop answering 410. @param list<string> $paths */
    public function pathsRevived(array $paths): void
    {
        DB::table('redirects')->whereIn('from_path', $paths)->where('status_code', 410)->delete();
    }

    private function put(string $from, ?string $to, int $status, ?int $userId): void
    {
        MissingPages::resolve($from);
        $existing = DB::table('redirects')->where('from_path', $from)->first();

        if ($existing === null) {
            $id = DB::table('redirects')->insertGetId(['from_path' => $from, 'to_path' => $to, 'status_code' => $status, 'created_by' => $userId]);
            AuditLogger::record('create', 'redirects', $id, ['from_path' => [null, $from], 'to_path' => [null, $to], 'status_code' => [null, $status]], $userId);

            return;
        }

        if ($existing->to_path !== $to || (int) $existing->status_code !== $status) {
            DB::table('redirects')->where('id', $existing->id)->update(['to_path' => $to, 'status_code' => $status]);
            AuditLogger::record('update', 'redirects', $existing->id, ['to_path' => [$existing->to_path, $to], 'status_code' => [$existing->status_code, $status]], $userId);
        }
    }
}
