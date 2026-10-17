<?php

namespace App\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

/** The HTML for a picture of the library: AVIF, WebP and JPEG sources by width, with its dimensions so the page does not jump (9.3). */
final class MediaHtml
{
    private const MIME = ['avif' => 'image/avif', 'webp' => 'image/webp', 'jpeg' => 'image/jpeg'];

    public function __construct(private MediaStorage $storage) {}

    public function picture(MediaAsset $asset, string $sizes = '(min-width: 768px) 720px, 100vw', string $loading = 'lazy'): string
    {
        $alt = e($asset->alt_text ?? '');
        $focal = $asset->focal_x !== null ? sprintf(' style="object-position:%s%% %s%%"', round($asset->focal_x * 100, 1), round($asset->focal_y * 100, 1)) : '';

        if ($asset->mime_type === 'image/svg+xml') {
            return '<img src="'.e($this->storage->url($asset)).'" alt="'.$alt.'" loading="'.$loading.'">';
        }

        $variants = DB::table('media_variants')->where('media_id', $asset->id)->whereNotIn('variant', ['thumb', 'og'])->orderBy('width')->get()->groupBy('format');

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
