<?php

use App\Audit\AuditLogger;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\LoginAttempts\LoginAttemptResource;
use App\Filament\Resources\LoginAttempts\Pages\ListLoginAttempts;
use App\Models\AuditLog;
use App\Models\AuditLogChange;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Support\TrackingHash;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create(['name' => 'المدير']);
    $this->actingAs($this->admin);
});

/** An audit row written straight into the table (INSERT is allowed; only UPDATE and DELETE are refused). */
function auditRow(array $values, array $changes = []): int
{
    $id = DB::table('audit_log')->insertGetId(['action' => 'update', ...$values]);
    foreach ($changes as $field => [$old, $new]) {
        DB::table('audit_log_changes')->insert(['audit_id' => $id, 'field' => $field, 'old_value' => $old, 'new_value' => $new]);
    }

    return $id;
}

// ---- reading -------------------------------------------------------------------------------------------

test('a row shows who, when (Palestine time), from where (device and HMAC only) and every field before and after', function () {
    $id = auditRow(['actor_user_id' => $this->admin->id, 'subject_table' => 'posts', 'subject_id' => 12, 'occurred_at' => '2026-10-20 09:30:00+00',
        'ip_hash' => TrackingHash::of('198.51.100.7'), 'user_agent' => PERSON_AGENT], ['title' => ['قديم', 'جديد'], 'status' => ['draft', 'published']]);

    Livewire::test(ViewAuditLog::class, ['record' => $id])
        ->assertSee('2026-10-20 12:30:00')->assertSee('المدير')->assertSee('المواد #12')->assertSee('Chrome — Android')
        ->assertSee(substr(TrackingHash::of('198.51.100.7'), 0, 16))->assertDontSee('198.51.100.7')
        ->assertSee('قديم')->assertSee('جديد')->assertSee('draft')->assertSee('published');
    Livewire::test(ListAuditLogs::class)->assertSee('المواد #12')->assertSee('Chrome — Android');
});

test('one search box finds by user, item name, item number, field and the values before or after', function () {
    $editor = User::factory()->staff()->create(['name' => 'سلمى المحررة']);
    $byEditor = auditRow(['actor_user_id' => $editor->id, 'subject_table' => 'services', 'subject_id' => 5]);
    $post = auditRow(['actor_user_id' => $this->admin->id, 'subject_table' => 'posts', 'subject_id' => 4321], ['title' => ['سعر الذهب', 'أسعار الذهب اليوم']]);
    $setting = auditRow(['action' => 'settings', 'actor_user_id' => $this->admin->id], ['contact.phone' => [null, '+970599000111']]);
    $rows = fn (int ...$ids) => AuditLog::query()->whereKey($ids)->get();

    Livewire::test(ListAuditLogs::class)
        ->searchTable('سلمى')->assertCanSeeTableRecords($rows($byEditor))->assertCanNotSeeTableRecords($rows($post, $setting))
        ->searchTable('الخدمات')->assertCanSeeTableRecords($rows($byEditor))->assertCanNotSeeTableRecords($rows($post))
        ->searchTable('4321')->assertCanSeeTableRecords($rows($post))->assertCanNotSeeTableRecords($rows($byEditor))
        ->searchTable('اليوم')->assertCanSeeTableRecords($rows($post))->assertCanNotSeeTableRecords($rows($setting))
        ->searchTable('سعر الذهب')->assertCanSeeTableRecords($rows($post))
        ->searchTable('contact.phone')->assertCanSeeTableRecords($rows($setting))->assertCanNotSeeTableRecords($rows($post))
        ->searchTable('100%_x')->assertCanNotSeeTableRecords($rows($byEditor, $post, $setting));
});

test('the filters: item, a period in Palestine days, and an address looked up by its HMAC', function () {
    $lateNight = auditRow(['subject_table' => 'posts', 'subject_id' => 1, 'occurred_at' => '2026-10-19 22:30:00+00', 'ip_hash' => TrackingHash::of('203.0.113.9')]);   // 20th in Palestine
    $earlier = auditRow(['subject_table' => 'redirects', 'subject_id' => 2, 'occurred_at' => '2026-10-19 12:00:00+00', 'ip_hash' => TrackingHash::of('198.51.100.1')]);
    $rows = fn (int ...$ids) => AuditLog::query()->whereKey($ids)->get();

    Livewire::test(ListAuditLogs::class)
        ->filterTable('subject_table', 'redirects')->assertCanSeeTableRecords($rows($earlier))->assertCanNotSeeTableRecords($rows($lateNight))
        ->resetTableFilters()
        ->filterTable('period', ['from' => '2026-10-20', 'until' => '2026-10-20'])->assertCanSeeTableRecords($rows($lateNight))->assertCanNotSeeTableRecords($rows($earlier))
        ->resetTableFilters()
        ->filterTable('address', ['ip' => '198.51.100.1'])->assertCanSeeTableRecords($rows($earlier))->assertCanNotSeeTableRecords($rows($lateNight))
        ->resetTableFilters()
        ->filterTable('address', ['ip' => 'not an address'])->assertCanSeeTableRecords($rows($earlier, $lateNight));
});

