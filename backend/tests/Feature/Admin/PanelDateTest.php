<?php

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\LoginAttempts\Pages\ListLoginAttempts;
use App\Filament\Resources\NewsletterIssues\Pages\ListNewsletterIssues;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\User;
use App\Panel\PanelDate;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Every date and time in the panel (PanelDate): Palestine time, 2026-11-21 21:18 (with seconds only in the security records), digits 0-9, one left-to-right run
 * inside the Arabic page so its parts keep their order. Its order on screen is read in a browser (panel-dates.spec.ts).
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->staff()->create());
});

test('Palestine time, winter and summer, in one shape, with digits 0-9 even under the Arabic locale', function () {
    App::setLocale('ar');

    expect(PanelDate::format('2026-11-21 19:18:00+00'))->toBe('2026-11-21 21:18')   // UTC+2
        ->and(PanelDate::format('2026-07-01 12:05:59+00'))->toBe('2026-07-01 15:05')  // UTC+3, seconds left out
        ->and(PanelDate::format(now()->setTimezone('Europe/London')->setDateTime(2026, 11, 21, 19, 18)))->toBe('2026-11-21 21:18')
        ->and(PanelDate::format(null))->toBeNull()
        ->and(PanelDate::format(''))->toBeNull();
});

test('HTML: one left-to-right run; text: the same run between the isolates, never cut', function () {
    expect(PanelDate::html('2026-11-21 19:18:00+00')->toHtml())->toBe('<bdi dir="ltr" class="raqam-date">2026-11-21 21:18</bdi>')
        ->and(PanelDate::html(null))->toBeNull()
        ->and(PanelDate::text('2026-11-21 19:18:00+00'))->toBe("\u{2066}2026\u{2011}11\u{2011}21\u{00A0}21:18\u{2069}")
        ->and(PanelDate::text(null))->toBeNull();
});

test('the security records\' variant: the same zone, shape and run, with seconds', function () {
    App::setLocale('ar');

    expect(PanelDate::format('2026-11-21 19:18:05+00', seconds: true))->toBe('2026-11-21 21:18:05')
        ->and(PanelDate::format('2026-07-01 12:05:59.9+00', seconds: true))->toBe('2026-07-01 15:05:59')
        ->and(PanelDate::html('2026-11-21 19:18:05+00', seconds: true)->toHtml())->toBe('<bdi dir="ltr" class="raqam-date">2026-11-21 21:18:05</bdi>')
        ->and(PanelDate::format(null, seconds: true))->toBeNull();
});

test('a calendar day keeps its date: no time added, not moved to another day', function () {
    expect(PanelDate::day('2026-11-21')->toHtml())->toBe('<bdi dir="ltr" class="raqam-date">2026-11-21</bdi>')
        ->and(PanelDate::day(null))->toBeNull();
});

test('the lists draw their times through it', function () {
    $id = insertPost(['title' => 'خبر بموعد']);
    DB::table('posts')->where('id', $id)->update(['published_at' => '2026-11-21 19:18:00+00']);

    expect(Livewire::test(ListPosts::class)->html())->toContain('<bdi dir="ltr" class="raqam-date">2026-11-21 21:18</bdi>');

    // The security records keep their seconds.
    DB::table('audit_log')->insert(['occurred_at' => '2026-07-01 12:05:09+00', 'actor_user_id' => auth()->id(), 'action' => 'login']);
    expect(Livewire::test(ListAuditLogs::class)->html())->toContain('<bdi dir="ltr" class="raqam-date">2026-07-01 15:05:09</bdi>');

    DB::table('login_attempts')->insert(['email' => 'someone@raqam.example', 'succeeded' => false, 'failure_reason' => 'bad_credentials', 'attempted_at' => '2026-07-01 12:05:09+00']);
    expect(Livewire::test(ListLoginAttempts::class)->html())->toContain('<bdi dir="ltr" class="raqam-date">2026-07-01 15:05:09</bdi>');
});

test('the newsletter\'s weeks show their first day as a date', function () {
    DB::table('newsletter_issues')->insert(['week_start' => '2026-11-21', 'status' => 'draft', 'subject' => 'عدد الأسبوع']);

    expect(Livewire::test(ListNewsletterIssues::class)->html())->toContain('<bdi dir="ltr" class="raqam-date">2026-11-21</bdi>');
});

test('no screen of the panel formats a date or a time of its own: each goes through PanelDate', function () {
    // What is not shown in the panel: the values of a sample e-mail, an export's file name. A value written to a field
    // or saved (with its seconds, `Y-m-d H:i:s`) is not shown either.
    $elsewhere = [
        'app/Filament/Resources/EmailTemplates/EmailTemplateResource.php',
        'app/Filament/Resources/Subscribers/Pages/ListSubscribers.php',
    ];
    $offenders = [];
    foreach (['app/Filament', 'app/Livewire', 'resources/views/filament', 'resources/views/livewire', 'resources/views/preview'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());
            if (in_array($path, $elsewhere, true)) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match("~->(dateTime|date|since|dateTimeTooltip|dateTooltip)\\(|translatedFormat\\(|format\\(\\s*['\"]Y-m-d(?! H:i:s)~", $line)) {
                    $offenders[] = $path.':'.($n + 1);
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('only the security records show seconds: the audit log (list and details) and the sign-in attempts', function () {
    $allowed = [
        'app/Filament/Resources/AuditLogs/AuditLogResource.php' => 2,       // the list's column and the details' entry
        'app/Filament/Resources/LoginAttempts/LoginAttemptResource.php' => 1,
    ];
    $found = [];
    foreach (['app', 'resources/views'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace(base_path().'/', '', $file->getPathname());
            if ($path === 'app/Panel/PanelDate.php') {
                continue;
            }
            $uses = preg_match_all('~PanelDate::withSeconds\(|seconds:\s*true|FORMAT_SECONDS~', file_get_contents($file->getPathname()));
            if ($uses > 0) {
                $found[$path] = $uses;
            }
        }
    }
    ksort($found);

    expect($found)->toBe($allowed);
});
