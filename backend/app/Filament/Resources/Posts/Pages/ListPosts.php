<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('مادة جديدة')];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('الكل')->modifyQueryUsing(fn (Builder $query) => $query->whereNull('deleted_at'))];

        foreach (Post::STATUSES as $status => $label) {
            $tabs[$status] = Tab::make($label)->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status)->whereNull('deleted_at'));
        }

        $tabs['trash'] = Tab::make('السلة')->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('deleted_at'));

        return $tabs;
    }
}
