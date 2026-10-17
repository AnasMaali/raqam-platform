<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The 11 kinds, database/schema/06-content.sql. The code is the primary key. */
class PostKind extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = ['*'];
}
