<?php

namespace App\Filament\Resources\NotFoundPaths;

use App\Analytics\MissingPages;
use App\Filament\Resources\NotFoundPaths\Pages\ListNotFoundPaths;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Models\NotFoundPath;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Pages the site's visitors reached and did not find (22.10), most reached first, so each can be redirected (it opens
 * the redirects screen with the old address filled in) or marked handled. Read only otherwise: the counter writes them.
 */
class NotFoundPathResource extends Resource
{
    protected static ?string $model = NotFoundPath::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $modelLabel = 'صفحة غير موجودة';

    protected static ?string $pluralModelLabel = 'صفحات غير موجودة (404)';

    protected static ?string $navigationLabel = 'صفحات غير موجودة (404)';

    protected static string|\UnitEnum|null $navigationGroup = 'الإحصائيات';

    protected static ?int $navigationSort = 6;

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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('hits', 'desc')
            ->columns([
                TextColumn::make('path')->label('العنوان')->searchable()->copyable()->copyMessage('نُسخ العنوان')->wrap()
                    ->extraAttributes(['dir' => 'ltr', 'style' => 'word-break:break-all']),
                TextColumn::make('hits')->label('الزوار')->numeric()->sortable(),
                TextColumn::make('first_seen_at')->label('أول مرة')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable()->toggleable(),
                TextColumn::make('last_seen_at')->label('آخر مرة')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable(),
                TextColumn::make('resolved_at')->label('الحالة')->badge()
                    ->state(fn (NotFoundPath $r) => $r->resolved_at === null ? 'مفتوحة' : ($r->last_seen_at > $r->resolved_at ? 'عُولجت وما زالت تُزار' : 'عُولجت'))
                    ->color(fn (string $state) => match ($state) {
                        'مفتوحة' => 'danger', 'عُولجت' => 'success', default => 'warning'
                    }),
            ])
            ->filters([
                SelectFilter::make('state')->label('الحالة')->default('open')
                    ->options(['open' => 'مفتوحة', 'resolved' => 'عُولجت'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('resolved_at'),
                        'resolved' => $query->whereNotNull('resolved_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('redirect')->label('اعمل تحويل')->icon('heroicon-o-arrow-uturn-left')
                    ->url(fn (NotFoundPath $r) => RedirectResource::getUrl('index', ['tableAction' => 'create', 'tableActionArguments' => ['from' => $r->path]])),
                Action::make('resolve')->label('تمت المعالجة')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (NotFoundPath $r) => $r->resolved_at === null)
                    ->action(function (NotFoundPath $r) {
                        MissingPages::resolve($r->path);
                        Notification::make()->success()->title('عُلّمت الصفحة كمعالَجة.')->send();
                    }),
                Action::make('reopen')->label('إعادة فتح')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (NotFoundPath $r) => $r->resolved_at !== null)
                    ->action(fn (NotFoundPath $r) => MissingPages::reopen($r->path)),
            ])
            ->toolbarActions([
                BulkAction::make('resolveMany')->label('تمت المعالجة')->icon('heroicon-o-check')
                    ->action(fn (Collection $records) => MissingPages::resolve(...$records->pluck('path')->all()))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotFoundPaths::route('/')];
    }
}
