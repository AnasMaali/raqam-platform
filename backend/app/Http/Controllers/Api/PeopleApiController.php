<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PeopleApiController extends ApiController
{
    public function authors(Request $request): JsonResponse
    {
        $p = DB::table('authors')->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->paginate(self::PER_PAGE, page: $this->page($request));
        $this->presenter->preload(collect($p->items())->pluck('photo_media_id'));

        return $this->pageOf($p, collect($p->items())->map(fn ($a) => $this->presenter->author($a))->all());
    }

    /** The «فريق العمل» page (28): every active writer the admin put on it, in the admin's order. */
    public function team(): JsonResponse
    {
        $rows = DB::table('authors')->whereNull('deleted_at')->where('is_active', true)->whereNotNull('team_sort_order')->orderBy('team_sort_order')->orderBy('name')->get();
        $this->presenter->preload($rows->pluck('photo_media_id'));

        return response()->json(['data' => $rows->map(fn ($a) => $this->presenter->author($a))->all()]);
    }

    public function author(string $slug): JsonResponse
    {
        $a = DB::table('authors')->whereNull('deleted_at')->where('is_active', true)->where('slug', $slug)->first();

        return $a ? response()->json(['data' => $this->presenter->author($a)]) : $this->notFound();
    }

    public function guests(Request $request): JsonResponse
    {
        $p = DB::table('guests')->whereNull('deleted_at')->orderBy('name')->paginate(self::PER_PAGE, page: $this->page($request));
        $this->presenter->preload(collect($p->items())->pluck('photo_media_id'));

        return $this->pageOf($p, collect($p->items())->map(fn ($g) => $this->presenter->guest((int) $g->id))->all());
    }

    public function guest(string $slug): JsonResponse
    {
        $id = DB::table('guests')->whereNull('deleted_at')->where('slug', $slug)->value('id');

        return $id ? response()->json(['data' => $this->presenter->guest((int) $id) + ['appearances' => $this->presenter->appearances((int) $id)]]) : $this->notFound();
    }
}
