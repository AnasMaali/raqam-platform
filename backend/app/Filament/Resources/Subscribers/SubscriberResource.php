<?php

namespace App\Filament\Resources\Subscribers;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Models\Subscriber;
use App\Newsletter\Subscribers;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** The newsletter's subscribers (19.9): who, since when and from where; export and delete. Nobody is added by hand. */
class SubscriberResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = Subscriber::class;

    protected static ?string $slug = 'subscribers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'مشترك';

    protected static ?string $pluralModelLabel = 'مشتركو النشرة';

    protected static ?string $navigationLabel = 'مشتركو النشرة';

    protected static string|\UnitEnum|null $navigationGroup = 'التواصل';

    protected static ?int $navigationSort = 71;

    protected static bool $isGloballySearchable = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('email')->label('البريد')->searchable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('name')->label('الاسم')->placeholder('—')->searchable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'bounced' => 'danger', default => 'gray'
                    })
                    ->formatStateUsing(fn (string $state) => Subscriber::STATUSES[$state] ?? $state),
                TextColumn::make('confirmed_at')->label('أكّد')->dateTime('Y-m-d H:i', 'Asia/Hebron')->placeholder('—')->sortable(),
                TextColumn::make('source_placement')->label('المكان')->formatStateUsing(fn (?string $state) => Subscriber::PLACEMENTS[$state] ?? $state),
                TextColumn::make('source_path')->label('الصفحة')->placeholder('—')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('subscribed_at')->label('طلب الاشتراك')->dateTime('Y-m-d H:i', 'Asia/Hebron')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('unsubscribed_at')->label('ألغى')->dateTime('Y-m-d H:i', 'Asia/Hebron')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(Subscriber::STATUSES),
                SelectFilter::make('source_placement')->label('المكان')->options(Subscriber::PLACEMENTS),
            ])
            ->recordActions([
                Action::make('delete')->label('حذف')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                    ->modalDescription('يُحذف المشترك وإثبات موافقته نهائياً من عندنا ومن Brevo.')
                    ->action(fn (Subscriber $record) => app(Subscribers::class)->delete($record->id, auth()->id())),
            ])
            ->toolbarActions([
                BulkAction::make('delete')->label('حذف المحدد')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                    ->modalDescription('يُحذف المشتركون المحددون نهائياً من عندنا ومن Brevo.')
                    ->action(fn (Collection $records) => $records->each(fn (Subscriber $s) => app(Subscribers::class)->delete($s->id, auth()->id()))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscribers::route('/')];
    }
}
