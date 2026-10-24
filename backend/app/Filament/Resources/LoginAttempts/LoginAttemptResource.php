<?php

namespace App\Filament\Resources\LoginAttempts;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\LoginAttempts\Pages\ListLoginAttempts;
use App\Models\LoginAttempt;
use App\Support\TrackingHash;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Every sign-in to the panel, right or wrong (22.16): when, which e-mail, the result, the device and the address (as an
 * HMAC). The device is named by its browser once the account approved it with a code; otherwise it is unknown.
 * Read only, like the audit trail.
 */
class LoginAttemptResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = LoginAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $modelLabel = 'محاولة دخول';

    protected static ?string $pluralModelLabel = 'محاولات الدخول';

    protected static ?string $navigationLabel = 'محاولات الدخول';

    protected static ?int $navigationSort = 91;

    protected static string|\UnitEnum|null $navigationGroup = 'النظام';

    public const REASONS = [
        'bad_credentials' => 'بريد أو كلمة مرور خاطئة',
        'bad_code' => 'رمز خاطئ',
        'expired_code' => 'رمز منتهٍ',
        'throttled' => 'محاولات كثيرة، رُفضت',
        'inactive' => 'حساب غير فعّال',
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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user')->addSelect(['device_agent' => DB::table('user_devices')->select('user_agent')
                ->whereColumn('user_devices.user_id', 'login_attempts.user_id')->whereColumn('user_devices.device_hash', 'login_attempts.device_hash')->limit(1)]))
            ->defaultSort('attempted_at', 'desc')
            ->columns([
                TextColumn::make('attempted_at')->label('الوقت')->dateTime('Y-m-d H:i:s', 'Asia/Hebron')->sortable(),
                TextColumn::make('email')->label('البريد')->searchable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('succeeded')->label('النتيجة')->badge()
                    ->state(fn (LoginAttempt $r) => $r->succeeded ? 'نجح' : (self::REASONS[$r->failure_reason] ?? 'فشل'))
                    ->color(fn (LoginAttempt $r) => $r->succeeded ? 'success' : ($r->failure_reason === 'throttled' ? 'warning' : 'danger')),
                TextColumn::make('device_agent')->label('الجهاز')
                    ->state(fn (LoginAttempt $r) => AuditLogResource::device($r->device_agent) ?? ($r->device_hash === null ? 'جهاز غير معروف' : 'جهاز لم يُعتمد بعد')),
                TextColumn::make('ip_hash')->label('بصمة العنوان')->limit(12)->placeholder('—')->extraAttributes(['dir' => 'ltr'])->toggleable(),
            ])
            ->filters([
                SelectFilter::make('result')->label('النتيجة')->options(['ok' => 'نجح', 'failed' => 'فشل'] + self::REASONS)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'ok' => $query->where('succeeded', true),
                        'failed' => $query->where('succeeded', false),
                        default => $query->where('failure_reason', $data['value']),
                    }),
                Filter::make('period')->label('الفترة')->schema([
                    DatePicker::make('from')->label('من (يوم بتوقيت فلسطين)'),
                    DatePicker::make('until')->label('إلى'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when(self::day($data['from'] ?? null), fn (Builder $q, CarbonImmutable $day) => $q->where('attempted_at', '>=', $day->utc()))
                    ->when(self::day($data['until'] ?? null), fn (Builder $q, CarbonImmutable $day) => $q->where('attempted_at', '<', $day->addDay()->utc()))),
                Filter::make('address')->label('من عنوان IP')->schema([
                    TextInput::make('ip')->label('عنوان IP')->extraInputAttributes(['dir' => 'ltr'])->helperText('يُحوَّل إلى بصمته ويُبحث بها؛ العنوان نفسه لا يُحفظ.'),
                ])->query(fn (Builder $query, array $data) => $query->when(
                    filter_var(trim((string) ($data['ip'] ?? '')), FILTER_VALIDATE_IP),
                    fn (Builder $q, string $ip) => $q->where('ip_hash', TrackingHash::of($ip)),
                )),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLoginAttempts::route('/')];
    }

    private static function day(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', substr($value, 0, 10), 'Asia/Hebron')->startOfDay()
            : null;
    }
}
