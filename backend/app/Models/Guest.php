<?php

namespace App\Models;

use App\Audit\Audited;
use App\Content\KeepsOldAddresses;
use App\Models\Concerns\HasSeoMeta;
use App\Seo\SitePaths;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A guest of a podcast episode or a round-table session (decisions 6.7 – 6.11); added once, linked to any number of items. */
class Guest extends Model
{
    use Audited, HasSeoMeta, KeepsOldAddresses, SoftDeletes;

    protected $fillable = ['name', 'slug', 'job_title', 'organization_id', 'bio', 'photo_media_id', 'seo_meta_id'];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'photo_media_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(GuestLink::class);
    }

    /** Its public page (23.3); a changed slug leaves a 301 behind, a deletion a 410 (23.6). */
    public static function addressFor(string $slug): string
    {
        return SitePaths::guest($slug);
    }
}
