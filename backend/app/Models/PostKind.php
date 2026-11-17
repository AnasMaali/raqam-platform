<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The 11 kinds of content (decision 3). The code is the primary key; the rows come from the seed. */
class PostKind extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /** @return array<string, string> code => Arabic name, in display order */
    public static function options(): array
    {
        return self::query()->orderBy('sort_order')->pluck('name_ar', 'code')->all();
    }

    /**
     * The same, read once per request (QA-5): a list asks it for every row. Kept on the request, so it ends with it.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        $kept = request()->attributes;
        if (! $kept->has('post_kinds.names')) {
            $kept->set('post_kinds.names', self::options());
        }

        return $kept->get('post_kinds.names');
    }
}
