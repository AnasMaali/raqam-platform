<?php

namespace App\Video;

/** Reads and writes the time of a chapter: "75", "1:15" or "1:02:03" (decision 8.8). */
final class ChapterTime
{
    public static function parse(mixed $input): ?int
    {
        $input = is_int($input) ? (string) $input : trim((string) $input);
        $input = strtr($input, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        if (! preg_match('/^(?:(\d{1,2}):)?(\d{1,3}):(\d{2})$|^(\d{1,6})$/', $input, $m)) {
            return null;
        }

        if (isset($m[4]) && $m[4] !== '') {
            return (int) $m[4];
        }
        if ((int) $m[3] > 59 || ($m[1] !== '' && (int) $m[2] > 59)) {
            return null;
        }

        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
    }

    public static function format(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
