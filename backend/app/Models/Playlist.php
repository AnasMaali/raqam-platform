<?php

namespace App\Models;

use App\Audit\Audited;
use App\Content\KeepsOldAddresses;
use App\Models\Concerns\HasSeoMeta;
use App\Seo\SitePaths;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An ordered list of videos (decision 8.9); a podcast season is one (5.1). */
class Playlist extends Model
{
    use Audited, HasSeoMeta, KeepsOldAddresses;

    protected $fillable = ['name_ar', 'slug', 'description', 'section_id', 'cover_media_id', 'seo_meta_id', 'sort_order', 'is_visible'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'sort_order' => 'integer'];
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'cover_media_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** Its public page (23.3); a changed slug leaves a 301 behind, a deletion a 410 (23.6). */
    public static function addressFor(string $slug): string
    {
        return SitePaths::playlist($slug);
    }
}
