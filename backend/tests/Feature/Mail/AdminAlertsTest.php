<?php

use App\Ads\AdAlerts;
use App\Ads\AdCampaigns;
use App\Integrations\ProviderHealth;
use App\Jobs\SendSystemEmail;
use App\Mail\TemplatedEmail;
use App\Models\AdCampaign;
use App\Panel\AdminAlerts;
use App\Panel\SystemFailures;
use App\Panel\WeeklyDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'array']);
    $this->a = staffAdmin('a@raqam.example');
    $this->b = staffAdmin('b@raqam.example');
    staffAdmin('off@raqam.example', ['is_active' => false]);
});

function disk(): callable
{
    return fn () => new TemplatedEmail('admin_disk_space', ['المساحة' => '1 GB', 'النسبة' => '5%']);
}

test('an alert rings the bell of every active admin and e-mails each of them', function () {
    expect(AdminAlerts::raise('system', 'disk', 'مساحة السيرفر', 'تفاصيل', null, disk()))->toBeTrue();

    expect(DB::table('admin_notifications')->orderBy('user_id')->pluck('user_id')->all())->toBe([$this->a, $this->b])
        ->and(AdminAlerts::unreadCount($this->a))->toBe(1)
        ->and(collect(sentEmails())->map(fn ($m) => $m->getTo()[0]->getAddress())->sort()->values()->all())->toBe(['a@raqam.example', 'b@raqam.example']);
});

test('the same alert is raised at most once an hour, by the database clock (20.11)', function () {
    AdminAlerts::raise('system', 'disk', 'مساحة', null, null, disk());
    expect(AdminAlerts::raise('system', 'disk', 'مساحة', null, null, disk()))->toBeFalse()
        ->and(AdminAlerts::raise('system', 'other', 'شيء آخر'))->toBeTrue();

    DB::table('admin_notifications')->where('dedupe_key', 'disk')->update(['created_at' => DB::raw("now() - interval '59 minutes'")]);
    expect(AdminAlerts::raise('system', 'disk', 'مساحة', null, null, disk()))->toBeFalse();
    DB::table('admin_notifications')->where('dedupe_key', 'disk')->update(['created_at' => DB::raw("now() - interval '61 minutes'")]);
    expect(AdminAlerts::raise('system', 'disk', 'مساحة', null, null, disk()))->toBeTrue();

    expect(DB::table('admin_notifications')->where('dedupe_key', 'disk')->count())->toBe(4)
        ->and(sentEmailsTo('a@raqam.example'))->toHaveCount(2);
});

test('an admin marks only their own alerts read', function () {
    AdminAlerts::raise('system', 'x', 'تنبيه');
    $mine = DB::table('admin_notifications')->where('user_id', $this->a)->value('id');
    $theirs = DB::table('admin_notifications')->where('user_id', $this->b)->value('id');

    expect(AdminAlerts::markRead($this->a, $theirs))->toBe(0)
        ->and(AdminAlerts::markRead($this->a, $mine))->toBe(1)
        ->and(AdminAlerts::unreadCount($this->a))->toBe(0)->and(AdminAlerts::unreadCount($this->b))->toBe(1);
});

test('a source failing a third time in a row e-mails the admins, once an hour (20.4)', function () {
    ProviderHealth::failure('openweathermap', 'انتهت المهلة');
    ProviderHealth::failure('openweathermap', 'انتهت المهلة');
    expect(sentEmails())->toBe([]);

    ProviderHealth::failure('openweathermap', 'انتهت المهلة');
    ProviderHealth::failure('openweathermap', 'انتهت المهلة');

    $mails = sentEmailsTo('a@raqam.example');
    expect($mails)->toHaveCount(1)->and($mails[0]->getSubject())->toBe('تنبيه: مصدر الطقس (OpenWeather) لا يستجيب')
        ->and($mails[0]->getHtmlBody())->toContain('فشل 3 مرات متتالية')->toContain('انتهت المهلة');
});

