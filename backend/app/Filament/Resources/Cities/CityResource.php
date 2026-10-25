<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Concerns\HasNumericRecordKey;
use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Integrations\DataProviders;
use App\Integrations\ProviderAlerts;
use App\Jobs\FetchCityWeather;
use App\Models\City;
use App\Models\WeatherLocation;
use App\Weather\WeatherBoard;
use App\Weather\WeatherCities;
use App\Weather\WeatherFetcher;
use App\Weather\WeatherSources;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The weather cities (decision 12.1): name, coordinates, order and whether each shows. A city's address stays as it
 * was made (its page is indexed, 12.11). The default city (Nablus) cannot be hidden.
 */
class CityResource extends Resource
{
    use HasNumericRecordKey;

    protected static ?string $model = WeatherLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static ?string $modelLabel = 'مدينة';

    protected static ?string $pluralModelLabel = 'مدن الطقس';

    protected static ?string $navigationLabel = 'الطقس';

    protected static ?int $navigationSort = 51;

    protected static string|\UnitEnum|null $navigationGroup = 'الأسعار والطقس';

    protected static ?string $slug = 'weather-cities';

    /** Coordinates must fall inside this box (Palestine with a margin): a guard against swapped or mistyped numbers. */
    public const LATITUDE = [29.0, 34.0];

    public const LONGITUDE = [34.0, 36.0];

