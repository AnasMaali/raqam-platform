<?php

namespace App\Demo;

use App\Support\ArabicNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * The readers' side of the demo (raqam:demo): newsletter subscribers, «اتصل بنا» messages, chat conversations, and the
 * last 90 days of the site's statistics (page views, sources, devices, events, searches, ad views and clicks, service
 * contacts, video plays, missing pages), so the panel's inbox, lists and reports have something to read. Written
 * straight to their tables, in the shape the forms and the statistics transfer write them. Every address, number and
 * name is made up; a row that is already there (statistics the site counted itself) is left as it is.
 */
final class DemoActivity
{
    /** How many days of statistics, today included. */
    public const DAYS = 90;

    /** What readers type in the search box, as they type it (some find nothing). */
    private const SEARCHES = [
        'سعر الدولار', 'الدولار اليوم', 'سعر الدينار', 'اليورو', 'أسعار الذهب', 'الطقس في نابلس', 'طقس جنين', 'الكنافة النابلسية',
        'الصابون النابلسي', 'زيت الزيتون', 'الطاقة الشمسية', 'تخزين الطاقة', 'البطالة', 'الرواتب', 'القروض الصغيرة', 'ريادة الأعمال',
        'المشاريع الصغيرة', 'التجارة الإلكترونية', 'الصادرات', 'غرفة تجارة نابلس', 'تاريخ نابلس', 'خان التجار', 'البلدة القديمة',
        'بودكاست', 'قصص نجاح', 'صنع في نابلس', 'مبادرات شبابية', 'طاولة مستديرة', 'التوعية المالية', 'الادخار', 'الضرائب',
        'التضخم', 'أسعار الخضار', 'الإيجارات', 'العقارات', 'السياحة', 'الحرف اليدوية', 'التمور', 'الأجبان', 'الحلويات',
    ];

    /** Searches that find nothing in the demo's content. */
    private const EMPTY_SEARCHES = ['بيتكوين', 'سعر الين', 'مباراة اليوم', 'وظائف شاغرة', 'كنافه بالقشطه'];

    private const SUBJECTS = ['استفسار عن الإعلان', 'تصحيح معلومة', 'اقتراح موضوع', 'طلب تغطية', 'شكر وتقدير', 'مشكلة في الموقع', 'تعاون إعلامي', 'سؤال عن خدمة'];

    private const MESSAGES = [
        'مرحباً، أود الاستفسار عن طريقة الإعلان في الموقع والمدة المتاحة.',
        'في الخبر المنشور اليوم رقم يحتاج إلى مراجعة، أرجو التأكد منه.',
        'أقترح أن تكتبوا عن تجربة المشاريع المنزلية في المدينة.',
        'لدينا افتتاح معرض الأسبوع القادم ونرغب بتغطيته.',
        'شكراً على المحتوى المفيد، أتابعكم كل يوم.',
        'صفحة العملات لم تفتح عندي على الهاتف صباح اليوم.',
        'نحن جمعية شبابية ونرغب بالتعاون في حملة توعية.',
        'كم تستغرق خدمة التصوير وهل تشمل المونتاج؟',
    ];

    /** What visitors ask the chat. */
    private const QUESTIONS = [
        'كم سعر الدولار اليوم؟', 'كيف أعلن عندكم؟', 'شو الطقس بكرا؟', 'وين مكانكم؟', 'بدي أحكي مع حدا', 'شو الخدمات اللي بتقدموها؟',
        'متى بتنزل حلقة البودكاست الجديدة؟', 'كيف أشترك بالنشرة؟', 'هل عندكم وظائف؟', 'كم سعر الدينار؟', 'بتغطوا افتتاح محلات؟',
    ];

    /** Addresses readers asked for that do not exist. */
    private const MISSING = ['/news/old', '/ar/home', '/wp-login.php', '/category/sport', '/podcast/episode-1', '/currency', '/weather/nablus', '/about-us', '/contact-us', '/news/12/old-title', '/feed.xml', '/sitemap_index.xml'];

    private const PATHS = ['/', '/currencies', '/weather', '/services', '/podcast', '/contact', '/advertise', '/video'];

    private CarbonImmutable $today;

    public function __construct(private Randomizer $rng, private CarbonImmutable $now, private int $userId)
    {
        $this->today = $now->setTimezone('Asia/Hebron')->startOfDay();
    }

    /** Everything, scaled to the number of posts the run writes. */
    public function run(int $postCount): void
    {
        $this->subscribers((int) round($postCount * 0.75));
        $this->messages(max(6, intdiv($postCount, 8)));
        $this->conversations(max(6, intdiv($postCount, 4)));
        $this->siteStats();
        $this->searches();
        $this->adStats();
        $this->serviceClicks();
        $this->videoPlays();
        $this->missingPages();
    }

