<?php

namespace App\Accounts;

use App\Api\Presenter;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a signed-in customer keeps (17.8 – 17.10): saved items to read later, followed categories and sections (ready for
 * notifications later), and their own bookings. Only what is public can be saved or followed, and only what is still
 * public is listed.
 */
final class CustomerLibrary
{
    public const FOLLOWS = ['category' => ['followed_categories', 'category_id', 'categories', 'is_active'], 'section' => ['followed_sections', 'section_id', 'sections', 'is_visible']];

    public function __construct(private Presenter $presenter) {}

    /** @return list<array<string, mixed>> */
    public function saved(User $user): array
    {
        $posts = Post::query()->live()->join('saved_posts as s', 's.post_id', '=', 'posts.id')->where('s.user_id', $user->id)
            ->orderByDesc('s.saved_at')->limit(500)->select('posts.*')->get();

        return $this->presenter->summaries($posts);
    }

    /** @throws AccountRefused */
    public function save(User $user, int $postId): void
    {
        if (! Post::query()->live()->whereKey($postId)->exists()) {
            throw new AccountRefused('not_found', 'هذه المادة غير موجودة.', 404);
        }
        DB::table('saved_posts')->insertOrIgnore(['user_id' => $user->id, 'post_id' => $postId]);
    }

    public function unsave(User $user, int $postId): void
    {
        DB::table('saved_posts')->where('user_id', $user->id)->where('post_id', $postId)->delete();
    }

    /** @return array{categories: list<array{id: int, name: string, slug: string}>, sections: list<array{id: int, name: string, slug: string}>} */
    public function follows(User $user): array
    {
        $list = fn (string $kind) => DB::table(self::FOLLOWS[$kind][0].' as f')->join(self::FOLLOWS[$kind][2].' as t', 't.id', '=', 'f.'.self::FOLLOWS[$kind][1])
            ->where('f.user_id', $user->id)->where('t.'.self::FOLLOWS[$kind][3], true)->orderBy('t.name_ar')->get(['t.id', 't.name_ar', 't.slug'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name_ar, 'slug' => $t->slug])->all();

        return ['categories' => $list('category'), 'sections' => $list('section')];
    }

    /** @throws AccountRefused */
    public function follow(User $user, string $kind, int $id): void
    {
        [$table, $column, $target, $public] = self::FOLLOWS[$kind] ?? throw new AccountRefused('not_found', 'غير موجود.', 404);
        if (! DB::table($target)->where('id', $id)->where($public, true)->exists()) {
            throw new AccountRefused('not_found', 'غير موجود.', 404);
        }
        DB::table($table)->insertOrIgnore(['user_id' => $user->id, $column => $id]);
    }

    public function unfollow(User $user, string $kind, int $id): void
    {
        if (isset(self::FOLLOWS[$kind])) {
            DB::table(self::FOLLOWS[$kind][0])->where('user_id', $user->id)->where(self::FOLLOWS[$kind][1], $id)->delete();
        }
    }

    /** «حجوزاتي» (17.8): bookings made while signed in to this account; older ones are never linked (2.6). */
    public function bookings(User $user): array
    {
        return DB::table('bookings as b')->join('services as s', 's.id', '=', 'b.service_id')->join('booking_statuses as st', 'st.code', '=', 'b.status_code')
            ->where('b.user_id', $user->id)->whereNull('b.deleted_at')->orderByDesc('b.created_at')->limit(200)
            ->get(['b.reference', 's.name_ar', 'st.code', 'st.name_ar as status', 'b.created_at', 'b.scheduled_for'])
            ->map(fn ($b) => ['reference' => $b->reference, 'service' => $b->name_ar, 'status' => ['code' => $b->code, 'name' => $b->status],
                'created_at' => CarbonImmutable::parse($b->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
                'scheduled_for' => $b->scheduled_for ? CarbonImmutable::parse($b->scheduled_for)->utc()->format('Y-m-d\TH:i:s\Z') : null])->all();
    }
}
