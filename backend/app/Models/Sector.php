<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** An economic sector (decisions 5.2, 5.3): the list a company's sector is chosen from. */
class Sector extends Model
{
    use Audited;

    public $timestamps = false;

    protected $fillable = ['name_ar', 'slug', 'sort_order'];
}
