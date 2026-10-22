<?php

namespace App\Filament\Resources\Services\Pages;

use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use App\Services\ServiceBoard;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditService extends EditRecord
{
    use SavesSeoMeta, SavesService {
        SavesSeoMeta::mutateFormDataBeforeFill as private fillSeo;
        SavesSeoMeta::afterSave as private saveSeo;
    }

    protected static string $resource = ServiceResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Service $service */
        $service = $this->getRecord();

        return self::formData($service) + $this->fillSeo($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Service $record */
        return $this->saveService($record, $data);
    }

    protected function afterSave(): void
    {
        // The service's page shows its SEO texts: a change there alone also refreshes the site.
        if ($this->syncSeo()) {
            ServiceBoard::changed($this->getRecord()->slug);
        }
    }
}
