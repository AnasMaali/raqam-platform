<?php

namespace App\Http\Controllers\Api;

use App\Analytics\MostRead;
use App\Analytics\StatsCounter;
use App\Analytics\ViewCounts;
use App\Api\Presenter;
use App\Api\SiteToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The site's statistics (decision 22): the hits it reports, and the most read posts it shows.
 */
class StatsApiController extends ApiController
{
    public function __construct(Presenter $presenter, private StatsCounter $counter, private MostRead $mostRead)
    {
        parent::__construct($presenter);
    }

    /**
     * 22.1: a page view, an interaction or a page that was not found, reported by the site's own server (its token, the
     * visitor's address and browser), never by a browser: no cookie, no CORS, JSON only. Always 204 for a well-formed
     * report, counted or not, so nobody can learn what counts; a report that is not the site's counts nothing.
     */
    public function hit(Request $request): JsonResponse|Response
    {
        if (! $request->isJson()) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON.');
        }
        $path = ['required', 'string', 'max:2000', 'regex:~^/(?!/)[^\s\\\\]*$~u'];
        $validator = Validator::make($request->json()->all(), [
            'type' => ['required', 'string', 'in:'.implode(',', [StatsCounter::VIEW, StatsCounter::EVENT, StatsCounter::NOT_FOUND])],
            'path' => $path,
            'referrer' => ['nullable', 'string', 'max:2000'],
            'post_id' => ['nullable', 'integer', 'min:1', 'max:999999999999999999', 'prohibited_unless:type,view'],
            'event' => ['required_if:type,event', 'prohibited_unless:type,event', 'string', 'in:'.implode(',', StatsCounter::eventCodes())],
            'video_id' => ['nullable', 'integer', 'min:1', 'max:999999999999999999', 'prohibited_unless:type,event'],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $hit = $validator->validated();

        if (($visitor = SiteToken::visitor($request)) !== null) {
            $this->counter->record([
                'type' => $hit['type'], 'path' => $hit['path'], 'referrer' => $hit['referrer'] ?? null,
                'post_id' => isset($hit['post_id']) ? (int) $hit['post_id'] : null,
                'event' => $hit['event'] ?? null, 'video_id' => isset($hit['video_id']) ? (int) $hit['video_id'] : null,
            ], $visitor, SiteToken::visitorAgent($request));
        }

        return response()->noContent()->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** 22.5: the most read live posts of today or of the last seven days, for the site's "most read" section. */
    public function mostRead(Request $request): JsonResponse
    {
        $q = $this->query($request, ['period' => ['nullable', 'in:day,week'], 'limit' => ['nullable', 'integer', 'min:1', 'max:'.MostRead::MAX]]);
        $result = $this->mostRead->for($q['period'] ?? 'day', (int) ($q['limit'] ?? 5));

        $data = $this->presenter->summaries($result['posts']);
        if (ViewCounts::shown()) {
            foreach ($result['posts']->values() as $i => $post) {
                $data[$i]['period_views'] = $result['views'][$post->id] ?? 0;
            }
        }

        return response()->json(['data' => $data, 'meta' => ['period' => $q['period'] ?? 'day', 'from' => $result['from'], 'to' => $result['to']]]);
    }
}
