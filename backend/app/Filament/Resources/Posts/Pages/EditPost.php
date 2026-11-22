<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Content\PostPreview;
use App\Content\PostRuleViolation;
use App\Content\PostSaver;
use App\Content\Subtypes\SubtypeRegistry;
use App\Filament\Concerns\ReportsPostRuleViolations;
use App\Filament\Concerns\SavesSeoMeta;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Seo\SeoMetaSaver;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The editor. Saving goes through PostSaver, which also keeps the revisions, the redirect, the body references
 * and the kind's own facts. A draft is saved by itself every thirty seconds while its text changes (decision 21.7);
 * a published item never is, because that would change the live article before the editor chose to.
 */
class EditPost extends EditRecord
{
    use ReportsPostRuleViolations, SavesSeoMeta {
        SavesSeoMeta::afterSave as private saveSeo;
    }

    protected static string $resource = PostResource::class;

    /** The item's state when the save began: a save that changes it opens the page again (afterSave). */
    private ?string $statusBeforeSave = null;

    protected function beforeSave(): void
    {
        $this->statusBeforeSave = $this->getRecord()->status;
    }

    /** What the database last said about this item; handed back on every save to notice another editor's change. */
    public ?string $saveToken = null;

    /** The fields autosave is allowed to carry; everything else waits for an explicit save. */
    private const AUTOSAVED = ['title', 'subtitle', 'excerpt', 'body'];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->saveToken = app(PostSaver::class)->token($this->getRecord());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Post $post */
        $post = $this->getRecord();

        $data['authors'] = $post->authors()->pluck('authors.id')->map(fn ($id) => (string) $id)->all();
        $data['tags'] = $post->tags()->orderBy('tags.name_ar')->pluck('name_ar')->all();
        $data['gallery'] = DB::table('post_gallery_items')->where('post_id', $post->id)->orderBy('sort_order')->pluck('media_id')->map(fn ($id) => ['media_id' => (string) $id])->all();
        $data['awareness_cards'] = DB::table('post_related')->join('posts', 'posts.id', '=', 'post_related.related_post_id')->where('post_related.post_id', $post->id)
            ->where('posts.kind_code', 'awareness')->orderBy('post_related.sort_order')->pluck('posts.id')->map(fn ($id) => (string) $id)->all();
        $data['guests'] = DB::table('post_guests')->where('post_id', $post->id)->orderBy('sort_order')->get(['guest_id', 'role_code'])->map(fn ($g) => ['guest_id' => (string) $g->guest_id, 'role_code' => $g->role_code])->all();

        if ($subtype = app(SubtypeRegistry::class)->for($post->kind_code)) {
            $data[$post->kind_code] = $subtype->load($post->id);
        }

