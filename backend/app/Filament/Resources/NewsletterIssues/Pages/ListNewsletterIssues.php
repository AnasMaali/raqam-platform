<?php

namespace App\Filament\Resources\NewsletterIssues\Pages;

use App\Filament\Resources\NewsletterIssues\NewsletterIssueResource;
use App\Newsletter\NewsletterIssues;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListNewsletterIssues extends ListRecords
{
    protected static string $resource = NewsletterIssueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('draftNow')->label('تجهيز مسودة هذا الأسبوع الآن')->icon('heroicon-o-document-plus')->requiresConfirmation()
                ->modalDescription('تُجهَّز من أهم مواد آخر 7 أيام وتُرسَل إلى Brevo كمسودة (بدون إرسال للمشتركين).')
                ->action(function () {
                    $id = app(NewsletterIssues::class)->createDraft(null, auth()->id());
                    if ($id === null) {
                        Notification::make()->warning()->title('مسودة هذا الأسبوع موجودة.')->send();

                        return;
                    }
                    $this->redirect(NewsletterIssueResource::getUrl('edit', ['record' => $id]));
                }),
        ];
    }
}
