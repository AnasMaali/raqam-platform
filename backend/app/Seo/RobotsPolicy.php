<?php

namespace App\Seo;

use Illuminate\Support\Facades\DB;

/**
 * Who may crawl the site (phase 10b, GEO): search engines always; answer engines (search assistants that read a page to
 * answer a person and cite it) unless the admin turns them off; crawlers that train language models only when the admin
 * turns them on (off by default; the decision is the platform's). The pages that are never to be indexed (23.17) are
 * closed to everyone. The website writes robots.txt from this.
 */
final class RobotsPolicy
{
    public const SETTINGS = ['seo.answer_bots', 'seo.training_bots', 'seo.llms_summary'];

    /** Search results, the newsletter pages, booking and the customer accounts (23.17), and previews. */
    public const PRIVATE_PATHS = ['/search', '/newsletter', '/bookings', '/account', '/preview'];

    public static function answerBotsAllowed(): bool
    {
        return self::flag('seo.answer_bots', true);
    }

    public static function trainingBotsAllowed(): bool
    {
        return self::flag('seo.training_bots', false);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'groups' => [
                ['purpose' => 'search', 'agents' => ['*'], 'allow' => true],
                ['purpose' => 'answer', 'agents' => CrawlerAgents::ANSWER, 'allow' => self::answerBotsAllowed()],
                ['purpose' => 'training', 'agents' => CrawlerAgents::TRAINING, 'allow' => self::trainingBotsAllowed()],
            ],
            'disallow' => self::PRIVATE_PATHS,
            'sitemaps' => ['/sitemap.xml'],
        ];
    }

    private static function flag(string $key, bool $default): bool
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return $value === null ? $default : $value === 'true';
    }
}
