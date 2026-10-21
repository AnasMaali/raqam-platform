<?php

use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\Currencies\Pages\ManageCurrencies;
use App\Integrations\DataProviders;
use App\Integrations\ProviderUnavailable;
use App\Markets\CurrencyBoard;
use App\Markets\CurrencyCatalog;
use App\Markets\CurrencySource;
use App\Markets\ExchangeRateApiSource;
use App\Markets\PublishedRate;
use App\Markets\RateFetcher;
use App\Markets\RateOverrides;
use App\Markets\RateRejected;
use App\Models\RateBoardCurrency;
use App\Models\User;
use App\Support\ProductionChecks;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    fakeBoi(boiBody());
    app(RateFetcher::class)->run();
});

function boardRow(string $code): object
{
    return DB::table('rate_board')->where('currency_code', $code)->first();
}

function audited(string $table): Collection
{
    return DB::table('audit_log')->join('audit_log_changes', 'audit_log_changes.audit_id', '=', 'audit_log.id')
        ->where('audit_log.subject_table', $table)->orderBy('audit_log.id')->get();
}

// ---- spreads ---------------------------------------------------------------------------------------------

test('a spread is typed in per cent and kept in basis points, exactly', function () {
    expect(CurrencyCatalog::basisPoints('0.5'))->toBe(50)
        ->and(CurrencyCatalog::basisPoints('1.25'))->toBe(125)
        ->and(CurrencyCatalog::basisPoints('20'))->toBe(2000)
        ->and(CurrencyCatalog::basisPoints('20.01'))->toBeNull()
        ->and(CurrencyCatalog::basisPoints('-1'))->toBeNull()
        ->and(CurrencyCatalog::basisPoints('0.125'))->toBeNull()
        ->and(CurrencyCatalog::basisPoints('1e1'))->toBeNull()
        ->and(CurrencyCatalog::percent(125))->toBe('1.25')
        ->and(CurrencyCatalog::percent(50))->toBe('0.5');
});

test('editing a currency changes its spreads, name and visibility, and the audit log names the currency', function () {
    $usd = RateBoardCurrency::find('USD');
    Livewire::test(ManageCurrencies::class)
        ->callAction(TestAction::make('edit')->table($usd), ['name_ar' => 'الدولار الأمريكي', 'symbol' => 'US$', 'buy_percent' => '1', 'sell_percent' => '1.5', 'display_decimals' => 4, 'is_active' => true])
        ->assertHasNoFormErrors()->assertNotified();

    $row = boardRow('USD');
    expect($row->buy_rate)->toBe('3.074940')                   // 3.106 × 0.99
        ->and($row->sell_rate)->toBe('3.152590')               // 3.106 × 1.015
        ->and($row->name_ar)->toBe('الدولار الأمريكي')
        ->and(audited('rate_board_currencies'))->toBeEmpty();  // the key is not a number: logged without a subject

    $changes = DB::table('audit_log_changes')->whereIn('field', ['buy_spread_bp', 'currency_code'])->pluck('new_value', 'field');
    expect($changes['buy_spread_bp'])->toBe('100')->and($changes['currency_code'])->toBe('USD');
});

test('a spread outside 0–20 % is refused', function () {
    Livewire::test(ManageCurrencies::class)
        ->callAction(TestAction::make('edit')->table(RateBoardCurrency::find('USD')), ['name_ar' => 'دولار', 'symbol' => '$', 'buy_percent' => '25', 'sell_percent' => 'abc', 'display_decimals' => 3, 'is_active' => true])
        ->assertHasFormErrors(['buy_percent', 'sell_percent']);

    expect(boardRow('USD')->buy_rate)->toBe('3.090470');
});

// ---- adding, ordering, removing ------------------------------------------------------------------------

