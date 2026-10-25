<?php

namespace App\Content;

/**
 * For a record with a public page addressed by its slug (category, tag, author, guest, character, service, page, playlist;
 * decision 23.6, the "non-post" part of it): a changed slug leaves a 301 from the old address, a deleted record a 410,
 * and a record that comes back (restored, or made again with the same address) clears its 410. No chains: RedirectManager.
 */
trait KeepsOldAddresses
{
    /** The page of a record with this slug (App\Seo\SitePaths). */
    abstract public static function addressFor(string $slug): string;

    public static function bootKeepsOldAddresses(): void
    {
        static::updated(function (self $record) {
            if ($record->wasChanged('slug')) {
                app(RedirectManager::class)->moved(static::addressFor((string) $record->getOriginal('slug')), static::addressFor($record->slug), auth()->id());
            }
        });
        static::created(fn (self $record) => app(RedirectManager::class)->pathsRevived([static::addressFor($record->slug)]));
        static::deleted(fn (self $record) => app(RedirectManager::class)->pathsGone([static::addressFor($record->slug)], auth()->id()));
        if (method_exists(static::class, 'restored')) {
            static::restored(fn (self $record) => app(RedirectManager::class)->pathsRevived([static::addressFor($record->slug)]));
        }
    }
}
