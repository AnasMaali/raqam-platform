<?php

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function panelUrl(string $path = ''): string
{
    return '/'.config('raqam.admin_path').$path;
}

test('the panel path comes from ADMIN_PATH and is not the usual one', function () {
    expect(config('raqam.admin_path'))->toBe('test-panel');

    $this->get('/admin')->assertNotFound();
    $this->get('/admin/login')->assertNotFound();
});

test('without ADMIN_PATH the panel sits at a random path nobody can guess', function () {
    config(['raqam.admin_path' => null]);
    $path = fn () => (new AdminPanelProvider(app()))->panel(Panel::make())->getPath();

    expect($path())->toMatch('/^[0-9a-f]{32}$/')->and($path())->not->toBe($path());
});

test('a guest is sent to the sign-in page', function () {
    $this->get(panelUrl())->assertRedirect(panelUrl('/login'));
});

test('an active staff account enters the panel', function () {
    $this->followingRedirects()->actingAs(User::factory()->staff()->create())->get(panelUrl())->assertOk()->assertSee('dir="rtl"', false);
});

test('a customer account is refused', function () {
    $this->actingAs(User::factory()->create())->get(panelUrl())->assertForbidden();
});

test('an inactive staff account is refused', function () {
    $this->actingAs(User::factory()->staff()->create(['is_active' => false]))->get(panelUrl())->assertForbidden();
});

test('a deleted staff account is refused', function () {
    $user = User::factory()->staff()->create();
    $user->forceFill(['deleted_at' => now()])->save();

    expect($user->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeFalse();
});

test('the panel is in Arabic and right-to-left', function () {
    $this->get(panelUrl('/login'))->assertOk()->assertSee('dir="rtl"', false)->assertSee('lang="ar"', false);
});
