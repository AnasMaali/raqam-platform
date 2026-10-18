<?php

namespace App\Taxonomy;

use App\Models\Tag;
use App\Support\UniqueSlug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tags typed straight into the post form (decision 4.4). A name that is the same word as an existing tag
 * once hamza, ta marbuta and diacritics are ignored (the database's own normalisation) finds that tag
 * instead of making a twin.
 */
final class TagService
{
    public const NAME_MAX = 80;

    /**
     * @param  iterable<string>  $names
     * @return Collection<int, Tag> in the order given, each tag once
     */
    public function fromNames(iterable $names): Collection
    {
        $tags = collect();

        foreach ($names as $name) {
            $name = $this->clean((string) $name);
            if ($name === null) {
                continue;
            }

            $tag = $this->find($name) ?? $this->create($name);
            if (! $tags->contains('id', $tag->id)) {
                $tags->push($tag);
            }
        }

        return $tags;
    }

    private function clean(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', ltrim(trim($name), '#')));

        return $name === '' ? null : mb_substr($name, 0, self::NAME_MAX);
    }

    private function find(string $name): ?Tag
    {
        return Tag::query()->whereRaw('search_name = normalize_ar(?)', [$name])->first();
    }

    private function create(string $name): Tag
    {
        try {
            return DB::transaction(fn () => Tag::query()->create([
                'name_ar' => $name,
                'slug' => UniqueSlug::for('tags', $name, fallback: 'tag'),
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Somebody saved the same tag a moment ago; theirs is the one to use.
            return $this->find($name) ?? throw $e;
        }
    }
}
