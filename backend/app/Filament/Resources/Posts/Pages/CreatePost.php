<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Content\PostSaver;
use App\Filament\Concerns\ReportsPostRuleViolations;
use App\Filament\Resources\Posts\PostResource;
use App\Models\PostKind;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * A new item starts as a draft: pick the kind and write a title, and the editor opens on a saved draft.
 * Everything after that (autosave included) happens on a record that already exists.
 */
class CreatePost extends CreateRecord
{
    use ReportsPostRuleViolations;

    protected static string $resource = PostResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('kind_code')->label('نوع المادة')->native(false)->required()->options(fn () => PostKind::options())->default('news'),
            TextInput::make('title')->label('العنوان')->required()->maxLength(255)->autofocus(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->reportingViolations(fn () => app(PostSaver::class)->create($data['kind_code'], $data['title'], Filament::auth()->id()));
    }

    protected function getRedirectUrl(): string
    {
        return PostResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'أُنشئت المسودة. أكمل التحرير.';
    }

    public function getTitle(): string
    {
        return 'مادة جديدة';
    }
}
