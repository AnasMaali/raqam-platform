<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Saves a post and its subtype row in one transaction.
 *
 * The "subtype row before leaving draft" rule is a deferred constraint trigger, which PostgreSQL
 * normally checks at COMMIT. SET CONSTRAINTS ... IMMEDIATE as the last statement runs the check
 * inside this method, so a refusal arrives here and can be shown in Arabic (and tests exercise
 * the real trigger even when the test itself never commits).
 */
final class PostSaver
{
    /** kind => [relation, columns that must all be present for the subtype row to exist] */
    private const SUBTYPES = [
        'episode' => ['episode', ['season_id', 'episode_number']],
        'story' => ['story', ['organization_id']],
    ];

    /** @param  array<string, mixed>  $data */
    public function create(array $data, int $userId): Post
    {
        $kind = $data['kind_code'];
        unset($data['kind_code']);

        return $this->persist(new Post, $data, $kind, fn (Post $post) => $post->forceFill([
            'kind_code' => $kind, 'created_by' => $userId, 'updated_by' => $userId,
        ]));
    }

    /** @param  array<string, mixed>  $data */
    public function update(Post $post, array $data, int $userId): Post
    {
        unset($data['kind_code']);

        return $this->persist($post, $data, $post->kind_code, fn (Post $post) => $post->forceFill(['updated_by' => $userId]));
    }

    private function persist(Post $post, array $data, string $kind, callable $stamp): Post
    {
        [$relation] = self::SUBTYPES[$kind] ?? [null];
        $subtype = $relation ? (array) ($data[$relation] ?? []) : [];
        unset($data['episode'], $data['story']);

        try {
            DB::transaction(function () use ($post, $data, $kind, $stamp, $subtype) {
                // The mode lasts until the outermost transaction ends, so state it on both sides:
                // inside an outer transaction (a test, an import) an earlier save must not leave it IMMEDIATE.
                DB::statement('SET CONSTRAINTS posts_subtype_present DEFERRED');
                $stamp($post->fill($data))->save();
                $this->saveSubtype($post, $kind, $subtype);
                DB::statement('SET CONSTRAINTS posts_subtype_present IMMEDIATE');
                DB::statement('SET CONSTRAINTS posts_subtype_present DEFERRED');
            });
        } catch (QueryException $e) {
            throw $this->translate($e) ?? $e;
        }

        return $post;
    }

    private function saveSubtype(Post $post, string $kind, array $values): void
    {
        if (! isset(self::SUBTYPES[$kind])) {
            return;
        }

        [$relation, $required] = self::SUBTYPES[$kind];
        $present = array_filter($values, fn ($value) => $value !== null && $value !== '');

        // A draft may leave the section data empty; the trigger refuses publishing without it.
        if ($present === []) {
            return;
        }

        if (array_diff($required, array_keys($present)) !== []) {
            throw new PostRuleViolation('بيانات القسم ناقصة، أكملها أو اتركها فارغة وهذا المنشور مسودة.');
        }

        // Not updateOrCreate(): its create-or-first fallback swallows a unique violation and would
        // then overwrite another post's row instead of reporting the clash.
        $existing = $post->{$relation};
        $existing ? $existing->update($values) : $post->{$relation}()->create($values);
    }

    private function translate(QueryException $e): ?PostRuleViolation
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'needs its') => new PostRuleViolation('لا يمكن نشر هذا المنشور قبل تعبئة بيانات قسمه (الحلقة أو القصة).'),
            str_contains($message, 'cannot change after creation') => new PostRuleViolation('نوع المنشور لا يتغير بعد إنشائه.', 'kind_code'),
            str_contains($message, 'needs a category') => new PostRuleViolation('هذا النوع يحتاج تصنيفًا قبل النشر.', 'category_id'),
            $e->getCode() === '23505' && str_contains($message, 'podcast_episodes') => new PostRuleViolation('رقم الحلقة مستخدم في هذا الموسم.', 'episode.episode_number'),
            default => null,
        };
    }
}
