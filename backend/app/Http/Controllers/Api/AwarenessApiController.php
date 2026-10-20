<?php

namespace App\Http\Controllers\Api;

use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The awareness section: its characters, each character's cards, and the section page (decisions 7.12, 7.13). Hidden characters do not exist here. */
class AwarenessApiController extends ApiController
{
    public function characters(): JsonResponse
    {
        $rows = DB::table('characters')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn ($c) => $this->presenter->character($c))->all()]);
    }

    public function character(string $slug): JsonResponse
    {
        $c = DB::table('characters')->where('is_active', true)->where('slug', $slug)->first();

        return $c ? response()->json(['data' => $this->presenter->character($c)]) : $this->notFound();
    }

    /** Every published card the character has said, newest first. */
    public function cards(Request $request, string $slug): JsonResponse
    {
        $c = DB::table('characters')->where('is_active', true)->where('slug', $slug)->first();
        if ($c === null) {
            return $this->notFound();
        }

        $p = $this->presenter->characterCards((int) $c->id)->orderByDesc('published_at')->orderByDesc('id')->paginate(self::PER_PAGE, page: $this->page($request));

        return $this->pageOf($p, $this->presenter->cards(collect($p->items())));
    }

    /** The awareness page: the section, the characters and the newest cards. */
    public function overview(): JsonResponse
    {
        $section = DB::table('sections')->where('key', 'awareness')->where('is_visible', true)->first();
        if ($section === null) {
            return $this->notFound();
        }

        $characters = DB::table('characters')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $latest = Post::query()->live()->where('kind_code', 'awareness')->whereIn('id', DB::table('awareness_cards')
            ->join('character_poses', 'character_poses.id', '=', 'awareness_cards.character_pose_id')->join('characters', 'characters.id', '=', 'character_poses.character_id')
            ->where('characters.is_active', true)->select('awareness_cards.post_id'))
            ->orderByDesc('published_at')->orderByDesc('id')->limit(self::PER_PAGE)->get();

        return response()->json(['data' => [
            'section' => ['id' => (int) $section->id, 'key' => $section->key, 'name' => $section->name_ar, 'slug' => $section->slug, 'description' => $section->description, 'cover' => $this->presenter->media($section->cover_media_id)],
            'characters' => $characters->map(fn ($c) => $this->presenter->character($c))->all(),
            'latest' => $this->presenter->cards($latest),
        ]]);
    }
}