test('a video that turned private or was deleted on YouTube alerts the admins with the posts that use it', function () {
    config(['raqam.youtube.key' => 'yt-key']);
    $private = insertYoutubeVideo('aaaaaaaaaaa');
    $gone = insertYoutubeVideo('bbbbbbbbbbb');
    insertYoutubeVideo('ccccccccccc');
    Http::fake(['*' => Http::response(['items' => [
        ['id' => 'aaaaaaaaaaa', 'status' => ['privacyStatus' => 'private', 'uploadStatus' => 'processed', 'embeddable' => true], 'snippet' => ['title' => 'حلقة'], 'contentDetails' => ['duration' => 'PT1M']],
        ['id' => 'ccccccccccc', 'status' => ['privacyStatus' => 'public', 'uploadStatus' => 'processed', 'embeddable' => true], 'snippet' => ['title' => 'حلقة'], 'contentDetails' => ['duration' => 'PT1M']],
    ]])]);

    $this->artisan('videos:check-availability')->assertSuccessful();

    $titles = DB::table('admin_notifications')->where('user_id', $this->a)->orderBy('id')->pluck('action_path')->all();
    expect($titles)->toBe(["videos/{$private}/edit", "videos/{$gone}/edit"])
        ->and(collect(sentEmailsTo('a@raqam.example'))->map->getSubject()->all())->toBe(['تنبيه: فيديو يوتيوب صار خاصاً', 'تنبيه: فيديو يوتيوب حُذف']);

    // The next weekly check finds them the same: no new alert.
    $this->artisan('videos:check-availability')->assertSuccessful();
    expect(DB::table('admin_notifications')->where('user_id', $this->a)->count())->toBe(2);
});

test('a job that gave up alerts the admins; when the job was an e-mail, only the bell rings', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\FetchCityWeather');
    event(new JobFailed('redis', $job, new RuntimeException('appid=secret-key timed out')));

    $mailJob = Mockery::mock(Job::class);
    $mailJob->shouldReceive('resolveName')->andReturn(SendSystemEmail::class);
    event(new JobFailed('redis', $mailJob, new RuntimeException('SMTP down')));

    $rows = DB::table('admin_notifications')->where('user_id', $this->a)->orderBy('id')->get(['title', 'body', 'category']);
    expect($rows->pluck('title')->all())->toBe(['فشلت المهمة «FetchCityWeather»', 'فشلت المهمة «SendSystemEmail»'])
        ->and($rows[0]->body)->not->toContain('secret-key')->and($rows[0]->category)->toBe('system')
        ->and(collect(sentEmailsTo('a@raqam.example'))->map->getSubject()->all())->toBe(['تنبيه: فشلت مهمة «FetchCityWeather»']);
});

test('a scheduled task that throws or exits with an error alerts the admins, once an hour per task', function () {
    $schedule = app(Schedule::class);
    $task = collect($schedule->events())->first(fn ($e) => str_contains($e->command, 'currency:fetch'));

    event(new ScheduledTaskFailed($task, new RuntimeException('boom')));
    $task->exitCode = 1;
    event(new ScheduledTaskFinished($task, 1.0));
    $ok = collect($schedule->events())->first(fn ($e) => str_contains($e->command, 'weather:fetch'));
    $ok->exitCode = 0;
    event(new ScheduledTaskFinished($ok, 1.0));

    expect(DB::table('admin_notifications')->where('user_id', $this->a)->pluck('title')->all())->toBe(['فشلت المهمة المجدولة «currency:fetch»'])
        ->and(SystemFailures::taskName($task))->toBe('currency:fetch');
});

test('a nearly full disk alerts the admins (20.4); a roomy one does not', function () {
    config(['raqam.alerts.disk_min_free_percent' => 0, 'raqam.alerts.disk_min_free_gb' => 0]);
    $this->artisan('system:check-disk')->assertSuccessful();
    expect(DB::table('admin_notifications')->count())->toBe(0);

    config(['raqam.alerts.disk_min_free_percent' => 101]);
    $this->artisan('system:check-disk')->assertSuccessful();
    expect(DB::table('admin_notifications')->where('user_id', $this->a)->value('title'))->toBe('مساحة السيرفر قاربت على النفاد')
        ->and(sentEmailsTo('a@raqam.example')[0]->getSubject())->toBe('تنبيه: مساحة السيرفر قاربت على النفاد');
});

