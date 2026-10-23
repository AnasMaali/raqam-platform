<?php

namespace App\Filament\Resources\AdminNotifications;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\AdminNotifications\Pages\ListAdminNotifications;
use App\Livewire\AdminBell;
use App\Models\AdminNotification;
use App\Panel\AdminAlerts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** Every alert of the signed-in admin (the bell's full list, 20.10); nobody sees another admin's. */
class AdminNotificationResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = AdminNotification::class;

    protected static ?string $slug = 'notifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $modelLabel = 'إشعار';

    protected static ?string $pluralModelLabel = 'الإشعارات';

    protected static ?string $navigationLabel = 'الإشعارات';

    protected static string|\UnitEnum|null $navigationGroup = 'النظام';

    protected static ?int $navigationSort = 79;

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', (int) Filament::auth()->id());
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable(),
                TextColumn::make('category')->label('النوع')->badge()->formatStateUsing(fn (string $state) => AdminAlerts::CATEGORIES[$state] ?? $state),
                TextColumn::make('title')->label('التنبيه')->wrap()->searchable()->weight(fn (AdminNotification $r) => $r->read_at ? null : 'bold'),
                TextColumn::make('body')->label('التفاصيل')->wrap()->limit(160)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('category')->label('النوع')->options(AdminAlerts::CATEGORIES),
                TernaryFilter::make('read_at')->label('مقروء')->nullable()->trueLabel('مقروءة')->falseLabel('غير مقروءة'),
            ])
            ->recordActions([
                Action::make('open')->label('فتح')->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (AdminNotification $r) => AdminBell::url($r->action_path) !== null)
                    ->action(function (AdminNotification $r) {
                        AdminAlerts::markRead((int) Filament::auth()->id(), $r->id);

                        return redirect(AdminBell::url($r->action_path));
                    }),
                Action::make('read')->label('تعليم كمقروء')->icon('heroicon-o-check')->visible(fn (AdminNotification $r) => $r->read_at === null)
                    ->action(fn (AdminNotification $r) => AdminAlerts::markRead((int) Filament::auth()->id(), $r->id)),
            ])
            ->toolbarActions([
                BulkAction::make('read')->label('تعليم المحدد كمقروء')->icon('heroicon-o-check')
                    ->action(fn (Collection $records) => $records->each(fn (AdminNotification $r) => AdminAlerts::markRead((int) Filament::auth()->id(), $r->id))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAdminNotifications::route('/')];
    }
}
