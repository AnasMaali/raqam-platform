<?php

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Model;

/** A period of Nablus economic history (decision 5.4): an admin-managed list instead of free text. */
class Era extends Model
{
    use Audited;

    public $timestamps = false;

    protected $fillable = ['name_ar', 'year_from', 'year_to', 'sort_order'];
}