test('a currency the bank publishes can be added; it shows once a rate is fetched', function () {
    Livewire::test(ManageCurrencies::class)
        ->callAction(TestAction::make('add')->table(), ['code' => 'CHF', 'name_ar' => 'فرنك سويسري', 'symbol' => 'CHF', 'buy_percent' => '0.5', 'sell_percent' => '0.5', 'display_decimals' => 3, 'is_active' => true])
        ->assertHasNoFormErrors();

    expect(DB::table('currencies')->where('code', 'CHF')->value('name_ar'))->toBe('فرنك سويسري')
        ->and(RateBoardCurrency::find('CHF')->sort_order)->toBe(8)
        ->and(api('/currencies')->json('data.*.code'))->not->toContain('CHF');

    fakeBoi(boiBody(['USD' => '3.106', 'JOD' => '4.38', 'EUR' => '3.48', 'GBP' => '4.14', 'EGP' => '0.057', 'CHF' => '3.7675'], now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z')));
    app(RateFetcher::class)->run();

    expect(collect(api('/currencies')->json('data'))->firstWhere('code', 'CHF')['mid'])->toBe('3.768');
});

test('only what the source publishes, or a pegged currency, can be added; never twice', function () {
    expect(fn () => CurrencyBoard::add('XAU', 'ذهب', 'g', 3, 50, 50))->toThrow(RateRejected::class)
        ->and(fn () => CurrencyBoard::add('USD', 'دولار', '$', 3, 50, 50))->toThrow(RateRejected::class);

    RateBoardCurrency::find('SAR')->delete();
    CurrencyBoard::add('SAR', 'ريال سعودي', 'ر.س', 3, 50, 50);
    expect(boardRow('SAR')->is_pegged)->toBeTrue();
});

test('the order is dragged, and removing a currency keeps its past rates', function () {
    api('/currencies');                                         // cached before the drag
    Livewire::test(ManageCurrencies::class)->call('reorderTable', ['EUR', 'USD', 'JOD', 'GBP', 'EGP', 'AED', 'SAR']);
    expect(array_slice(api('/currencies')->json('data.*.code'), 0, 3))->toBe(['EUR', 'USD', 'JOD']);

    Livewire::test(ManageCurrencies::class)->callAction(TestAction::make('delete')->table(RateBoardCurrency::find('GBP')));
    expect(RateBoardCurrency::find('GBP'))->toBeNull()
        ->and(DB::table('exchange_rates')->where('currency_code', 'GBP')->count())->toBe(1)
        ->and(api('/currencies')->json('data.*.code'))->not->toContain('GBP');
});

// ---- manual rates --------------------------------------------------------------------------------------

test('a manual rate replaces buy and sell until its time, then the official rate comes back by itself', function () {
    Livewire::test(ManageCurrencies::class)
        ->callAction(TestAction::make('manual')->table(RateBoardCurrency::find('USD')), ['buy' => '3.08', 'sell' => '3.14', 'until' => now('Asia/Hebron')->addHours(2)->format('Y-m-d H:i:s')])
        ->assertNotified();

    expect(boardRow('USD'))->buy_rate->toBe('3.0800000000')->sell_rate->toBe('3.1400000000')->is_manual->toBeTrue();

    DB::table('rate_overrides')->update(['valid_from' => DB::raw("valid_from - interval '3 hours'"), 'valid_until' => DB::raw("now() - interval '1 second'")]);
    expect(boardRow('USD'))->buy_rate->toBe('3.090470')->is_manual->toBeFalse();
});

test('a new manual rate ends the current one at the same instant (no overlap), and ending it returns the official rate', function () {
    $overrides = app(RateOverrides::class);
    $first = $overrides->set('USD', '3.08', '3.14', null);
    // A minute passes (inside the test's transaction the database clock stands still).
    DB::table('rate_overrides')->update(['valid_from' => DB::raw("valid_from - interval '1 minute'")]);
    $second = $overrides->set('USD', '3.07', '3.15', now()->addDay());

    $rows = DB::table('rate_overrides')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->valid_until)->toBe($rows[1]->valid_from)
        ->and(boardRow('USD')->buy_rate)->toBe('3.0700000000');

    DB::table('rate_overrides')->update(['valid_from' => DB::raw("valid_from - interval '1 minute'"), 'valid_until' => DB::raw("valid_until - interval '1 minute'")]);
    Livewire::test(ManageCurrencies::class)->callAction(TestAction::make('endManual')->table(RateBoardCurrency::find('USD')))->assertNotified();
    expect(boardRow('USD')->is_manual)->toBeFalse()
        ->and(audited('rate_overrides')->pluck('field')->all())->toBe(['currency_code', 'buy_rate', 'sell_rate', 'valid_until', 'valid_until', 'currency_code', 'buy_rate', 'sell_rate', 'valid_until', 'valid_until']);
});

test('ending a manual rate after the server clock stepped back still keeps the end after the start', function () {
    app(RateOverrides::class)->set('USD', '3.08', '3.14', null);
    // The clock stepped back: the start now lies two seconds ahead of "now".
    DB::table('rate_overrides')->update(['valid_from' => DB::raw("now() + interval '2 seconds'")]);

    $id = app(RateOverrides::class)->set('USD', '3.07', '3.15', null);
    $rows = DB::table('rate_overrides')->orderBy('id')->get();

    expect($rows[0]->valid_until)->not->toBeNull()
        ->and(DB::selectOne('SELECT valid_until > valid_from AS ok FROM rate_overrides WHERE id = ?', [$rows[0]->id])->ok)->toBeTrue()
        ->and($rows[1]->id)->toBe($id)
        ->and(DB::selectOne('SELECT ? ::timestamptz >= ?::timestamptz AS ok', [$rows[1]->valid_from, $rows[0]->valid_until])->ok)->toBeTrue();
});

test('a manual rate is refused when it makes no sense', function (string $buy, string $sell, ?string $until, string $why) {
    expect(fn () => app(RateOverrides::class)->set('USD', $buy, $sell, $until ? CarbonImmutable::parse($until) : null))
        ->toThrow(RateRejected::class, $why);
    expect(DB::table('rate_overrides')->count())->toBe(0);
})->with([
    'sell below buy' => ['3.2', '3.1', null, 'سعر البيع يجب ألا يقل'],
    'a slipped digit' => ['31.0', '31.5', null, 'بعيد جداً'],
    'far too low' => ['0.3', '3.1', null, 'بعيد جداً'],
    'zero' => ['0', '3.1', null, 'أكبر من صفر'],
    'negative' => ['-3', '3.1', null, 'أدخل رقماً موجباً'],
    'letters' => ['abc', '3.1', null, 'أدخل رقماً موجباً'],
    'too many decimals' => ['3.1234567', '3.2', null, 'أدخل رقماً موجباً'],
    'ends in the past' => ['3.08', '3.14', '2020-01-01 10:00:00', 'في المستقبل'],
]);

test('the manual dialog says why it refused and stays open', function () {
    Livewire::test(ManageCurrencies::class)
        ->callAction(TestAction::make('manual')->table(RateBoardCurrency::find('USD')), ['buy' => '3.5', 'sell' => '3.1', 'until' => null])
        ->assertNotified('لم يُحفظ السعر اليدوي');

    expect(DB::table('rate_overrides')->count())->toBe(0);
});

// ---- the source -----------------------------------------------------------------------------------------

test('the backup source (ExchangeRate-API) is registered, switched off, and never opens a connection', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake();
    $source = new ExchangeRateApiSource;

    expect(DB::table('data_providers')->where('code', 'exchangerate_api')->first())->is_active->toBeFalse()->is_backup->toBeTrue()
        ->and($source->available())->toBeFalse()
        ->and(fn () => $source->fetch())->toThrow(ProviderUnavailable::class);
    Http::assertNothingSent();

    Livewire::test(ManageCurrencies::class)->callAction(TestAction::make('source')->table(), ['provider' => 'exchangerate_api'])
        ->assertHasFormErrors(['provider']);
    expect(DataProviders::active('currency'))->toBe('bank_of_israel');
    Http::assertNothingSent();
});

