<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Services\PostSaver;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPost extends EditRecord
{
    use ReportsPostRuleViolations;

    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $post = $this->getRecord();

        return $data + [
            'episode' => $post->episode?->only(['season_id', 'episode_number']) ?? [],
            'story' => $post->story?->only(['organization_id', 'person_name']) ?? [],
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->reportingViolations(fn () => app(PostSaver::class)->update($record, $data, auth()->id()));
    }
}
