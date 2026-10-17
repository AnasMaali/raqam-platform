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
    expect(fn () => $this->flags->set('online_payments', true, $this->admin->id))
        ->toThrow(FeatureFlagRefused::class, 'لا يمكن تفعيل «الدفع الإلكتروني» قبل تفعيل «نظام الحجز الإلكتروني»');

    expect(FeatureFlag::find('online_payments')->is_enabled)->toBeFalse()
        ->and(DB::table('audit_log')->where('action', 'settings')->count())->toBe(0);
});

test('booking cannot be switched off while payments depend on it', function () {
    $this->flags->set('booking', true, $this->admin->id);
    $this->flags->set('online_payments', true, $this->admin->id);

    expect(fn () => $this->flags->set('booking', false, $this->admin->id))
        ->toThrow(FeatureFlagRefused::class, 'تعتمد عليه: «الدفع الإلكتروني»');

    expect(FeatureFlag::find('booking')->is_enabled)->toBeTrue();
});

test('after a refusal the next switch still works (the failed statement is rolled back)', function () {
    try {
        $this->flags->set('online_payments', true, $this->admin->id);
    } catch (FeatureFlagRefused) {
    }

    $this->flags->set('booking', true, $this->admin->id);

    expect(FeatureFlag::find('booking')->is_enabled)->toBeTrue();
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
    $component = Livewire::test(ListFeatureFlags::class)
        ->call('updateTableColumnState', 'is_enabled', 'online_payments', true)
        ->assertNotified();

    // The switch on screen goes back to off and says why.
    expect($component->effects['returns'][0]['error'] ?? null)->toContain('قبل تفعيل');

    expect(FeatureFlag::find('online_payments')->is_enabled)->toBeFalse();
});

test('features cannot be created or deleted from the panel', function () {
    $resource = FeatureFlagResource::class;

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canDelete(FeatureFlag::first()))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse()
        ->and(array_keys($resource::getPages()))->toBe(['index']);
});
