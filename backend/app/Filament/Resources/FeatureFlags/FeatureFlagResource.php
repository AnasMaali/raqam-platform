<?php

namespace App\Filament\Resources\FeatureFlags;

use App\Filament\Resources\FeatureFlags\Pages\ListFeatureFlags;
use App\Models\FeatureFlag;
use App\Settings\FeatureFlagRefused;
use App\Settings\FeatureFlags;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Feature switches (21.14): booking, payments, accounts, chatbot, ads. A list with a switch on each row. */
class FeatureFlagResource extends Resource
{
    protected static ?string $model = FeatureFlag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $modelLabel = 'مفتاح';

    protected static ?string $pluralModelLabel = 'مفاتيح التشغيل';

    protected static ?string $navigationLabel = 'مفاتيح التشغيل';

    protected static ?int $navigationSort = 81;

    public static function canCreate(): bool
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
            ->defaultSort('key')
            ->paginated(false)
            ->columns([
                TextColumn::make('description_ar')->label('الميزة')->wrap(),
                TextColumn::make('depends_on')->label('تتطلب')
                    ->formatStateUsing(fn (?string $state) => $state ? FeatureFlag::query()->whereKey($state)->value('description_ar') : '—')
                    ->wrap(),
                ToggleColumn::make('is_enabled')->label('مفعّلة')
                    ->updateStateUsing(function (FeatureFlag $record, bool $state) {
                        try {
                            app(FeatureFlags::class)->set($record->key, $state, Filament::auth()->id());
                        } catch (FeatureFlagRefused $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                            return ['error' => $e->getMessage()];
                        }

                        return $state;
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFeatureFlags::route('/')];
    }
}
