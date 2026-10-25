<?php

namespace App\Filament\Resources\BotEntries;

use App\Chat\BotLibrary;
use App\Chat\KnowledgeBase;
use App\Filament\Actions\BulkRecords;
use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\BotEntries\Pages\CreateBotEntry;
use App\Filament\Resources\BotEntries\Pages\EditBotEntry;
use App\Filament\Resources\BotEntries\Pages\ListBotEntries;
use App\Models\BotEntry;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The chatbot's knowledge base (18.3): a question in several wordings, keywords, the answer with links, an action and
 * the buttons shown after it. Entries made from the FAQ page keep their question and answer there.
 */
class BotEntryResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = BotEntry::class;

    protected static ?string $slug = 'bot-entries';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'جواب';

    protected static ?string $pluralModelLabel = 'قاعدة المعرفة';

    protected static ?string $navigationLabel = 'قاعدة المعرفة';

    protected static string|\UnitEnum|null $navigationGroup = 'الشات بوت';

    protected static ?int $navigationSort = 73;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::fields(fn (Get $get) => (bool) $get('faq_id')));
    }

    /** @return list<mixed> the entry's fields; also used when an unanswered question becomes an entry */
    public static function fields(?callable $fromFaq = null, bool $withQuestion = true): array
    {
        $fromFaq ??= fn () => false;

        return array_values(array_filter([
            Hidden::make('faq_id')->dehydrated(false),
            Text::make('هذا الجواب من «الأسئلة الشائعة»: السؤال والجواب يُعدّلان هناك، وهنا الصيغ والكلمات والإجراء.')->visible($fromFaq)->columnSpanFull(),
            $withQuestion ? TextInput::make('question')->label('السؤال')->maxLength(300)->required(fn (Get $get) => ! $fromFaq($get))->hidden($fromFaq)->columnSpanFull() : null,
            RichEditor::make('answer')->label('الجواب')->required(fn (Get $get) => ! $fromFaq($get))->hidden($fromFaq)->columnSpanFull()
                ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                ->helperText('الروابط: https:// أو مسار من الموقع مثل /services.'),
            TagsInput::make('variants')->label('صيغ أخرى للسؤال')->splitKeys(['Enter', 'Tab'])->columnSpanFull()
                ->helperText('كيف ممكن يسأل الزائر نفس السؤال. اكتب الصيغة واضغط Enter.'),
            TagsInput::make('keywords')->label('كلمات مفتاحية')->splitKeys(['Enter', 'Tab', '،', ','])->columnSpanFull()
                ->helperText('كلمة في السؤال تكفي لاختيار هذا الجواب (الهمزة والتاء المربوطة و«ال» لا تفرق).'),
            Select::make('action_type')->label('إجراء مع الجواب')->options(BotLibrary::ACTIONS)->default('none')->required()->native(false)->live(),
            Select::make('action_service_id')->label('الخدمة')->native(false)->visible(fn (Get $get) => $get('action_type') === 'service')
                ->options(fn () => DB::table('services')->whereNull('deleted_at')->orderBy('name_ar')->pluck('name_ar', 'id')->all()),
            Select::make('action_page_id')->label('الصفحة')->native(false)->visible(fn (Get $get) => $get('action_type') === 'page')
                ->options(fn () => DB::table('pages')->orderBy('title')->pluck('title', 'id')->all()),
            TextInput::make('action_url')->label('الرابط')->url()->maxLength(2048)->visible(fn (Get $get) => $get('action_type') === 'url'),
            Select::make('followups')->label('أزرار بعد الجواب')->multiple()->native(false)->maxItems(6)
                ->options(fn () => DB::table('bot_quick_replies')->where('is_active', true)->orderBy('sort_order')->pluck('label_ar', 'id')->all()),
            Toggle::make('is_active')->label('مفعّل')->default(true),
        ]));
    }

    /** @return array<string, mixed> an entry as the form shows it */
    public static function formData(BotEntry $entry): array
    {
        return [
            'faq_id' => $entry->faq_id, 'question' => $entry->question, 'answer' => $entry->answer, 'is_active' => (bool) $entry->is_active,
            'variants' => DB::table('bot_entry_variants')->where('entry_id', $entry->id)->orderBy('phrase')->pluck('phrase')->all(),
            'keywords' => DB::table('bot_entry_keywords')->where('entry_id', $entry->id)->orderBy('keyword')->pluck('keyword')->all(),
            'action_type' => $entry->action_type, 'action_service_id' => $entry->action_service_id, 'action_page_id' => $entry->action_page_id, 'action_url' => $entry->action_url,
            'followups' => DB::table('bot_entry_quick_replies')->where('entry_id', $entry->id)->orderBy('sort_order')->pluck('quick_reply_id')->all(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->leftJoin('faqs', 'faqs.id', '=', 'bot_entries.faq_id')
                ->addSelect(['bot_entries.*', DB::raw('COALESCE(bot_entries.question, faqs.question) AS shown_question')]))
            ->defaultSort('bot_entries.id', 'desc')
            ->columns([
                TextColumn::make('shown_question')->label('السؤال')->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereRaw('normalize_ar(COALESCE(bot_entries.question, faqs.question)) ILIKE \'%\' || normalize_ar(?) || \'%\'', [$search])),
                TextColumn::make('faq_id')->label('المصدر')->formatStateUsing(fn ($state) => $state ? 'الأسئلة الشائعة' : 'قاعدة المعرفة')->badge()->placeholder('قاعدة المعرفة'),
                TextColumn::make('action_type')->label('الإجراء')->formatStateUsing(fn (string $state) => BotLibrary::ACTIONS[$state] ?? $state),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->filters([SelectFilter::make('action_type')->label('الإجراء')->options(BotLibrary::ACTIONS)])
            ->toolbarActions([BulkRecords::delete(fn (BotEntry $record) => $record->faq_id === null)->after(fn () => KnowledgeBase::changed())])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->visible(fn (BotEntry $record) => $record->faq_id === null)
                    ->after(fn () => KnowledgeBase::changed()),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        return $record->faq_id === null;
    }

    public static function getPages(): array
    {
        return ['index' => ListBotEntries::route('/'), 'create' => CreateBotEntry::route('/create'), 'edit' => EditBotEntry::route('/{record}/edit')];
    }
}
