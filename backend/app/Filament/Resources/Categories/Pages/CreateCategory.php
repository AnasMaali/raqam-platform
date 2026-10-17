<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Concerns\ReportsDatabaseRefusals;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Categories\CategoryResource;
use App\Support\UniqueSlug;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateCategory extends CreateRecord
{
    use ReportsDatabaseRefusals, SavesSeoMeta;

    protected static string $resource = CategoryResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->takeSeo($data);
        $data['slug'] = UniqueSlug::for('categories', $data['slug'] ?: $data['name_ar'], fallback: 'category');
        $data['sort_order'] = (int) DB::table('categories')->max('sort_order') + 1;

        return $data;
    }
}
