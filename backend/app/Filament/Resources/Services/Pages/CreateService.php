<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Services\ServiceResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateService extends CreateRecord
{
    use SavesSeoMeta, SavesService;

    protected static string $resource = ServiceResource::class;

    protected static bool $canCreateAnother = false;

    protected function fillForm(): void
    {
        $this->form->fill(['price_mode' => 'on_request', 'currency_code' => 'ILS', 'is_active' => false, 'group_id' => DB::table('service_groups')->orderBy('sort_order')->value('id')]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->saveService(null, $data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
