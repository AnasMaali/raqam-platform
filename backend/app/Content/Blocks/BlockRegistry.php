<?php

namespace App\Content\Blocks;

/** The custom blocks an article body may contain (decision 21.6), by their ids. */
final class BlockRegistry
{
    /** @var list<class-string<ContentBlock>> */
    private const BLOCKS = [ImageBlock::class, GalleryBlock::class, AwarenessCardBlock::class, ReadAlsoBlock::class, VideoBlock::class];

    /** @return class-string<ContentBlock>|null */
    public function find(string $id): ?string
    {
        foreach (self::BLOCKS as $block) {
            if ($block::getId() === $id) {
                return $block;
            }
        }

        return null;
    }

    /** @return list<class-string<ContentBlock>> the blocks the editor offers (video joins them with the video phase) */
    public function forEditor(): array
    {
        return array_values(array_filter(self::BLOCKS, fn (string $block) => $block::availableInEditor()));
    }
}