// ---- nobody changes or deletes it (22.15) ---------------------------------------------------------------

test('the panel has no way to add, change or delete a row: no page, no action, no bulk action', function () {
    $id = AuditLogger::record('create', 'posts', 3, ['title' => [null, 'x']], $this->admin->id);
    $record = AuditLog::query()->find($id);

    expect(AuditLogResource::getPages())->toHaveKeys(['index', 'view'])->toHaveCount(2)
        ->and(AuditLogResource::canCreate())->toBeFalse()->and(AuditLogResource::canEdit($record))->toBeFalse()
        ->and(AuditLogResource::canDelete($record))->toBeFalse()->and(AuditLogResource::canDeleteAny())->toBeFalse()
        ->and(LoginAttemptResource::getPages())->toHaveKeys(['index'])->toHaveCount(1);

    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())
        ->filter(fn (string $uri) => str_contains($uri, 'audit-logs') || str_contains($uri, 'login-attempts'))->values();
    expect($routes->filter(fn (string $uri) => preg_match('~/(create|edit)~', $uri)))->toBeEmpty()->and($routes->count())->toBe(3);
    $this->get(AuditLogResource::getUrl('index').'/'.$id.'/edit')->assertNotFound();

    Livewire::test(ListAuditLogs::class)
        ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete')->assertActionDoesNotExist('create');
    Livewire::test(ViewAuditLog::class, ['record' => $id])->assertActionDoesNotExist('edit')->assertActionDoesNotExist('delete');
    Livewire::test(ListLoginAttempts::class)->assertTableActionDoesNotExist('delete')->assertTableBulkActionDoesNotExist('delete');
});

test('the models refuse to save or delete, and the database refuses whatever goes around them', function () {
    $id = AuditLogger::record('create', 'posts', 3, ['title' => [null, 'x']], $this->admin->id);
    $row = AuditLog::query()->find($id);
    $change = AuditLogChange::query()->where('audit_id', $id)->first();

    expect(fn () => $row->forceFill(['action' => 'delete'])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class)
        ->and(fn () => $change->delete())->toThrow(LogicException::class)
        ->and(fn () => (new AuditLog)->forceFill(['action' => 'create'])->save())->toThrow(LogicException::class)
        ->and(fn () => (new LoginAttempt)->forceFill(['email' => 'a@b.example', 'succeeded' => true])->save())->toThrow(LogicException::class)
        ->and(rejected(fn () => DB::table('audit_log')->where('id', $id)->update(['action' => 'delete'])))->toThrow(QueryException::class, 'append-only')
        ->and(rejected(fn () => DB::table('audit_log')->where('id', $id)->delete()))->toThrow(QueryException::class, 'append-only');
    expect(DB::table('audit_log')->where('id', $id)->value('action'))->toBe('create');
});

// ---- two years, then pruned (22.17) ------------------------------------------------------------------

test('audit:prune deletes only what is older than two years, with its fields, and the sign-in attempts as old', function () {
    $old = auditRow(['occurred_at' => DB::raw("now() - interval '2 years' - interval '1 day'")], ['title' => ['a', 'b']]);
    $young = auditRow(['occurred_at' => DB::raw("now() - interval '2 years' + interval '1 day'")], ['title' => ['c', 'd']]);
    $new = auditRow([]);
    DB::table('login_attempts')->insert([
        ['email' => 'old@raqam.example', 'succeeded' => true, 'attempted_at' => DB::raw("now() - interval '2 years' - interval '1 day'")],
        ['email' => 'young@raqam.example', 'succeeded' => true, 'attempted_at' => DB::raw("now() - interval '700 days'")],
    ]);

    $this->artisan('audit:prune')->assertSuccessful()->expectsOutputToContain('Deleted 1 audit rows and 1 sign-in attempts');

    expect(DB::table('audit_log')->orderBy('id')->pluck('id')->all())->toBe([$young, $new])
        ->and(DB::table('audit_log_changes')->where('audit_id', $old)->count())->toBe(0)
        ->and(DB::table('audit_log_changes')->where('audit_id', $young)->count())->toBe(1)
        ->and(DB::table('login_attempts')->pluck('email')->all())->toBe(['young@raqam.example']);
});

test('a longer retention setting keeps more; a shorter one never prunes before two years', function () {
    $threeYears = auditRow(['occurred_at' => DB::raw("now() - interval '3 years'")]);
    $thirtyMonths = auditRow(['occurred_at' => DB::raw("now() - interval '30 months'")]);
    $oneYear = auditRow(['occurred_at' => DB::raw("now() - interval '1 year'")]);

    DB::table('settings')->where('key', 'audit.retention_days')->update(['value' => '1000']);
    $this->artisan('audit:prune')->assertSuccessful();
    expect(DB::table('audit_log')->orderBy('id')->pluck('id')->all())->toBe([$thirtyMonths, $oneYear]);

    DB::table('settings')->where('key', 'audit.retention_days')->update(['value' => '30']);
    $this->artisan('audit:prune')->assertSuccessful();
    expect(DB::table('audit_log')->orderBy('id')->pluck('id')->all())->toBe([$oneYear])->and($threeYears)->toBeInt();
});

