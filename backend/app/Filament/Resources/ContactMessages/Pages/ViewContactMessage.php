<?php

namespace App\Filament\Resources\ContactMessages\Pages;

use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Models\ContactMessage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewContactMessage extends ViewRecord
{
    protected static string $resource = ContactMessageResource::class;

    /** Opening a new message marks it read. */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        /** @var ContactMessage $message */
        $message = $this->getRecord();
        if ($message->status === 'new') {
            ContactMessageResource::mark($message, 'read');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')->label('رد بالبريد')->icon('heroicon-o-arrow-uturn-left')
                ->url(fn (ContactMessage $record) => ContactMessageResource::replyUrl($record)),
            ContactMessageResource::archiveAction(),
            DeleteAction::make()->label('حذف')->modalDescription('تُحذف الرسالة وبيانات المرسل نهائياً.'),
        ];
    }
}
