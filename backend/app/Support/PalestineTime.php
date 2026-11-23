<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A time an editor typed, in Palestine time, as the moment it names.
 *
 * Palestine moves its clocks twice a year. The night they go forward, the hour that is skipped does not exist: a time
 * typed inside it names no moment, and is refused rather than moved. The night they go back, one hour is lived twice: a
 * time typed inside it is the second of the two, on winter time (as PHP reads it, and as the panel's date fields do).
 */
final class PalestineTime
{
    public const ZONE = 'Asia/Hebron';

    /** The moment, in UTC; null when the text is not a time, or names a time the clocks skip. */
    public static function fromTyped(string $typed): ?Carbon
    {
        $typed = trim($typed);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $at = Carbon::createFromFormat('!'.$format, $typed, self::ZONE);
            } catch (\Throwable) {
                continue;
            }

            // A skipped time comes back moved an hour on: it reads differently from what was typed.
            return $at !== null && $at->format($format) === $typed ? $at->utc() : null;
        }

        return null;
    }
}
