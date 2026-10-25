<?php

namespace App\Content;

use Illuminate\Support\Facades\DB;

/** The pages every site like this needs (decision 28), made once as unpublished drafts for the owner to write. */
final class StaticPages
{
    /** system_key => [title, slug] */
    public const SYSTEM = [
        'about' => ['من نحن', 'about'],
        'team' => ['فريق العمل', 'team'],
        'contact' => ['اتصل بنا', 'contact'],
        'advertise' => ['أعلن معنا', 'advertise'],
        'services' => ['خدماتنا والأسعار', 'services'],
        'faq' => ['الأسئلة الشائعة', 'faq'],
        'privacy' => ['سياسة الخصوصية', 'privacy'],
        'terms' => ['شروط الاستخدام', 'terms'],
        'cookies' => ['سياسة ملفات الارتباط', 'cookies'],
        'copyright' => ['حقوق النشر وإعادة الاستخدام', 'copyright'],
        'editorial_policy' => ['السياسة التحريرية', 'editorial-policy'],
        'corrections_policy' => ['سياسة التصحيح', 'corrections-policy'],
        'sponsored_policy' => ['سياسة المحتوى الممول', 'sponsored-policy'],
    ];

    /**
     * Addresses that belong to the site itself; a page cannot take one (decisions 23.2, 23.3).
     *
     * @var list<string>
     */
    public const RESERVED = [
        'news', 'report', 'opinion', 'infographic', 'podcast', 'success-stories', 'made-in-nablus', 'history', 'initiatives',
        'round-table', 'awareness', 'category', 'tag', 'author', 'guest', 'video', 'videos', 'search', 'api', 'currency', 'weather',
        'newsletter', 'preview', 'storage', 'private-media', 'livewire', 'up', 'sitemap', 'sitemap.xml', 'robots.txt', 'rss', 'feed', 'login', 'logout',
        'currencies', 'feeds', 'bookings', 'account', 'llms.txt',
    ];

    /** @return int how many were added (existing ones are left exactly as they are) */
    public function install(): int
    {
        $added = 0;

        foreach (self::SYSTEM as $key => [$title, $slug]) {
            $added += DB::table('pages')->insertOrIgnore(['system_key' => $key, 'title' => $title, 'slug' => $slug, 'body' => '', 'is_published' => false]);
        }

        return $added;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(mb_strtolower($slug), self::RESERVED, true);
    }
}
