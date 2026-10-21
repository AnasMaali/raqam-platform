<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** «تغطيات إعلامية» / «إعلانات»: the groups of the prices page (14.6). */
class ServiceGroup extends Model
{
    public $timestamps = false;

    protected $fillable = ['name_ar', 'sort_order'];
}