test('audit:prune runs every night', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'audit:prune'));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('10 3 * * *');
});

// ---- sign-in attempts (22.16) ------------------------------------------------------------------------

test('the sign-in attempts: time, e-mail, result in words, the device by its browser once approved, the address by HMAC', function () {
    $device = hash('sha256', 'device-1');
    DB::table('user_devices')->insert(['user_id' => $this->admin->id, 'device_hash' => $device, 'user_agent' => DESKTOP_AGENT]);
    DB::table('login_attempts')->insert([
        ['email' => 'admin@raqam.example', 'user_id' => $this->admin->id, 'succeeded' => true, 'failure_reason' => null, 'device_hash' => $device, 'ip_hash' => TrackingHash::of('198.51.100.7'), 'attempted_at' => '2026-10-20 09:00:00+00'],
        ['email' => 'admin@raqam.example', 'user_id' => $this->admin->id, 'succeeded' => false, 'failure_reason' => 'bad_code', 'device_hash' => hash('sha256', 'new'), 'ip_hash' => null, 'attempted_at' => '2026-10-20 08:00:00+00'],
        ['email' => 'stranger@example.com', 'user_id' => null, 'succeeded' => false, 'failure_reason' => 'bad_credentials', 'device_hash' => null, 'ip_hash' => TrackingHash::of('203.0.113.4'), 'attempted_at' => '2026-10-19 08:00:00+00'],
    ]);

    Livewire::test(ListLoginAttempts::class)->assertOk()
        ->assertSee('2026-10-20 12:00:00')->assertSee('نجح')->assertSee('رمز خاطئ')->assertSee('بريد أو كلمة مرور خاطئة')
        ->assertSee('Chrome — Windows')->assertSee('جهاز لم يُعتمد بعد')->assertSee('جهاز غير معروف')
        ->assertDontSee('198.51.100.7')->assertSeeInOrder(['2026-10-20 12:00:00', '2026-10-20 11:00:00', '2026-10-19 11:00:00']);
});

test('the sign-in attempts can be searched and filtered by result, reason, period and address', function () {
    DB::table('login_attempts')->insert([
        ['email' => 'admin@raqam.example', 'succeeded' => true, 'failure_reason' => null, 'ip_hash' => TrackingHash::of('198.51.100.7'), 'attempted_at' => '2026-10-20 09:00:00+00'],
        ['email' => 'stranger@example.com', 'succeeded' => false, 'failure_reason' => 'throttled', 'ip_hash' => TrackingHash::of('203.0.113.4'), 'attempted_at' => '2026-10-18 09:00:00+00'],
    ]);
    $ok = LoginAttempt::query()->where('succeeded', true)->get();
    $bad = LoginAttempt::query()->where('succeeded', false)->get();

    Livewire::test(ListLoginAttempts::class)
        ->searchTable('stranger')->assertCanSeeTableRecords($bad)->assertCanNotSeeTableRecords($ok)->searchTable('')
        ->filterTable('result', 'ok')->assertCanSeeTableRecords($ok)->assertCanNotSeeTableRecords($bad)
        ->filterTable('result', 'throttled')->assertCanSeeTableRecords($bad)->assertCanNotSeeTableRecords($ok)
        ->resetTableFilters()
        ->filterTable('period', ['from' => '2026-10-20', 'until' => '2026-10-20'])->assertCanSeeTableRecords($ok)->assertCanNotSeeTableRecords($bad)
        ->resetTableFilters()
        ->filterTable('address', ['ip' => '203.0.113.4'])->assertCanSeeTableRecords($bad)->assertCanNotSeeTableRecords($ok);
});

test('the screens are for signed-in staff only', function () {
    $urls = [AuditLogResource::getUrl('index'), LoginAttemptResource::getUrl('index')];
    auth()->logout();

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect();
    }
});

test('a search for % or _ looks for those characters, not for anything', function () {
    $percent = auditRow(['subject_table' => 'services', 'subject_id' => 1], ['discount' => ['10', '50%']]);
    $plain = auditRow(['subject_table' => 'services', 'subject_id' => 2], ['discount' => ['10', '20']]);

    Livewire::test(ListAuditLogs::class)->searchTable('%')
        ->assertCanSeeTableRecords(AuditLog::query()->whereKey($percent)->get())->assertCanNotSeeTableRecords(AuditLog::query()->whereKey($plain)->get());
});

test('a device approved by another account does not name an attempt of this one', function () {
    $other = User::factory()->staff()->create();
    $device = hash('sha256', 'shared-cookie');
    DB::table('user_devices')->insert(['user_id' => $other->id, 'device_hash' => $device, 'user_agent' => DESKTOP_AGENT]);
    DB::table('login_attempts')->insert(['email' => 'admin@raqam.example', 'user_id' => $this->admin->id, 'succeeded' => false, 'failure_reason' => 'bad_code', 'device_hash' => $device]);

    Livewire::test(ListLoginAttempts::class)->assertSee('جهاز لم يُعتمد بعد')->assertDontSee('Chrome — Windows');
});
