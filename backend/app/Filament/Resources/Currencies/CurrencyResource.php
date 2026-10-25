<?php

namespace App\Filament\Resources\Currencies;

use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Integrations\DataProviders;
use App\Integrations\ProviderAlerts;
use App\Jobs\FetchCurrencyRates;
use App\Markets\CurrencyBoard;
use App\Markets\CurrencyCatalog;
use App\Markets\CurrencySources;
use App\Markets\RateBoard;
use App\Markets\RateOverrides;
use App\Markets\RateRejected;
use App\Models\Currency;
use App\Models\RateBoardCurrency;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Unique;

/**
 * The currency board (decision 11): which currencies show and in what order, their buy/sell spreads, manual rates
 * until a chosen time, a "fetch now" button and the choice of source. Every change lands in the audit log.
 */
class CurrencyResource extends Resource
{
    protected static ?string $model = RateBoardCurrency::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $modelLabel = 'عملة';

    protected static ?string $pluralModelLabel = 'أسعار العملات';

    protected static ?string $navigationLabel = 'العملات';

    protected static ?int $navigationSort = 50;

    protected static string|\UnitEnum|null $navigationGroup = 'الأسعار والطقس';

    protected static ?string $slug = 'currencies';

    /** @var array<string, object>|null the live board, read once per request */
    private static ?array $live = null;

    public static function canCreate(): bool
    {
        return false;   // "add currency" below chooses from what the source publishes
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('currency')->orderBy('sort_order'))
            ->reorderable('sort_order')
            ->paginated(false)
            ->description(fn () => self::status())
            ->columns([
                TextColumn::make('currency.name_ar')->label('العملة')->searchable()->description(fn (RateBoardCurrency $r) => $r->currency_code.' '.$r->currency?->symbol),
                TextColumn::make('mid')->label('السعر الرسمي')->state(fn (RateBoardCurrency $r) => self::shown($r, 'mid_rate'))->placeholder('—'),
                TextColumn::make('buy')->label('شراء')->state(fn (RateBoardCurrency $r) => self::shown($r, 'buy_rate'))->placeholder('—')
                    ->description(fn (RateBoardCurrency $r) => '−'.CurrencyCatalog::percent($r->buy_spread_bp).'%'),
                TextColumn::make('sell')->label('بيع')->state(fn (RateBoardCurrency $r) => self::shown($r, 'sell_rate'))->placeholder('—')
                    ->description(fn (RateBoardCurrency $r) => '+'.CurrencyCatalog::percent($r->sell_spread_bp).'%'),
                TextColumn::make('manual')->label('يدوي')->badge()->color('warning')->placeholder('—')
                    ->state(fn (RateBoardCurrency $r) => self::override($r)),
                TextColumn::make('change')->label('التغيّر')->placeholder('—')
                    ->state(fn (RateBoardCurrency $r) => ($c = self::live($r)?->change_pct) === null ? null : RateBoard::decimal($c, 2).'%'),
                TextColumn::make('date')->label('تاريخ النشر')->placeholder('—')->state(fn (RateBoardCurrency $r) => self::live($r)?->rate_date)
                    ->description(fn (RateBoardCurrency $r) => self::live($r)?->is_pegged ? 'من الدولار بالربط الثابت' : null),
                IconColumn::make('is_active')->label('ظاهرة')->boolean(),
            ])