        $data['seo'] = app(SeoMetaSaver::class)->load($post);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->takeSeo($data);
        $data['gallery'] = array_values(array_filter(array_map(fn ($item) => ((array) $item)['media_id'] ?? null, (array) ($data['gallery'] ?? []))));
        $data['guests'] = array_values((array) ($data['guests'] ?? []));

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->reportingViolations(fn () => app(PostSaver::class)->save($record, $data, Filament::auth()->id(), $this->saveToken));
    }

    protected function afterSave(): void
    {
        $this->saveSeo();   // the SEO part, after the item itself

        $this->saveToken = app(PostSaver::class)->token($this->getRecord());
        $this->fillForm();   // show what was really stored: the cleaned text, the address that was made
        $this->dispatch('post-saved');

        // The bar's buttons follow the stored state (getFormActions), and the page keeps its layout for the whole
        // request: after publishing or scheduling, it is built again from the new state. The bar carries the state in its
        // wire:key, so the page replaces the old bar instead of reshaping its buttons one into another.
        if ($this->getRecord()->status !== $this->statusBeforeSave) {
            unset($this->cachedSchemas['content']);
        }
    }

    /** Called by the page every 30 seconds while a draft's text has changed. Not validated: a half-written draft is fine. */
    public function autosave(): void
    {
        $post = $this->getRecord();

        if ($post->status !== 'draft') {
            return;
        }

        try {
            $state = Arr::only($this->dehydratedState(), self::AUTOSAVED);
            $saved = app(PostSaver::class)->autosave($post, $state, Filament::auth()->id(), $this->saveToken);
            $this->saveToken = app(PostSaver::class)->token($saved);
            $this->dispatch('post-autosaved', at: now()->setTimezone('Asia/Hebron')->format('H:i:s'));
        } catch (PostRuleViolation $e) {
            $this->dispatch('post-autosave-failed', message: $e->getMessage());
        }
    }

    /**
     * The form's values as they will be stored (the rich editor's body as HTML), without validating: a half-written
     * draft is exactly what autosave is for.
     *
     * @return array<string, mixed>
     */
    private function dehydratedState(): array
    {
        $state = ['data' => (array) $this->form->getRawState()];
        $this->form->dehydrateState($state);
        $this->form->mutateDehydratedState($state);

        return $state['data'];
    }

    /** The bar stays at the bottom of the screen while the form scrolls, on a phone too. */
    public static bool $formActionsAreSticky = true;

    /**
     * The bar under the form, by the item's state as stored: a draft is published, scheduled or kept as a draft; a
     * scheduled, published or archived item is updated, and the first and last can also go live at once. Ctrl+S is
     * always the save button, so it never publishes. The bar starts with what has happened to the editor's changes.
     */
    protected function getFormActions(): array
    {
        $status = $this->getRecord()->status;
        $save = $this->getSaveFormAction()
            ->label(fn () => $status !== 'draft' ? 'تحديث' : (($this->data['status'] ?? 'draft') === 'draft' ? 'حفظ كمسودة' : 'حفظ'))
            ->color($status === 'draft' ? 'gray' : 'primary');

        return array_values(array_filter([
            Action::make('saveState')->view('filament.post-save-state', fn () => ['status' => Post::STATUSES[$status] ?? $status, 'tone' => $status]),
            $status === 'draft' ? $this->publishAction('نشر', 'primary') : null,
            $status === 'draft' ? $this->scheduleAction() : null,
            $save,
            in_array($status, ['scheduled', 'archived'], true) ? $this->publishAction($status === 'archived' ? 'نشر' : 'نشر الآن', 'gray') : null,
            // A phone has its own way back; the bar keeps to one line there (theme.css).
            $this->getCancelFormAction()->extraAttributes(['class' => 'raqam-bar-cancel']),
        ]));
    }

    public function getFormActionsContentComponent(): Component
    {
        return parent::getFormActionsContentComponent()->extraAttributes(['wire:key' => 'post-bar-'.$this->getRecord()->status]);
    }

    private function publishAction(string $label, string $color): Action
    {
        return Action::make('publishNow')->label($label)->icon(Heroicon::OutlinedPaperAirplane)->color($color)
            ->requiresConfirmation()->modalHeading(fn () => 'نشر «'.$this->getRecord()->title.'»')
            ->modalDescription('تُحفظ التعديلات وتُنشر المادة فوراً.')
            ->action(fn (Action $action) => $this->saveFromAction($action, ['status' => 'published'], 'تعذّر النشر'));
    }

    private function scheduleAction(): Action
    {
        return Action::make('schedule')->label('جدولة…')->icon(Heroicon::OutlinedClock)->color('gray')
            ->modalHeading(fn () => 'جدولة «'.$this->getRecord()->title.'»')
            // No timezone() here: the value goes into the form as typed (Palestine time), and the form's own date field
            // turns it into UTC once, on save. With timezone() it would be turned twice.
            ->schema([DateTimePicker::make('at')->label('وقت النشر')->seconds(false)->native(false)->displayFormat('Y-m-d H:i')
                ->required()->helperText('بتوقيت فلسطين، بعد دقيقة من الآن على الأقل.')])
            ->modalDescription('تُحفظ التعديلات وتُنشر المادة تلقائياً في هذا الوقت.')->modalSubmitActionLabel('جدولة')
            ->action(fn (array $data, Action $action) => $this->saveFromAction($action, ['status' => 'scheduled', 'published_at' => $data['at']], 'تعذّرت الجدولة'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('breaking')->label('عاجل')->icon(Heroicon::OutlinedBolt)->color('danger')
                ->schema([Select::make('hours')->label('مدة العاجل')->native(false)->options([1 => 'ساعة', 3 => '3 ساعات', 6 => '6 ساعات', 12 => '12 ساعة', 24 => '24 ساعة'])->default(3)->required()])
                ->modalDescription('تُحفظ التعديلات ويظهر الخبر في شريط العاجل حتى انتهاء المدة.')->modalSubmitActionLabel('جعله عاجلاً')
                ->action(fn (array $data, Action $action) => $this->saveFromAction($action, ['breaking_until' => now('Asia/Hebron')->addHours((int) $data['hours'])->format('Y-m-d H:i:s')], 'تعذّر جعل الخبر عاجلاً')),
            Action::make('preview')->label('معاينة')->icon(Heroicon::OutlinedEye)
                ->schema([Select::make('hours')->label('مدة صلاحية الرابط')->native(false)->options(PostPreview::HOURS)->default(48)->required()])
                ->modalDescription('رابط سري يفتح المادة كما هي الآن، على الكمبيوتر أو الجوال، دون تسجيل دخول. لا تشاركه إلا مع من يجب أن يراها.')->modalSubmitActionLabel('إنشاء الرابط')
                ->action(function (array $data) {
                    $url = app(PostPreview::class)->url($this->getRecord(), (int) $data['hours']);
                    Notification::make()->success()->persistent()->title('رابط المعاينة')->body($url)
                        ->actions([Action::make('open')->label('فتح')->url($url, shouldOpenInNewTab: true)])->send();
                }),
            Action::make('revisions')->label('النسخ السابقة')->icon(Heroicon::OutlinedClock)->color('gray')
                ->schema(fn () => [
                    Select::make('revision_no')->label('النسخة')->native(false)->required()->live()
                        ->options(fn () => DB::table('post_revisions')->leftJoin('users', 'users.id', '=', 'post_revisions.created_by')
                            ->where('post_id', $this->getRecord()->id)->orderByDesc('revision_no')->limit(100)
                            ->get(['revision_no', 'post_revisions.created_at', 'users.name', 'title'])
                            ->mapWithKeys(fn ($r) => [$r->revision_no => '#'.$r->revision_no.' — '.Carbon::parse($r->created_at)->setTimezone('Asia/Hebron')->format('Y-m-d H:i').' — '.($r->name ?? 'النظام')])->all()),
                    TextEntry::make('revision_text')->hiddenLabel()
                        ->state(fn (Get $get) => ($no = $get('revision_no'))
                            ? mb_substr(trim(strip_tags((string) DB::table('post_revisions')->where('post_id', $this->getRecord()->id)->where('revision_no', (int) $no)->value('body'))), 0, 600)
                            : 'اختر نسخة لمعاينة نصها.'),
                ])
                ->modalDescription('يُحفظ النص الحالي كنسخة قبل الاسترجاع، فيمكن التراجع.')->modalSubmitActionLabel('استرجاع هذه النسخة')
                ->action(function (array $data, Action $action) {
                    try {
                        $this->reportingViolations(fn () => app(PostSaver::class)->restoreRevision($this->getRecord(), (int) $data['revision_no'], Filament::auth()->id()));
                    } catch (ValidationException $e) {
                        // The rules refuse this text for this item (an empty body on a published one, say): say so and close the dialog.
                        Notification::make()->danger()->persistent()->title('تعذّر استرجاع النسخة')->body(collect($e->errors())->flatten()->unique()->take(3)->implode(' — '))->send();
                        $action->cancel();
                    } catch (Halt) {
                        $action->cancel();
                    }
                    $this->saveToken = app(PostSaver::class)->token($this->getRecord());
                    $this->fillForm();
                    $this->dispatch('post-saved');
                    Notification::make()->success()->title('استُرجعت النسخة.')->send();
                }),
            Action::make('trash')->label('نقل إلى السلة')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->requiresConfirmation()->modalDescription('تبقى في السلة 90 يوماً ويمكن استرجاعها.')
                ->action(function () {
                    app(PostSaver::class)->trash($this->getRecord());
                    Notification::make()->success()->title('نُقلت إلى السلة.')->send();
                    $this->redirect(PostResource::getUrl('index'));
                }),
        ];
    }

    /**
     * Saves the form with a few values set by a header button. If the rules refuse the save, an error raised inside
     * a button's own dialog would leave that dialog open and say nothing: so the values are put back, the reasons
     * are shown under their fields and in a notification, and the dialog is closed.
     *
     * @param  array<string, mixed>  $changes
     */
    private function saveFromAction(Action $action, array $changes, string $failedTitle): void
    {
        $before = Arr::only($this->data, array_keys($changes));
        $this->data = array_merge($this->data, $changes);

        try {
            $this->save();
        } catch (ValidationException $e) {
            $this->data = array_merge($this->data, $before);
            $this->setErrorBag($e->errors());
            Notification::make()->danger()->persistent()->title($failedTitle)->body(collect($e->errors())->flatten()->unique()->take(4)->implode(' — '))->send();
            $action->cancel();
        } catch (Halt) {
            // The reason was already shown (a notification from the content rules); only the dialog is left.
            $this->data = array_merge($this->data, $before);
            $action->cancel();
        }
    }

    public function getTitle(): string
    {
        return 'تحرير: '.$this->getRecord()->title;
    }
}
