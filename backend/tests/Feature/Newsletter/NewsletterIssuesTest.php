<?php

use App\Content\PostPath;
use App\Filament\Pages\GeneralSettings;
use App\Filament\Resources\NewsletterIssues\Pages\EditNewsletterIssue;
use App\Filament\Resources\NewsletterIssues\Pages\ListNewsletterIssues;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Models\Subscriber;
use App\Models\User;
use App\Newsletter\NewsletterIssues;
use App\Newsletter\NewsletterStats;
use App\Newsletter\SubscriberExport;
use App\Panel\ConnectionStatus;
use App\Settings\SettingsStore;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'array']);
    Cache::flush();
    brevoOn();
    fakeOutside();
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->staff()->create();
});

function weekPost(string $title, array $overrides = []): int
{
    return insertPost(publishedPostValues(['title' => $title, ...$overrides]));
}

function setting(string $key, string $value, string $type = 'string'): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'value_type' => $type]);
}

test('the newsletter week starts on Saturday, Palestine time', function (string $now, string $start) {
    expect(NewsletterIssues::weekStart(CarbonImmutable::parse($now, 'Asia/Hebron'))->toDateString())->toBe($start);
})->with([
    ['2026-10-24 00:30', '2026-10-24'], ['2026-10-30 23:59', '2026-10-24'], ['2026-10-23 12:00', '2026-10-17'], ['2026-10-25 09:00', '2026-10-24'],
]);

test('the draft is due on the admin\'s day once the time has come, and only once a week', function () {
    setting('newsletter.send_day', 'sunday');
    setting('newsletter.send_time', '10:30', 'time');
    $issues = app(NewsletterIssues::class);

    expect($issues->isDue(CarbonImmutable::parse('2026-10-25 10:29', 'Asia/Hebron')))->toBeFalse()
        ->and($issues->isDue(CarbonImmutable::parse('2026-10-25 10:30', 'Asia/Hebron')))->toBeTrue()
        ->and($issues->isDue(CarbonImmutable::parse('2026-10-26 10:30', 'Asia/Hebron')))->toBeFalse();

    $issues->createDraft(CarbonImmutable::parse('2026-10-25 10:30', 'Asia/Hebron'));
    expect($issues->isDue(CarbonImmutable::parse('2026-10-25 11:00', 'Asia/Hebron')))->toBeFalse();
});

test('the draft takes the week\'s published posts, featured first then newest, at most eight', function () {
    $old = weekPost('قديم', ['published_at' => now()->subDays(8)]);
    $featured = weekPost('مميز', ['published_at' => now()->subDays(3), 'is_featured' => true]);
    $news = collect(range(1, 8))->map(fn ($i) => weekPost("خبر {$i}", ['published_at' => now()->subHours(10 - $i)]));
    insertPost(['title' => 'مسودة']);

    $id = app(NewsletterIssues::class)->createDraft();

    $picked = DB::table('newsletter_issue_posts')->where('issue_id', $id)->orderBy('sort_order')->pluck('post_id')->all();
    expect($picked)->toHaveCount(8)->and($picked[0])->toBe($featured)->and($picked[1])->toBe($news->last())
        ->and($picked)->not->toContain($old)
        ->and(app(NewsletterIssues::class)->createDraft())->toBeNull();
});

test('the draft keeps to the last seven days', function () {
    $old = weekPost('قديم', ['published_at' => now()->subDays(7)->subMinute()]);
    $new = weekPost('جديد', ['published_at' => now()->subDays(6)]);

    $id = app(NewsletterIssues::class)->createDraft();
    expect(DB::table('newsletter_issue_posts')->where('issue_id', $id)->pluck('post_id')->all())->toBe([$new]);
});

test('the draft goes to Brevo as a draft campaign for our list, never scheduled or sent; the admins are told', function () {
    weekPost('خبر الأسبوع');
    $id = app(NewsletterIssues::class)->createDraft();

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === 'https://brevo.test/v3/emailCampaigns'
        && $r['recipients'] === ['listIds' => [3]] && ! isset($r['scheduledAt']) && str_contains($r['htmlContent'], 'خبر الأسبوع'));
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'sendNow') || str_contains($r->url(), 'schedule') || str_contains($r->url(), 'sendTest'));
    expect(DB::table('newsletter_issues')->where('id', $id)->first(['status', 'brevo_campaign_id']))->toEqual((object) ['status' => 'sent_to_brevo', 'brevo_campaign_id' => '77'])
        ->and(DB::table('admin_notifications')->where('user_id', $this->admin->id)->value('title'))->toBe('مسودة النشرة الأسبوعية جاهزة للمراجعة');
});