    public static function canCreate(): bool
    {
        return false;   // "add city" below makes the city and its place on the list together
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('city')->orderBy('sort_order')->orderBy('city_id'))
            ->reorderable('sort_order')
            ->paginated(false)
            ->description(fn () => self::status())
            ->columns([
                TextColumn::make('city.name_ar')->label('المدينة')->searchable()->description(fn (WeatherLocation $r) => '/weather/'.$r->city?->slug)
                    ->badge(fn (WeatherLocation $r) => $r->is_default)->color(fn (WeatherLocation $r) => $r->is_default ? 'primary' : null),
                TextColumn::make('coordinates')->label('الإحداثيات')->state(fn (WeatherLocation $r) => $r->city?->latitude.', '.$r->city?->longitude),
                TextColumn::make('now')->label('الآن')->placeholder('—')->state(fn (WeatherLocation $r) => self::latest($r)),
                IconColumn::make('is_active')->label('ظاهرة')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label('ظاهرة')])
            ->recordActions([
                Action::make('edit')->label('تعديل')->icon(Heroicon::OutlinedPencilSquare)->modalSubmitActionLabel('حفظ')
                    ->fillForm(fn (WeatherLocation $r) => ['name_ar' => $r->city->name_ar, 'latitude' => $r->city->latitude, 'longitude' => $r->city->longitude, 'is_active' => $r->is_active])
                    ->schema(fn (WeatherLocation $r) => self::fields($r))
                    ->action(function (array $data, WeatherLocation $r) {
                        DB::transaction(function () use ($data, $r) {
                            $r->city->update(['name_ar' => trim($data['name_ar']), 'latitude' => $data['latitude'], 'longitude' => $data['longitude']]);
                            $r->update(['is_active' => $r->is_default || (bool) ($data['is_active'] ?? false)]);
                        });
                        Notification::make()->success()->title('حُفظت المدينة.')->send();
                    }),
                Action::make('remove')->label('حذف')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (WeatherLocation $r) => ! $r->is_default)
                    ->requiresConfirmation()->modalHeading('حذف المدينة من الطقس')
                    ->modalDescription('تُحذف المدينة مع قراءاتها وتوقعاتها، وتجيب صفحتها «لم تعد موجودة» (410). لإخفائها مؤقتاً استعمل «تعديل» وأطفئ الظهور.')
                    ->action(function (WeatherLocation $r) {
                        app(WeatherCities::class)->remove($r, auth()->id());
                        Notification::make()->success()->title('حُذفت المدينة.')->send();
                    }),
                Action::make('fetch')->label('جلب الطقس')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->visible(fn () => WeatherSources::ready())
                    ->action(function (WeatherLocation $r) {
                        FetchCityWeather::dispatch($r->city_id);
                        Notification::make()->success()->title('أُرسل الطلب.')->body('يظهر الطقس خلال دقيقة؛ حدّث الصفحة.')->send();
                    }),
            ])
            ->headerActions([
                Action::make('add')->label('إضافة مدينة')->icon(Heroicon::OutlinedPlus)->modalSubmitActionLabel('إضافة')
                    ->schema(self::fields(null))->fillForm(['is_active' => true])
                    ->action(function (array $data) {
                        app(WeatherCities::class)->add($data);
                        Notification::make()->success()->title('أُضيفت المدينة.')->body('يظهر طقسها بعد أول جلب (خلال ساعة).')->send();
                    }),
                self::sourceAction(),
                Action::make('fetchAll')->label('جلب الطقس الآن')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->visible(fn () => WeatherSources::ready())
                    ->requiresConfirmation()->modalDescription('يُطلب طقس كل المدن الظاهرة من OpenWeather في الخلفية (طلبان لكل مدينة)، ويظهر خلال دقيقة.')
                    // In the background: a slow or silent source must not hold the page (twelve cities, two calls each).
                    ->action(function () {
                        DB::table('weather_locations')->where('is_active', true)->orderBy('sort_order')->pluck('city_id')
                            ->each(fn ($id) => FetchCityWeather::dispatch((int) $id));
                        Notification::make()->success()->title('أُرسل الطلب.')->body('يظهر الطقس خلال دقيقة؛ حدّث الصفحة.')->send();
                    }),
            ]);
    }

    /** The source of the weather (12.13): a setting, audited; a source the code does not know, or without its key, cannot be chosen. */
    private static function sourceAction(): Action
    {
        return Action::make('source')->label('مصدر الطقس')->icon(Heroicon::OutlinedBuildingLibrary)->color('gray')->modalSubmitActionLabel('حفظ')
            ->fillForm(fn () => ['provider' => DataProviders::active('weather')])
            ->schema([
                Radio::make('provider')->label('المصدر')->required()
                    ->options(fn () => collect(DataProviders::of('weather'))->mapWithKeys(fn ($name, $code) => [$code => ProviderAlerts::name($code)])->all())
                    ->descriptions(fn () => collect(DataProviders::of('weather'))->mapWithKeys(fn ($name, $code) => [$code => WeatherSources::get($code)?->configured() ? 'متاح' : 'غير متاح: لا ربط له في الكود أو مفتاحه غير مضبوط.'])->all())
                    ->disableOptionWhen(fn (string $value) => ! (WeatherSources::get($value)?->configured() ?? false)),
            ])
            ->action(function (array $data) {
                DataProviders::switchTo('weather', (string) $data['provider']);
                WeatherBoard::changed();
                Notification::make()->success()->title('حُفظ مصدر الطقس.')->send();
            });
    }

    /** @return list<Field> */
    private static function fields(?WeatherLocation $record): array
    {
        $coordinate = fn (string $name, string $label, array $range) => TextInput::make($name)->label($label)->required()
            ->rule('regex:/^-?\d{1,3}(\.\d{1,6})?$/')->validationMessages(['regex' => 'رقم بستة منازل عشرية على الأكثر، مثل 32.2211'])
            ->rule(fn () => function ($attribute, $value, $fail) use ($range) {
                if (is_numeric($value) && ((float) $value < $range[0] || (float) $value > $range[1])) {
                    $fail("خارج فلسطين: يجب أن يكون بين {$range[0]} و{$range[1]}. تأكد أنك لم تبدّل خط العرض بخط الطول.");
                }
            });

        return [
            TextInput::make('name_ar')->label('الاسم')->required()->maxLength(80)
                ->unique('cities', 'name_ar', ignorable: $record?->city, modifyRuleUsing: fn ($rule) => $rule->whereIn('id', WeatherLocation::query()->select('city_id')))
                ->validationMessages(['unique' => 'توجد مدينة بهذا الاسم.']),
            $coordinate('latitude', 'خط العرض (Latitude)', self::LATITUDE),
            $coordinate('longitude', 'خط الطول (Longitude)', self::LONGITUDE),
            Toggle::make('is_active')->label('ظاهرة على الموقع')
                ->disabled($record?->is_default ?? false)->helperText($record?->is_default ? 'المدينة الافتراضية تبقى ظاهرة.' : null),
        ];
    }

    private static function status(): string
    {
        $code = DataProviders::active('weather') ?? WeatherFetcher::PROVIDER;
        if (! WeatherSources::ready()) {
            return ($code === WeatherFetcher::PROVIDER ? 'مفتاح OpenWeather غير مضبوط' : 'مصدر الطقس المختار غير متاح').': لا يُجلب طقس، ويبقى آخر طقس محفوظ.';
        }
        $health = DB::table('provider_health')->where('provider_code', $code)->first();

        return 'المصدر: '.ProviderAlerts::name($code).' · كل مدينة مرة بالساعة'
            .' · آخر نجاح: '.($health?->last_success_at ? ProviderAlerts::time($health->last_success_at) : '—')
            .($health && $health->consecutive_failures > 0 ? ' · فشل متتالٍ: '.$health->consecutive_failures : '');
    }

    private static function latest(WeatherLocation $r): ?string
    {
        $o = DB::table('weather_observations')->join('weather_conditions', 'weather_conditions.code', '=', 'weather_observations.condition_code')
            ->where('city_id', $r->city_id)->orderByDesc('observed_at')->first(['temperature_c', 'description_ar', 'observed_at']);

        return $o === null ? null : rtrim(rtrim($o->temperature_c, '0'), '.').'° '.$o->description_ar.' ('.Carbon::parse($o->observed_at)->timezone('Asia/Hebron')->format('H:i').')';
    }

    public static function getPages(): array
    {
        return ['index' => ManageCities::route('/')];
    }
}
