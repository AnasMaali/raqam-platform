<?php

namespace App\Filament\Resources\EmailLogs;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Mail\EmailCatalog;
use App\Models\EmailLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The e-mail log (20.9), read only: who got which message, and whether it went. 90 days. */
class EmailLogResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = EmailLog::class;

    protected static ?string $slug = 'email-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $modelLabel = 'رسالة مرسلة';

    protected static ?string $pluralModelLabel = 'سجل الإيميلات';

    protected static ?string $navigationLabel = 'سجل الإيميلات';

    protected static string|\UnitEnum|null $navigationGroup = 'النظام';

    protected static ?int $navigationSort = 81;

    protected static bool $isGloballySearchable = false;

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
        $names = collect(EmailCatalog::templates())->map(fn ($t) => $t['name'])->all();

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('queued_at')->label('الوقت')->dateTime('Y-m-d H:i:s', 'Asia/Hebron')->sortable(),
                TextColumn::make('recipient')->label('المستلم')->searchable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('template_key')->label('الرسالة')->formatStateUsing(fn (string $state) => $names[$state] ?? $state),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success', 'failed' => 'danger', default => 'warning'
                    })
                    ->formatStateUsing(fn (string $state) => EmailLog::STATUSES[$state] ?? $state),
                TextColumn::make('attempts')->label('المحاولات'),
                TextColumn::make('error')->label('الخطأ')->placeholder('—')->limit(80)->wrap()->toggleable(),
                TextColumn::make('sent_at')->label('أُرسلت')->dateTime('Y-m-d H:i:s', 'Asia/Hebron')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(EmailLog::STATUSES),
                SelectFilter::make('template_key')->label('الرسالة')->options($names),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEmailLogs::route('/')];
    }
}
