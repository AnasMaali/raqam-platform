<?php

namespace App\Panel;

use App\Ads\AdReport;
use App\Analytics\StatsReport;
use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use App\Support\ArabicNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The weekly summary for the admins (20.12), over the seven Palestine days before the day it runs: visits and sources,
 * the most read, new subscribers, how the ads did, the chatbot's unanswered questions and the new contact messages
 * (the visit figures come from the statistics, 22). Everything that came from outside (a visitor's question, a campaign name) is escaped here,
 * because the summary goes into the e-mail as a ready block.
 */
final class WeeklyDigest
{
    /** @return array{from: string, to: string, label: string, html: string, numbers: array<string, int>} */
    public static function build(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now('Asia/Hebron'))->setTimezone('Asia/Hebron')->startOfDay();
        $from = $today->subDays(7);
        [$start, $end] = [$from->utc(), $today->utc()];

        $subscribers = (int) DB::table('subscribers')->where('status', 'active')->whereBetween('confirmed_at', [$start, $end])->count();
        $totalSubscribers = (int) DB::table('subscribers')->where('status', 'active')->count();

        $ads = DB::table('ad_stats_daily as s')->join('ad_campaigns as c', 'c.id', '=', 's.campaign_id')
            ->where('s.stat_date', '>=', $from->toDateString())->where('s.stat_date', '<', $today->toDateString())
            ->groupBy('c.id', 'c.name')->orderByRaw('sum(s.impressions) DESC')->limit(10)
            ->get(['c.name', DB::raw('sum(s.impressions) AS views'), DB::raw('sum(s.clicks) AS clicks')]);

        $unanswered = DB::table('bot_messages')->where('role', 'user')->whereNull('matched_entry_id')->whereNull('reviewed_at')
            ->whereBetween('created_at', [$start, $end])->pluck('content');
        $top = $unanswered->countBy(fn ($q) => ArabicNormalizer::normalize(mb_substr((string) $q, 0, 200)))
            ->sortDesc()->take(5);
        $examples = $unanswered->keyBy(fn ($q) => ArabicNormalizer::normalize(mb_substr((string) $q, 0, 200)));

        $messages = (int) DB::table('contact_messages')->whereBetween('created_at', [$start, $end])->count();

        $stats = StatsReport::for('custom', $from->toDateString(), $today->subDay()->toDateString())->data;
        $visits = $stats['totals'];
        $html = self::heading('الزيارات').self::para('مشاهدات الصفحات: '.$visits['page_views'].' — الزوار: '.$visits['visitors'].' — الزيارات: '.$visits['visits']);
        if ($stats['sources'] !== []) {
            $html .= self::para('أهم المصادر: '.implode('، ', array_map(fn (array $s) => $s['name'].' '.$s['share'].'%', array_slice($stats['sources'], 0, 3))));
        }
        $html .= self::heading('الأكثر قراءة');
        if ($stats['posts'] === []) {
            $html .= self::para('لا قراءات هذا الأسبوع.');
        } else {
            $html .= '<ol>';
            foreach (array_slice($stats['posts'], 0, 5) as $post) {
                $html .= '<li>'.e($post['title']).' ('.$post['count'].')</li>';
            }
            $html .= '</ol>';
        }
        $html .= self::heading('النشرة البريدية').self::para('مشتركون جدد: '.$subscribers.' — المجموع الآن: '.$totalSubscribers)
            .self::heading('الإعلانات');
        if ($ads->isEmpty()) {
            $html .= self::para('لا مشاهدات للإعلانات هذا الأسبوع.');
        } else {
            $rows = '';
            foreach ($ads as $ad) {
                $ctr = AdReport::ctr((int) $ad->views, (int) $ad->clicks);
                $rows .= '<tr><td style="padding:4px 0 4px 12px">'.e($ad->name).'</td><td style="padding:4px 12px" dir="ltr">'.(int) $ad->views
                    .'</td><td style="padding:4px 12px" dir="ltr">'.(int) $ad->clicks.'</td><td style="padding:4px 12px" dir="ltr">'.e($ctr === null ? '—' : $ctr.'%').'</td></tr>';
            }
            $html .= '<table style="border-collapse:collapse;font-size:14px"><tr><th style="text-align:right;padding:4px 0 4px 12px">الحملة</th><th style="padding:4px 12px">مشاهدات</th>'
                .'<th style="padding:4px 12px">نقرات</th><th style="padding:4px 12px">نسبة النقر</th></tr>'.$rows.'</table>';
        }
        $html .= self::heading('الشات بوت').self::para('أسئلة بدون جواب: '.$unanswered->count());
        if ($top->isNotEmpty()) {
            $html .= '<ul>';
            foreach ($top as $normal => $count) {
                $html .= '<li>'.e((string) $examples[$normal]).' ('.$count.')</li>';
            }
            $html .= '</ul>';
        }
        $html .= self::heading('رسائل «اتصل بنا»').self::para('رسائل جديدة: '.$messages);

        return [
            'from' => $from->toDateString(), 'to' => $today->subDay()->toDateString(),
            'label' => $from->format('Y-m-d').' – '.$today->subDay()->format('Y-m-d'),
            'html' => $html,
            'numbers' => ['page_views' => $visits['page_views'], 'visitors' => $visits['visitors'], 'subscribers' => $subscribers, 'unanswered' => $unanswered->count(), 'messages' => $messages, 'campaigns' => $ads->count()],
        ];
    }

    /** Queues the summary to every active admin; returns how many. */
    public static function send(?CarbonImmutable $today = null): int
    {
        $digest = self::build($today);

        return SystemMail::toAdmins(fn () => new TemplatedEmail('admin_weekly_digest', ['الفترة' => $digest['label'], 'الملخص' => $digest['html']]));
    }

    private static function heading(string $text): string
    {
        return '<h3 style="margin:16px 0 4px;font-size:16px">'.e($text).'</h3>';
    }

    private static function para(string $text): string
    {
        return '<p style="margin:0 0 8px">'.e($text).'</p>';
    }
}
