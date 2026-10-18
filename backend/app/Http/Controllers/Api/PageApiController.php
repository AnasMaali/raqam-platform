<?php

namespace App\Http\Controllers\Api;

use App\Content\BodyRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PageApiController extends ApiController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('pages')->where('is_published', true)->orderBy('id')->get(['slug', 'title', 'system_key'])->map(fn ($p) => (array) $p)->all()]);
    }

    public function show(string $slug, BodyRenderer $renderer): JsonResponse
    {
        $p = DB::table('pages')->where('is_published', true)->where('slug', $slug)->first();
        if (! $p) {
            return $this->notFound();
        }
        $meta = $p->seo_meta_id ? DB::table('seo_meta')->where('id', $p->seo_meta_id)->first() : null;

        return response()->json(['data' => [
            'slug' => $p->slug, 'title' => $p->title, 'system_key' => $p->system_key, 'body_html' => $renderer->html($p->body),
            'updated_at' => $this->presenter->iso($p->updated_at), 'seo' => $this->presenter->seo($meta, $p->title, null, null),
        ]]);
    }
}
