<?php

use App\Filament\Resources\FeatureFlags\FeatureFlagResource;
use App\Filament\Resources\FeatureFlags\Pages\ListFeatureFlags;
use App\Models\FeatureFlag;
use App\Models\User;
use App\Settings\FeatureFlagRefused;
use App\Settings\FeatureFlags;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
    $this->flags = app(FeatureFlags::class);
});

test('a feature can be switched on and off, audited with before and after', function () {
    $this->flags->set('ad_popup', true, $this->admin->id);
    expect(FeatureFlag::find('ad_popup')->is_enabled)->toBeTrue();

    $this->flags->set('ad_popup', false, $this->admin->id);

    $rows = DB::table('audit_log')->where('action', 'settings')->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    $change = DB::table('audit_log_changes')->where('audit_id', $rows[0]->id)->sole();
    expect($change->field)->toBe('feature.ad_popup')->and($change->old_value)->toBe('false')->and($change->new_value)->toBe('true');
    expect(FeatureFlag::find('ad_popup')->updated_by)->toBe($this->admin->id);
});

test('payments cannot be switched on before booking, with a clear Arabic reason', function () {
    expect(fn () => $this->flags->set('online_payments', true, $this->admin->id, fromServer: true))
        ->toThrow(FeatureFlagRefused::class, 'لا يمكن تفعيل «الدفع الإلكتروني» قبل تفعيل «نظام الحجز الإلكتروني»');

    expect(FeatureFlag::find('online_payments')->is_enabled)->toBeFalse()
        ->and(DB::table('audit_log')->where('action', 'settings')->count())->toBe(0);
});

test('booking cannot be switched off while payments depend on it', function () {
    $this->flags->set('booking', true, $this->admin->id, fromServer: true);
    $this->flags->set('online_payments', true, $this->admin->id, fromServer: true);

    expect(fn () => $this->flags->set('booking', false, $this->admin->id, fromServer: true))
        ->toThrow(FeatureFlagRefused::class, 'تعتمد عليه: «الدفع الإلكتروني»');

    expect(FeatureFlag::find('booking')->is_enabled)->toBeTrue();
});

test('after a refusal the next switch still works (the failed statement is rolled back)', function () {
    $this->flags->set('chatbot', false, $this->admin->id);
    try {
        $this->flags->set('chatbot_ai', true, $this->admin->id);
    } catch (FeatureFlagRefused) {
    }

    $this->flags->set('ad_popup', true, $this->admin->id);

    expect(FeatureFlag::find('ad_popup')->is_enabled)->toBeTrue();
});

test('switching to the state a feature already has does nothing', function () {
    $this->flags->set('chatbot', true, $this->admin->id);

    expect(DB::table('audit_log')->count())->toBe(0);
});

test('an unknown feature is not found', function () {
    expect(fn () => $this->flags->set('nonsense', true, $this->admin->id))->toThrow(ModelNotFoundException::class);
});

test('the list shows every feature and flipping a switch saves it', function () {
    $component = Livewire::test(ListFeatureFlags::class)->assertCanSeeTableRecords(FeatureFlag::all());

    $component->call('updateTableColumnState', 'is_enabled', 'ad_popup', true);

    expect(FeatureFlag::find('ad_popup')->is_enabled)->toBeTrue();
});

test('flipping a switch the database refuses shows the reason and keeps it off', function () {
    $this->flags->set('chatbot', false, $this->admin->id);
    $component = Livewire::test(ListFeatureFlags::class)
        ->call('updateTableColumnState', 'is_enabled', 'chatbot_ai', true)
        ->assertNotified();

    // The switch on screen goes back to off and says why.
    expect($component->effects['returns'][0]['error'] ?? null)->toContain('قبل تفعيل');

    expect(FeatureFlag::find('chatbot_ai')->is_enabled)->toBeFalse();
});

test('features cannot be created or deleted from the panel', function () {
    $resource = FeatureFlagResource::class;

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canDelete(FeatureFlag::first()))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse()
        ->and(array_keys($resource::getPages()))->toBe(['index']);
});

// --- Locked in the panel until their site pages exist (phase 10 review N1) --------------------------------------------

test('booking, payments, customer accounts and roles are the switches only the server flips', function () {
    expect(FeatureFlags::SERVER_ONLY)->toEqualCanonicalizing(['booking', 'online_payments', 'customer_accounts', 'multi_admin']);
});

test('the panel cannot flip them, on or off, and nothing is saved or audited', function () {
    foreach (FeatureFlags::SERVER_ONLY as $key) {
        expect(fn () => $this->flags->set($key, true, $this->admin->id))->toThrow(FeatureFlagRefused::class, 'من السيرفر فقط');
    }
    DB::table('feature_flags')->where('key', 'customer_accounts')->update(['is_enabled' => true]);
    expect(fn () => $this->flags->set('customer_accounts', false, $this->admin->id))->toThrow(FeatureFlagRefused::class, 'من السيرفر فقط');

    expect(DB::table('feature_flags')->whereIn('key', FeatureFlags::SERVER_ONLY)->where('is_enabled', true)->pluck('key')->all())->toBe(['customer_accounts'])
        ->and(DB::table('audit_log')->where('action', 'settings')->count())->toBe(0);
});

test('on the screen their switches are locked, the others are not, and a forged flip changes nothing', function () {
    $component = Livewire::test(ListFeatureFlags::class);
    $column = $component->instance()->getTable()->getColumn('is_enabled');
    foreach (FeatureFlag::all() as $flag) {
        expect($column->record($flag)->isDisabled())->toBe(in_array($flag->key, FeatureFlags::SERVER_ONLY, true), $flag->key);
    }

    $component->call('updateTableColumnState', 'is_enabled', 'booking', true)
        ->call('updateTableColumnState', 'is_enabled', 'multi_admin', true);

    expect(DB::table('feature_flags')->whereIn('key', FeatureFlags::SERVER_ONLY)->where('is_enabled', true)->count())->toBe(0);
});

test('feature:switch on the server flips them, audited, with the same dependency rule', function () {
    $this->artisan('feature:switch', ['key' => 'online_payments', 'state' => 'on'])->assertFailed()
        ->expectsOutputToContain('قبل تفعيل «نظام الحجز الإلكتروني»');
    $this->artisan('feature:switch', ['key' => 'booking', 'state' => 'on'])->assertSuccessful()->expectsOutput('booking is on.');
    $this->artisan('feature:switch', ['key' => 'online_payments', 'state' => 'on'])->assertSuccessful();
    $this->artisan('feature:switch', ['key' => 'online_payments', 'state' => 'off'])->assertSuccessful();

    expect(FeatureFlag::find('booking')->is_enabled)->toBeTrue()
        ->and(FeatureFlag::find('online_payments')->is_enabled)->toBeFalse()
        ->and(FeatureFlag::find('booking')->updated_by)->toBeNull()
        ->and(DB::table('audit_log_changes')->where('field', 'like', 'feature.%')->orderBy('audit_id')->pluck('field')->all())
        ->toBe(['feature.booking', 'feature.online_payments', 'feature.online_payments']);
});

test('feature:switch refuses an unknown feature and a state other than on or off', function () {
    $this->artisan('feature:switch', ['key' => 'nonsense', 'state' => 'on'])->assertFailed()->expectsOutput('There is no feature "nonsense".');
    $this->artisan('feature:switch', ['key' => 'booking', 'state' => 'yes'])->assertExitCode(2);

    expect(FeatureFlag::find('booking')->is_enabled)->toBeFalse();
});
