<?php

namespace App\Auth;

use App\Rules\NotLeaked;

/** The one set of rules for an admin password (decision 2.9): 10 to 128 characters, not in a public leak. */
final class AdminPassword
{
    public const MIN = 10;

    public const MAX = 128;

    /** @return list<mixed> */
    public static function rules(): array
    {
        return ['required', 'string', 'min:'.self::MIN, 'max:'.self::MAX, new NotLeaked];
    }

    /** @return array<string, string> */
    public static function messages(string $field = 'password'): array
    {
        return [
            "{$field}.required" => 'كلمة المرور مطلوبة.',
            "{$field}.min" => 'كلمة المرور يجب ألا تقل عن '.self::MIN.' أحرف.',
            "{$field}.max" => 'كلمة المرور يجب ألا تزيد على '.self::MAX.' حرفاً.',
        ];
    }
}