test('a campaign ending within three days is e-mailed once; a new end gets its own warning (13.9)', function () {
    $soon = adCampaign(['name' => 'حملة قريبة', 'ends_at' => now()->addDays(2)->utc()->format('Y-m-d H:i:s')]);
    adCampaign(['name' => 'حملة بعيدة', 'ends_at' => now()->addDays(10)->utc()->format('Y-m-d H:i:s')]);
    adCampaign(['name' => 'مسودة', 'status' => 'draft', 'ends_at' => now()->addDay()->utc()->format('Y-m-d H:i:s')]);

    $this->artisan('ads:ending-alerts')->assertSuccessful();
    $this->artisan('ads:ending-alerts')->assertSuccessful();

    expect(collect(sentEmailsTo('a@raqam.example'))->map->getSubject()->all())->toBe(['حملة «حملة قريبة» تنتهي قريباً'])
        ->and(AdCampaign::query()->find($soon->id)->ending_alert_sent_at)->not->toBeNull();

    // Extended: warned again when the new end comes near.
    app(AdCampaigns::class)->save($soon->fresh(), adCampaignData(['advertiser_id' => $soon->advertiser_id, 'name' => 'حملة قريبة', 'ends_at' => now()->addDays(2)->addHour()->utc()->format('Y-m-d H:i:s')]), null);
    expect(AdCampaign::query()->find($soon->id)->ending_alert_sent_at)->toBeNull();
    $this->artisan('ads:ending-alerts')->assertSuccessful();
    expect(sentEmailsTo('a@raqam.example'))->toHaveCount(2)->and(AdAlerts::sendEnding())->toBe(0);
});

test('the scheduler runs the alerts and the weekly summary at their times', function () {
    $events = collect(app(Schedule::class)->events());
    $find = fn (string $command) => $events->first(fn ($e) => str_contains($e->command, $command));

    expect($find('ads:ending-alerts')->expression)->toBe('0 * * * *')
        ->and($find('system:check-disk')->expression)->toBe('0 * * * *')
        ->and($find('admin:weekly-digest')->expression)->toBe('0 9 * * 6')
        ->and($find('admin:weekly-digest')->timezone)->toBe('Asia/Hebron');
});

test('the weekly summary counts the week before and escapes what visitors wrote (20.12)', function () {
    $today = CarbonImmutable::parse('2026-10-24 09:00', 'Asia/Hebron');   // a Saturday
    DB::table('subscribers')->insert([
        ['email' => 'in@example.com', 'source_placement' => 'footer', 'status' => 'active', 'confirmed_at' => '2026-10-20 10:00:00+00'],
        ['email' => 'before@example.com', 'source_placement' => 'footer', 'status' => 'active', 'confirmed_at' => '2026-10-10 10:00:00+00'],
        ['email' => 'pending@example.com', 'source_placement' => 'footer', 'status' => 'pending', 'confirmed_at' => null],
    ]);
    $c = adCampaign(['name' => 'حملة <b>الخريف</b>']);
    DB::table('ad_stats_daily')->insert([
        ['campaign_id' => $c->id, 'placement_code' => 'header_leaderboard', 'stat_date' => '2026-10-20', 'impressions' => 1000, 'clicks' => 25],
        ['campaign_id' => $c->id, 'placement_code' => 'header_leaderboard', 'stat_date' => '2026-10-24', 'impressions' => 999, 'clicks' => 99],   // today: next week
    ]);
    $conv = DB::table('bot_conversations')->insertGetId(['session_hash' => str_repeat('a', 64)]);
    foreach (['<script>alert(1)</script> متى تفتحون؟', '<script>alert(1)</script> متى تفتحون؟', 'سؤال آخر'] as $q) {
        DB::table('bot_messages')->insert(['conversation_id' => $conv, 'role' => 'user', 'content' => $q, 'created_at' => '2026-10-21 10:00:00+00']);
    }
    DB::table('contact_messages')->insert(['name' => 'س', 'email' => 's@example.com', 'subject' => 'م', 'message' => 'نص', 'created_at' => '2026-10-22 10:00:00+00']);

    $d = WeeklyDigest::build($today);

    expect($d['label'])->toBe('2026-10-17 – 2026-10-23')
        ->and($d['numbers'])->toBe(['subscribers' => 1, 'unanswered' => 3, 'messages' => 1, 'campaigns' => 1])
        ->and($d['html'])->toContain('مشتركون جدد: 1 — المجموع الآن: 2')->toContain('>1000<')->toContain('>25<')->toContain('2.50%')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; متى تفتحون؟ (2)')->toContain('حملة &lt;b&gt;الخريف&lt;/b&gt;')
        ->not->toContain('<script>')->not->toContain('<b>الخريف')
        ->toContain('تُضاف مع مرحلة الإحصائيات');

    WeeklyDigest::send($today);
    $mail = sentEmailsTo('a@raqam.example')[0];
    expect($mail->getSubject())->toBe('ملخص رَقَم الأسبوعي: 2026-10-17 – 2026-10-23')->and($mail->getHtmlBody())->not->toContain('<script>')
        ->and($mail->getTextBody())->toContain('أسئلة بدون جواب: 3');
});
