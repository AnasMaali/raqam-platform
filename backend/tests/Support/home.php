<?php

/** Helpers for the home page tests: one block as the screen sends it. */
function homeBlock(string $type, array $extra = []): array
{
    return ['id' => null, 'block_type' => $type, 'title_ar' => null, 'category_id' => null, 'section_id' => null, 'playlist_id' => null,
        'placement_code' => null, 'item_limit' => null, 'is_active' => true, ...$extra];
}
