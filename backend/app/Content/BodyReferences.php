<?php

namespace App\Content;

use App\Content\Blocks\BlockRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Tells the database what a saved body uses (06-content.sql: post_body_media, post_body_videos, post_related),
 * so a picture in use cannot be deleted, a video that goes private can be traced to every article, and the
 * internal links are known. Rewritten each time the body is saved.
 */
final class BodyReferences
{
    public function __construct(private BlockRegistry $blocks) {}

    /** @return array{media: list<int>, videos: list<int>, posts: list<int>} what the (already sanitised) body points at */
    public function in(string $body): array
    {
        $found = ['media' => [], 'videos' => [], 'posts' => []];

        if (! str_contains($body, 'customBlock')) {
            return $found;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$body.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->getElementsByTagName('div') as $div) {
            $block = $this->blocks->find($div->getAttribute('data-id'));
            $config = json_decode($div->getAttribute('data-config'), true);
            if ($block === null || ! is_array($config)) {
                continue;
            }

            foreach ($block::references($config) as $kind => $ids) {
                $found[$kind] = array_merge($found[$kind], $ids);
            }
        }

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $found);
    }

    /** Writes the references of a post's body; "read also" links keep the order they have in the text. */
    public function sync(int $postId, string $body): void
    {
        $refs = $this->in($body);

        DB::table('post_body_media')->where('post_id', $postId)->delete();
        DB::table('post_body_videos')->where('post_id', $postId)->delete();
        DB::table('post_related')->where('post_id', $postId)->delete();

        if ($refs['media'] !== []) {
            DB::table('post_body_media')->insert(array_map(fn ($id) => ['post_id' => $postId, 'media_id' => $id], $refs['media']));
        }
        if ($refs['videos'] !== []) {
            DB::table('post_body_videos')->insert(array_map(fn ($id) => ['post_id' => $postId, 'video_id' => $id], $refs['videos']));
        }

        $related = array_values(array_filter($refs['posts'], fn ($id) => $id !== $postId));   // a post does not point at itself
        foreach ($related as $position => $relatedId) {
            DB::table('post_related')->insert(['post_id' => $postId, 'related_post_id' => $relatedId, 'sort_order' => min($position, 32767)]);
        }
    }
}
