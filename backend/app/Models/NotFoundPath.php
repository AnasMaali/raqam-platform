<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A page the site could not find, as its visitors reached it (22.10): the path without its query, how many visitors,
 * first and last seen. Written by the statistics counter only; the admin redirects it or marks it handled.
 */
class NotFoundPath extends Model
{
    protected $primaryKey = 'path';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['hits' => 'integer', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
