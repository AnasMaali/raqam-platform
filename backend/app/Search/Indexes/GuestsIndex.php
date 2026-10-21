<?php

namespace App\Search\Indexes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Guests with a page: not in the trash (the same rule as /guests in the API). */
final class GuestsIndex extends BaseIndex
{
    public function key(): string
    {
        return 'guests';
    }

    protected function searchable(): array
    {
        return ['name_n', 'job_title_n', 'organization_n'];
    }

    public function belonging(): Builder
    {
        return DB::table('guests')->whereNull('guests.deleted_at');
    }

    public function documents(array $ids): array
    {
        return $ids === [] ? [] : $this->belonging()->whereIn('guests.id', $ids)
            ->leftJoin('organizations', 'organizations.id', '=', 'guests.organization_id')
            ->get(['guests.id', 'guests.name', 'guests.job_title', 'organizations.name as organization'])
            ->map(fn ($g) => [
                'id' => (int) $g->id, 'name_n' => self::form($g->name), 'job_title_n' => self::form($g->job_title), 'organization_n' => self::form($g->organization),
            ])->values()->all();
    }
}
