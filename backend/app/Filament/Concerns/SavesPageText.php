<?php

namespace App\Filament\Concerns;

use App\Content\BodySanitizer;
use App\Support\UniqueSlug;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

/** Create and edit pages of a static page: the text is cleaned (no custom blocks here), the address made unique, the editor stamped. */
trait SavesPageText
{
    protected function preparePage(array $data, ?int $ignoreId = null): array
    {
        try {
            $data['body'] = app(BodySanitizer::class)->clean((string) ($data['body'] ?? ''), allowBlocks: false);
        } catch (\LengthException) {
            Notification::make()->danger()->title('النص طويل جداً.')->send();
            $this->halt();
        }

        if (array_key_exists('slug', $data)) {
            $data['slug'] = UniqueSlug::for('pages', $data['slug'] ?: ($data['title'] ?? ''), $ignoreId, 'page');
        }

        $data['updated_by'] = Filament::auth()->id();

        return $data;
    }
}
