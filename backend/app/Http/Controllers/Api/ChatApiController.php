<?php

namespace App\Http\Controllers\Api;

use App\Api\SiteToken;
use App\Chat\ChatRefused;
use App\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The chatbot (decision 18). GET /chat tells the site whether it is on, its welcome and its first buttons (18.1, 18.6);
 * the site loads the chat only when the visitor opens it (18.15). POST /chat/messages comes from the site's server
 * only (its token and the visitor's address), JSON only.
 */
class ChatApiController extends ApiController
{
    public function config(): JsonResponse
    {
        $on = ChatService::enabled();

        return response()->json(['data' => [
            'enabled' => $on,
            'welcome' => $on ? (DB::table('settings')->where('key', 'chatbot.welcome')->value('value') ?: 'أهلاً بك! أنا مساعد رَقَم. اسألني أو اختر من الأزرار.') : null,
            'buttons' => $on ? DB::table('bot_quick_replies')->where('is_root', true)->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'label_ar'])->map(fn ($b) => ['id' => (int) $b->id, 'label' => $b->label_ar])->all() : [],
            'max_length' => 500,
        ]]);
    }

    public function message(Request $request, ChatService $chat): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON.');
        }
        if (($visitor = SiteToken::visitor($request)) === null) {
            return $this->error(403, 'site_only', 'المحادثة تُرسَل من الموقع فقط.');
        }
        $body = $request->json()->all();
        $string = fn (string $key, int $max) => is_string($body[$key] ?? null) && strlen($body[$key]) <= $max ? $body[$key] : null;
        $button = $body['button'] ?? null;
        // Button ids are small numbers (smallint); anything else is simply no such button.
        $button = is_int($button) || (is_string($button) && ctype_digit($button) && strlen($button) <= 9) ? (int) $button : null;
        if ($button !== null && ($button < 1 || $button > 32767)) {
            return $this->error(404, 'not_found', 'هذا الزر غير موجود.')->withHeaders(['Cache-Control' => 'no-store']);
        }
        // A message far beyond the limit is refused as such, not cut; bytes allow 4 per character.
        if (is_string($body['message'] ?? null) && mb_strlen($body['message']) > 500) {
            return $this->error(422, 'not_allowed', 'الرسالة أطول من 500 حرف.');
        }

        try {
            $data = $chat->handle($string('conversation', 200), $string('message', 4000), $button, $string('path', 500), $visitor);
        } catch (ChatRefused $e) {
            return $this->error($e->status, $e->reason, $e->getMessage())->withHeaders(['Cache-Control' => 'no-store']);
        }

        return response()->json(['data' => $data])->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** The FAQ page (28): the published general questions, in order. */
    public function faqs(): JsonResponse
    {
        return response()->json(['data' => DB::table('faqs')->whereNull('service_id')->where('is_published', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'question', 'answer'])->map(fn ($f) => ['id' => (int) $f->id, 'question' => $f->question, 'answer' => $f->answer])->all()]);
    }
}
