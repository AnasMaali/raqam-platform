<?php

namespace App\Seo;

use App\Api\Presenter;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the website's RSS feeds are made of (decision 23.12): the whole site, one category (with its sub-categories) or
 * one section, the latest live items first. The site writes the XML; an item carries the same summary as every list.
 */
final class FeedBoard
{
    public const ITEMS = 30;

    public function __construct(private Presenter $presenter) {}

    /** @return array<string, mixed> */
    public function latest(): array
    {
        return $this->feed(null, '/', null, Post::query()->live());
    }

    /** @return array<string, mixed>|null null when there is no such visible category */
    public function category(string $slug): ?array
    {
        $c = DB::table('categories')->where('slug', $slug)->where('is_active', true)->first();
        if ($c === null) {
            return null;
        }
        $ids = DB::table('categories')->where('is_active', true)->where(fn ($q) => $q->where('id', $c->id)->orWhere('parent_id', $c->id))->pluck('id');

        return $this->feed($c->name_ar, SitePaths::category($c->slug), $c->description, Post::query()->live()->whereIn('category_id', $ids));
    }

    /** @return array<string, mixed>|null null when there is no such visible section */
    public function section(string $slug): ?array
    {
        $s = DB::table('sections')->where('slug', $slug)->where('is_visible', true)->first();
        if ($s === null) {
            return null;
        }

        return $this->feed($s->name_ar, SitePaths::section($s->slug), $s->description,
            Post::query()->live()->whereIn('kind_code', DB::table('post_kinds')->where('section_id', $s->id)->select('code')));
    }

    /** @return array<string, mixed> */
    private function feed(?string $title, string $path, ?string $description, Builder $posts): array
    {
        $items = $posts->orderByDesc('published_at')->orderByDesc('id')->limit(self::ITEMS)->get();

        return [
            'title' => $title, 'path' => $path, 'description' => $description,
            'updated_at' => $this->presenter->iso($items->max('updated_at')),
            'items' => $this->presenter->summaries($items),
        ];
    }
}