test('switching the source is a setting: the fetch uses the new one, and the switch is in the audit log', function () {
    app()->instance(ExchangeRateApiSource::class, new class implements CurrencySource
    {
        public function code(): string
        {
            return 'exchangerate_api';
        }

        public function available(): bool
        {
            return true;
        }

        public function supports(): array
        {
            return ['USD'];
        }

        public function fetch(): array
        {
            return ['USD' => new PublishedRate('USD', '3.2', CarbonImmutable::now()->subMinute())];
        }

        public function note(): string
        {
            return 'نص آخر';
        }
    });

    Livewire::test(ManageCurrencies::class)->callAction(TestAction::make('source')->table(), ['provider' => 'exchangerate_api'])->assertNotified();
    $report = app(RateFetcher::class)->run();

    expect(DataProviders::active('currency'))->toBe('exchangerate_api')
        ->and(DB::table('data_providers')->where('code', 'bank_of_israel')->first())->is_active->toBeFalse()->is_backup->toBeTrue()
        ->and($report['stored'])->toBe(['USD'])
        ->and(api('/currencies')->json('meta.note'))->toBe('نص آخر')
        ->and(DB::table('audit_log_changes')->where('field', 'provider.currency')->first())->old_value->toBe('bank_of_israel')->new_value->toBe('exchangerate_api');
});

test('fetch now sends the request (a job; the test queue runs it at once) and the screen\'s status line shows the outcome', function () {
    fakeBoi(boiBody(['USD' => '3.12', 'JOD' => '4.38', 'EUR' => '3.48', 'GBP' => '4.14', 'EGP' => '0.057'], now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z')));
    Livewire::test(ManageCurrencies::class)->callAction(TestAction::make('fetch')->table())->assertNotified('أُرسل الطلب.');
    expect(boardRow('USD')->mid_rate)->toStartWith('3.12');

    fakeBoi('down', 503);
    Livewire::test(ManageCurrencies::class)->callAction(TestAction::make('fetch')->table())->assertNotified('أُرسل الطلب.');
    $this->get(CurrencyResource::getUrl())->assertSee('فشل متتالٍ: 1');
});

test('the screen shows the board and is for signed-in staff only', function () {
    $this->get(CurrencyResource::getUrl())->assertOk()->assertSee('دولار أمريكي')->assertSee('3.106')->assertSee('بنك إسرائيل');

    auth()->logout();
    $this->get(CurrencyResource::getUrl())->assertRedirect();
});

test('production refuses to start with the test stand-in for the bank set', function () {
    productionConfig(['raqam.currency.boi_base' => 'http://127.0.0.1:9999']);
    expect(ProductionChecks::failures())->toContain('CURRENCY_BOI_BASE is for browser tests only and must not be set.');

    productionConfig();
    expect(ProductionChecks::failures())->toBe([]);
});
