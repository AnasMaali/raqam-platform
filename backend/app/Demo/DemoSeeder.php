<?php

namespace App\Demo;

use App\Ads\AdCampaigns;
use App\Ads\AdSlots;
use App\Awareness\CharacterLibrary;
use App\Chat\KnowledgeBase;
use App\Content\BodySanitizer;
use App\Content\PostSaver;
use App\Integrations\DataProviders;
use App\Markets\CurrencyCatalog;
use App\Markets\RateBoard;
use App\Markets\RateFetcher;
use App\Media\MediaStorage;
use App\Media\MediaUploader;
use App\Models\AdPlacement;
use App\Models\Advertiser;
use App\Models\Author;
use App\Models\Character;
use App\Models\Era;
use App\Models\Faq;
use App\Models\Guest;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Playlist;
use App\Models\Sector;
use App\Models\Service;
use App\Navigation\HomeLayout;
use App\Navigation\MenuStore;
use App\Services\ServiceCatalog;
use App\Support\UniqueSlug;
use App\Video\VideoLibrary;
use App\Weather\WeatherBoard;
use App\Weather\WeatherFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Fills an empty database with made-up content for designing the website (raqam:demo). Everything goes through the
 * same services the panel uses (PostSaver, MediaUploader, VideoLibrary, AdCampaigns, ServiceCatalog, MenuStore,
 * HomeLayout ...), so every rule, subtype row and revision is what an editor would have produced. Only the market data
 * (a year of rates, today's weather) and the reading counts are written straight to their tables, in the shape the
 * fetchers and the statistics transfer write them.
 */
class DemoSeeder
{
    /** How 300 items are shared between the kinds; another total is scaled from this (each kind keeps at least one). */
    public const KINDS = [
        'news' => 143, 'report' => 16, 'opinion' => 14, 'infographic' => 10, 'episode' => 20, 'story' => 18,
        'product' => 18, 'history' => 15, 'initiative' => 12, 'roundtable' => 10, 'awareness' => 24,
    ];

    /** Shekels for one unit around which a year of rates wanders (the Bank of Israel publishes these). */
    private const RATE_BASE = ['USD' => '3.70', 'EUR' => '4.02', 'GBP' => '4.75', 'JOD' => '5.22', 'EGP' => '0.0755'];

    /** Autumn lows and highs by city name; others get the Nablus values. */
    private const CLIMATE = [
        'نابلس' => [15, 25], 'جنين' => [17, 28], 'طولكرم' => [18, 28], 'قلقيلية' => [18, 28], 'سلفيت' => [15, 25], 'رام الله والبيرة' => [14, 24],
        'أريحا' => [21, 33], 'القدس' => [14, 24], 'بيت لحم' => [14, 24], 'الخليل' => [12, 22], 'طوباس' => [16, 27], 'غزة' => [20, 28],
    ];

    /**
     * Made-up prices of the installed services (decision: every amount outside the panel is obviously made up).
     *
     * @var array<string, array{0: string, 1?: string, 2?: string, 3?: ?string, 4?: ?string}> slug => mode, price, currency, note, offer price
     */
    private const SERVICE_PRICES = [
        'podcast-episode' => ['fixed', '1234.56', 'ILS', 'للحلقة'],
        'success-story' => ['from', '234.56', 'USD', null],
        'made-in-nablus' => ['fixed', '123.45', 'ILS', null, '99.99'],
        'round-table' => ['fixed', '111.11', 'ILS', 'للمقعد'],
        'initiative' => ['on_request'],
        'ad-packages' => ['from', '135.70', 'USD', 'شهرياً'],
    ];

    private Randomizer $rng;

    private int $user;

    private CarbonImmutable $now;

    /** @var array<string, list<int>> picture ids by shape */
    private array $pictures = ['wide' => [], 'tall' => [], 'square' => [], 'chart' => []];

    /** @var list<array{id: int, name: string, sector: string, city: string, website: string, founded: int}> */
    private array $orgs = [];

    /** @var list<int> */
    private array $authors = [];

    /** @var list<array{id: int, name: string, role: string}> the hosts first */
    private array $guests = [];

    /** @var array<int, string> city id => name */
    private array $cities = [];

    /** @var array<string, int> category slug => id */
    private array $categories = [];

    /** @var list<int> */
    private array $eras = [];

    /** @var list<array{id: int, playlist: int}> */
    private array $seasons = [];

    /** @var array<string, list<int>> */
    private array $videos = ['report' => [], 'short' => []];

    /** @var array<string, array<string, int>> character slug => pose code => pose id */
    private array $poses = [];

    /** @var array<int, list<int>> category id => post ids, oldest first (for «read also») */
    private array $byCategory = [];

    /** @var list<int> */
    private array $cards = [];

    /** @var array<string, true> */
    private array $titles = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var list<array{0: string, 1: string, 2: string}> disk, visibility and path of every file stored, to take away if the run fails */
    private array $stored = [];

    /** @var (callable(string): void)|null */
    private $progress = null;

    public function __construct(
        private PostSaver $posts,
        private MediaUploader $uploader,
        private DemoImages $images,
        private VideoLibrary $library,
        private CharacterLibrary $characters,
        private AdCampaigns $campaigns,
        private AdSlots $slots,
        private ServiceCatalog $catalog,
        private MenuStore $menus,
        private HomeLayout $home,
        private BodySanitizer $sanitizer,
        private MediaStorage $storage,
    ) {}

    /**
     * Writes everything. The caller runs it inside one transaction and records the new rows afterwards.
     *
     * @param  (callable(string): void)|null  $progress
     */
    public function run(int $userId, int $postCount, int $seed, DemoManifest $manifest, ?callable $progress = null): void
    {
        $this->rng = new Randomizer(new Mt19937($seed));
        $this->user = $userId;
        $this->now = CarbonImmutable::now();
        $this->progress = $progress;
        $plan = self::plan($postCount);

        $this->cities = DB::table('cities')->orderBy('id')->pluck('name_ar', 'id')->all();
        $this->categories = DB::table('categories')->whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->pluck('id', 'slug')->all();

        try {
            $this->step('pictures');
            $this->makePictures($plan);
            $this->step('sectors, organisations, writers and guests');
            $this->makePeople();
            $this->step('awareness character poses');
            $this->makePoses();
            $this->step('videos and playlists');
            $this->makeVideos($plan);
            $this->step('posts');
            $this->makePosts($plan);
            $this->step('reading counts');
            $this->makeViews();
            $this->step('exchange rates (one year) and weather');
            $this->makeRates();
            $this->makeWeather();
            $this->step('advertisers, campaigns and house banners');
            $this->makeAds();
            $this->step('services, FAQ, pages, menus and home page');
            $this->makeServices($manifest);
            $this->makeFaqs();
            $this->makePages($manifest);
            $this->makeMenus();
            $this->makeHome();
        } finally {
            $this->images->cleanUp();
        }
    }

    /** After a failed run (its rows rolled back): the picture files it stored are of no use to anyone. */
    public function discardFiles(): void
    {
        foreach ($this->stored as [$disk, $visibility, $path]) {
            $this->storage->disk($disk, $visibility)->delete($path);
        }
        $this->stored = [];
    }

    /** @return array<string, int> kind => how many, adding up to $total */
    public static function plan(int $total): array
    {
        $plan = [];
        foreach (self::KINDS as $kind => $n) {
            $plan[$kind] = max(1, intdiv($n * $total, 300));
        }
        // Kinds with a fixed list of titles do not grow beyond it; the rest goes to the news.
        $caps = ['report' => count(DemoCorpus::REPORT_TITLES), 'opinion' => count(DemoCorpus::OPINION_TITLES), 'infographic' => count(DemoCorpus::INFOGRAPHIC_TITLES),
            'episode' => count(DemoCorpus::EPISODE_TOPICS), 'product' => count(DemoCorpus::PRODUCTS), 'history' => count(DemoCorpus::HISTORY),
            'initiative' => count(DemoCorpus::INITIATIVES), 'roundtable' => count(DemoCorpus::ROUNDTABLE_TITLES), 'awareness' => 24];
        foreach ($caps as $kind => $cap) {
            $plan[$kind] = min($plan[$kind], $cap);
        }
        $plan['news'] += $total - array_sum($plan);
        // A total under the sum of the minimums: take from the largest kinds, never below one.
        while (array_sum($plan) > $total && max($plan) > 1) {
            $plan[array_search(max($plan), $plan, true)]--;
        }

        return $plan;
    }

    // ---- pictures ---------------------------------------------------------------------------------------------

    private function makePictures(array $plan): void
    {
        $total = array_sum($plan);
        $shapes = [
            'wide' => [max(8, min(30, intdiv($total, 10))), 1920, 1080],
            'tall' => [max(3, min(8, intdiv($total, 40))), 1600, 2000],
            'square' => [4, 1600, 1600],
            'chart' => [max(1, min(6, $plan['infographic'])), 1600, 2800],
        ];
        foreach ($shapes as $shape => [$count, $w, $h]) {
            for ($i = 0; $i < $count; $i++) {
                $file = $shape === 'chart' ? $this->images->infographic($w, $h, $this->seed()) : $this->images->scene($w, $h, $this->seed());
                $this->pictures[$shape][] = $this->upload($file, "{$shape}-".($i + 1).'.jpg', $this->pick(DemoCorpus::PICTURE_ALTS));
            }
        }
    }

    private function upload(string $file, string $name, string $alt, ?string $caption = null): int
    {
        $upload = $this->uploader->store($file, $name, ['alt_text' => $alt, 'caption' => $caption, 'credit' => 'رَقَم — صورة توضيحية', 'visibility' => 'public'], $this->user);
        if (! $upload->duplicate) {
            $this->stored[] = [$upload->asset->disk, $upload->asset->visibility, $upload->asset->path];
        }

        return (int) $upload->asset->id;
    }

    // ---- people and organisations ------------------------------------------------------------------------------

    private function makePeople(): void
    {
        $sectors = [];
        foreach (DemoCorpus::SECTORS as $i => $name) {
            $sectors[$i] = Sector::query()->create(['name_ar' => $name, 'slug' => UniqueSlug::for('sectors', $name, fallback: 'sector'), 'sort_order' => $i + 1]);
        }

        $cityIds = array_flip($this->cities);
        foreach (DemoCorpus::ORGANIZATIONS as $i => [$name, $sector, $city, $founded, $handle]) {
            $logo = $i < 12 ? $this->upload($this->images->logo($name, $this->seed()), "logo-{$handle}.jpg", "شعار {$name}") : null;
            $org = Organization::query()->create([
                'name' => $name, 'slug' => UniqueSlug::for('organizations', $name, fallback: 'organization'), 'sector_id' => $sectors[$sector]->id,
                'city_id' => $cityIds[$city] ?? null, 'founded_year' => $founded, 'website' => "https://{$handle}.example", 'logo_media_id' => $logo,
            ]);
            $this->orgs[] = ['id' => (int) $org->id, 'name' => $name, 'sector' => DemoCorpus::SECTORS[$sector], 'city' => $city, 'website' => "https://{$handle}.example", 'founded' => $founded];
        }

        foreach (DemoCorpus::AUTHORS as [$name, $title, $bio, $team]) {
            $author = Author::query()->create([
                'name' => $name, 'slug' => UniqueSlug::for('authors', $name, fallback: 'author'), 'job_title' => $title, 'bio' => $bio,
                'photo_media_id' => $this->upload($this->images->portrait($name, $this->seed()), 'author.jpg', "صورة رمزية لـ{$name}"),
                'is_active' => true, 'team_sort_order' => $team,
            ]);
            $this->authors[] = (int) $author->id;
        }

        foreach (DemoCorpus::GUESTS as [$name, $title, $org, $bio]) {
            $guest = Guest::query()->create([
                'name' => $name, 'slug' => UniqueSlug::for('guests', $name, fallback: 'guest'), 'job_title' => $title, 'bio' => $bio,
                'organization_id' => $org === null ? null : $this->orgs[$org]['id'],
                'photo_media_id' => $this->upload($this->images->portrait($name, $this->seed()), 'guest.jpg', "صورة رمزية لـ{$name}"),
            ]);
            $this->guests[] = ['id' => (int) $guest->id, 'name' => $name, 'role' => $title.($org === null ? '' : ' في '.$this->orgs[$org]['name'])];
        }

        foreach (DemoCorpus::ERAS as $i => [$name, $from, $to]) {
            $this->eras[] = (int) Era::query()->create(['name_ar' => $name, 'year_from' => $from, 'year_to' => $to, 'sort_order' => $i + 1])->id;
        }
    }

    // ---- awareness characters ----------------------------------------------------------------------------------

    private function makePoses(): void
    {
        $kinds = DB::table('pose_types')->orderBy('sort_order')->pluck('name_ar', 'code')->all();
        foreach (Character::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $character) {
            if (DB::table('character_poses')->where('character_id', $character->id)->exists()) {
                continue;   // the admin already gave it pictures
            }
            $poses = [];
            foreach ($kinds as $code => $name) {
                $file = $this->images->pose($character->accent_color, $code, $this->seed());
                $poses[$code] = ['static_media_id' => $this->upload($file, "pose-{$character->slug}-{$code}.jpg", "{$character->name_ar} — {$name}")];
            }
            $this->characters->savePoses($character, $poses);
        }
        foreach (Character::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $character) {
            $poses = DB::table('character_poses')->where('character_id', $character->id)->pluck('id', 'pose_type_code')->map(fn ($id) => (int) $id)->all();
            if ($poses !== []) {
                $this->poses[$character->slug] = $poses;
            }
        }
    }

    // ---- videos -------------------------------------------------------------------------------------------------

    private function makeVideos(array $plan): void
    {
        $section = fn (string $key) => DB::table('sections')->where('key', $key)->value('id');
        $playlist = fn (string $name, ?int $sectionId, int $order) => Playlist::query()->create([
            'name_ar' => $name, 'slug' => UniqueSlug::for('playlists', $name, fallback: 'playlist'), 'section_id' => $sectionId,
            'cover_media_id' => $this->pick($this->pictures['wide']), 'sort_order' => $order, 'is_visible' => true,
            'description' => 'قائمة تشغيل من مكتبة فيديو رَقَم.',
        ]);

        // The podcast's seasons, each with its own playlist; the episodes join it as they are saved.
        $seasons = $plan['episode'] > 1 ? 2 : 1;
        for ($s = 1; $s <= $seasons; $s++) {
            $list = $playlist('بودكاست رَقَم — الموسم '.($s === 1 ? 'الأول' : 'الثاني'), $section('podcast'), $s);
            $number = (int) DB::table('podcast_seasons')->max('season_number') + 1;
            $id = DB::table('podcast_seasons')->insertGetId(['season_number' => $number, 'title_ar' => $s === 1 ? 'البدايات' : 'من السوق إلى العالم', 'playlist_id' => $list->id]);
            $this->seasons[] = ['id' => (int) $id, 'playlist' => (int) $list->id];
        }

        $total = array_sum($plan);
        $reports = $playlist('تقارير رَقَم المصورة', null, 10);
        foreach (array_slice(DemoCorpus::VIDEO_TITLES, 0, max(2, min(count(DemoCorpus::VIDEO_TITLES), intdiv($total, 30)))) as $title) {
            $this->videos['report'][] = $this->video($title, $this->rng->getInt(240, 900), false);
        }
        $this->library->setPlaylistVideos($reports->id, $this->videos['report']);

        $shorts = $playlist('رَقَم في دقيقة', null, 11);
        foreach (array_slice(DemoCorpus::SHORT_TITLES, 0, max(3, min(count(DemoCorpus::SHORT_TITLES), intdiv($total, 25)))) as $title) {
            $this->videos['short'][] = $this->video($title, $this->rng->getInt(20, 59), true);
        }
        $this->library->setPlaylistVideos($shorts->id, $this->videos['short']);
    }

    /** A YouTube video added by hand, as the panel does without a YouTube key: a made-up address, our title and picture. */
    private function video(string $title, int $seconds, bool $short, ?array $chapters = null): int
    {
        $id = $this->youtubeId();
        $video = $this->library->addYoutube($short ? "https://www.youtube.com/shorts/{$id}" : "https://www.youtube.com/watch?v={$id}", ['title' => $title, 'duration' => $seconds], $this->user);
        $this->library->updateDisplay($video, null, 'فيديو من إنتاج رَقَم: '.$title.'.', $this->pick($this->pictures[$short ? 'tall' : 'wide']));
        if ($chapters !== null) {
            $this->library->syncChapters($video, $chapters);
        }

        return (int) $video->id;
    }

    private function youtubeId(): string
    {
        do {
            $id = $this->rng->getBytesFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-', 11);
        } while (DB::table('youtube_videos')->where('youtube_id', $id)->exists());

        return $id;
    }

    // ---- posts --------------------------------------------------------------------------------------------------

    private function makePosts(array $plan): void
    {
        $kinds = [];
        foreach ($plan as $kind => $n) {
            array_push($kinds, ...array_fill(0, $n, $kind));
        }
        $kinds = $this->rng->shuffleArray($kinds);
        $dates = $this->dates(count($kinds));

        // The newest two news items are breaking; a few recent ones and two older ones are featured.
        $news = array_keys(array_filter($kinds, fn ($k) => $k === 'news'));
        $breaking = array_slice(array_reverse($news), 0, 2);
        $recent = array_keys(array_filter($dates, fn (CarbonImmutable $d) => $d->greaterThan($this->now->subDays(45))));
        $featured = array_merge(array_slice(array_reverse($news), 0, 3), $this->sample($recent, 5), $this->sample(array_keys($kinds), 2));
        $corrected = $this->sample(array_keys(array_filter($kinds, fn ($k, $i) => in_array($k, ['news', 'report'], true) && $dates[$i]->lessThan($this->now->subDays(7)), ARRAY_FILTER_USE_BOTH)), 5);

        $episodes = 0;
        $episodesInFirst = (int) ceil($plan['episode'] / count($this->seasons));
        $done = 0;
        foreach ($kinds as $i => $kind) {
            $at = $dates[$i];
            $number = $this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;
            $data = match ($kind) {
                'news', 'report', 'opinion', 'infographic' => $this->general($kind, $number),
                'episode' => $this->episode($number, $episodes++ < $episodesInFirst ? 0 : min(1, count($this->seasons) - 1)),
                'story' => $this->story($number),
                'product' => $this->product($number),
                'history' => $this->history($number),
                'initiative' => $this->initiative($number, $at),
                'roundtable' => $this->roundtable($number, $at),
                'awareness' => $this->awareness($number),
            };

            $data += [
                'status' => 'published', 'published_at' => $at->toIso8601String(), 'is_featured' => in_array($i, $featured, true),
                'breaking_until' => in_array($i, $breaking, true) ? $this->now->addDays($i === $breaking[0] ? 2 : 4)->toIso8601String() : null,
                'correction_note' => in_array($i, $corrected, true) ? $this->pick(DemoCorpus::CORRECTIONS) : null,
            ];
            $data['excerpt'] ??= $this->excerpt($data['body']);
            $data['authors'] ??= $this->writers($kind);

            $post = $this->posts->create($kind, $data['title'], $this->user);
            $this->posts->save($post, $data, $this->user);

            if (isset($data['category_id'])) {
                $this->byCategory[$data['category_id']][] = (int) $post->id;
            }
            if ($kind === 'awareness') {
                $this->cards[] = (int) $post->id;
            }
            if (++$done % 50 === 0) {
                $this->step("  {$done} of ".count($kinds).' posts');
            }
        }
    }

    /** @return list<CarbonImmutable> publication times over the past year, oldest first, denser lately; the newest within hours */
    private function dates(int $n): array
    {
        $dates = [];
        for ($i = 0; $i < $n; $i++) {
            $age = 365 * (1 - $i / max(1, $n - 1)) ** 1.6;   // days back
            $day = $this->now->subDays((int) floor($age))->setTimezone('Asia/Hebron')->setTime($this->rng->getInt(7, 21), $this->rng->getInt(0, 59));
            $dates[] = $day->greaterThan($this->now->subMinutes(20)) ? $this->now->subMinutes($this->rng->getInt(20, 180)) : $day->utc();
        }
        sort($dates);

        return $dates;
    }

    /** News, reports, opinion pieces and infographics: a category, a writer, a body made of the category's paragraphs. */
    private function general(string $kind, int $n): array
    {
        // Reports and infographics have their own category; news and opinion pieces take any.
        [$title, $slug] = match ($kind) {
            'report' => DemoCorpus::REPORT_TITLES[($n - 1) % count(DemoCorpus::REPORT_TITLES)],
            'infographic' => DemoCorpus::INFOGRAPHIC_TITLES[($n - 1) % count(DemoCorpus::INFOGRAPHIC_TITLES)],
            'opinion' => [DemoCorpus::OPINION_TITLES[($n - 1) % count(DemoCorpus::OPINION_TITLES)], null],
            'news' => [null, null],
        };
        $slug = isset($this->categories[$slug ?? '']) ? $slug : $this->pick(array_keys($this->categories));
        $category = $this->categories[$slug];
        $paragraphs = DemoCorpus::NEWS_PARAGRAPHS[$slug] ?? DemoCorpus::NEWS_PARAGRAPHS['محلي-نابلس'];
        $values = $this->values($slug);
        $lead = null;
        if ($kind === 'news') {
            // A title and its opening sentence; another template or other numbers until the title is new.
            $topics = DemoCorpus::NEWS_TITLES[$slug] ?? DemoCorpus::NEWS_TITLES['محلي-نابلس'];
            for ($try = 0; $try < 25; $try++) {
                [$template, $lead] = $this->pick($topics);
                $title = DemoCorpus::fill($template, $values);
                if (! isset($this->titles[$title])) {
                    break;
                }
                $values = $this->values($slug);
            }
        }
        $this->titles[$title] = true;

        $html = match ($kind) {
            'news' => $this->paragraphs([$lead, ...$this->sample($paragraphs, $this->rng->getInt(2, 4))], $values),
            'report' => $this->report($paragraphs, $values, $slug),
            'opinion' => $this->opinion($values),
            'infographic' => $this->paragraphs($this->lead($paragraphs, 2), $values).$this->list($this->pick(DemoCorpus::LISTS))
                .'<p>يمكنكم تنزيل الإنفوغراف بحجمه الكامل من الرابط أسفل الصورة.</p>',
        };
        $html .= $this->extras($kind, $category);

        $data = [
            'title' => $title, 'body' => $html, 'category_id' => $category, 'cover_media_id' => $this->pick($this->pictures[$kind === 'infographic' ? 'tall' : 'wide']),
            'tags' => $this->tags($slug),
            'subtitle' => in_array($kind, ['report', 'opinion'], true) && $this->rng->getInt(0, 1) ? $this->subtitle($values) : null,
            'gallery' => $kind !== 'infographic' && $this->chance(0.2) ? $this->sample($this->pictures['wide'], $this->rng->getInt(3, 6)) : [],
        ];
        if ($kind === 'infographic') {
            $data['infographic'] = ['download_media_id' => $this->pick($this->pictures['chart'])];
        }
        if ($kind === 'report' && $this->videos['report'] !== [] && $this->chance(0.3)) {
            $data['primary_video_id'] = $this->pick($this->videos['report']);
        }
        if ($kind === 'report' && $this->cards !== [] && $this->chance(0.4)) {
            $data['awareness_cards'] = [$this->pick($this->cards)];
        }

        return $data;
    }

    private function report(array $paragraphs, array $values, string $category): string
    {
        $html = $this->paragraphs($this->lead($paragraphs, 2), $values);
        $analysis = $this->sample(DemoCorpus::ANALYSIS_PARAGRAPHS, 6);
        foreach ($this->sample(DemoCorpus::HEADINGS, 3) as $i => $heading) {
            $html .= '<h2>'.e($heading).'</h2>'.$this->paragraphs(array_slice($analysis, $i * 2, 2), $this->values($category));
            if ($i === 0) {
                $html .= '<blockquote><p>'.e($this->pick(DemoCorpus::QUOTES)).'</p></blockquote>';
            }
            if ($i === 1) {
                $html .= $this->list($this->pick(DemoCorpus::LISTS));
                if ($this->chance(0.5)) {
                    $html .= $this->block('image', ['media_id' => $this->pick($this->pictures['wide']), 'caption' => 'صورة توضيحية من أرشيف رَقَم']);
                }
            }
        }

        return $html;
    }

    private function opinion(array $values): string
    {
        $parts = $this->sample(DemoCorpus::OPINION_PARAGRAPHS, $this->rng->getInt(5, 7));
        $html = $this->paragraphs(array_slice($parts, 0, 3), $values);
        $html .= '<h2>'.e($this->pick(DemoCorpus::HEADINGS)).'</h2>';
        $html .= $this->paragraphs(array_slice($parts, 3), $values);
        if ($this->chance(0.5)) {
            $html .= '<blockquote><p>'.e($this->pick(DemoCorpus::QUOTES)).'</p></blockquote>';
        }

        return $html;
    }

    /** Blocks some items carry after their text: a picture, a gallery, a video, an awareness card, «read also». */
    private function extras(string $kind, int $category): string
    {
        $html = '';
        if ($kind === 'news' && $this->chance(0.2)) {
            $html .= $this->block('image', ['media_id' => $this->pick($this->pictures['wide'])]);
        }
        if (in_array($kind, ['news', 'report'], true) && $this->chance(0.1)) {
            $html .= $this->block('gallery', ['media_ids' => $this->sample($this->pictures['wide'], 4)]);
        }
        if (in_array($kind, ['news', 'report'], true) && $this->videos['report'] !== [] && $this->chance(0.08)) {
            $html .= $this->block('video', ['video_id' => $this->pick($this->videos['report'])]);
        }
        if ($kind === 'report' && $this->cards !== [] && $this->chance(0.2)) {
            $html .= $this->block('awareness', ['post_id' => $this->pick($this->cards)]);
        }
        $earlier = array_slice($this->byCategory[$category] ?? [], -15);
        if ($kind !== 'infographic' && count($earlier) >= 2 && $this->chance(0.4)) {
            $html .= $this->block('read-also', ['post_ids' => $this->sample($earlier, min(3, count($earlier)))]);
        }

        return $html;
    }

    private function episode(int $n, int $season): array
    {
        $topic = DemoCorpus::EPISODE_TOPICS[($n - 1) % count(DemoCorpus::EPISODE_TOPICS)];
        $number = (int) DB::table('podcast_episodes')->where('season_id', $this->seasons[$season]['id'])->max('episode_number') + 1;
        $host = $this->guests[0];
        $guests = $this->sample(array_slice($this->guests, 2), $this->rng->getInt(1, 2));
        $title = "الحلقة {$number}: {$topic}";
        $points = $this->sample(DemoCorpus::EPISODE_POINTS, 4);

        $seconds = $this->rng->getInt(1500, 3600);
        $chapters = [];
        foreach ($points as $i => $point) {
            $chapters[] = ['time' => (int) floor($i * $seconds / count($points)), 'title' => $point];
        }

        $body = '<p>'.e("في هذه الحلقة من بودكاست رَقَم، يستضيف {$host['name']} ".implode(' و', array_map(fn ($g) => $g['name'].' ('.$g['role'].')', $guests))." للحديث عن «{$topic}».").'</p>'
            .'<h3>محاور الحلقة</h3>'.$this->list($points)
            .'<p>'.e('يمكنكم الاستماع إلى الحلقة كاملة عبر الفيديو أعلاه، أو متابعة بودكاست رَقَم على قوائم التشغيل.').'</p>';

        return [
            'title' => $title, 'body' => $body, 'cover_media_id' => $this->pick($this->pictures['wide']), 'tags' => $this->tags(null),
            'primary_video_id' => $this->video($title, $seconds, false, $chapters),
            'guests' => [['guest_id' => $host['id'], 'role_code' => 'host'], ...array_map(fn ($g) => ['guest_id' => $g['id'], 'role_code' => 'guest'], $guests)],
            'episode' => ['season_id' => $this->seasons[$season]['id'], 'episode_number' => $number],
        ];
    }

    private function story(int $n): array
    {
        $org = $this->orgs[($n * 7) % count($this->orgs)];
        [$person, $gender] = $this->personName();
        $verbs = DemoCorpus::verbs($gender);
        $values = ['org' => $org['name'], 'person' => $person, 'role' => $verbs['founder'].' '.$org['name']] + $verbs + $this->values();
        $title = $this->uniqueTitle(fn () => DemoCorpus::fill($this->pick(DemoCorpus::STORY_TITLES), $values));
        $figures = array_map(fn ($f) => ['value' => DemoCorpus::fill($f[0], ['year' => (string) $org['founded']] + $values), 'label' => $f[1]], $this->sample(DemoCorpus::STORY_FIGURES, 3));

        return [
            'title' => $title, 'body' => $this->paragraphs($this->opening(DemoCorpus::STORY_PARAGRAPHS, 5), $values).'<blockquote><p>'.e($this->pick(DemoCorpus::QUOTES)).'</p></blockquote>',
            'cover_media_id' => $this->pick($this->pictures['wide']), 'tags' => ['ريادة الأعمال', 'المنتج المحلي'],
            'gallery' => $this->sample($this->pictures['wide'], 3),
            'story' => ['organization_id' => $org['id'], 'person_name' => $person, 'figures' => $figures],
        ];
    }

    private function product(int $n): array
    {
        [$name, $sector] = DemoCorpus::PRODUCTS[($n - 1) % count(DemoCorpus::PRODUCTS)];
        $makers = array_values(array_filter($this->orgs, fn ($o) => $o['sector'] === DemoCorpus::SECTORS[$sector])) ?: $this->orgs;
        $org = $makers[intdiv($n - 1, count(DemoCorpus::PRODUCTS)) % count($makers)];
        [$person, $gender] = $this->personName();
        $values = ['org' => $org['name'], 'product' => $name, 'person' => $person] + DemoCorpus::verbs($gender) + $this->values();

        return [
            'title' => "صُنع في نابلس: {$name} من {$org['name']}", 'body' => $this->paragraphs($this->opening(DemoCorpus::PRODUCT_PARAGRAPHS, 4), $values),
            'cover_media_id' => $this->pick($this->pictures['square']), 'tags' => ['المنتج المحلي', 'الصناعات الغذائية'],
            'gallery' => $this->sample($this->pictures['wide'], 3),
            'sponsor_organization_id' => $n <= 2 ? $org['id'] : null,
            'product' => ['product_name' => $name, 'organization_id' => $org['id'], 'contact_url' => $org['website'].'/products'],
        ];
    }

    private function history(int $n): array
    {
        [$title, $era, $label, $from, $to] = DemoCorpus::HISTORY[($n - 1) % count(DemoCorpus::HISTORY)];
        $paragraphs = $this->lead(DemoCorpus::HISTORY_PARAGRAPHS, 4);

        return [
            'title' => $title, 'cover_media_id' => $this->pick($this->pictures['wide']), 'tags' => ['البلدة القديمة'],
            'body' => $this->paragraphs(array_slice($paragraphs, 0, 2), []).'<h2>'.e($this->pick(DemoCorpus::HEADINGS)).'</h2>'.$this->paragraphs(array_slice($paragraphs, 2), []),
            'history' => ['era_id' => $this->eras[$era], 'period_label' => $label, 'year_from' => $from, 'year_to' => $to],
        ];
    }

    private function initiative(int $n, CarbonImmutable $at): array
    {
        [$name, $aim] = DemoCorpus::INITIATIVES[($n - 1) % count(DemoCorpus::INITIATIVES)];
        $organisers = array_values(array_filter($this->orgs, fn ($o) => in_array($o['sector'], [DemoCorpus::SECTORS[5], DemoCorpus::SECTORS[7]], true)));
        $org = $organisers[($n - 1) % count($organisers)];
        $values = ['initiative' => $name, 'aim' => $aim, 'org' => $org['name']] + $this->values();

        return [
            'title' => "{$name}: {$aim}", 'body' => $this->paragraphs($this->opening(DemoCorpus::INITIATIVE_PARAGRAPHS, 4), $values),
            'cover_media_id' => $this->pick($this->pictures['wide']), 'tags' => ['الشباب', 'التعليم المهني'],
            'sponsor_organization_id' => $n === 1 ? $org['id'] : null,
            'initiative' => ['initiative_name' => $name, 'organizer_organization_id' => $org['id'], 'status' => $at->greaterThan($this->now->subDays(120)) ? 'ongoing' : 'ended', 'url' => $org['website'].'/initiative'],
        ];
    }

    private function roundtable(int $n, CarbonImmutable $at): array
    {
        $title = DemoCorpus::ROUNDTABLE_TITLES[($n - 1) % count(DemoCorpus::ROUNDTABLE_TITLES)];
        $host = $this->guests[1];
        $panel = $this->sample(array_slice($this->guests, 2), 3);
        $data = [
            'title' => $title, 'cover_media_id' => $this->pick($this->pictures['wide']), 'tags' => $this->tags(null),
            'body' => $this->paragraphs(DemoCorpus::ROUNDTABLE_PARAGRAPHS, ['host' => $host['name']]).'<h2>أبرز التوصيات</h2>'.$this->list(DemoCorpus::LISTS[0]),
            'guests' => [['guest_id' => $host['id'], 'role_code' => 'host'], ...array_map(fn ($g) => ['guest_id' => $g['id'], 'role_code' => 'panelist'], $panel)],
            'roundtable' => ['held_on' => $at->setTimezone('Asia/Hebron')->subDays($this->rng->getInt(1, 5))->toDateString()],
        ];
        if ($n % 2 === 1) {
            $data['primary_video_id'] = $this->video($title, $this->rng->getInt(2400, 5400), false);
        }

        return $data;
    }

    /** The cards go round the characters: three each, the narrator's first. */
    private function awareness(int $n): array
    {
        $slugs = array_keys($this->poses);
        if ($slugs === []) {
            throw new \RuntimeException('No visible awareness character to draw cards for.');
        }
        $slug = $slugs[($n - 1) % count($slugs)];
        $round = intdiv($n - 1, count($slugs));
        $type = DemoCorpus::CHARACTER_TYPES[$slug] ?? null;
        [$type, $title, $text] = $type === null
            ? DemoCorpus::NARRATOR_CARDS[$round % count(DemoCorpus::NARRATOR_CARDS)]
            : [$type, ...DemoCorpus::AWARENESS[$type][$round % count(DemoCorpus::AWARENESS[$type])]];
        $values = $this->values();
        $codes = array_keys($this->poses[$slug]);
        $pose = $this->poses[$slug][$codes[($n - 1) % count($codes)]];

        return [
            'title' => DemoCorpus::fill($title, $values), 'body' => '<p>'.e(DemoCorpus::fill($text, $values)).'</p>',
            'cover_media_id' => (int) DB::table('character_poses')->where('id', $pose)->value('static_media_id'),
            'tags' => ['حماية المستهلك', 'الادخار'],
            'awareness' => ['character_pose_id' => $pose, 'awareness_type_code' => DB::table('awareness_types')->where('code', $type)->exists() ? $type : DB::table('awareness_types')->value('code')],
        ];
    }

    // ---- bodies -------------------------------------------------------------------------------------------------

    /** @param list<string> $templates */
    private function paragraphs(array $templates, array $values): string
    {
        return implode('', array_map(fn ($t) => '<p>'.e(DemoCorpus::fill($t, $values + $this->values())).'</p>', $templates));
    }

    /** @param list<string> $items */
    private function list(array $items): string
    {
        return '<ul>'.implode('', array_map(fn ($i) => '<li>'.e($i).'</li>', $items)).'</ul>';
    }

    /** A custom block as the editor writes it: a reference only, checked again by the sanitizer. */
    private function block(string $id, array $config): string
    {
        return '<div data-type="customBlock" data-id="'.$id.'" data-config="'.e(json_encode($config, JSON_UNESCAPED_UNICODE)).'"></div>';
    }

    private function excerpt(string $html): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/<div[^>]*><\/div>/u', '', explode('</p>', $html)[0]))));

        return mb_strlen($text) > 280 ? rtrim(mb_substr($text, 0, 277)).'…' : $text;
    }

    private function subtitle(array $values): string
    {
        return DemoCorpus::fill($this->pick([
            'قراءة في الأرقام وما وراءها', 'ما يقوله أصحاب الشأن ولا تقوله البيانات', 'جولة ميدانية وأسئلة مفتوحة', 'بين التحدي والفرصة في {city}',
        ]), $values);
    }

    /** @return list<int> one writer mostly, sometimes two, sometimes none (shown as «فريق رَقَم») */
    private function writers(string $kind): array
    {
        if ($kind === 'opinion') {
            return [$this->authors[5] ?? $this->authors[0]];
        }
        $roll = $this->rng->getFloat(0, 1);

        return match (true) {
            $roll < 0.08 => [],
            $roll < 0.18 => $this->sample($this->authors, 2),
            default => [$this->pick($this->authors)],
        };
    }

    /** @return list<string> */
    private function tags(?string $category): array
    {
        $own = $category === null ? [] : (DemoCorpus::CATEGORY_TAGS[$category] ?? []);

        $general = $this->chance(0.25) ? [$this->pick(['الأسعار', 'سوق العمل', 'الشباب', 'المنتج المحلي', 'التمويل'])] : [];

        return array_values(array_unique([...$this->sample($own, min(count($own), $this->rng->getInt(1, 3))), ...$general]));
    }

    /** The random values a template may ask for: an organisation that suits the category, one of its speakers, numbers. */
    private function values(?string $category = null): array
    {
        $sectors = array_map(fn ($i) => DemoCorpus::SECTORS[$i], DemoCorpus::CATEGORY_SECTORS[$category] ?? array_keys(DemoCorpus::SECTORS));
        $orgs = array_values(array_filter($this->orgs, fn ($o) => in_array($o['sector'], $sectors, true))) ?: $this->orgs;
        $org = $orgs === [] ? null : $this->pick($orgs);
        $others = array_values(array_filter($this->orgs, fn ($o) => $o['name'] !== ($org['name'] ?? null)));
        [$speaker, $role, $gender] = $this->pick(DemoCorpus::SPEAKERS[$category] ?? DemoCorpus::SPEAKERS['*']);

        return DemoCorpus::verbs($gender) + [
            'org' => $org['name'] ?? '', 'org2' => $others === [] ? '' : $this->pick($others)['name'], 'sector' => $org['sector'] ?? '',
            'city' => $this->pick(array_values($this->cities)), 'person' => $speaker, 'role' => $role,
            'pct' => $this->rng->getInt(3, 35), 'pct2' => $this->rng->getInt(1, 4), 'num' => $this->rng->getInt(12, 480), 'num2' => $this->rng->getInt(3, 12),
            'big' => $this->rng->getInt(5, 60), 'year' => $org['founded'] ?? $this->rng->getInt(1985, 2018),
            'season' => $this->pick(DemoCorpus::SEASONS), 'trend' => $this->pick(DemoCorpus::TRENDS), 'crop' => $this->pick(DemoCorpus::CROPS),
            'product' => $this->pick(DemoCorpus::PRODUCT_NOUNS[array_search($org['sector'] ?? '', DemoCorpus::SECTORS, true)] ?? array_merge(...DemoCorpus::PRODUCT_NOUNS)),
            'currency' => $this->pick(DemoCorpus::CURRENCIES),
        ];
    }

    private function uniqueTitle(\Closure $make): string
    {
        for ($try = 0; $try < 25; $try++) {
            $title = $make();
            if (! isset($this->titles[$title])) {
                break;
            }
        }
        $this->titles[$title] = true;

        return $title;
    }

    /** @return array{0: string, 1: string} a made-up name and its gender */
    private function personName(): array
    {
        $gender = $this->pick(['m', 'f']);

        return [$this->pick(DemoCorpus::FIRST_NAMES[$gender]).' '.$this->pick(DemoCorpus::LAST_NAMES), $gender];
    }

    /** @return list<string> the first paragraph of the list, then $n - 1 of the others */
    private function opening(array $pool, int $n): array
    {
        return [$pool[0], ...$this->sample(array_slice($pool, 1), $n - 1)];
    }

    /** @return list<string> $n paragraphs, the first one able to open an item (not a «و…» continuation) */
    private function lead(array $pool, int $n): array
    {
        $openers = array_values(array_filter($pool, fn ($p) => ! str_starts_with($p, 'و')));
        $first = $openers === [] ? $this->pick($pool) : $this->pick($openers);

        return [$first, ...$this->sample(array_values(array_filter($pool, fn ($p) => $p !== $first)), $n - 1)];
    }

    // ---- reading counts, markets ----------------------------------------------------------------------------------

    /** Daily reading counts of the last two weeks for the recent items, so «most read» has something to rank. */
    private function makeViews(): void
    {
        $today = $this->now->setTimezone('Asia/Hebron')->startOfDay();
        $rows = [];
        foreach (DB::table('posts')->where('status', 'published')->where('published_at', '>', $this->now->subDays(30))->get(['id', 'published_at']) as $post) {
            $base = $this->rng->getInt(40, 1600);
            $first = CarbonImmutable::parse($post->published_at)->setTimezone('Asia/Hebron')->startOfDay();
            for ($day = $first->max($today->subDays(13)); $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
                $age = (int) $first->diffInDays($day);
                $rows[] = ['post_id' => $post->id, 'stat_date' => $day->toDateString(), 'views' => max(1, (int) round($base / (1 + $age * 0.6) * $this->rng->getFloat(0.7, 1.3)))];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('post_views_daily')->insert($chunk);
        }
        DB::statement('REFRESH MATERIALIZED VIEW post_view_totals');
    }

    /** One publication per business day for a year, as the Bank of Israel's fetch keeps them: a random walk around today's levels. */
    private function makeRates(): void
    {
        $provider = DataProviders::active('currency');
        if ($provider === null) {
            return;
        }
        // The dollar first: the dinar follows it (the Jordanian dinar is pegged to the dollar).
        $codes = collect(app(RateFetcher::class)->wanted())->sortBy(fn ($c) => $c === 'USD' ? 0 : 1)->values()->all();
        $level = [];
        foreach ($codes as $code) {
            $bounds = CurrencyCatalog::bounds($code);
            $level[$code] = (float) (self::RATE_BASE[$code] ?? ($bounds === null ? 1 : ((float) $bounds[0] + (float) $bounds[1]) / 2));
        }

        $rows = [];
        $today = $this->now->setTimezone('Asia/Hebron')->startOfDay();
        for ($day = $today->subYear(); $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            if ($day->dayOfWeekIso > 5) {   // the bank publishes Monday to Friday
                continue;
            }
            $published = $day->setTime(15, $this->rng->getInt(20, 45));
            if ($published->greaterThan($this->now)) {
                break;
            }
            $usd = null;
            foreach ($codes as $code) {
                $base = (float) (self::RATE_BASE[$code] ?? $level[$code]);
                // A small daily step, pulled gently back towards the starting level.
                $level[$code] *= exp($this->rng->getFloat(-0.006, 0.006) + 0.03 * log($base / $level[$code]));
                $value = $code === 'JOD' && isset($usd) ? $usd / 0.709 : $level[$code];
                if ($code === 'USD') {
                    $usd = $value;
                }
                $bounds = CurrencyCatalog::bounds($code);
                if ($bounds !== null) {
                    $value = min((float) $bounds[1], max((float) $bounds[0], $value));
                }
                $rows[] = [
                    'currency_code' => $code, 'provider_code' => $provider, 'mid_rate' => number_format($value, $value < 1 ? 4 : 3, '.', ''),
                    'effective_at' => $published->utc()->format('Y-m-d H:i:sP'), 'fetched_at' => $published->addMinutes(7)->min($this->now)->utc()->format('Y-m-d H:i:sP'),
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('exchange_rates')->insertOrIgnore($chunk);
        }
        RateBoard::changed('rates_published');
    }

    /** The last thirty hours of readings and five days of forecast for every city on the weather list. */
    private function makeWeather(): void
    {
        $provider = DataProviders::active('weather') ?? WeatherFetcher::PROVIDER;
        $hour = $this->now->startOfHour();
        foreach (DB::table('weather_locations')->join('cities', 'cities.id', '=', 'weather_locations.city_id')->where('weather_locations.is_active', true)->get(['cities.id', 'cities.name_ar']) as $city) {
            [$low, $high] = self::CLIMATE[$city->name_ar] ?? self::CLIMATE['نابلس'];
            $offset = $this->rng->getInt(1, 9);
            $sky = $this->pick([800, 800, 801, 802, 803, 500]);
            $readings = [];
            for ($k = 29; $k >= 0; $k--) {
                $at = $hour->subHours($k)->subMinutes($offset);
                $local = (int) $at->setTimezone('Asia/Hebron')->format('G');
                $temperature = $low + ($high - $low) * max(0, sin(M_PI * ($local - 5) / 16)) + $this->rng->getFloat(-0.8, 0.8);
                $readings[] = [
                    'city_id' => $city->id, 'provider_code' => $provider, 'observed_at' => $at->utc()->format('Y-m-d H:i:sP'),
                    'temperature_c' => round($temperature, 1), 'feels_like_c' => round($temperature + $this->rng->getFloat(-1.5, 1.0), 1),
                    'humidity_pct' => $this->rng->getInt(35, 80), 'wind_kmh' => round($this->rng->getFloat(4, 24), 1), 'wind_deg' => $this->rng->getInt(0, 359),
                    'condition_code' => $local >= 6 && $local <= 17 ? $sky : ($sky === 500 ? 500 : 800), 'is_day' => $local >= 6 && $local <= 17,
                    'fetched_at' => $at->addMinutes(2)->utc()->format('Y-m-d H:i:sP'),
                ];
            }
            DB::table('weather_observations')->insertOrIgnore($readings);

            $today = $this->now->setTimezone('Asia/Hebron')->startOfDay();
            $seen = DB::table('weather_observations')->where('city_id', $city->id)->where('observed_at', '>=', $today->utc()->format('Y-m-d H:i:sP'))
                ->selectRaw('min(temperature_c) AS low, max(temperature_c) AS high')->first();
            $forecast = [];
            for ($d = 0; $d <= 5; $d++) {
                $min = round($low + $this->rng->getFloat(-2, 1.5), 1);
                $max = round($high + $this->rng->getFloat(-2.5, 1.5), 1);
                if ($d === 0 && $seen->low !== null) {
                    $min = min($min, (float) $seen->low);
                    $max = max($max, (float) $seen->high);
                }
                $forecast[] = ['city_id' => $city->id, 'forecast_date' => $today->addDays($d)->toDateString(), 'temp_min_c' => $min, 'temp_max_c' => max($min, $max),
                    'condition_code' => $d === 0 ? $sky : $this->pick([800, 801, 802, 803, 804, 500, 520]), 'provider_code' => $provider];
            }
            DB::table('weather_forecasts')->insertOrIgnore($forecast);
        }
        WeatherBoard::changed();
    }

    // ---- ads ------------------------------------------------------------------------------------------------------

    private function makeAds(): void
    {
        $advertisers = [];
        foreach ([3, 1, 10, 6, 21] as $index) {
            $org = $this->orgs[$index];
            $advertisers[] = Advertiser::query()->create(['organization_id' => $org['id'], 'contact_name' => $this->personName()[0],
                'contact_email' => 'ads@'.parse_url($org['website'], PHP_URL_HOST), 'notes' => 'معلن تجريبي.'])->id;
        }

        $placements = AdPlacement::query()->where('is_enabled', true)->orderBy('code')->get();
        $banner = function (AdPlacement $placement, string $device, bool $house = false) {
            $size = $placement->size($device);

            return $size === null ? null : $this->upload($this->images->banner($size['width'] * 2, $size['height'] * 2, $this->seed(), $house),
                "banner-{$placement->code}-{$device}.jpg", $house ? 'أعلن معنا في رَقَم' : 'إعلان تجريبي');
        };

        foreach ($placements as $i => $placement) {
            $campaigns = $placement->code === 'sidebar_mpu' ? 2 : 1;
            for ($c = 0; $c < $campaigns; $c++) {
                $advertiser = $advertisers[($i + $c) % count($advertisers)];
                $org = Advertiser::query()->find($advertiser)->organization_id;
                $website = collect($this->orgs)->firstWhere('id', $org)['website'];
                $this->campaigns->save(null, [
                    'advertiser_id' => $advertiser, 'name' => 'حملة تجريبية — '.$placement->name_ar.($c ? ' (2)' : ''), 'target_url' => $website.'/offers',
                    'starts_at' => $this->now->subDays($this->rng->getInt(3, 20))->format('Y-m-d H:i:s'), 'ends_at' => $this->now->addDays($this->rng->getInt(30, 90))->format('Y-m-d H:i:s'),
                    'priority' => 70 - $c * 30, 'status' => 'active', 'placements' => [$placement->code],
                    'desktop_media_id' => $banner($placement, 'desktop'), 'mobile_media_id' => $banner($placement, 'mobile'),
                ], $this->user);
            }

            $this->slots->save($placement, [
                'name_ar' => $placement->name_ar, 'desktop_width' => $placement->desktop_width, 'desktop_height' => $placement->desktop_height,
                'mobile_width' => $placement->mobile_width, 'mobile_height' => $placement->mobile_height, 'is_enabled' => $placement->is_enabled,
                'empty_behavior' => $placement->empty_behavior, 'frequency_cap_hours' => $placement->frequency_cap_hours, 'lazy_load' => $placement->lazy_load,
                'house_desktop_media_id' => $banner($placement, 'desktop', true), 'house_mobile_media_id' => $banner($placement, 'mobile', true),
                'house_target_path' => '/advertise', 'house_is_active' => true,
            ]);
        }

        // One paused campaign, for the panel's lists.
        $first = $placements->first();
        if ($first !== null) {
            $this->campaigns->save(null, [
                'advertiser_id' => $advertisers[0], 'name' => 'حملة موقوفة مؤقتاً', 'target_url' => $this->orgs[3]['website'].'/spring',
                'starts_at' => $this->now->subDays(10)->format('Y-m-d H:i:s'), 'ends_at' => $this->now->addDays(20)->format('Y-m-d H:i:s'),
                'priority' => 40, 'status' => 'paused', 'placements' => [$first->code],
                'desktop_media_id' => $banner($first, 'desktop'), 'mobile_media_id' => $banner($first, 'mobile'),
            ], $this->user);
        }
    }

    // ---- services, FAQ, pages, menus, home ------------------------------------------------------------------------

    private function makeServices(DemoManifest $manifest): void
    {
        foreach (Service::query()->whereIn('slug', array_keys(self::SERVICE_PRICES))->orderBy('id')->get() as $service) {
            [$mode, $price, $currency, $note, $offer] = self::SERVICE_PRICES[$service->slug] + [null, null, null, null, null];
            $before = DemoRemover::serviceState($service->id);
            $deliverables = $service->deliverables() ?: (DemoCorpus::SERVICE_ITEMS[$service->slug] ?? []);
            $faqs = DB::table('faqs')->where('service_id', $service->id)->orderBy('sort_order')->get(['question', 'answer', 'is_published'])->map(fn ($f) => (array) $f)->all();

            $this->catalog->save($service, [
                'group_id' => $service->group_id, 'section_id' => $service->section_id, 'name_ar' => $service->name_ar, 'slug' => $service->slug,
                'summary' => $service->summary, 'description' => $service->description, 'cover_media_id' => $this->pick($this->pictures['wide']),
                'is_active' => true, 'deliverables' => $deliverables, 'faqs' => $faqs,
                'price_mode' => $mode, 'price' => $price, 'currency_code' => $currency, 'price_note' => $note,
                'offer' => $offer !== null, 'sale_price' => $offer, 'sale_ends_at' => $offer !== null ? $this->now->addDays(20)->format('Y-m-d H:i:s') : null,
            ], $this->user);
            $manifest->rememberService($service->id, $before, DemoRemover::serviceHash($service->id));
        }
    }

    private function makeFaqs(): void
    {
        $start = (int) DB::table('faqs')->whereNull('service_id')->max('sort_order');
        foreach (DemoCorpus::FAQS as $i => [$question, $answer]) {
            Faq::query()->create(['service_id' => null, 'question' => $question, 'answer' => $answer, 'sort_order' => $start + $i + 1, 'is_published' => true]);
        }
        KnowledgeBase::changed();
    }

    /** The standard pages are drafts until the owner writes them; the empty ones get a placeholder text and go live. */
    private function makePages(DemoManifest $manifest): void
    {
        foreach (Page::query()->whereIn('system_key', array_keys(DemoCorpus::PAGE_PARTS))->where('is_published', false)->get() as $page) {
            if (trim((string) $page->body) !== '') {
                continue;
            }
            $parts = DemoCorpus::PAGE_PARTS[$page->system_key];
            $html = '<p>'.e(array_shift($parts)).'</p>';
            foreach (array_chunk($parts, 2) as [$heading, $text]) {
                $html .= '<h2>'.e($heading).'</h2><p>'.e($text).'</p>';
            }
            $body = $this->sanitizer->clean($html, allowBlocks: false);
            $manifest->rememberPage($page->id, ['body' => $page->body, 'is_published' => (bool) $page->is_published, 'updated_by' => $page->updated_by], $body);
            $page->fill(['body' => $body, 'is_published' => true, 'updated_by' => $this->user])->save();
        }
    }

    /** Header and footer links to real addresses of the site; a menu the admin already filled is left alone. */
    private function makeMenus(): void
    {
        $pages = DB::table('pages')->pluck('id', 'system_key');
        $sections = DB::table('sections')->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar']);
        $url = fn (string $label, string $path) => ['label_ar' => $label, 'type' => 'url', 'url' => $path];
        $page = fn (string $key) => isset($pages[$key]) ? ['label_ar' => DB::table('pages')->where('id', $pages[$key])->value('title'), 'type' => 'page', 'page_id' => $pages[$key]] : null;

        $trees = [
            'header' => [
                $url('الرئيسية', '/'),
                ...array_map(fn ($slug, $id) => ['label_ar' => DB::table('categories')->where('id', $id)->value('name_ar'), 'type' => 'category', 'category_id' => $id], array_keys($this->categories), $this->categories),
                ['label_ar' => 'أقسام رَقَم', 'type' => 'section', 'section_id' => $sections->first()?->id,
                    'children' => $sections->map(fn ($s) => ['label_ar' => $s->name_ar, 'type' => 'section', 'section_id' => $s->id])->all()],
                $url('فيديو', '/video'), $url('العملات', '/currencies'), $url('الطقس', '/weather'),
            ],
            'footer_main' => array_values(array_filter(array_map($page, ['about', 'team', 'contact', 'advertise', 'services', 'faq', 'editorial_policy']))),
            'footer_legal' => array_values(array_filter(array_map($page, ['privacy', 'terms', 'cookies', 'copyright', 'corrections_policy', 'sponsored_policy']))),
        ];
        foreach ($trees as $key => $tree) {
            if ($this->menus->load($key) === [] && $tree !== []) {
                $this->menus->save($key, $tree, $this->user);
            }
        }
    }

    /** The default home page already lists every block; the newest podcast season joins it as a playlist block after the videos. */
    private function makeHome(): void
    {
        $season = end($this->seasons);
        $blocks = $this->home->load();
        if ($season === false || collect($blocks)->contains(fn ($b) => $b['block_type'] === 'playlist' && (int) $b['playlist_id'] === $season['playlist'])) {
            return;
        }
        $at = collect($blocks)->search(fn ($b) => $b['block_type'] === 'videos');
        $new = ['id' => null, 'block_type' => 'playlist', 'title_ar' => null, 'category_id' => null, 'section_id' => null, 'playlist_id' => $season['playlist'],
            'placement_code' => null, 'item_limit' => 8, 'is_active' => true];
        array_splice($blocks, $at === false ? count($blocks) : $at + 1, 0, [$new]);
        $this->home->save($blocks, $this->user);
    }

    // ---- randomness -----------------------------------------------------------------------------------------------

    private function seed(): int
    {
        return $this->rng->getInt(1, PHP_INT_MAX >> 1);
    }

    private function pick(array $list): mixed
    {
        return $list[array_keys($list)[$this->rng->getInt(0, count($list) - 1)]];
    }

    /** @return list<mixed> up to $n different items */
    private function sample(array $list, int $n): array
    {
        return array_slice($this->rng->shuffleArray(array_values($list)), 0, max(0, $n));
    }

    private function chance(float $p): bool
    {
        return $this->rng->getFloat(0, 1) < $p;
    }

    private function step(string $what): void
    {
        $this->progress && ($this->progress)($what);
    }
}
