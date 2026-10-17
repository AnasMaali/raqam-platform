<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The part a guest plays in one item (decision 6.10): host, guest or panelist. The three codes are seeded. */
class GuestRole extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    /** @return array<string, string> code => Arabic name, for a select */
    public static function options(): array
    {
        return self::query()->orderBy('code')->pluck('name_ar', 'code')->all();
    }
}
