<?php

namespace App\Filament\Resources\Synonyms\Pages;

use App\Filament\Resources\Synonyms\SynonymGroupResource;
use App\Models\SynonymGroup;
use App\Search\SynonymLibrary;
use App\Search\SynonymRejected;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSynonymGroup extends CreateRecord
{
    protected static string $resource = SynonymGroupResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return SynonymGroup::findOrFail(app(SynonymLibrary::class)->save(null, (array) ($data['terms'] ?? []), auth()->id()));
        } catch (SynonymRejected $e) {
            throw ValidationException::withMessages(['data.terms' => $e->getMessage()]);
        }
    }
}
