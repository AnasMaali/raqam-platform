<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The audit trail (22.14 – 22.16), read only: nobody can add to it from here, change it or delete it,
 * and the database would refuse if they could.
 */
class AuditLogResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'عملية';

    protected static ?string $pluralModelLabel = 'سجل العمليات';

    protected static ?string $navigationLabel = 'سجل العمليات';

    protected static ?int $navigationSort = 90;

    protected static string|\UnitEnum|null $navigationGroup = 'النظام';

    protected static ?string $recordTitleAttribute = 'id';

    public const ACTIONS = [
        'create' => 'إضافة',
        'update' => 'تعديل',
        'delete' => 'حذف',
        'restore' => 'استرجاع',
        'publish' => 'نشر',
        'unpublish' => 'إلغاء نشر',
        'login' => 'تسجيل دخول',
        'logout' => 'تسجيل خروج',
        'settings' => 'تغيير إعدادات',
        'export' => 'تصدير',
    ];

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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('العملية')->columns(2)->schema([
                TextEntry::make('occurred_at')->label('الوقت')->dateTime('Y-m-d H:i:s', 'Asia/Hebron'),
                TextEntry::make('action')->label('النوع')->formatStateUsing(fn (string $state) => self::ACTIONS[$state] ?? $state),
                TextEntry::make('actor.name')->label('المستخدم')->placeholder('النظام'),
                TextEntry::make('subject_table')->label('العنصر')
                    ->formatStateUsing(fn (AuditLog $record) => self::subject($record))->placeholder('—'),
                TextEntry::make('ip_hash')->label('بصمة العنوان (HMAC)')->limit(16)->placeholder('—'),
                TextEntry::make('user_agent')->label('المتصفح')->placeholder('—'),
            ]),
            Section::make('التغييرات')->schema([
                RepeatableEntry::make('changes')->hiddenLabel()->contained(false)->columns(3)->schema([
                    TextEntry::make('field')->label('الحقل'),
                    TextEntry::make('old_value')->label('قبل')->placeholder('—'),
                    TextEntry::make('new_value')->label('بعد')->placeholder('—'),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('actor'))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('الوقت')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable(),
                TextColumn::make('actor.name')->label('المستخدم')->placeholder('النظام')->searchable(),
                TextColumn::make('action')->label('النوع')->badge()
                    ->formatStateUsing(fn (string $state) => self::ACTIONS[$state] ?? $state),
                TextColumn::make('subject_table')->label('العنصر')
                    ->formatStateUsing(fn (AuditLog $record) => self::subject($record))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('actor_user_id')->label('المستخدم')
                    ->options(fn () => User::query()->where('user_type', 'staff')->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('action')->label('النوع')->options(self::ACTIONS),
                Filter::make('period')->label('الفترة')->schema([
                    DatePicker::make('from')->label('من'),
                    DatePicker::make('until')->label('إلى'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->where('occurred_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->where('occurred_at', '<', Carbon::parse($date)->addDay()))),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }

    private static function subject(AuditLog $record): string
    {
        return $record->subject_table === null ? '—' : $record->subject_table.' #'.$record->subject_id;
    }
}
