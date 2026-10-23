<?php

namespace App\Filament\Resources\BotQuestions;

use App\Chat\BotLibrary;
use App\Chat\BotStats;
use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\BotEntries\BotEntryResource;
use App\Filament\Resources\BotQuestions\Pages\ListBotQuestions;
use App\Models\BotMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The questions the chatbot could not answer (18.10). One click turns a question into an entry of the knowledge base
 * (it and the ones worded the same way leave the list); "ignore" takes it off the list.
 */
class BotQuestionResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = BotMessage::class;

    protected static ?string $slug = 'bot-questions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $modelLabel = 'سؤال';

    protected static ?string $pluralModelLabel = 'أسئلة بدون جواب';

    protected static ?string $navigationLabel = 'أسئلة بدون جواب';

    protected static string|\UnitEnum|null $navigationGroup = 'الشات بوت';

    protected static ?int $navigationSort = 76;

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', 'user')->whereNull('matched_entry_id')->whereNull('reviewed_at');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = self::getEloquentQuery()->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect(['bot_messages.*', 'times' => DB::table('bot_messages as o')->selectRaw('count(*)')
                ->where('o.role', 'user')->whereNull('o.matched_entry_id')->whereNull('o.reviewed_at')->whereRaw(BotStats::key('o.content').' = '.BotStats::key('bot_messages.content'))]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable(),
                TextColumn::make('content')->label('السؤال')->wrap()->searchable(),
                TextColumn::make('times')->label('مرات'),
            ])
            ->recordActions([
                Action::make('answer')->label('حوّله لجواب')->icon('heroicon-o-check-badge')->modalHeading('جواب جديد في قاعدة المعرفة')
                    ->modalDescription(fn (BotMessage $record) => 'السؤال: '.$record->content)
                    ->schema(BotEntryResource::fields(withQuestion: false))
                    ->action(function (BotMessage $record, array $data, Action $action) {
                        try {
                            app(BotLibrary::class)->answerQuestion((int) $record->id, $data, auth()->id());
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                            $action->halt();
                        }
                        Notification::make()->success()->title('صار للسؤال جواب في قاعدة المعرفة.')->send();
                    }),
                Action::make('ignore')->label('تجاهل')->icon('heroicon-o-x-mark')->color('gray')
                    ->action(fn (BotMessage $record) => app(BotLibrary::class)->markHandled((string) $record->content)),
            ])
            ->toolbarActions([
                BulkAction::make('ignore')->label('تجاهل المحدد')->icon('heroicon-o-x-mark')->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each(fn (BotMessage $m) => app(BotLibrary::class)->markHandled((string) $m->content))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBotQuestions::route('/')];
    }
}
