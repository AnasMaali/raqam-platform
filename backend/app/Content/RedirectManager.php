<?php

namespace App\Content;

use App\Analytics\MissingPages;
use App\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Keeps old addresses working (decisions 23.2, 23.6): a changed slug becomes a 301 from the old address to the
 * new one, and a post deleted for good leaves a 410 ("gone") behind. Chains are never built: whatever pointed at
 * the old address is pointed at the new one, and an address that is live again stops being a redirect.
 */
final class RedirectManager
{
    /** The slug of a post changed: its old address now leads to the new one. */
    public function slugChanged(string $kind, int $id, string $oldSlug, string $newSlug, ?int $userId): void
    {
        if ($oldSlug === $newSlug) {
            return;
        }

        $old = PostPath::for($kind, $id, $oldSlug);
        $new = PostPath::for($kind, $id, $newSlug);

        DB::transaction(function () use ($old, $new, $userId) {
            // The new address is live now, so it cannot also be a redirect.
            DB::table('redirects')->where('from_path', $new)->delete();

            // Anything that led to the old address leads to the new one directly.
            DB::table('redirects')->where('to_path', $old)->update(['to_path' => $new]);

            $this->put($old, $new, 301, $userId);
        });
    }

    /** A post was deleted for good: its addresses answer "gone" (410). */
    public function gone(string $kind, int $id, string $slug, ?int $userId): void
    {
        DB::transaction(function () use ($kind, $id, $slug, $userId) {
            $paths = [PostPath::for($kind, $id, $slug), PostPath::bare($kind, $id)];

            // Old addresses that led here are gone as well.
            foreach (DB::table('redirects')->where('status_code', 301)->whereIn('to_path', $paths)->pluck('from_path') as $from) {
                $paths[] = $from;
            }

            foreach (array_unique($paths) as $path) {
                $this->put($path, null, 410, $userId);
            }
        });
    }

    /** The post is back (restored from the trash before it was purged): its addresses are live again. */
    public function revived(string $kind, int $id, string $slug): void
    {
        DB::table('redirects')->whereIn('from_path', [PostPath::for($kind, $id, $slug), PostPath::bare($kind, $id)])->where('status_code', 410)->delete();
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
