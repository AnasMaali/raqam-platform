<?php

namespace App\Models;

use App\Analytics\MissingPages;
use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/**
 * An old address that leads somewhere else (301, 302) or nowhere (410, gone). Many are made by the application when a
 * slug changes or a post is deleted for good; the admin adds the rest (decision 23.6). `hits` is counted by the API.
 */
class Redirect extends Model
{
    use Audited;

    public const UPDATED_AT = null;

    protected $fillable = ['from_path', 'to_path', 'status_code', 'created_by'];

    /** An address that now leads somewhere (or is declared gone) is no longer a missing page to handle (22.10). */
    protected static function booted(): void
    {
        static::saved(fn (Redirect $r) => MissingPages::resolve($r->from_path));
    }

    protected function casts(): array
    {
        return ['status_code' => 'integer', 'hits' => 'integer', 'last_hit_at' => 'datetime'];
    }
}
