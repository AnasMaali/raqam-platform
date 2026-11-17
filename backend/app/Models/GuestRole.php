<?php

namespace App\Models;

use App\Support\RequestMemo;
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

    /**
     * The same, read once per request (QA-5): each guest of an item being edited asks it. Kept on the request.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        if (! RequestMemo::has('guest_roles.names')) {
            RequestMemo::put('guest_roles.names', self::options());
        }

        return RequestMemo::get('guest_roles.names');
    }
}
