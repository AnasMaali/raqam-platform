<?php

namespace App\Models;

use App\Audit\Audited;
use App\Models\Concerns\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An awareness character (decision 7): a name, a role, a colour and up to five poses, each with a picture and maybe an animation state. */
class Character extends Model
{
    use Audited, HasSeoMeta;

    protected $fillable = ['name_ar', 'slug', 'role_ar', 'description', 'accent_color', 'rive_media_id', 'seo_meta_id', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function rive(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'rive_media_id');
    }
}
