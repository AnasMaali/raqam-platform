<?php

namespace App\Models\Concerns;

use App\Models\SeoMeta;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** For records with an optional row of search and share overrides (the seo_meta_id column). */
trait HasSeoMeta
{
    public function seoMeta(): BelongsTo
    {
        return $this->belongsTo(SeoMeta::class, 'seo_meta_id');
    }
}
