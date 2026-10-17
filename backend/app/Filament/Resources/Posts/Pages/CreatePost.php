<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Services\PostSaver;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePost extends CreateRecord
{
    use ReportsPostRuleViolations;

    protected static string $resource = PostResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->reportingViolations(fn () => app(PostSaver::class)->create($data, auth()->id()));
    }
}
