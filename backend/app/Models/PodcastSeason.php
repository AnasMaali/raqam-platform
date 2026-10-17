<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** database/schema/06-content.sql */
class PodcastSeason extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];
}
