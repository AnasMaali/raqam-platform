<?php

namespace App\Filament\Resources\AuditLogs;

use App\Audit\AuditSubjects;
use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\TrackingHash;
use App\Support\UserAgentSummary;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\Facades\DB;

/**
 * The audit trail (22.14 – 22.17), read only: who, when, from where (the address only as an HMAC, and the browser),
 * what, and each field before and after. Nobody can add to it from here, change it or delete it: the resource has no
 * such page or action, the models refuse to save, and the database refuses UPDATE and DELETE outright (except the
 * pruning of rows older than two years, audit:prune).
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

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('العملية')->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('occurred_at')->label('الوقت (بتوقيت فلسطين)')->dateTime('Y-m-d H:i:s', 'Asia/Hebron'),
                TextEntry::make('action')->label('النوع')->formatStateUsing(fn (string $state) => self::ACTIONS[$state] ?? $state),
                TextEntry::make('actor.name')->label('المستخدم')->placeholder('النظام'),
                TextEntry::make('subject_table')->label('العنصر')
                    ->formatStateUsing(fn (AuditLog $record) => self::subject($record))->placeholder('—'),
                TextEntry::make('device')->label('من أين: الجهاز')->state(fn (AuditLog $record) => self::device($record->user_agent))->placeholder('—'),
                TextEntry::make('ip_hash')->label('من أين: بصمة العنوان (HMAC، لا يُحفظ العنوان نفسه)')->limit(16)->placeholder('—')
                    ->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('user_agent')->label('المتصفح كما أرسله')->placeholder('—')->columnSpanFull()->extraAttributes(['dir' => 'ltr']),
            ]),
            Section::make('التغييرات: قبل وبعد')->schema([
                RepeatableEntry::make('changes')->hiddenLabel()->contained(false)->columns(['default' => 1, 'md' => 3])->schema([
                    TextEntry::make('field')->label('الحقل')->extraAttributes(['dir' => 'ltr']),
                    TextEntry::make('old_value')->label('قبل')->placeholder('—'),
                    TextEntry::make('new_value')->label('بعد')->placeholder('—'),
                ])->placeholder('لا حقول لهذه العملية.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('actor'))
            ->defaultSort('occurred_at', 'desc')
            ->searchPlaceholder('ابحث: مستخدم، عنصر، رقم، حقل، قيمة قبل أو بعد')
            ->columns([
                TextColumn::make('occurred_at')->label('الوقت')->dateTime('Y-m-d H:i', 'Asia/Hebron')->sortable(),
                TextColumn::make('actor.name')->label('المستخدم')->placeholder('النظام')
                    ->searchable(query: fn (Builder $query, string $search) => self::search($query, $search)),
                TextColumn::make('action')->label('النوع')->badge()
                    ->formatStateUsing(fn (string $state) => self::ACTIONS[$state] ?? $state),
                TextColumn::make('subject_table')->label('العنصر')
                    ->formatStateUsing(fn (AuditLog $record) => self::subject($record))->placeholder('—'),
                TextColumn::make('user_agent')->label('من أين')->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => self::device($state))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('actor_user_id')->label('المستخدم')
                    ->options(fn () => User::query()->where('user_type', 'staff')->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('action')->label('النوع')->options(self::ACTIONS),
                SelectFilter::make('subject_table')->label('العنصر')
                    ->options(fn () => DB::table('audit_log')->whereNotNull('subject_table')->distinct()->orderBy('subject_table')->pluck('subject_table')
                        ->mapWithKeys(fn (string $t) => [$t => AuditSubjects::name($t)])->all()),
                Filter::make('period')->label('الفترة')->schema([
                    DatePicker::make('from')->label('من (يوم بتوقيت فلسطين)'),
                    DatePicker::make('until')->label('إلى'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when(self::day($data['from'] ?? null), fn (Builder $q, CarbonImmutable $day) => $q->where('occurred_at', '>=', $day->utc()))
                    ->when(self::day($data['until'] ?? null), fn (Builder $q, CarbonImmutable $day) => $q->where('occurred_at', '<', $day->addDay()->utc()))),
                // From where: the address is never stored, but the same address gives the same HMAC, so one can be looked up.
                Filter::make('address')->label('من عنوان IP')->schema([
                    TextInput::make('ip')->label('عنوان IP')->extraInputAttributes(['dir' => 'ltr'])->helperText('يُحوَّل إلى بصمته ويُبحث بها؛ العنوان نفسه لا يُحفظ.'),
                ])->query(fn (Builder $query, array $data) => $query->when(
                    filter_var(trim((string) ($data['ip'] ?? '')), FILTER_VALIDATE_IP),
                    fn (Builder $q, string $ip) => $q->where('ip_hash', TrackingHash::of($ip)),
                )),
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

    /** Who did it, which item (by its Arabic name or number), which field or value: one box searches them all. */
    private static function search(Builder $query, string $search): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
        $tables = array_keys(array_filter(AuditSubjects::NAMES, fn (string $name) => mb_stripos($name, $search) !== false));

        return $query->where(fn (Builder $q) => $q
            ->whereHas('actor', fn (Builder $a) => $a->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like))
            ->orWhere('subject_table', 'ilike', $like)
            ->when($tables !== [], fn (Builder $t) => $t->orWhereIn('subject_table', $tables))
            ->when(ctype_digit($search) && strlen($search) <= 18, fn (Builder $n) => $n->orWhere('subject_id', (int) $search)->orWhere('id', (int) $search))
            ->orWhereExists(fn ($c) => $c->selectRaw('1')->from('audit_log_changes')->whereColumn('audit_log_changes.audit_id', 'audit_log.id')
                ->where(fn ($v) => $v->where('field', 'ilike', $like)->orWhere('old_value', 'ilike', $like)->orWhere('new_value', 'ilike', $like))));
    }

    private static function subject(AuditLog $record): string
    {
        return $record->subject_table === null ? '—' : AuditSubjects::name($record->subject_table).' #'.$record->subject_id;
    }

    public static function device(?string $agent): ?string
    {
        if ($agent === null || $agent === '') {
            return null;
        }
        $ua = UserAgentSummary::of($agent);

        return $ua['browser'].' — '.$ua['os'];
    }

    private static function day(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', substr($value, 0, 10), 'Asia/Hebron')->startOfDay()
            : null;
    }
}
