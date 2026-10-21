<?php

namespace App\Http\Controllers\Api;

use App\Ads\AdBoard;
use App\Ads\AdClickLink;
use App\Ads\AdCounter;
use App\Ads\AdPlacements;
use App\Ads\AdTargetUrl;
use App\Api\Presenter;
use App\Api\SiteToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Ads for the site (decision 13): the slots with what fills them, the click link that counts and then sends the
 * visitor on, and the view reports the site sends when half an ad is on screen.
 */
class AdApiController extends ApiController
{
    public function __construct(Presenter $presenter, private AdBoard $board, private AdCounter $counter)
    {
        parent::__construct($presenter);
    }

    public function slots(): JsonResponse
    {
        return response()->json($this->board->slots());
    }

    /**
     * 13.7: count, then 302 to the address stored with the campaign, and only there. A link whose signature does not
     * match, or a campaign that was never shown (a draft) or no longer exists, is a 404. An ended or paused campaign still
     * takes the visitor to the advertiser (the page they clicked on was older than the change) but is not counted.
     */
    public function click(Request $request, string $campaign, string $placement, string $signature): JsonResponse|RedirectResponse
    {
        $id = (int) $campaign;
        if (! AdClickLink::valid($id, $placement, $signature)) {
            return $this->notFound();
        }
        $row = DB::table('ad_campaigns')->where('id', $id)->where('status', '<>', 'draft')->first(['id', 'target_url']);
        $inSlot = $row !== null && DB::table('ad_campaign_placements')->where('campaign_id', $id)->where('placement_code', $placement)->exists();
        if (! $inSlot || ! AdTargetUrl::isValid($row->target_url)) {
            return $this->notFound();
        }

        $this->counter->click($id, $placement, SiteToken::visitorIp($request), SiteToken::visitorAgent($request));

        $response = new RedirectResponse($row->target_url, 302);
        $response->headers->add(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'strict-origin-when-cross-origin']);

        return $response;
    }

    /**
     * 13.6: the site reports the ads that were at least half on screen in one page view. Always 204 for a well-formed
     * report, counted or not, so nobody can learn which reports count. JSON only: a page elsewhere cannot make a browser
     * send one (that would need a preflight, and CORS is off).
     */
    public function impressions(Request $request): JsonResponse|Response
    {
        if (! $request->isJson()) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON.');
        }
        $validator = Validator::make($request->json()->all(), [
            'view_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'items' => ['required', 'array', 'min:1', 'max:'.(int) config('raqam.ads.max_items')],
            'items.*' => ['required', 'array:campaign_id,placement'],
            'items.*.campaign_id' => ['required', 'integer', 'min:1', 'max:999999999999999999'],
            'items.*.placement' => ['required', 'string', 'in:'.implode(',', AdPlacements::ORDER)],
        ]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $data = $validator->validated();

        $this->counter->impressions(array_values($data['items']), $data['view_id'], SiteToken::visitorIp($request), SiteToken::visitorAgent($request));

        return response()->noContent()->withHeaders(['Cache-Control' => 'no-store']);
    }
}
