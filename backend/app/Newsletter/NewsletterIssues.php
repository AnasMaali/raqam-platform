<?php

namespace App\Newsletter;

use App\Api\Presenter;
use App\Audit\AuditLogger;
use App\Mail\TemplatedEmail;
use App\Models\Post;
use App\Panel\AdminAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The weekly newsletter (19.6): on the day and at the time the admin set (settings newsletter.send_day / send_time,
 * Palestine), a draft is made from the week's best published posts and handed to Brevo as a draft campaign. The admin
 * reviews it there and sends it there: nothing here ever sends or schedules a campaign. The week runs Saturday to Friday.
 */
final class NewsletterIssues
{
    public const DAYS = ['saturday' => 'السبت', 'sunday' => 'الأحد', 'monday' => 'الإثنين', 'tuesday' => 'الثلاثاء',
        'wednesday' => 'الأربعاء', 'thursday' => 'الخميس', 'friday' => 'الجمعة'];

    public const STATUSES = ['draft' => 'مسودة (عندنا)', 'sent_to_brevo' => 'مسودة في Brevo', 'sent' => 'أُرسلت'];

    public function __construct(private BrevoClient $brevo, private Presenter $presenter) {}

    public static function weekStart(CarbonImmutable $now): CarbonImmutable
    {
        $local = $now->setTimezone('Asia/Hebron')->startOfDay();

        return $local->subDays(($local->dayOfWeek + 1) % 7);   // Saturday = 6 → 0 days back
    }

    /** Whether this week's draft should be made now: its day and time have come and it does not exist yet. */
    public function isDue(CarbonImmutable $now): bool
    {
        $day = (string) (DB::table('settings')->where('key', 'newsletter.send_day')->value('value') ?: 'saturday');
        $time = (string) (DB::table('settings')->where('key', 'newsletter.send_time')->value('value') ?: '09:00');
        $local = $now->setTimezone('Asia/Hebron');

        return strtolower($local->englishDayOfWeek) === $day && $local->format('H:i') >= $time
            && ! DB::table('newsletter_issues')->where('week_start', self::weekStart($now)->toDateString())->exists();
    }

    /** Makes this week's draft from the last seven days' posts and hands it to Brevo; null when it exists already. */
    public function createDraft(?CarbonImmutable $now = null, ?int $userId = null): ?int
    {
        $now ??= CarbonImmutable::now();
        $week = self::weekStart($now);
        $posts = Post::query()->live()->where('published_at', '>', $now->subDays(7))
            ->orderByDesc('is_featured')->orderByDesc('published_at')->orderByDesc('id')
            ->limit((int) config('raqam.newsletter.issue_posts'))->pluck('id');

        $id = DB::transaction(function () use ($week, $posts, $userId) {
            $id = DB::table('newsletter_issues')->insertOrIgnore([
                'week_start' => $week->toDateString(), 'subject' => 'نشرة رَقَم الأسبوعية — '.$week->format('Y-m-d'),
                'intro' => 'أهم ما نشرناه هذا الأسبوع في الاقتصاد والأعمال.', 'created_by' => $userId,
            ]) === 1 ? (int) DB::table('newsletter_issues')->where('week_start', $week->toDateString())->value('id') : null;
            if ($id !== null) {
                DB::table('newsletter_issue_posts')->insert($posts->values()->map(fn ($p, $i) => ['issue_id' => $id, 'post_id' => $p, 'sort_order' => $i])->all());
            }

            return $id;
        });
        if ($id === null) {
            return null;
        }

        $pushed = $this->tryPush($id);
        AdminAlerts::raise('content', 'newsletter:'.$week->toDateString(), 'مسودة النشرة الأسبوعية جاهزة للمراجعة',
            $pushed ? 'المسودة في Brevo: راجعها وأرسلها من هناك.' : 'لم تصل إلى Brevo بعد؛ راجعها وأرسلها إليه من شاشة النشرات.', "newsletter-issues/{$id}/edit");

        return $id;
    }

