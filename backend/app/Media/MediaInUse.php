<?php

namespace App\Media;

/** A file that is still used somewhere cannot go to the trash or be deleted. */
final class MediaInUse extends \RuntimeException
{
    /** @param  list<array{label: string, id: string}>  $usages */
    public function __construct(public readonly array $usages)
    {
        parent::__construct('لا يمكن حذف هذه الصورة لأنها مستخدمة في: '.collect($usages)->take(5)->map(fn ($u) => "{$u['label']} ({$u['id']})")->implode('، ').(count($usages) > 5 ? '، ...' : '').'.');
    }
}
