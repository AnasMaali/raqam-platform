<?php

namespace App\Panel;

use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Every date and time the panel shows: Palestine time, one shape (2026-11-21 21:18), digits 0-9 (1.3).
 *
 * Inside an Arabic line a date is a left-to-right run of numbers and separators; left to the page's direction its parts
 * come out in the wrong order («21:18 2026-11-21»). So it is always isolated as one left-to-right run: a `<bdi dir="ltr">`
 * where the panel draws HTML (lists, details, the bell), the Unicode isolates where only text goes (a select's options,
 * a sentence). Either way it is held together, so a narrow screen does not cut it at a hyphen or the space.
 */
final class PanelDate
{
    public const ZONE = 'Asia/Hebron';

    public const FORMAT = 'Y-m-d H:i';

    /** The bare text (for a file name, a sentence built elsewhere); null for no time. */
    public static function format(mixed $time): ?string
    {
        // format(), not translatedFormat(): the digits stay 0-9 whatever the application's locale.
        return blank($time) ? null : Carbon::parse($time)->setTimezone(self::ZONE)->format(self::FORMAT);
    }

    /** For HTML: one left-to-right run that does not wrap (theme.css `.raqam-date`). */
    public static function html(mixed $time): ?HtmlString
    {
        $text = self::format($time);

        return $text === null ? null : new HtmlString('<bdi dir="ltr" class="raqam-date">'.e($text).'</bdi>');
    }

    /** For plain text: the same run between the Unicode isolates, with a non-breaking hyphen and space. */
    public static function text(mixed $time): ?string
    {
        $text = self::format($time);

        return $text === null ? null : "\u{2066}".str_replace(['-', ' '], ["\u{2011}", "\u{00A0}"], $text)."\u{2069}";
    }

    /**
     * A calendar day with no time (a week's first day): the date only, the same way round. Not moved to Palestine time:
     * a day is not an instant, and moving its midnight would change it.
     */
    public static function day(mixed $date): ?HtmlString
    {
        return blank($date) ? null : new HtmlString('<bdi dir="ltr" class="raqam-date">'.e(Carbon::parse($date)->format('Y-m-d')).'</bdi>');
    }

    /** A column of calendar days (see day()). */
    public static function ofDays(TextColumn $column): TextColumn
    {
        return $column->formatStateUsing(fn (mixed $state) => self::day($state));
    }

    /**
     * A list's column or a details entry that shows a time, in place of Filament's dateTime() (which leaves the order
     * to the page's direction). Its placeholder, sorting and the rest stay the column's own.
     *
     * @template T of TextColumn|TextEntry
     *
     * @param  T  $component
     * @return T
     */
    public static function of(TextColumn|TextEntry $component): TextColumn|TextEntry
    {
        return $component->formatStateUsing(fn (mixed $state) => self::html($state));
    }
}
