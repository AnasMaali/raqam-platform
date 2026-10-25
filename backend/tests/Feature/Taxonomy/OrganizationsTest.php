<?php

use App\Filament\Forms\OrganizationSelect;
use App\Filament\Resources\Organizations\Pages\ManageOrganizations;
use App\Filament\Resources\Sectors\Pages\ManageSectors;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/* A company's sector, city and founding year (5.2, 5.3): entered in the panel, shown with its stories and products. */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin = User::factory()->staff()->create());
});

test('the admin keeps the list of sectors; each gets an Arabic address and the last place', function () {
    Livewire::test(ManageSectors::class)->callAction(TestAction::make('create')->table(), ['name_ar' => 'الصناعات الغذائية'])->assertHasNoFormErrors();
    Livewire::test(ManageSectors::class)->callAction(TestAction::make('create')->table(), ['name_ar' => 'الحجر والرخام'])->assertHasNoFormErrors();
    Livewire::test(ManageSectors::class)->callAction(TestAction::make('create')->table(), ['name_ar' => 'الحجر والرخام'])->assertHasFormErrors(['name_ar']);

    expect(DB::table('sectors')->orderBy('sort_order')->pluck('slug')->all())->toBe(['الصناعات-الغذائية', 'الحجر-والرخام']);
});

test('a company added from a form carries its sector, city and founding year; wrong values are refused', function () {
    $sector = DB::table('sectors')->insertGetId(['name_ar' => 'الصابون', 'slug' => 'soap']);
    $nablus = DB::table('cities')->where('slug', 'نابلس')->value('id');

    $id = OrganizationSelect::create(['name' => 'مصنع الصابون', 'website' => 'https://soap.example', 'sector_id' => $sector, 'city_id' => $nablus, 'founded_year' => '1872']);

    expect(Organization::find($id)->only(['sector_id', 'city_id', 'founded_year']))->toBe(['sector_id' => $sector, 'city_id' => $nablus, 'founded_year' => 1872]);
    foreach ([['founded_year' => 1600], ['founded_year' => (int) date('Y') + 2], ['sector_id' => 9999], ['city_id' => 999999], ['website' => 'javascript:alert(1)']] as $wrong) {
        expect(fn () => OrganizationSelect::create(['name' => 'جهة', ...$wrong]))->toThrow(ValidationException::class);
    }
});

test('the companies screen completes a company: sector, city, year; the change is audited', function () {
    $org = Organization::create(['name' => 'مصنع الحلاوة', 'slug' => 'halawa']);
    $sector = DB::table('sectors')->insertGetId(['name_ar' => 'الحلويات', 'slug' => 'sweets']);

    Livewire::test(ManageOrganizations::class)->assertCanSeeTableRecords([$org])
        ->callTableAction('edit', $org, ['name' => 'مصنع الحلاوة', 'sector_id' => $sector, 'founded_year' => 1950])->assertHasNoTableActionErrors();

    expect($org->fresh()->only(['sector_id', 'founded_year']))->toBe(['sector_id' => $sector, 'founded_year' => 1950])
        ->and(DB::table('audit_log')->where('subject_table', 'organizations')->where('subject_id', $org->id)->where('action', 'update')->exists())->toBeTrue();
});

test('a success story and a product show their company\'s sector, city and founding year', function () {
    $sector = DB::table('sectors')->insertGetId(['name_ar' => 'الصابون', 'slug' => 'soap']);
    $org = Organization::create(['name' => 'مصنع الصابون', 'slug' => 'soap-factory', 'sector_id' => $sector, 'city_id' => DB::table('cities')->where('slug', 'نابلس')->value('id'), 'founded_year' => 1872]);
    $story = insertPost(['kind_code' => 'story', 'status' => 'published', 'published_at' => now(), 'body' => '<p>x</p>', 'cover_media_id' => insertMedia()]);
    DB::table('success_stories')->insert(['post_id' => $story, 'kind_code' => 'story', 'organization_id' => $org->id]);

    $company = api("/posts/{$story}")->assertOk()->json('data.details.organization');

    expect($company)->toMatchArray(['sector' => ['name' => 'الصابون', 'slug' => 'soap'], 'city' => ['name' => 'نابلس', 'slug' => 'نابلس'], 'founded_year' => 1872, 'logo' => null]);
    matchesContract($company, 'Organization');
});