test('when Brevo is down the draft stays with us, and the admins are told to send it from the panel', function () {
    fakeOutside(brevoStatus: 503);
    weekPost('خبر');
    $id = app(NewsletterIssues::class)->createDraft();

    expect(DB::table('newsletter_issues')->where('id', $id)->value('status'))->toBe('draft')
        ->and(DB::table('admin_notifications')->where('user_id', $this->admin->id)->value('body'))->toContain('لم تصل إلى Brevo');
});

test('the campaign is RTL, links every post to the site (tagged as the newsletter\'s visit), escapes the texts and carries both unsubscribe links', function () {
    weekPost('<script>alert(1)</script> عنوان', ['excerpt' => 'ملخص <b>عريض</b>']);
    $id = app(NewsletterIssues::class)->createDraft();
    DB::table('newsletter_issues')->where('id', $id)->update(['intro' => "سطر <i>أول</i>\nسطر ثانٍ"]);

    $html = app(NewsletterIssues::class)->html($id);
    $post = DB::table('posts')->first();
    expect($html)->toContain('dir="rtl"')->toContain('href="https://raqam.example'.PostPath::for($post->kind_code, $post->id, $post->slug).'?utm_source=newsletter"')
        ->not->toContain('<script>')->toContain('&lt;script&gt;')->not->toContain('<b>عريض</b>')->toContain('سطر &lt;i&gt;أول&lt;/i&gt;<br>')
        ->toContain('href="{{ unsubscribe }}"')->toContain('href="{{ contact.RAQAM_UNSUB }}"');
});

test('editing a draft checks it and updates Brevo\'s copy; a sent newsletter cannot be edited', function () {
    $a = weekPost('أ');
    $b = weekPost('ب');
    $id = app(NewsletterIssues::class)->createDraft();

    app(NewsletterIssues::class)->save($id, 'عنوان جديد', 'مقدمة', [$b, $a], null);
    expect(DB::table('newsletter_issue_posts')->where('issue_id', $id)->orderBy('sort_order')->pluck('post_id')->all())->toBe([$b, $a]);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && $r->url() === 'https://brevo.test/v3/emailCampaigns/77' && $r['subject'] === 'عنوان جديد');

    expect(fn () => app(NewsletterIssues::class)->save($id, 'x', null, [insertPost(['title' => 'مسودة'])], null))->toThrow(ValidationException::class, 'منشورة');
    DB::table('newsletter_issues')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now()]);
    expect(fn () => app(NewsletterIssues::class)->save($id, 'عنوان', null, [$a], null))->toThrow(ValidationException::class, 'أُرسلت');
});

test('Brevo\'s numbers are read (19.10), and a campaign Brevo sent is marked sent', function () {
    weekPost('خبر');
    $id = app(NewsletterIssues::class)->createDraft();

    expect(app(NewsletterIssues::class)->stats($id))->toMatchArray(['status' => 'sent', 'delivered' => 120, 'opens' => 60, 'clicks' => 15, 'unsubscribes' => 2])
        ->and(DB::table('newsletter_issues')->where('id', $id)->value('status'))->toBe('sent')
        ->and(DB::table('newsletter_issues')->where('id', $id)->value('sent_at'))->not->toBeNull();
});

test('the commands run at their times', function () {
    $events = collect(app(Schedule::class)->events());
    expect($events->first(fn ($e) => str_contains($e->command, 'newsletter:weekly-draft'))->expression)->toBe('*/15 * * * *')
        ->and($events->first(fn ($e) => str_contains($e->command, 'newsletter:refresh-stats'))->expression)->toBe('20 * * * *');

    $this->artisan('newsletter:weekly-draft --now')->assertSuccessful();
    expect(DB::table('newsletter_issues')->count())->toBe(1);
});

