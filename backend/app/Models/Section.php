<?php

namespace App\Models;

use App\Audit\Audited;
use App\Models\Concerns\HasSeoMeta;
use App\Navigation\MenuBoard;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the seven special sections (decision 5). They exist from the seed; the admin changes the name,
 * description, picture and order, hides or shows them, but cannot add, delete, re-key or re-route one
 * (the address of each section is fixed by decision 23.3).
 */
class Section extends Model
{
    use Audited, HasSeoMeta;

    public const CREATED_AT = null;

    protected $fillable = ['name_ar', 'description', 'cover_media_id', 'sort_order', 'is_visible', 'seo_meta_id'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        // A menu link to the section disappears while it is hidden (21.12, 21.16).
        static::saved(fn (self $s) => $s->wasChanged('is_visible') ? MenuBoard::targetChanged('section_id', $s->id) : null);
    }

    public function cover()
    {
        return $this->belongsTo(MediaAsset::class, 'cover_media_id');
    }
}
