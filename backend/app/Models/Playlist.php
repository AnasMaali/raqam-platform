<?php

namespace App\Models;

use App\Audit\Audited;
use App\Models\Concerns\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An ordered list of videos (decision 8.9); a podcast season is one (5.1). */
class Playlist extends Model
{
    use Audited, HasSeoMeta;

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
}
