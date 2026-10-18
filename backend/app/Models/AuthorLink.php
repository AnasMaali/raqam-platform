<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A social link of an author (one row per platform). Saved with the author, whose audit row covers it. */
class AuthorLink extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['author_id', 'platform_code', 'url'];
}