test('the counts and the weekly growth of subscribers (19.10)', function () {
    $now = CarbonImmutable::parse('2026-10-28 12:00', 'Asia/Hebron');
    $insert = fn (string $email, string $status, string $confirmed, ?string $left = null) => DB::table('subscribers')->insert([
        'email' => $email, 'status' => $status, 'confirmed_at' => $confirmed, 'unsubscribed_at' => $left, 'source_placement' => 'footer']);
    $insert('a@example.com', 'active', '2026-10-10 10:00+00');
    $insert('b@example.com', 'active', '2026-10-25 10:00+00');
    $insert('c@example.com', 'unsubscribed', '2026-10-11 10:00+00', '2026-10-26 10:00+00');

    $growth = collect(NewsletterStats::growth(3, $now))->keyBy('week');
    expect($growth['2026-10-10'])->toBe(['week' => '2026-10-10', 'joined' => 2, 'left' => 0, 'total' => 2])
        ->and($growth['2026-10-24'])->toBe(['week' => '2026-10-24', 'joined' => 1, 'left' => 1, 'total' => 2])
        ->and(NewsletterStats::totals()['active'])->toBe(2);
});

test('the subscribers screen shows the numbers, exports Excel (audited, text cells) and deletes', function () {
    $this->actingAs($this->admin);
    $id = DB::table('subscribers')->insertGetId(['email' => 'reader@example.com', 'name' => '=HYPERLINK("x")', 'status' => 'active', 'confirmed_at' => now(), 'source_placement' => 'footer']);

    Livewire::test(ListSubscribers::class)->assertSee('مشتركون الآن: 1')->assertSee('reader@example.com')
        ->callAction('export', ['which' => 'all'])->assertFileDownloaded();
    expect(DB::table('audit_log_changes')->where('field', 'subscribers.rows')->value('new_value'))->toBe('1');

    // The reader would rebuild a formula from any text starting with "="; what matters is the file: no <f> in it.
    $path = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($path, SubscriberExport::xlsx(null, null));
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($path);
    expect($sheet)->toContain('rightToLeft="true"')->toContain('=HYPERLINK(&quot;x&quot;)')->not->toContain('<f>');

    Livewire::test(ListSubscribers::class)->callAction(TestAction::make('delete')->table(Subscriber::query()->find($id)));
    expect(DB::table('subscribers')->count())->toBe(0);
});

test('the newsletters screen makes this week\'s draft, edits it and previews it in a sandboxed frame', function () {
    $this->actingAs($this->admin);
    $a = weekPost('مادة أ');
    Livewire::test(ListNewsletterIssues::class)->callAction('draftNow');
    $id = DB::table('newsletter_issues')->value('id');

    Livewire::test(EditNewsletterIssue::class, ['record' => $id])
        ->fillForm(['subject' => 'عنوان معدّل', 'intro' => 'مقدمة'])->call('save')->assertHasNoFormErrors()
        ->mountAction('preview')->assertMountedActionModalSeeHtml('sandbox=""');
    expect(DB::table('newsletter_issues')->where('id', $id)->value('subject'))->toBe('عنوان معدّل');
});

test('the newsletter day is chosen from the week\'s days; anything else is refused', function () {
    $this->actingAs($this->admin);
    $name = app(SettingsStore::class)->fieldName('newsletter.send_day');

    expect(fn () => app(SettingsStore::class)->save(['newsletter.send_day' => 'someday'], [], null))->toThrow(ValidationException::class);
    app(SettingsStore::class)->save(['newsletter.send_day' => 'monday'], [], null);
    expect(DB::table('settings')->where('key', 'newsletter.send_day')->value('value'))->toBe('monday');
    Livewire::test(GeneralSettings::class)->assertSee('يوم تجهيز النشرة الأسبوعية');
});

test('the home shows Brevo\'s connection: off without a key, then from its health', function () {
    $state = fn () => collect(ConnectionStatus::all(false))->firstWhere('name', 'النشرة (Brevo)');
    expect($state()['state'])->toBe(ConnectionStatus::UNKNOWN)->and($state()['key'])->toBe('مضبوط');

    config(['raqam.newsletter.brevo.key' => null]);
    expect($state()['state'])->toBe(ConnectionStatus::OFF)->and(json_encode(ConnectionStatus::all(false)))->not->toContain('xkeysib');
});
