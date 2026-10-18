<?php

namespace App\Content\Blocks;

use Illuminate\Support\Facades\DB;

/** Looks up posts for the blocks that point at other posts. */
final class PostCardQuery
{
    /** @param  list<int>  $ids  @return list<int> those that exist and are not in the trash, in the order given */
    public static function existing(array $ids, ?string $kind = null): array
    {
        $found = DB::table('posts')->whereIn('id', $ids)->whereNull('deleted_at')
            ->when($kind, fn ($q) => $q->where('kind_code', $kind))->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_filter($ids, fn ($id) => in_array($id, $found, true)));
    }

    /** @param  list<int>  $ids  @return \Illuminate\Support\Collection<int, object> published, live posts keyed by id */
    public static function published(array $ids)
    {
        return DB::table('posts')->whereIn('id', $ids)->where('status', 'published')->whereNull('deleted_at')
            ->where('published_at', '<=', now())->get(['id', 'kind_code', 'title', 'slug', 'excerpt'])->keyBy('id');
    }
}
