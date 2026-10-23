<?php

namespace App\Filament\Resources\BotQuickReplies\Pages;

use App\Chat\KnowledgeBase;
use App\Filament\Resources\BotQuickReplies\BotQuickReplyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBotQuickReplies extends ManageRecords
{
    protected static string $resource = BotQuickReplyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('زر جديد')->mutateDataUsing(fn (array $data) => BotQuickReplyResource::clean($data))->after(fn () => KnowledgeBase::changed())];
    }
}
