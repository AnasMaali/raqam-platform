<?php

namespace App\Filament\Resources\BotQuickReplies;

use App\Chat\KnowledgeBase;
use App\Filament\Actions\BulkRecords;
use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\BotQuickReplies\Pages\ManageBotQuickReplies;
use App\Models\BotQuickReply;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** The chatbot's buttons (18.6): the first ones when the chat opens, and the ones an answer offers after it. */
class BotQuickReplyResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = BotQuickReply::class;

    protected static ?string $slug = 'bot-buttons';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static ?string $modelLabel = 'زر';

    protected static ?string $pluralModelLabel = 'أزرار الشات بوت';

    protected static ?string $navigationLabel = 'الأزرار';

    protected static string|\UnitEnum|null $navigationGroup = 'الشات بوت';

    protected static ?int $navigationSort = 75;

    protected static bool $isGloballySearchable = false;

    public const ACTIONS = ['entry' => 'جواب من قاعدة المعرفة', 'service' => 'خدمة', 'page' => 'صفحة', 'url' => 'رابط', 'whatsapp' => 'واتساب',
        'rates' => 'أسعار العملات اليوم', 'weather' => 'الطقس الآن'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label_ar')->label('نص الزر')->required()->maxLength(60),
            Select::make('action_type')->label('عند الضغط')->options(self::ACTIONS)->required()->native(false)->live(),
            Select::make('target_entry_id')->label('الجواب')->native(false)->searchable()->visible(fn (Get $get) => $get('action_type') === 'entry')->required(fn (Get $get) => $get('action_type') === 'entry')
                ->options(fn () => DB::table('bot_entries as e')->leftJoin('faqs as f', 'f.id', '=', 'e.faq_id')->orderBy('e.id')
                    ->pluck(DB::raw('COALESCE(e.question, f.question)'), 'e.id')->all()),
            Select::make('target_service_id')->label('الخدمة')->native(false)->visible(fn (Get $get) => $get('action_type') === 'service')->required(fn (Get $get) => $get('action_type') === 'service')
                ->options(fn () => DB::table('services')->whereNull('deleted_at')->pluck('name_ar', 'id')->all()),
            Select::make('target_page_id')->label('الصفحة')->native(false)->visible(fn (Get $get) => $get('action_type') === 'page')->required(fn (Get $get) => $get('action_type') === 'page')
                ->options(fn () => DB::table('pages')->pluck('title', 'id')->all()),
            TextInput::make('target_url')->label('الرابط')->url()->rule('starts_with:https://')->maxLength(2048)
                ->visible(fn (Get $get) => $get('action_type') === 'url')->required(fn (Get $get) => $get('action_type') === 'url'),
            Toggle::make('is_root')->label('يظهر عند فتح المحادثة'),
            Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')->defaultSort('sort_order')
            ->columns([
                TextColumn::make('label_ar')->label('الزر')->searchable(),
                TextColumn::make('action_type')->label('عند الضغط')->formatStateUsing(fn (string $state) => self::ACTIONS[$state] ?? $state),
                IconColumn::make('is_root')->label('عند الفتح')->boolean(),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label('مفعّل'), TernaryFilter::make('is_root')->label('عند الفتح')])
            ->toolbarActions([...array_map(fn ($a) => $a->after(fn () => KnowledgeBase::changed()), BulkRecords::toggle('is_active', 'تفعيل المحدد', 'إيقاف المحدد')),
                BulkRecords::delete()->after(fn () => KnowledgeBase::changed())])
            ->recordActions([
                EditAction::make()->label('تعديل')->mutateDataUsing(fn (array $data) => self::clean($data))->after(fn () => KnowledgeBase::changed()),
                DeleteAction::make()->label('حذف')->after(fn () => KnowledgeBase::changed()),
            ]);
    }

    /** Only the target of the chosen action is kept (the table's CHECKs refuse two). */
    public static function clean(array $data): array
    {
        $keep = ['entry' => 'target_entry_id', 'service' => 'target_service_id', 'page' => 'target_page_id', 'url' => 'target_url'][$data['action_type'] ?? ''] ?? null;
        foreach (['target_entry_id', 'target_service_id', 'target_page_id', 'target_url'] as $field) {
            if ($field !== $keep) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    public static function getPages(): array
    {
        return ['index' => ManageBotQuickReplies::route('/')];
    }
}
