<?php

namespace App\Search\Indexes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Writers with a page: not in the trash and active (the same rule as /authors in the API). */
final class AuthorsIndex extends BaseIndex
{
    public function key(): string
    {
        return 'authors';
    }

    protected function searchable(): array
    {
        return ['name_n', 'job_title_n'];
    }

    public function belonging(): Builder
    {
        return DB::table('authors')->whereNull('deleted_at')->where('is_active', true);
    }

    public function documents(array $ids): array
    {
        return $ids === [] ? [] : $this->belonging()->whereIn('id', $ids)->get(['id', 'name', 'job_title'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'name_n' => self::form($a->name), 'job_title_n' => self::form($a->job_title)])
            ->values()->all();
    }
}
