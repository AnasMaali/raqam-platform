<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A social link of a guest (one row per platform). */
class GuestLink extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['guest_id', 'platform_code', 'url'];
}
