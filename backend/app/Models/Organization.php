<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** A company or body a guest or a success story belongs to. */
class Organization extends Model
{
    use Audited;

    protected $fillable = ['name', 'slug', 'website', 'sector_id', 'city_id', 'founded_year', 'logo_media_id'];
}
