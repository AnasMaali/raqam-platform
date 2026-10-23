<?php

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Mail\EmailCatalog;
use App\Mail\EmailTemplates;
use App\Mail\SystemMail;
use App\Models\EmailTemplate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    public function getTitle(): string
    {
        return EmailCatalog::get($this->getRecord()->getKey())['name'];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var EmailTemplate $record */
        try {
            return EmailTemplates::save($record->key, (string) ($data['subject'] ?? ''), (string) ($data['body_html'] ?? ''),
                EmailCatalog::isLocked($record->key) || (bool) ($data['is_active'] ?? false), auth()->id());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $field) => ['data.'.$field => $m])->all());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('معاينة')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                ->modalHeading(fn () => EmailTemplateResource::sampleEmail($this->getRecord()->getKey())->rendered()['subject'])
                // The saved text with made-up values; every value is escaped by the renderer, the text was cleaned on save.
                ->modalContent(fn () => new HtmlString('<div dir="rtl" style="line-height:1.8">'
                    .EmailTemplateResource::sampleEmail($this->getRecord()->getKey())->rendered()['html'].'</div>')),
            Action::make('test')->label('إرسال تجربة لي')->icon('heroicon-o-paper-airplane')->requiresConfirmation()
                ->modalDescription('تُرسل الرسالة المحفوظة بقيم تجريبية إلى بريدك.')
                ->action(function () {
                    $key = $this->getRecord()->getKey();
                    if (! EmailTemplates::isActive($key)) {
                        Notification::make()->warning()->title('الرسالة مطفأة؛ فعّلها أولاً.')->send();

                        return;
                    }
                    SystemMail::queue(EmailTemplateResource::sampleEmail($key), (string) auth()->user()->email);
                    Notification::make()->success()->title('أُرسلت إلى بريدك.')->send();
                }),
        ];
    }
}
