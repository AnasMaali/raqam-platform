<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A slug that is free in its table: the wanted one, or the wanted one with -2, -3 ... after it.
 * Slugs are made from names by ArabicSlug; two different names can still give the same slug.
 */
final class UniqueSlug
{
    public static function for(string $table, ?string $wanted, ?int $ignoreId = null, string $fallback = 'item'): string
    {
        $base = ArabicSlug::make($wanted);
        $base = $base === '' ? $fallback : $base;

        $taken = fn (string $slug) => DB::table($table)->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '<>', $ignoreId))->exists();

        $slug = $base;
        for ($n = 2; $taken($slug); $n++) {
            $suffix = '-'.$n;
            $slug = rtrim(mb_substr($base, 0, 200 - mb_strlen($suffix)), '-').$suffix;
        }

        return $slug;
    }
}
