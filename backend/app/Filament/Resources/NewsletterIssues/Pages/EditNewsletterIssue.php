<?php

namespace App\Filament\Resources\NewsletterIssues\Pages;

use App\Filament\Resources\NewsletterIssues\NewsletterIssueResource;
use App\Models\NewsletterIssue;
use App\Newsletter\BrevoClient;
use App\Newsletter\BrevoUnavailable;
use App\Newsletter\NewsletterIssues;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class EditNewsletterIssue extends EditRecord
{
    protected static string $resource = NewsletterIssueResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['posts'] = DB::table('newsletter_issue_posts')->where('issue_id', $this->getRecord()->getKey())->orderBy('sort_order')->pluck('post_id')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(NewsletterIssues::class)->save((int) $record->getKey(), (string) ($data['subject'] ?? ''), $data['intro'] ?? null, array_values((array) ($data['posts'] ?? [])), auth()->id());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $field) => ['data.'.$field => $m])->all());
        }

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        /** @var NewsletterIssue $issue */
        $issue = $this->getRecord();

        return [
            Action::make('preview')->label('معاينة')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')->modalWidth('4xl')
                // The campaign in a sandboxed frame: it is a whole e-mail document, and nothing in it may run.
                ->modalContent(fn () => new HtmlString('<iframe sandbox="" style="width:100%;height:70vh;border:0" srcdoc="'
                    .e(app(NewsletterIssues::class)->html((int) $issue->getKey())).'"></iframe>')),
            Action::make('brevo')->label($issue->brevo_campaign_id ? 'تحديث المسودة في Brevo' : 'إرسال إلى Brevo كمسودة')->icon('heroicon-o-cloud-arrow-up')
                ->visible(fn () => $issue->status !== 'sent')->requiresConfirmation()
                ->modalDescription('تصل إلى Brevo كمسودة فقط؛ الإرسال للمشتركين من Brevo بعد مراجعتها.')
                ->action(function () use ($issue) {
                    if (! BrevoClient::configured()) {
                        Notification::make()->warning()->title('Brevo غير مربوط بعد (المفتاح أو رقم القائمة).')->send();

                        return;
                    }
                    try {
                        app(NewsletterIssues::class)->pushToBrevo((int) $issue->getKey());
                        Notification::make()->success()->title('المسودة في Brevo الآن.')->send();
                    } catch (BrevoUnavailable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                    $this->refreshFormData(['status']);
                }),
            Action::make('stats')->label('الأرقام من Brevo')->icon('heroicon-o-chart-bar')->visible(fn () => (bool) $issue->brevo_campaign_id)
                ->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
                ->modalContent(function () use ($issue) {
                    $s = app(NewsletterIssues::class)->stats((int) $issue->getKey(), fresh: true);

                    return new HtmlString($s === null ? '<p>تعذّر الوصول إلى Brevo الآن.</p>'
                        : '<ul><li>الحالة في Brevo: '.e($s['status']).'</li><li>وصلت: '.$s['delivered'].'</li><li>فتح: '.$s['opens']
                            .'</li><li>نقر: '.$s['clicks'].'</li><li>إلغاء اشتراك: '.$s['unsubscribes'].'</li></ul>');
                }),
        ];
    }
}
