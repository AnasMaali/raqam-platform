<?php

use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Models\Redirect;
use App\Models\User;
use App\Support\SafeUrl;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
    $this->actingAs($this->admin);
});

function addRedirect(array $data)
{
    return Livewire::test(ManageRedirects::class)->callAction(TestAction::make('create')->table(), $data);
}

test('a redirect is added with who made it, and audited', function () {
    addRedirect(['from_path' => '/old-page', 'to_path' => '/new-page', 'status_code' => 301])->assertHasNoFormErrors();

    $redirect = Redirect::sole();
    expect($redirect)->from_path->toBe('/old-page')->to_path->toBe('/new-page')->status_code->toBe(301)->created_by->toBe($this->admin->id);
    expect(DB::table('audit_log')->where('subject_table', 'redirects')->where('action', 'create')->exists())->toBeTrue();
});

test('an address that is gone (410) has no destination', function () {
    addRedirect(['from_path' => '/removed', 'to_path' => '/ignored', 'status_code' => 410])->assertHasNoFormErrors();

    expect(Redirect::sole())->status_code->toBe(410)->to_path->toBeNull();
});

test('the old address must be a path of the site', function (string $bad) {
    addRedirect(['from_path' => $bad, 'to_path' => '/x', 'status_code' => 301])->assertHasFormErrors(['from_path']);

    expect(Redirect::count())->toBe(0);
})->with(['no slash', 'https://other.example/a', '//host/a', '/has space', '', 'javascript:alert(1)']);

test('the new address must be safe: a path or a web address, never a script or another-site shortcut', function (string $bad) {
    addRedirect(['from_path' => '/old', 'to_path' => $bad, 'status_code' => 301])->assertHasFormErrors(['to_path']);

    expect(Redirect::count())->toBe(0);
})->with(['javascript:alert(1)', '//evil.example', '/\\evil.example', 'data:text/html,x', 'ftp://x.com', 'plain', '/has space']);

test('a redirect needs a destination unless it is a 410', function () {
    addRedirect(['from_path' => '/old', 'to_path' => '', 'status_code' => 301])->assertHasFormErrors(['to_path']);
});

test('a redirect to itself, to another redirect (a chain) or from an address that already redirects is refused', function () {
    addRedirect(['from_path' => '/a', 'to_path' => '/b', 'status_code' => 301]);

    addRedirect(['from_path' => '/self', 'to_path' => '/self', 'status_code' => 301])->assertHasFormErrors(['to_path']);
    addRedirect(['from_path' => '/c', 'to_path' => '/a', 'status_code' => 301])->assertHasFormErrors(['to_path']);
    addRedirect(['from_path' => '/a', 'to_path' => '/z', 'status_code' => 301])->assertHasFormErrors(['from_path']);

    expect(Redirect::count())->toBe(1);
});

test('a redirect can be changed to gone and deleted', function () {
    $redirect = Redirect::create(['from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);

    Livewire::test(ManageRedirects::class)->callAction(TestAction::make('edit')->table($redirect), ['from_path' => '/old', 'to_path' => '/new', 'status_code' => 410])->assertHasNoFormErrors();
    expect($redirect->fresh())->status_code->toBe(410)->to_path->toBeNull();

    Livewire::test(ManageRedirects::class)->callAction(TestAction::make('delete')->table($redirect));
    expect(Redirect::count())->toBe(0);
});

test('the list shows the redirects made by the application, searchable by path and filterable by type', function () {
    DB::table('redirects')->insert([
        ['from_path' => '/news/1/a', 'to_path' => '/news/1/b', 'status_code' => 301],
        ['from_path' => '/news/2', 'to_path' => null, 'status_code' => 410],
    ]);

    Livewire::test(ManageRedirects::class)
        ->assertCanSeeTableRecords(Redirect::all())
        ->searchTable('/news/2')->assertCanSeeTableRecords(Redirect::where('status_code', 410)->get())->assertCanNotSeeTableRecords(Redirect::where('status_code', 301)->get())
        ->searchTable('')
        ->filterTable('status_code', 301)->assertCanSeeTableRecords(Redirect::where('status_code', 301)->get())->assertCanNotSeeTableRecords(Redirect::where('status_code', 410)->get());
});

test('the safe-address rule', function (string $url, bool $ok) {
    expect(SafeUrl::isSafe($url))->toBe($ok);
})->with([
    ['/', true], ['/a/b?c=1#d', true], ['/مسار/عربي', true], ['https://example.com', true], ['http://example.com/a', true],
    ['//host', false], ['/\\host', false], ['javascript:alert(1)', false], ['JAVASCRIPT:alert(1)', false], ['data:x', false],
    ['https://', false], ['', false], ['/a b', false], ["/a\nb", false], [str_repeat('/a', 300), false],
]);

test('the screen is for signed-in staff only', function () {
    auth()->logout();
    $this->get('/'.config('raqam.admin_path').'/redirects')->assertRedirect();
});
