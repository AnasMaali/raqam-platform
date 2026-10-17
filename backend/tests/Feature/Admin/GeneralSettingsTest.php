<?php

use App\Filament\Pages\GeneralSettings;
use App\Models\Setting;
use App\Models\User;
use App\Settings\SettingsStore;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    $this->store = app(SettingsStore::class);
});

function saveSettings(array $values, array $social = []): void
{
    app(SettingsStore::class)->save($values, $social, auth()->id());
}

test('saved values come back, blank ones become "no value"', function () {
    saveSettings(['general.site_name' => '  رَقَم  ', 'contact.email' => 'info@raqam.ps', 'contact.phone' => '']);

    expect($this->store->values())->toMatchArray(['general.site_name' => 'رَقَم', 'contact.email' => 'info@raqam.ps', 'contact.phone' => null]);
});

test('settings that are not sent are left as they were', function () {
    saveSettings(['general.site_name' => 'اسم جديد']);

    expect(Setting::find('contact.address')->value)->toBe('نابلس، فلسطين');
});

test('keys outside the catalog are ignored, so a request cannot write any setting it likes', function () {
    saveSettings(['general.site_name' => 'رَقَم', 'general.timezone' => 'UTC', 'audit.retention_days' => '1', 'nonsense.key' => 'x']);

    expect(Setting::find('general.timezone')->value)->toBe('Asia/Hebron')
        ->and(Setting::find('audit.retention_days')->value)->toBe('730')
        ->and(Setting::find('nonsense.key'))->toBeNull();
});

test('each type is checked', function (string $key, string $bad) {
    expect(fn () => saveSettings([$key => $bad]))->toThrow(ValidationException::class);
    expect(Setting::find($key)?->value)->not->toBe($bad);
})->with([
    'phone without +' => ['contact.phone', '0599123456'],
    'phone with letters' => ['contact.whatsapp', '+97059abc'],
    'email' => ['contact.email', 'not-an-email'],
    'name too long' => ['general.site_name', str_repeat('ا', 256)],
    'description too long' => ['seo.default_description', str_repeat('ا', 2001)],
]);

test('a valid phone number and e-mail are accepted', function () {
    saveSettings(['contact.phone' => '+970599123456', 'contact.email' => 'info@raqam.ps']);

    expect(Setting::find('contact.phone')->value)->toBe('+970599123456');
});

test('the database has the last word: a value the form let through is still refused', function () {
    // A phone the catalog's regex accepts but the domain does not would be a gap; prove the CHECK exists on its own.
    expect(fn () => DB::transaction(fn () => Setting::query()->whereKey('contact.phone')->update(['value' => 'abc'])))
        ->toThrow(QueryException::class);
});

test('a change is audited as a settings change with before and after, and an unchanged save is not', function () {
    saveSettings(['general.site_name' => 'رَقَم الاقتصادية']);

    $row = DB::table('audit_log')->where('action', 'settings')->sole();
    expect($row->actor_user_id)->toBe($this->admin->id)->and($row->subject_table)->toBeNull();
    $change = DB::table('audit_log_changes')->where('audit_id', $row->id)->sole();
    expect($change->field)->toBe('general.site_name')->and($change->old_value)->toBe('رَقَم')->and($change->new_value)->toBe('رَقَم الاقتصادية');

    saveSettings(['general.site_name' => 'رَقَم الاقتصادية']);
    expect(DB::table('audit_log')->where('action', 'settings')->count())->toBe(1);
});

test('saving a value that did not change leaves the row, and who last changed it, alone', function () {
    saveSettings(['general.site_name' => 'اسم']);
    $first = DB::table('settings')->where('key', 'general.site_name')->first();

    $other = User::factory()->staff()->create();
    app(SettingsStore::class)->save(['general.site_name' => 'اسم'], [], $other->id);

    $after = DB::table('settings')->where('key', 'general.site_name')->first();
    expect($after->updated_by)->toBe($this->admin->id)->and($after->updated_at)->toBe($first->updated_at);
});

test('a setting that was never stored is created with its type', function () {
    DB::table('settings')->where('key', 'contact.address')->delete();

    saveSettings(['contact.address' => 'نابلس']);

    $setting = Setting::find('contact.address');
    expect($setting->value)->toBe('نابلس')->and($setting->value_type)->toBe('string');
});

test('social links are saved, changed and removed when emptied, each audited', function () {
    saveSettings([], ['facebook' => 'https://facebook.com/raqam']);
    expect($this->store->socialLinks())->toBe(['facebook' => 'https://facebook.com/raqam']);

    saveSettings([], ['facebook' => 'https://facebook.com/raqam.ps', 'x' => 'https://x.com/raqam']);
    saveSettings([], ['facebook' => '']);

    expect($this->store->socialLinks())->toBe(['x' => 'https://x.com/raqam']);
    $fields = DB::table('audit_log_changes')->orderBy('audit_id')->pluck('field')->all();
    expect($fields)->toContain('social.facebook', 'social.x');
});

test('a social link must be a web address', function (string $url) {
    expect(fn () => saveSettings([], ['facebook' => $url]))->toThrow(ValidationException::class);
    expect($this->store->socialLinks())->toBe([]);
})->with(['javascript:alert(1)', 'ftp://x.com/a', 'facebook.com/raqam', 'data:text/html,<script>']);

test('a platform that does not exist is ignored', function () {
    saveSettings([], ['myspace' => 'https://myspace.com/x']);

    expect($this->store->socialLinks())->toBe([]);
});

test('the settings screen loads the stored values and saves through the form', function () {
    Livewire::test(GeneralSettings::class)
        ->assertSet('data.general__site_name', 'رَقَم')
        ->fillForm(['general__site_name' => 'رَقَم الجديدة', 'contact__email' => 'hello@raqam.ps'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->store->values())->toMatchArray(['general.site_name' => 'رَقَم الجديدة', 'contact.email' => 'hello@raqam.ps']);
});

test('the screen shows an Arabic error under a field with a wrong value and saves nothing', function () {
    Livewire::test(GeneralSettings::class)
        ->fillForm(['contact__email' => 'nope', 'contact__phone' => '123'])
        ->call('save')
        ->assertHasFormErrors(['contact__email', 'contact__phone']);

    expect(Setting::find('contact.email')->value)->toBeNull();
});

test('the settings page is only for signed-in staff', function () {
    auth()->logout();
    $this->get('/'.config('raqam.admin_path').'/settings')->assertRedirect('/'.config('raqam.admin_path').'/login');

    $this->actingAs(User::factory()->create())->get('/'.config('raqam.admin_path').'/settings')->assertForbidden();
});