            ->filters([TernaryFilter::make('is_active')->label('ظاهرة')])
            ->recordActions([
                self::editAction(),
                self::manualAction(),
                Action::make('endManual')->label('إلغاء اليدوي')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (RateBoardCurrency $r) => self::override($r) !== null)
                    ->requiresConfirmation()->modalDescription('يرجع السعر الآن إلى السعر الرسمي مع نسبة الشراء والبيع.')
                    ->action(function (RateBoardCurrency $r) {
                        app(RateOverrides::class)->end($r->currency_code);
                        self::$live = null;
                        Notification::make()->success()->title('رجع السعر الرسمي.')->send();
                    }),
                DeleteAction::make()->label('إزالة')->modalHeading('إزالة العملة من اللوحة')
                    ->modalDescription('تختفي من الموقع؛ أسعارها السابقة تبقى محفوظة، ويمكن إضافتها من جديد.'),
            ])
            ->headerActions([self::addAction(), self::fetchAction(), self::sourceAction()]);
    }

    private static function editAction(): Action
    {
        return Action::make('edit')->label('تعديل')->icon(Heroicon::OutlinedPencilSquare)->modalSubmitActionLabel('حفظ')
            ->fillForm(fn (RateBoardCurrency $r) => [
                'name_ar' => $r->currency?->name_ar, 'symbol' => $r->currency?->symbol, 'display_decimals' => $r->display_decimals,
                'buy_percent' => CurrencyCatalog::percent($r->buy_spread_bp), 'sell_percent' => CurrencyCatalog::percent($r->sell_spread_bp),
                'is_active' => $r->is_active,
            ])
            ->schema(fn (RateBoardCurrency $r) => self::fields($r))
            ->action(function (array $data, RateBoardCurrency $r) {
                DB::transaction(function () use ($data, $r) {
                    $r->currency->update(['name_ar' => trim($data['name_ar']), 'symbol' => trim($data['symbol'])]);
                    $r->update([
                        'display_decimals' => (int) $data['display_decimals'], 'is_active' => (bool) $data['is_active'],
                        'buy_spread_bp' => CurrencyCatalog::basisPoints((string) $data['buy_percent']),
                        'sell_spread_bp' => CurrencyCatalog::basisPoints((string) $data['sell_percent']),
                    ]);
                });
                self::$live = null;
                Notification::make()->success()->title('حُفظت العملة.')->send();
            });
    }

    /** @return list<Component|Field> */
    private static function fields(?RateBoardCurrency $record): array
    {
        $percent = fn (string $name, string $label) => TextInput::make($name)->label($label)->suffix('%')->required()
            ->rule('regex:/^\d{1,2}(\.\d{1,2})?$/')->rule(fn () => function ($attribute, $value, $fail) {
                if (CurrencyCatalog::basisPoints((string) $value) === null) {
                    $fail('النسبة بين 0 و'.CurrencyCatalog::MAX_SPREAD_PERCENT.'%، بمنزلتين عشريتين على الأكثر.');
                }
            })->validationMessages(['regex' => 'أدخل نسبة مثل 0.5']);

        return [
            TextInput::make('name_ar')->label('الاسم')->required()->maxLength(60)
                // Another currency's name only: a currency keeps its own (the one edited, or one added back to the board).
                ->unique('currencies', 'name_ar', ignoreRecord: false, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->whereNot('code', $record?->currency_code ?? (string) $get('code')))
                ->validationMessages(['unique' => 'توجد عملة بهذا الاسم.']),
            TextInput::make('symbol')->label('الرمز')->required()->maxLength(8),
            $percent('buy_percent', 'نسبة الشراء (تحت السعر الرسمي)')->helperText('سعر الشراء = السعر الرسمي × (1 − النسبة).'),
            $percent('sell_percent', 'نسبة البيع (فوق السعر الرسمي)')->helperText('سعر البيع = السعر الرسمي × (1 + النسبة).'),
            Select::make('display_decimals')->label('المنازل العشرية المعروضة')->native(false)->options([2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6'])->required(),
            Toggle::make('is_active')->label('ظاهرة على الموقع'),
        ];
    }

    private static function manualAction(): Action
    {
        return Action::make('manual')->label('سعر يدوي')->icon(Heroicon::OutlinedHandRaised)->color('warning')->modalSubmitActionLabel('حفظ السعر اليدوي')
            ->modalDescription('يحلّ محلّ سعري الشراء والبيع المحسوبين من الآن حتى الوقت المختار (أو حتى تلغيه)، ثم يرجع السعر الرسمي وحده.')
            ->fillForm(fn (RateBoardCurrency $r) => ['buy' => self::shown($r, 'buy_rate'), 'sell' => self::shown($r, 'sell_rate')])
            ->schema([
                TextInput::make('buy')->label('سعر الشراء')->required()->helperText('بالشيكل لوحدة واحدة.'),
                TextInput::make('sell')->label('سعر البيع')->required(),
                DateTimePicker::make('until')->label('حتى (توقيت فلسطين)')->seconds(false)->native(false)->displayFormat('Y-m-d H:i')->timezone('Asia/Hebron')
                    ->helperText('اتركه فارغاً ليبقى حتى تلغيه.'),
            ])
            ->action(function (array $data, RateBoardCurrency $r, Action $action) {
                try {
                    $until = filled($data['until'] ?? null) ? CarbonImmutable::parse($data['until'], config('app.timezone')) : null;
                    app(RateOverrides::class)->set($r->currency_code, (string) $data['buy'], (string) $data['sell'], $until);
                } catch (RateRejected $e) {
                    Notification::make()->danger()->title('لم يُحفظ السعر اليدوي')->body($e->getMessage())->send();
                    $action->halt();
                }
                self::$live = null;
                Notification::make()->success()->title('حُفظ السعر اليدوي.')->send();
            });
    }

    private static function addAction(): Action
    {
        return Action::make('add')->label('إضافة عملة')->icon(Heroicon::OutlinedPlus)->modalSubmitActionLabel('إضافة')
            ->visible(fn () => CurrencyBoard::addable() !== [])
            ->schema([
                Select::make('code')->label('العملة')->native(false)->required()->options(fn () => CurrencyBoard::addable())->live()
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        if ($state !== null) {
                            $existing = Currency::query()->find($state);
                            $set('name_ar', $existing?->name_ar ?? CurrencyCatalog::name($state));
                            $set('symbol', $existing?->symbol ?? CurrencyCatalog::symbol($state));
                        }
                    }),
                ...self::fields(null),
            ])
            ->fillForm(['buy_percent' => '0.5', 'sell_percent' => '0.5', 'display_decimals' => 3, 'is_active' => true])
            ->action(function (array $data, Action $action) {
                try {
                    $row = CurrencyBoard::add((string) $data['code'], trim($data['name_ar']), trim($data['symbol']), (int) $data['display_decimals'],
                        CurrencyCatalog::basisPoints((string) $data['buy_percent']), CurrencyCatalog::basisPoints((string) $data['sell_percent']));
                    $row->update(['is_active' => (bool) $data['is_active']]);
                } catch (RateRejected $e) {
                    Notification::make()->danger()->title('لم تُضف العملة')->body($e->getMessage())->send();
                    $action->halt();
                }
                self::$live = null;
                Notification::make()->success()->title('أُضيفت العملة.')->body('يظهر سعرها بعد أول جلب ينشر فيه المصدر سعرها.')->send();
            });
    }

    private static function fetchAction(): Action
    {
        return Action::make('fetch')->label('جلب الأسعار الآن')->icon(Heroicon::OutlinedArrowPath)->color('gray')
            ->requiresConfirmation()->modalDescription('يُسأل مصدر الأسعار في الخلفية. لا يُسجَّل شيء إلا إذا نشر المصدر أسعاراً جديدة.')
            // In the background: a slow or silent source must not hold the page.
            ->action(function () {
                FetchCurrencyRates::dispatch();
                Notification::make()->success()->title('أُرسل الطلب.')->body('حدّث الصفحة بعد دقيقة؛ نتيجة آخر جلب في سطر المصدر أعلى الجدول.')->send();
            });
    }

    private static function sourceAction(): Action
    {
        return Action::make('source')->label('مصدر الأسعار')->icon(Heroicon::OutlinedBuildingLibrary)->color('gray')->modalSubmitActionLabel('حفظ')
            ->fillForm(fn () => ['provider' => DataProviders::active('currency')])
            ->schema([
                Radio::make('provider')->label('المصدر')->required()
                    ->options(fn () => collect(DataProviders::of('currency'))->mapWithKeys(fn ($name, $code) => [$code => ProviderAlerts::name($code)])->all())
                    ->descriptions(fn () => collect(DataProviders::of('currency'))->mapWithKeys(fn ($name, $code) => [$code => CurrencySources::get($code)?->available() ? 'متاح' : 'غير متاح: يحتاج اشتراكاً مدفوعاً ومفتاحاً، ولا ربط له بعد.'])->all())
                    ->disableOptionWhen(fn (string $value) => ! (CurrencySources::get($value)?->available() ?? false)),
            ])
            // A source that is not available is a disabled option, which the form refuses.
            ->action(function (array $data) {
                DataProviders::switchTo('currency', (string) $data['provider']);
                RateBoard::changed('source');
                Notification::make()->success()->title('حُفظ مصدر الأسعار.')->send();
            });
    }

    /** One line above the table: source, last check, last publication. */
    private static function status(): string
    {
        $code = DataProviders::active('currency');
        $health = $code ? DB::table('provider_health')->where('provider_code', $code)->first() : null;
        $published = DB::table('exchange_rates')->max('effective_at');

        return 'المصدر: '.($code ? ProviderAlerts::name($code) : '—')
            .' · آخر فحص ناجح: '.($health?->last_success_at ? ProviderAlerts::time($health->last_success_at) : '—')
            .' · آخر نشر: '.($published ? ProviderAlerts::time($published) : '—')
            .($health && $health->consecutive_failures > 0 ? ' · فشل متتالٍ: '.$health->consecutive_failures : '');
    }

    private static function live(RateBoardCurrency $r): ?object
    {
        self::$live ??= DB::table('rate_board')->get()->keyBy(fn ($row) => trim($row->currency_code))->all();

        return self::$live[$r->currency_code] ?? null;
    }

    private static function shown(RateBoardCurrency $r, string $column): ?string
    {
        return RateBoard::decimal(self::live($r)?->{$column}, $r->display_decimals);
    }

    private static function override(RateBoardCurrency $r): ?string
    {
        $o = DB::table('rate_overrides')->where('currency_code', $r->currency_code)
            ->whereRaw('valid_from <= now() AND (valid_until IS NULL OR valid_until > now())')->first();

        return $o === null ? null : ($o->valid_until ? 'حتى '.Carbon::parse($o->valid_until)->timezone('Asia/Hebron')->format('Y-m-d H:i') : 'حتى الإلغاء');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCurrencies::route('/')];
    }
}
