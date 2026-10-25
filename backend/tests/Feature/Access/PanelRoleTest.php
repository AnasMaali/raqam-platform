<?php

use App\Access\Access;
use App\Mail\SystemMail;
use App\Models\User;
use App\Panel\AdminAlerts;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/* Decision 2026-10-25: the panel is for an active staff account with a role; while multi_admin is off only «admin». */

beforeEach(fn () => Filament::setCurrentPanel('admin'));

function panelEditorRole(): int
{
    return DB::table('roles')->where('code', 'editor')->value('id') ?? DB::table('roles')->insertGetId(['code' => 'editor', 'name_ar' => 'محرر']);
}

test('who may enter: admin yes; no role no; another role only once multi_admin is on; inactive or deleted never', function () {
    $admin = User::factory()->staff()->create();
    $bare = User::factory()->bareStaff()->create();
    $editor = User::factory()->bareStaff()->create();
    DB::table('user_roles')->insert(['user_id' => $editor->id, 'role_id' => panelEditorRole()]);
    $inactive = User::factory()->staff()->create(['is_active' => false]);
    $customer = User::factory()->create(['user_type' => 'customer']);
    $access = app(Access::class);

    expect($access->mayEnterPanel($admin))->toBeTrue()->and($access->mayEnterPanel($bare))->toBeFalse()->and($access->mayEnterPanel($editor))->toBeFalse()
        ->and($access->mayEnterPanel($inactive))->toBeFalse()->and($access->mayEnterPanel($customer))->toBeFalse();

    DB::table('feature_flags')->where('key', 'multi_admin')->update(['is_enabled' => true]);
    expect($access->mayEnterPanel($editor))->toBeTrue()->and($access->mayEnterPanel($bare))->toBeFalse()->and($access->mayEnterPanel($inactive))->toBeFalse();
});

test('an admin who loses the role is out of the panel at the next request', function () {
    $admin = User::factory()->staff()->create();
    $this->actingAs($admin);
    $this->get(Filament::getPanel('admin')->getUrl())->assertOk();

    DB::table('user_roles')->where('user_id', $admin->id)->delete();

    expect($this->get(Filament::getPanel('admin')->getUrl())->status())->toBeIn([302, 403]);
});

test('alerts and admin mail reach only those who may enter the panel', function () {
    config(['mail.default' => 'array']);
    $admin = staffAdmin('owner@raqam.example');
    $bare = insertUser('staff', 'bare@raqam.example');

    AdminAlerts::raise('system', 'role-test', 'تنبيه تجريبي');

    expect(DB::table('admin_notifications')->pluck('user_id')->map(fn ($id) => (int) $id)->all())->toBe([$admin])
        ->and(SystemMail::adminEmails())->toBe(['owner@raqam.example']);
});
