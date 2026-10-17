<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** database/schema/02-media.sql */
class MediaAsset extends Model
{
    use SoftDeletes;

    protected $guarded = ['*'];
}
