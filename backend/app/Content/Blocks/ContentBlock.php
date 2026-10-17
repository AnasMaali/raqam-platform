<?php

namespace App\Content\Blocks;

use Filament\Forms\Components\RichEditor\RichContentCustomBlock;

/**
 * A block inside an article body (decision 21.6). Stored as a bare reference ({"media_id": 5}), never as
 * HTML, so what a visitor sees is built from the database at display time and a deleted picture cannot
 * linger in old text. The sanitiser asks every block to re-check its config before the body is saved.
 */
abstract class ContentBlock extends RichContentCustomBlock
{
    /**
     * The config as it will be kept, or null when it is not valid (the block is then dropped).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    abstract public static function normalize(array $config): ?array;

    /**
     * What this block points at, so the database knows every use.
     *
     * @param  array<string, mixed>  $config
     * @return array{media?: list<int>, videos?: list<int>, posts?: list<int>}
     */
    abstract public static function references(array $config): array;

    /** The HTML a visitor (and the preview) sees. */
    abstract public static function render(array $config): string;

    public static function availableInEditor(): bool
    {
        return true;
    }

    public static function toHtml(array $config, array $data): ?string
    {
        return static::render($config);
    }

    public static function toPreviewHtml(array $config): ?string
    {
        return static::render($config);
    }

    /** Positive integers only, and not absurdly many: a config comes from a request. */
    protected static function ids(mixed $value, int $max): ?array
    {
        if (! is_array($value) || $value === [] || count($value) > $max) {
            return null;
        }

        $ids = [];
        foreach ($value as $id) {
            if (! (is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id < 1 || (int) $id > PHP_INT_MAX / 2) {
                return null;
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    protected static function id(mixed $value): ?int
    {
        return static::ids([$value], 1)[0] ?? null;
    }

    protected static function text(mixed $value, int $max): ?string
    {
        $text = trim(strip_tags((string) $value));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
