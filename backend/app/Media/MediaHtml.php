<?php

namespace App\Media;

use App\Models\MediaAsset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The HTML for a picture of the library: AVIF, WebP and JPEG sources by width, with its dimensions so the page does not jump (9.3). */
final class MediaHtml
{
    private const MIME = ['avif' => 'image/avif', 'webp' => 'image/webp', 'jpeg' => 'image/jpeg'];

    /** @var array<int, Collection<int, object>> the variants of pictures read together (preload), by picture */
    private array $variants = [];

    public function __construct(private MediaStorage $storage) {}

    /**
     * Reads the variants of several pictures in one query (a gallery), so picture() asks nothing more for them. Without
     * it, each picture costs a query of its own. This object is made anew for each use, so what it read never goes stale.
     *
     * @param  iterable<MediaAsset>  $assets
     */
    public function preload(iterable $assets): void
    {
        $ids = array_values(array_diff(array_map(fn (MediaAsset $asset) => $asset->id, [...$assets]), array_keys($this->variants)));
        if ($ids === []) {
            return;
        }
        $rows = $this->variantQuery()->whereIn('media_id', $ids)->get()->groupBy('media_id');
        foreach ($ids as $id) {
            $this->variants[$id] = $rows->get($id, new Collection);
        }
    }

    private function variantQuery(): Builder
    {
        return DB::table('media_variants')->whereNotIn('variant', ['thumb', 'og'])->orderBy('width');
    }

    public function picture(MediaAsset $asset, string $sizes = '(min-width: 768px) 720px, 100vw', string $loading = 'lazy'): string
    {
        $alt = e($asset->alt_text ?? '');
        $focal = $asset->focal_x !== null ? sprintf(' style="object-position:%s%% %s%%"', round($asset->focal_x * 100, 1), round($asset->focal_y * 100, 1)) : '';

        if ($asset->mime_type === 'image/svg+xml') {
            return '<img src="'.e($this->storage->url($asset)).'" alt="'.$alt.'" loading="'.$loading.'">';
        }

        $variants = ($this->variants[$asset->id] ?? $this->variantQuery()->where('media_id', $asset->id)->get())->groupBy('format');

        $sources = '';
        foreach (self::MIME as $format => $mime) {
            $set = $variants->get($format);
            if ($set && $set->isNotEmpty()) {
                $srcset = $set->map(fn ($v) => e($this->storage->url($asset, $v->path)).' '.$v->width.'w')->implode(', ');
                $sources .= '<source type="'.$mime.'" srcset="'.$srcset.'" sizes="'.e($sizes).'">';
            }
        }

        $dimensions = $asset->width ? ' width="'.$asset->width.'" height="'.$asset->height.'"' : '';

        return '<picture>'.$sources.'<img src="'.e($this->storage->url($asset)).'" alt="'.$alt.'"'.$dimensions.' loading="'.$loading.'" decoding="async"'.$focal.'></picture>';
    }
}