    /**
     * @param  list<int|string>  $postIds
     *
     * @throws ValidationException
     */
    public function save(int $id, string $subject, ?string $intro, array $postIds, ?int $userId): void
    {
        $status = DB::table('newsletter_issues')->where('id', $id)->value('status');
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject));
        $ids = collect($postIds)->filter(fn ($p) => is_numeric($p))->map(fn ($p) => (int) $p)->unique()->values();
        $errors = [];
        if ($status === 'sent') {
            $errors['subject'] = 'أُرسلت هذه النشرة؛ لا يمكن تعديلها.';
        }
        if ($subject === '' || mb_strlen($subject) > 200) {
            $errors['subject'] ??= 'العنوان مطلوب، وحتى 200 حرف.';
        }
        if ($intro !== null && mb_strlen($intro) > 2000) {
            $errors['intro'] = 'المقدمة حتى 2000 حرف.';
        }
        if ($ids->isEmpty() || $ids->count() > 20 || Post::query()->live()->whereIn('id', $ids)->count() !== $ids->count()) {
            $errors['posts'] = 'اختر من 1 إلى 20 مادة منشورة.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($id, $subject, $intro, $ids, $userId) {
            $before = DB::table('newsletter_issues')->where('id', $id)->first(['subject', 'intro']);
            $beforePosts = DB::table('newsletter_issue_posts')->where('issue_id', $id)->orderBy('sort_order')->pluck('post_id')->implode(',');
            $intro = filled($intro) ? trim($intro) : null;
            DB::table('newsletter_issues')->where('id', $id)->update(['subject' => $subject, 'intro' => $intro, 'updated_at' => DB::raw('now()')]);
            DB::table('newsletter_issue_posts')->where('issue_id', $id)->delete();
            DB::table('newsletter_issue_posts')->insert($ids->map(fn ($p, $i) => ['issue_id' => $id, 'post_id' => $p, 'sort_order' => $i])->all());

            // 22.14: every change made in the panel is in the audit trail.
            $changes = array_filter([
                'subject' => $before->subject !== $subject ? [$before->subject, $subject] : null,
                'intro' => $before->intro !== $intro ? [$before->intro, $intro] : null,
                'posts' => $beforePosts !== $ids->implode(',') ? [$beforePosts, $ids->implode(',')] : null,
            ]);
            if ($changes !== []) {
                AuditLogger::record('update', 'newsletter_issues', $id, $changes, $userId);
            }
        });
        if ($status === 'sent_to_brevo') {
            $this->tryPush($id);   // keep Brevo's draft the same as ours
        }
    }

    /** Creates the draft campaign in Brevo, or updates it; never sends. @throws BrevoUnavailable */
    public function pushToBrevo(int $id): void
    {
        $issue = DB::table('newsletter_issues')->where('id', $id)->first();
        if ($issue === null || $issue->status === 'sent') {
            return;
        }
        $html = $this->html($id);
        if ($issue->brevo_campaign_id) {
            $this->brevo->updateDraftCampaign((string) $issue->brevo_campaign_id, $issue->subject, $html);

            return;
        }
        $sender = TemplatedEmail::sender('info');
        $campaign = $this->brevo->createDraftCampaign('Raqam '.$issue->week_start, $issue->subject, $html, $sender->address, $sender->name ?? 'رَقَم');
        DB::table('newsletter_issues')->where('id', $id)->update(['brevo_campaign_id' => $campaign, 'status' => 'sent_to_brevo', 'updated_at' => DB::raw('now()')]);
    }

    public function tryPush(int $id): bool
    {
        if (! BrevoClient::configured()) {
            return false;
        }
        try {
            $this->pushToBrevo($id);

            return true;
        } catch (BrevoUnavailable) {
            return false;
        }
    }

    /**
     * The campaign's numbers from Brevo (19.10), kept 10 minutes; marks the issue sent once Brevo sent it.
     *
     * @return array{status: string, sent_at: ?string, delivered: int, opens: int, clicks: int, unsubscribes: int}|null
     */
    public function stats(int $id, bool $fresh = false): ?array
    {
        $campaign = DB::table('newsletter_issues')->where('id', $id)->value('brevo_campaign_id');
        if ($campaign === null || ! BrevoClient::configured()) {
            return null;
        }
        $key = 'newsletter:stats:'.$id;
        if ($fresh) {
            Cache::forget($key);
        }
        try {
            $stats = Cache::remember($key, 600, fn () => $this->brevo->campaign((string) $campaign));
        } catch (BrevoUnavailable) {
            return null;
        }
        if (in_array($stats['status'], ['sent', 'archive'], true)) {
            DB::table('newsletter_issues')->where('id', $id)->where('status', '<>', 'sent')->update([
                'status' => 'sent', 'sent_at' => $stats['sent_at'] ? CarbonImmutable::parse($stats['sent_at']) : DB::raw('now()'), 'updated_at' => DB::raw('now()'),
            ]);
        }

        return $stats;
    }

    /** The campaign's HTML: RTL, the brand, the posts as links to the site, Brevo's unsubscribe link and ours. */
    public function html(int $id): string
    {
        $issue = DB::table('newsletter_issues')->where('id', $id)->first();
        $ids = DB::table('newsletter_issue_posts')->where('issue_id', $id)->orderBy('sort_order')->pluck('post_id');
        $posts = Post::query()->live()->whereIn('id', $ids)->get()->sortBy(fn (Post $p) => $ids->search($p->id))->values();
        $site = rtrim((string) config('raqam.site_url'), '/');

        // The tag names the source of the visit (22.6): a mail app sends no referrer.
        $items = collect($this->presenter->summaries($posts))->map(fn (array $p) => [
            'title' => $p['title'], 'excerpt' => $p['excerpt'], 'url' => $site.$p['path'].'?utm_source=newsletter',
            'image' => data_get($p, 'cover.variants.md.jpeg.url') ?? data_get($p, 'cover.url'), 'alt' => (string) data_get($p, 'cover.alt', ''),
        ])->all();

        return view('newsletter.campaign', [
            'subject' => $issue->subject, 'intro' => $issue->intro, 'posts' => $items, 'siteUrl' => $site, 'siteName' => TemplatedEmail::siteName(),
        ])->render();
    }
}
