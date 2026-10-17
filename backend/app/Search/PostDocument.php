<?php

namespace App\Search;

use App\Models\Post;
use App\Support\ArabicNormalizer;
use Closure;

/**
 * The Meilisearch document of a post. Every text field exists twice: the original (shown, never
 * searched) and a `_n` copy that went through ArabicNormalizer (searched, never shown).
 * The body is only searched, so only its normalised copy is stored.
 */
final class PostDocument
{
    /** @return array<string, mixed> */
    public static function from(Post $post, ?Closure $searchForm = null): array
    {
        $searchForm ??= fn (string $normalised) => $normalised;
        $search = fn (?string $text) => $searchForm(ArabicNormalizer::normalize((string) $text));

        return [
            'id' => $post->id,
            'kind_code' => $post->kind_code,
            'category_id' => $post->category_id,
            'category' => $post->category?->name_ar,
            'published_at' => $post->published_at?->getTimestamp(),
            'title' => $post->title,
            'subtitle' => $post->subtitle,
            'excerpt' => $post->excerpt,
            'title_n' => $search($post->title),
            'subtitle_n' => $search($post->subtitle),
            'excerpt_n' => $search($post->excerpt),
            'body_n' => $search(PlainText::fromHtml((string) $post->body)),
        ];
    }

    /**
     * Meilisearch detects the language of every text and tokenises accordingly. On short Arabic
     * strings the detection is unreliable, and the same word is then tokenised differently in
     * the document and in the query, so it is not found. Pinning the locale removes the guess.
     */
    public const LOCALES = ['ara'];

    /** @return array<string, mixed> */
    public static function settings(array $synonyms = [], bool $pinLocale = true): array
    {
        return [
            'searchableAttributes' => ['title_n', 'subtitle_n', 'excerpt_n', 'body_n'],
            'displayedAttributes' => ['id', 'kind_code', 'category_id', 'category', 'published_at',
                'title', 'subtitle', 'excerpt', 'title_n', 'subtitle_n', 'excerpt_n'],
            'filterableAttributes' => ['kind_code', 'category_id'],
            'sortableAttributes' => ['published_at'],
            'synonyms' => (object) $synonyms,
            'localizedAttributes' => $pinLocale
                ? [['attributePatterns' => ['title_n', 'subtitle_n', 'excerpt_n', 'body_n'], 'locales' => self::LOCALES]]
                : [],
        ];
    }
}
