<?php

namespace App\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

/**
 * Where a file is used (decision 9.1). Instead of a hand-kept list the foreign keys that point at
 * media_assets are read from the database, so a table added later is covered without anyone remembering.
 * The one reference without a key is a "media" setting (the logo, the default share image).
 */
final class MediaUsage
{
    /** What the library says for the columns people meet; anything else shows its table and column. */
    private const LABELS = [
        'posts.cover_media_id' => 'الصورة الرئيسية لمادة',
        'post_gallery_items.media_id' => 'معرض صور مادة',
        'post_body_media.media_id' => 'صورة داخل نص مادة',
        'infographics.download_media_id' => 'ملف تحميل إنفوغراف',
        'sections.cover_media_id' => 'صورة قسم',
        'authors.photo_media_id' => 'صورة كاتب',
        'guests.photo_media_id' => 'صورة ضيف',
        'organizations.logo_media_id' => 'شعار جهة',
        'seo_meta.share_media_id' => 'صورة مشاركة',
        'videos.poster_media_id' => 'غلاف فيديو',
        'videos.upload_media_id' => 'ملف فيديو',
        'playlists.cover_media_id' => 'غلاف قائمة تشغيل',
        'characters.rive_media_id' => 'ملف شخصية',
        'character_poses.static_media_id' => 'صورة وضعية شخصية',
        'ad_creatives.media_id' => 'إعلان',
        'house_ads.desktop_media_id' => 'إعلان داخلي (كمبيوتر)',
        'house_ads.mobile_media_id' => 'إعلان داخلي (جوال)',
        'services.cover_media_id' => 'صورة خدمة',
        'booking_attachments.media_id' => 'مرفق حجز',
        'invoices.pdf_media_id' => 'فاتورة',
        'settings.value' => 'إعدادات الموقع',
    ];

    /** @var list<array{table: string, column: string}>|null */
    private static ?array $references = null;

    /** @return list<array{label: string, id: string}> */
    public function of(MediaAsset|int $asset): array
    {
        $id = $asset instanceof MediaAsset ? $asset->id : $asset;
        $usages = [];

        foreach ($this->references() as ['table' => $table, 'column' => $column]) {
            $key = "{$table}.{$column}";
            $rows = DB::table($table)->where($column, $id)->limit(20)->get();

            foreach ($rows as $row) {
                $usages[] = ['label' => self::LABELS[$key] ?? $key, 'id' => $this->identify($table, (array) $row)];
            }
        }

        foreach (DB::table('settings')->where('value_type', 'media')->where('value', (string) $id)->pluck('key') as $key) {
            $usages[] = ['label' => self::LABELS['settings.value'], 'id' => $key];
        }

        return $usages;
    }

    /** @return list<array{table: string, column: string}> */
    private function references(): array
    {
        return self::$references ??= array_map(fn ($r) => ['table' => $r->table_name, 'column' => $r->column_name], DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS table_name, a.attname AS column_name
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND c.confrelid = 'media_assets'::regclass AND c.conrelid <> 'media_variants'::regclass
            ORDER BY 1, 2
            SQL));
    }

    /** The identifying columns of a referencing row, e.g. "id=5" or "post_id=5, media_id=3". */
    private function identify(string $table, array $row): string
    {
        $keys = DB::select(<<<'SQL'
            SELECT a.attname FROM pg_index i
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
            WHERE i.indrelid = ?::regclass AND i.indisprimary
            SQL, [$table]);

        return collect($keys)->map(fn ($k) => $k->attname.'='.($row[$k->attname] ?? '?'))->implode(', ');
    }
}
