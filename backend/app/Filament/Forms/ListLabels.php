<?php

namespace App\Filament\Forms;

use Closure;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The labels of the records a list of pickers shows (a repeater of videos, pictures or guests): read for all the items
 * in one query, not one per item (QA-5), and kept on the request, so they end with it.
 */
final class ListLabels
{
    /**
     * @param  string|null  $siblings  inside a repeater, the path from the field to the repeater's list ("../../videos")
     * @param  Closure(list<int>): array<int, string>  $read  the labels of these ids
     */
    public static function of(string $kind, mixed $value, Get $get, string $field, ?string $siblings, Closure $read): ?string
    {
        if (! MediaSelect::isId($value)) {
            return null;
        }
        $ids = $siblings === null ? [] : collect($get($siblings) ?? [])->pluck($field)->filter(fn ($id) => MediaSelect::isId($id))->all();

        return self::read($kind, [...$ids, $value], $read)[(int) $value] ?? null;
    }

    /**
     * @param  array<int|string>  $ids
     * @param  Closure(list<int>): array<int, string>  $read
     * @return array<int, string>
     */
    private static function read(string $kind, array $ids, Closure $read): array
    {
        $kept = request()->attributes;
        $known = $kept->get('list_labels.'.$kind, []);
        $missing = array_values(array_diff(array_unique(array_map('intval', $ids)), array_keys($known)));
        if ($missing !== []) {
            $known = array_replace($known, array_fill_keys($missing, null), $read($missing));
            $kept->set('list_labels.'.$kind, $known);
        }

        return array_filter($known, fn ($label) => $label !== null);
    }
}
