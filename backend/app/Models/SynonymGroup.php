<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** A group of words that find each other (10.9). Its words live in synonym_terms; App\Search\SynonymLibrary saves them. */
class SynonymGroup extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return list<string> */
    public function terms(): array
    {
        return DB::table('synonym_terms')->where('group_id', $this->id)->orderBy('term')->pluck('term')->all();
    }
}