    /** The days of the statistics, oldest first, as Palestine dates. @return list<CarbonImmutable> */
    public static function days(CarbonImmutable $today): array
    {
        return array_map(fn ($i) => $today->subDays(self::DAYS - 1 - $i), range(0, self::DAYS - 1));
    }

    private function subscribers(int $count): void
    {
        $placements = ['footer', 'post_end', 'newsletter_page', 'home'];
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $subscribed = $this->moment($this->rng->getInt(0, 364));
            $status = $this->pick(['active' => 75, 'pending' => 10, 'unsubscribed' => 10, 'bounced' => 5]);
            $confirmed = $status === 'pending' ? null : $this->after($subscribed, 1, 90 * 60);
            $unsubscribed = $status === 'unsubscribed' ? $this->after($confirmed, 3600, 60 * 86400) : null;
            $rows[] = [
                'email' => "reader{$i}@example.org",
                'name' => $this->rng->getInt(1, 10) <= 6 ? $this->person() : null,
                'status' => $status,
                'source_path' => $this->rng->getInt(1, 3) === 1 ? '/newsletter' : '/',
                'source_placement' => $placements[$this->rng->getInt(0, 3)],
                'consent_ip_hash' => $this->hash(),
                'subscribed_at' => $subscribed,
                'confirmed_at' => $confirmed,
                'unsubscribed_at' => $unsubscribed,
                'updated_at' => max(array_filter([$subscribed, $confirmed, $unsubscribed])),
            ];
        }
        $this->insert('subscribers', $rows);
    }

    private function messages(int $count): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $created = $this->moment($this->rng->getInt(0, self::DAYS - 1));
            // The newest are still to be read; the older ones were read, and some put away.
            $status = $i > $count - max(3, intdiv($count, 5)) ? 'new' : $this->pick(['read' => 60, 'archived' => 40]);
            $rows[] = [
                'name' => $this->person(),
                'email' => "sender{$i}@example.org",
                // Numbers from the range kept for fiction (+1 555 555 01xx).
                'phone' => $this->rng->getInt(1, 10) <= 4 ? '+1555555'.str_pad((string) (100 + $i % 100), 4, '0', STR_PAD_LEFT) : null,
                'subject' => self::SUBJECTS[$this->rng->getInt(0, count(self::SUBJECTS) - 1)],
                'message' => self::MESSAGES[$this->rng->getInt(0, count(self::MESSAGES) - 1)],
                'status' => $status,
                'ip_hash' => $this->hash(),
                'handled_by' => $status === 'new' ? null : $this->userId,
                'handled_at' => $status === 'new' ? null : $this->after($created, 600, 2 * 86400),
                'created_at' => $created,
            ];
        }
        // Their order of arrival follows their ids, as the form writes them.
        usort($rows, fn ($a, $b) => $a['created_at'] <=> $b['created_at']);
        $this->insert('contact_messages', $rows);
    }

    private function conversations(int $count): void
    {
        $entries = DB::table('bot_entries')->leftJoin('faqs', 'faqs.id', '=', 'bot_entries.faq_id')->where('bot_entries.is_active', true)
            ->orderBy('bot_entries.id')->get(['bot_entries.id', DB::raw('coalesce(bot_entries.answer, faqs.answer) AS answer')])->all();
        $starts = [];
        for ($i = 0; $i < $count; $i++) {
            $starts[] = $this->moment($this->rng->getInt(0, self::DAYS - 1));
        }
        sort($starts);
        foreach ($starts as $started) {
            $id = DB::table('bot_conversations')->insertGetId([
                'session_hash' => $this->hash(),
                'entry_path' => self::PATHS[$this->rng->getInt(0, count(self::PATHS) - 1)],
                'started_at' => $started,
            ]);
            $at = $started;
            $messages = [];
            foreach (range(1, $this->rng->getInt(1, 3)) as $turn) {
                $at = $at->addSeconds($this->rng->getInt(5, 90));
                $entry = $entries !== [] && $this->rng->getInt(1, 10) <= 6 ? $entries[$this->rng->getInt(0, count($entries) - 1)] : null;
                // A question nobody answered, from more than a week ago, was looked at by the admin now and then.
                $reviewed = $entry === null && $at->lessThan($this->now->subWeek()) && $this->rng->getInt(1, 3) === 1 ? $at->addDays(2) : null;
                $messages[] = [
                    'conversation_id' => $id, 'role' => 'user', 'content' => self::QUESTIONS[$this->rng->getInt(0, count(self::QUESTIONS) - 1)],
                    'matched_entry_id' => $entry?->id, 'match_score' => $entry ? $this->rng->getInt(55, 100) / 100 : null,
                    'reviewed_at' => $reviewed, 'created_at' => $at,
                ];
                $at = $at->addSeconds(1);
                $answer = $entry ? mb_substr(trim(strip_tags((string) $entry->answer)), 0, 4000) : '';
                $messages[] = [
                    'conversation_id' => $id, 'role' => 'bot', 'content' => $answer !== '' ? $answer : 'لم أجد جواباً لسؤالك. هذه أقرب المواد من الموقع.',
                    'matched_entry_id' => $entry?->id, 'match_score' => null, 'reviewed_at' => null, 'created_at' => $at,
                ];
            }
            DB::table('bot_messages')->insert($messages);
        }
    }

    /** Page views and visitors, where they came from, on what, and what they did: each day's views hold the posts' own. */
    private function siteStats(): void
    {
        $postViews = DB::table('post_views_daily')->where('stat_date', '>=', $this->today->subDays(self::DAYS - 1)->toDateString())
            ->groupBy('stat_date')->selectRaw('stat_date, sum(views) AS v')->pluck('v', 'stat_date')->map(fn ($v) => (int) $v)->all();
        // The usual order of a news site's sources, the largest first; any other source after them.
        $usual = ['google', 'direct', 'facebook', 'whatsapp', 'instagram', 'newsletter', 'youtube', 'x', 'tiktok', 'bing', 'other'];
        $sources = DB::table('traffic_sources')->pluck('code')->sortBy(fn ($c) => [array_search($c, $usual, true) === false ? 1 : 0, array_search($c, $usual, true), $c])->values()->all();
        $events = DB::table('event_types')->orderBy('code')->pluck('code')->all();
        $site = $traffic = $devices = $counted = [];
        foreach (self::days($this->today) as $n => $day) {
            $date = $day->toDateString();
            // The posts are 55 to 70 % of a day's views; the other pages (home, markets, lists) add the rest, growing
            // over the quarter and quieter on Fridays.
            $others = (int) round((300 + 6 * $n) * ($day->dayOfWeekIso === 5 ? 0.7 : 1) * $this->rng->getFloat(0.85, 1.15));
            $views = (int) round(($postViews[$date] ?? 0) / $this->rng->getFloat(0.55, 0.7)) + $others;
            $visitors = (int) round($views * $this->rng->getFloat(0.35, 0.55));
            $site[] = ['stat_date' => $date, 'page_views' => $views, 'unique_visitors' => $visitors];
            foreach ($this->share($visitors, count($sources)) as $i => $visits) {
                $visits > 0 && $traffic[] = ['stat_date' => $date, 'source_code' => $sources[$i], 'visits' => $visits];
            }
            $mobile = (int) round($visitors * $this->rng->getFloat(0.62, 0.72));
            $tablet = (int) round($visitors * $this->rng->getFloat(0.04, 0.08));
            foreach (['mobile' => $mobile, 'tablet' => $tablet, 'desktop' => max(0, $visitors - $mobile - $tablet)] as $device => $visits) {
                $devices[] = ['stat_date' => $date, 'device_type' => $device, 'visits' => $visits];
            }
            foreach ($events as $code) {
                $counted[] = ['stat_date' => $date, 'event_code' => $code, 'count' => (int) round($visitors * $this->rng->getFloat(0.002, 0.04))];
            }
        }
        $this->insert('site_stats_daily', $site);
        $this->insert('traffic_sources_daily', $traffic);
        $this->insert('device_stats_daily', $devices);
        $this->insert('event_stats_daily', $counted);
    }

    private function searches(): void
    {
        $terms = [];
        foreach ([...self::SEARCHES, ...self::EMPTY_SEARCHES] as $query) {
            $term = mb_substr(ArabicNormalizer::normalize($query), 0, 120);
            DB::table('search_terms')->insertOrIgnore(['normalized_term' => $term]);
            $terms[$term] = in_array($query, self::EMPTY_SEARCHES, true);
        }
        $ids = DB::table('search_terms')->whereIn('normalized_term', array_keys($terms))->pluck('id', 'normalized_term')->all();
        $rows = [];
        foreach ($terms as $term => $empty) {
            $popular = $this->rng->getInt(1, 40);
            foreach (self::days($this->today) as $day) {
                $searches = max(0, (int) round($popular * $this->rng->getFloat(-0.3, 1.0)));
                if ($searches > 0) {
                    $rows[] = ['term_id' => $ids[$term], 'stat_date' => $day->toDateString(), 'searches' => $searches,
                        'zero_result_searches' => $empty ? $searches : (int) floor($searches * $this->rng->getFloat(0, 0.08))];
                }
            }
        }
        $this->insert('search_stats_daily', $rows);
    }

    /** Each campaign's views and clicks in each of its places, on the days it ran. */
    private function adStats(): void
    {
        $first = $this->today->subDays(self::DAYS - 1);
        $rows = [];
        foreach (DB::table('ad_campaigns')->join('ad_campaign_placements', 'ad_campaign_placements.campaign_id', '=', 'ad_campaigns.id')
            ->where('ad_campaigns.status', '<>', 'draft')->orderBy('ad_campaigns.id')->orderBy('placement_code')
            ->get(['ad_campaigns.id', 'placement_code', 'starts_at', 'ends_at']) as $c) {
            $from = CarbonImmutable::parse($c->starts_at)->setTimezone('Asia/Hebron')->startOfDay()->max($first);
            $to = CarbonImmutable::parse($c->ends_at)->setTimezone('Asia/Hebron')->startOfDay()->min($this->today);
            $level = $this->rng->getInt(300, 4000);
            for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
                $impressions = (int) round($level * $this->rng->getFloat(0.7, 1.3));
                $rows[] = ['campaign_id' => $c->id, 'placement_code' => $c->placement_code, 'stat_date' => $day->toDateString(),
                    'impressions' => $impressions, 'clicks' => (int) round($impressions * $this->rng->getFloat(0.002, 0.015))];
            }
        }
        $this->insert('ad_stats_daily', $rows);
    }

    private function serviceClicks(): void
    {
        $rows = [];
        foreach (DB::table('services')->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->pluck('id') as $service) {
            foreach (self::days($this->today) as $day) {
                foreach (['whatsapp' => 5, 'phone' => 2, 'email' => 1] as $channel => $most) {
                    $clicks = $this->rng->getInt(-$most, $most);
                    $clicks > 0 && $rows[] = ['service_id' => $service, 'channel' => $channel, 'stat_date' => $day->toDateString(), 'clicks' => $clicks];
                }
            }
        }
        $this->insert('service_contact_clicks_daily', $rows);
    }

    private function videoPlays(): void
    {
        $rows = [];
        foreach (DB::table('videos')->orderBy('id')->pluck('id') as $video) {
            $level = $this->rng->getInt(0, 60);
            foreach (self::days($this->today) as $day) {
                $plays = (int) round($level * $this->rng->getFloat(-0.5, 1.0));
                $plays > 0 && $rows[] = ['video_id' => $video, 'stat_date' => $day->toDateString(), 'plays' => $plays];
            }
        }
        $this->insert('video_plays_daily', $rows);
    }

    private function missingPages(): void
    {
        $rows = [];
        foreach (self::MISSING as $i => $path) {
            $first = $this->moment($this->rng->getInt(10, self::DAYS - 1));
            $last = $this->after($first, 3600, 9 * 86400);
            // One in four was dealt with (a redirect made) after it was last asked for.
            $rows[] = ['path' => $path, 'hits' => $this->rng->getInt(1, 400), 'first_seen_at' => $first, 'last_seen_at' => $last, 'resolved_at' => $i % 4 === 0 ? $last : null];
        }
        $this->insert('not_found_paths', $rows);
    }

    /** Rows that are already there (the site's own counts, a search somebody made) stay as they are. */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk);
        }
    }

    /** A moment of the Palestine day `$daysAgo` days back, during waking hours, never in the future. */
    private function moment(int $daysAgo): CarbonImmutable
    {
        $at = $this->today->subDays($daysAgo)->addMinutes($this->rng->getInt(7 * 60, 23 * 60))->utc();

        return $at->greaterThan($this->now) ? $this->now->subMinutes($this->rng->getInt(1, 60)) : $at;
    }

    private function after(CarbonImmutable $from, int $min, int $max): CarbonImmutable
    {
        return $from->addSeconds($this->rng->getInt($min, $max))->min($this->now)->max($from);
    }

    /** @param  array<string, int>  $weights */
    private function pick(array $weights): string
    {
        $roll = $this->rng->getInt(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }

    /** `$total` split into `$parts` uneven shares (the first ones larger), adding up to it. @return list<int> */
    private function share(int $total, int $parts): array
    {
        $weights = array_map(fn ($i) => $this->rng->getFloat(0.2, 1.0) / ($i + 1), range(0, $parts - 1));
        $sum = array_sum($weights);
        $shares = array_map(fn ($w) => (int) floor($total * $w / $sum), $weights);
        $shares[0] += $total - array_sum($shares);

        return $shares;
    }

    private function person(): string
    {
        $gender = $this->rng->getInt(0, 1) === 0 ? 'm' : 'f';
        $first = DemoCorpus::FIRST_NAMES[$gender];

        return $first[$this->rng->getInt(0, count($first) - 1)].' '.DemoCorpus::LAST_NAMES[$this->rng->getInt(0, count(DemoCorpus::LAST_NAMES) - 1)];
    }

    private function hash(): string
    {
        return hash('sha256', $this->rng->getBytes(16));
    }
}
