<?php

namespace App\Search\Indexes;

use App\Models\Post;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Tags that lead somewhere: at least one live item carries them (a tag page with nothing on it is not a result). */
final class TagsIndex extends BaseIndex
{
    public function key(): string
    {
        return 'tags';
    }

    protected function searchable(): array
    {
        return ['name_n'];
    }

    public function belonging(): Builder
    {
        return DB::table('tags')->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('post_tags')
            ->whereColumn('post_tags.tag_id', 'tags.id')
            ->whereIn('post_tags.post_id', Post::query()->live()->select('posts.id')->toBase()));
    }

    public function documents(array $ids): array
    {
        return $ids === [] ? [] : $this->belonging()->whereIn('tags.id', $ids)->get(['tags.id', 'tags.name_ar'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'name_n' => self::form($t->name_ar)])
            ->values()->all();
    }
}
