<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * The hidden field every public form carries (24.10): the website draws it out of sight (not display:none alone, which
 * some robots skip), a person never fills it, a form-filling robot usually does. A caught request gets an answer of the
 * same shape as a real one, with nothing behind it: nothing is stored, sent or counted, and the robot is not told why.
 */
final class Honeypot
{
    public const FIELD = 'website';

    /** @param  array<mixed>  $input */
    public static function caught(array $input): bool
    {
        return filled($input[self::FIELD] ?? null);
    }

    /**
     * A challenge that looks exactly like a real one (the same encryption, the same length range) but leads nowhere: the
     * code form it opens refuses every code, as it would a wrong one.
     */
    public static function decoyChallenge(): string
    {
        return Crypt::encryptString(json_encode(['decoy' => bin2hex(random_bytes(24)), 'exp' => now()->addMinutes(30)->getTimestamp()]));
    }
}
