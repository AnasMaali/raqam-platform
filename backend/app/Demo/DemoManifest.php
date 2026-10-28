<?php

namespace App\Demo;

use Illuminate\Support\Facades\DB;

/**
 * The record of what raqam:demo wrote, so that `--fresh` / `--remove` take away exactly the demo's own rows and nothing
 * else. It lives in a file next to the application (one per database), not in the database: the schema has no place for
 * it and needs none. It holds the keys of the rows the run added (every table below, found by comparing the keys before
 * and after the run), what the pages and services looked like before the demo filled them, and the checksum of each
 * picture, which tells whether the ids still point at the demo's rows (a database rebuilt since would not match).
 */
class DemoManifest
{
    /**
     * Every table the demo adds rows to, with the expression that names a row, in the order the rows are removed
     * (what points at a row goes before it).
     *
     * @var array<string, string>
     */
    public const TABLES = [
        'home_blocks' => 'id',
        'menu_items' => 'id',
        'faqs' => 'id',
        'ad_campaigns' => 'id',
        'house_ads' => 'placement_code',
        'advertisers' => 'id',
        'posts' => 'id',
        'podcast_seasons' => 'id',
        'videos' => 'id',
        'playlists' => 'id',
        'character_poses' => 'id',
        'guests' => 'id',
        'authors' => 'id',
        'organizations' => 'id',
        'sectors' => 'id',
        'eras' => 'id',
        'tags' => 'id',
        'exchange_rates' => 'id',
        'weather_observations' => 'id',
        'weather_forecasts' => "city_id || '|' || forecast_date",
        'media_assets' => 'id',
    ];

    /** @var array<string, list<string>> table => keys */
    private array $rows = [];

    /** @var array<int, array<string, mixed>> page id => what it was before, and a hash of what the demo wrote */
    private array $pages = [];

    /** @var array<int, array<string, mixed>> service id => what it was before, and a hash of what the demo wrote */
    private array $services = [];

    /** @var array<int, string> media id => checksum */
    private array $checksums = [];

    public function __construct(private ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? storage_path('app/private/raqam-demo/'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) DB::connection()->getDatabaseName()).'.json');
    }

    public function load(): static
    {
        $data = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;
        $this->rows = array_map(fn ($keys) => array_map('strval', (array) $keys), (array) ($data['rows'] ?? []));
        $this->pages = (array) ($data['pages'] ?? []);
        $this->services = (array) ($data['services'] ?? []);
        $this->checksums = (array) ($data['checksums'] ?? []);

        return $this;
    }

    public function save(): void
    {
        if (! is_dir(dirname($this->path()))) {
            mkdir(dirname($this->path()), 0775, true);
        }
        file_put_contents($this->path(), json_encode(
            ['database' => DB::connection()->getDatabaseName(), 'rows' => $this->rows, 'pages' => $this->pages, 'services' => $this->services, 'checksums' => $this->checksums],
            JSON_UNESCAPED_UNICODE,
        ));
    }

    public function forget(): void
    {
        @unlink($this->path());
        $this->rows = $this->pages = $this->services = $this->checksums = [];
    }

    public function isEmpty(): bool
    {
        return array_filter($this->rows) === [] && $this->pages === [] && $this->services === [];
    }

    /** @return list<string> */
    public function keys(string $table): array
    {
        return $this->rows[$table] ?? [];
    }

    /** Adds rows by hand (the tests use it to fake an earlier run). */
    public function add(string $table, array $keys): void
    {
        $this->rows[$table] = array_values(array_unique([...$this->keys($table), ...array_map('strval', $keys)]));
    }

    /** @return array<string, list<string>> the keys of every table now, to compare with after a run */
    public static function snapshot(): array
    {
        $keys = [];
        foreach (self::TABLES as $table => $key) {
            $keys[$table] = DB::table($table)->selectRaw("({$key})::text AS k")->pluck('k')->all();
        }

        return $keys;
    }

    /** Records every row that appeared since the snapshot, and the checksum of every new picture. */
    public function recordSince(array $before): void
    {
        foreach (self::snapshot() as $table => $keys) {
            $this->add($table, array_values(array_diff($keys, $before[$table] ?? [])));
        }
        foreach (array_chunk($this->keys('media_assets'), 1000) as $chunk) {
            $this->checksums += DB::table('media_assets')->whereIn('id', $chunk)->pluck('checksum_sha256', 'id')->map(fn ($c) => trim($c))->all();
        }
    }

    public function rememberPage(int $id, array $before, string $demoBody): void
    {
        $this->pages[$id] ??= $before + ['demo_hash' => md5($demoBody)];
    }

    /** @return array<int, array<string, mixed>> */
    public function pages(): array
    {
        return $this->pages;
    }

    public function rememberService(int $id, array $before, string $demoHash): void
    {
        $this->services[$id] ??= $before + ['demo_hash' => $demoHash];
    }

    /** @return array<int, array<string, mixed>> */
    public function services(): array
    {
        return $this->services;
    }

    /** @return array<int, string> */
    public function checksums(): array
    {
        return $this->checksums;
    }
}
