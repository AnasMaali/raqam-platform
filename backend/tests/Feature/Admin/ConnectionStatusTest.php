<?php

use App\Filament\Pages\Dashboard;
use App\Integrations\ProviderHealth;
use App\Models\User;
use App\Panel\ConnectionStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
});

function states(): array
{
    return collect(ConnectionStatus::all())->mapWithKeys(fn ($c) => [$c['name'] => $c['state']])->all();
}

test('the home shows every outside connection: currencies, weather, YouTube, search and e-mail', function () {
    $page = $this->get(Dashboard::getUrl())->assertOk();

    foreach (['حالة الربط الخارجي', 'العملات', 'الطقس', 'يوتيوب', 'البحث', 'البريد', 'بنك إسرائيل'] as $text) {
        $page->assertSee($text);
    }
    expect(states())->toBe(['العملات' => 'unknown', 'الطقس' => 'off', 'يوتيوب' => 'off', 'البحث' => 'off', 'البريد' => 'off', 'النشرة (Brevo)' => 'off']);
});

test('a source that answered is working; one failing says since when and why', function () {
    config(['raqam.weather.key' => 'ow-key-1234567890']);
    ProviderHealth::success('bank_of_israel');
    ProviderHealth::success('openweathermap');
    ProviderHealth::failure('openweathermap', 'ردّ OpenWeather بخطأ (500).');

    expect(states())->toMatchArray(['العملات' => 'ok', 'الطقس' => 'failing']);
    $this->get(Dashboard::getUrl())->assertSee('فشل متتالٍ: 1')->assertSee('ردّ OpenWeather بخطأ (500).');
});

test('no key appears on the home, not even a part of it', function () {
    $keys = ['raqam.youtube.key' => 'yt-AbCdEfGh12345678', 'raqam.weather.key' => 'ow-ZyXwVu98765432', 'raqam.search.key' => 'ms-QwErTy13579246',
        'mail.mailers.smtp.password' => 'smtp-PaSsWoRd24680'];
    config($keys + ['mail.default' => 'smtp', 'raqam.search.host' => 'http://127.0.0.1:9']);
    Http::fake();

    $html = $this->get(Dashboard::getUrl())->assertOk()->assertSee('المفتاح: مضبوط')->assertSee('كلمة المرور: مضبوطة')->getContent();

    foreach ($keys as $value) {
        expect($html)->not->toContain($value)->not->toContain(substr($value, -4))->not->toContain(substr($value, 0, 6));
    }
    expect(states()['البحث'])->toBe('failing');
});

test('a key that is not set says so', function () {
    config(['raqam.youtube.key' => '', 'raqam.weather.key' => '']);

    $this->get(Dashboard::getUrl())->assertSee('المفتاح: غير مضبوط');
    expect(DB::table('provider_health')->where('provider_code', 'openweathermap')->value('consecutive_failures'))->toBe(0);
});
