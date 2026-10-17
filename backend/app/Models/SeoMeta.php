<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** Search and share overrides for one page-like record (title, description, canonical, noindex, share image). */
class SeoMeta extends Model
{
    use Audited;

    protected $table = 'seo_meta';

    public const CREATED_AT = null;

    protected $fillable = ['meta_title', 'meta_description', 'canonical_url', 'robots_noindex', 'share_media_id'];

    protected function casts(): array
    {
        return ['robots_noindex' => 'boolean'];
    }
}
