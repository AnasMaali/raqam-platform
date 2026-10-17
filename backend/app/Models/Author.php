<?php

namespace App\Models;

use App\Audit\Audited;
use App\Models\Concerns\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A byline (decision 6): not a panel user. A post with no author is shown as «فريق رَقَم», so that
 * default needs no row.
 */
class Author extends Model
{
    use Audited, HasSeoMeta, SoftDeletes;

    protected $fillable = ['name', 'slug', 'job_title', 'bio', 'photo_media_id', 'is_active', 'team_sort_order', 'seo_meta_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'team_sort_order' => 'integer'];
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'photo_media_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(AuthorLink::class);
    }
}
