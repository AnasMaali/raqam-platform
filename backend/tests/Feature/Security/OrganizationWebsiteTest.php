<?php

use App\Filament\Forms\OrganizationSelect;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('an organisation website must be http or https', function (string $url) {
    expect(fn () => OrganizationSelect::create(['name' => 'جهة', 'website' => $url]))->toThrow(ValidationException::class);
    expect(Organization::count())->toBe(0);
})->with(['data://text/html;base64,PHNjcmlwdD4=', 'view-source://x', 'blob://x', 'gopher://x', 'ftp://x', 'javascript:alert(1)', 'mailto://a@b']);

test('a normal website, or none, is accepted', function () {
    expect(OrganizationSelect::create(['name' => 'جهة أولى', 'website' => 'https://example.com/ar']))->toBeInt();
    expect(OrganizationSelect::create(['name' => 'جهة ثانية', 'website' => '']))->toBeInt();
    expect(Organization::count())->toBe(2);
});
