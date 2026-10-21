<?php

use App\Audit\AuditLogger;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create(['name' => 'المدير']);
    $this->url = '/'.config('raqam.admin_path');
});

// ---- the back end serves no public pages --------------------------------------------------------

test('the root of the back end is a 404 and the starter page is gone', function () {
    $this->get('/')->assertNotFound();

    expect(file_exists(resource_path('views/welcome.blade.php')))->toBeFalse();
});

// ---- scheduler ----------------------------------------------------------------------------------

test('failed jobs older than 30 days are pruned every day', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'queue:prune-failed'));

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('--hours=720')
        ->and($event->expression)->toBe('0 0 * * *');
});

// ---- the look: Arabic, hosted here, usable on a phone -------------------------------------------

test('the panel font is served from this site, with no CDN anywhere in the page', function () {
    $html = $this->get($this->url.'/login')->assertOk()->getContent();

    expect($html)->toContain('/css/panel-font.css')
        ->not->toMatch('~(fonts\.googleapis|fonts\.gstatic|fonts\.bunny|cdn\.jsdelivr|unpkg|cdnjs)~i');
    expect(file_exists(public_path('css/panel-font.css')))->toBeTrue();
});

test('every font file the stylesheet names exists on disk', function () {
    preg_match_all('~url\((/fonts/[^)]+)\)~', file_get_contents(public_path('css/panel-font.css')), $m);

    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $file) {
        expect(file_exists(public_path($file)))->toBeTrue("missing {$file}");
    }
});

test('the page declares a mobile viewport', function () {
    $this->get($this->url.'/login')->assertSee('name="viewport" content="width=device-width', false);
});

// ---- the home -----------------------------------------------------------------------------------

test('the dashboard has its cards and says where each one gets its numbers', function () {
    $this->actingAs($this->admin)->followingRedirects()->get($this->url)->assertOk()
        ->assertSee('زيارات اليوم')->assertSee('الأكثر قراءة')->assertSee('رسائل جديدة')
        ->assertSee('حملات إعلانية فعّالة')->assertSee('حالة الربط الخارجي')->assertSee('التنبيهات');
});

test('the menu links the settings, the switches and the audit log', function () {
    $this->actingAs($this->admin)->followingRedirects()->get($this->url)
        ->assertSee($this->url.'/settings', false)->assertSee($this->url.'/feature-flags', false)->assertSee($this->url.'/audit-logs', false);
});

// ---- the audit log screen -----------------------------------------------------------------------

function logEntry(string $action, ?int $actor, array $changes = []): int
{
    return AuditLogger::record($action, 'posts', 7, $changes, $actor);
}

test('the audit log lists operations newest first and shows a record with its changes', function () {
    $first = logEntry('create', $this->admin->id, ['title' => [null, 'عنوان']]);
    $second = logEntry('update', $this->admin->id, ['title' => ['عنوان', 'عنوان جديد']]);
    $this->actingAs($this->admin);

    Livewire::test(ListAuditLogs::class)->assertCanSeeTableRecords(AuditLog::orderByDesc('id')->get(), inOrder: true);

    Livewire::test(ViewAuditLog::class, ['record' => $second])
        ->assertSee('عنوان جديد')->assertSee('تعديل')->assertSee('المدير');
});

test('the log can be filtered by user, by type and by period', function () {
    $other = User::factory()->staff()->create(['name' => 'محرر']);
    $mine = logEntry('create', $this->admin->id);
    $theirs = logEntry('delete', $other->id);
    $this->actingAs($this->admin);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('actor_user_id', $other->id)
        ->assertCanSeeTableRecords(AuditLog::whereKey($theirs)->get())->assertCanNotSeeTableRecords(AuditLog::whereKey($mine)->get())
        ->resetTableFilters()
        ->filterTable('action', 'create')
        ->assertCanSeeTableRecords(AuditLog::whereKey($mine)->get())->assertCanNotSeeTableRecords(AuditLog::whereKey($theirs)->get());
});

test('the period filter keeps only operations inside the dates', function () {
    $old = logEntry('create', $this->admin->id);
    $recent = logEntry('update', $this->admin->id);
    DB::statement('ALTER TABLE audit_log DISABLE TRIGGER audit_log_append_only');
    DB::table('audit_log')->where('id', $old)->update(['occurred_at' => '2026-01-10 12:00:00+00']);
    DB::table('audit_log')->where('id', $recent)->update(['occurred_at' => '2026-03-10 12:00:00+00']);
    DB::statement('ALTER TABLE audit_log ENABLE TRIGGER audit_log_append_only');
    $this->actingAs($this->admin);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('period', ['from' => '2026-03-01', 'until' => '2026-03-10'])
        ->assertCanSeeTableRecords(AuditLog::whereKey($recent)->get())->assertCanNotSeeTableRecords(AuditLog::whereKey($old)->get())
        ->filterTable('period', ['from' => '2026-01-01', 'until' => '2026-01-10'])
        ->assertCanSeeTableRecords(AuditLog::whereKey($old)->get())->assertCanNotSeeTableRecords(AuditLog::whereKey($recent)->get());
});

test('the audit log is read only: no create, edit or delete', function () {
    $id = logEntry('create', $this->admin->id);
    $record = AuditLog::find($id);

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($record))->toBeFalse()
        ->and(AuditLogResource::canDelete($record))->toBeFalse()
        ->and(AuditLogResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(AuditLogResource::getPages()))->toBe(['index', 'view']);

    $this->actingAs($this->admin)->get($this->url.'/audit-logs/create')->assertNotFound();
    $this->get($this->url."/audit-logs/{$id}/edit")->assertNotFound();
    $this->get($this->url.'/audit-logs/not-a-number')->assertNotFound();
    $this->get($this->url.'/audit-logs/99999999999999999999')->assertNotFound();
});

test('a customer account cannot open the audit log', function () {
    $this->actingAs(User::factory()->create())->get($this->url.'/audit-logs')->assertForbidden();
});
