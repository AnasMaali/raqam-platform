<?php

namespace App\Http\Controllers\Api;

use App\Ads\AdPlacements;
use App\Api\Presenter;
use App\Api\SiteToken;
use App\Content\BodyRenderer;
use App\Http\Middleware\ApiResponseCache;
use App\Models\AdPlacement;
use App\Models\Service;
use App\Services\ContactClicks;
use App\Services\ServiceBoard;
use App\Services\ServiceContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The services and prices page and one page per service (decision 14), the «أعلن معنا» page (13.14), and the counter of
 * the contact buttons (14.9).
 */
class ServiceApiController extends ApiController
{
    public function __construct(Presenter $presenter, private ServiceBoard $board, private BodyRenderer $renderer)
    {
        parent::__construct($presenter);
    }

    public function index(Request $request): JsonResponse
    {
        ApiResponseCache::trueFor($request, ServiceBoard::nextChange());

        return response()->json(['data' => ['page' => $this->systemPage('services'), 'groups' => $this->board->groups()], 'meta' => $this->board->meta()]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        ApiResponseCache::trueFor($request, ServiceBoard::nextChange());
        $service = $this->board->detail($slug);

        return $service === null ? $this->notFound() : response()->json(['data' => $service, 'meta' => $this->board->meta()]);
    }

    /** 13.14: the slots and their sizes, the ad packages with their prices (from the services), and the contact buttons. */
    public function advertise(Request $request): JsonResponse
    {
        ApiResponseCache::trueFor($request, ServiceBoard::nextChange());
        $flags = AdPlacements::flags();
        $slots = AdPlacements::all()->filter(fn (AdPlacement $p) => AdPlacements::isOn($p, $flags))
            ->map(fn (AdPlacement $p) => ['code' => $p->code, 'name' => $p->name_ar, 'sizes' => ['desktop' => $p->size('desktop'), 'mobile' => $p->size('mobile')]])->values()->all();

        return response()->json(['data' => [
            'page' => $this->systemPage('advertise'),
            'placements' => $slots,
            'packages' => $this->board->adPackages(),
            'contact' => ServiceContact::links('الإعلان على منصة رَقَم'),
            'booking' => ['enabled' => ServiceBoard::bookingOn()],
        ]]);
    }

    /** 14.9: the site reports a press on a contact button of a visible service. Always 204 for a well-formed report. */
    public function contact(Request $request, string $slug): JsonResponse|Response
    {
        if (! $request->isJson()) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON.');
        }
        $validator = Validator::make($request->json()->all(), ['channel' => ['required', 'string', 'in:'.implode(',', ServiceContact::CHANNELS)]]);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        $id = Service::query()->where('is_active', true)->where('slug', $slug)->value('id');
        if ($id === null) {
            return $this->notFound();
        }
        ContactClicks::record((int) $id, $validator->validated()['channel'], SiteToken::visitorIp($request), SiteToken::visitorAgent($request));

        return response()->noContent()->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** A system page's text (its intro), when published. @return array<string, mixed>|null */
    private function systemPage(string $systemKey): ?array
    {
        $p = DB::table('pages')->where('system_key', $systemKey)->where('is_published', true)->first();
        if ($p === null) {
            return null;
        }
        $meta = $p->seo_meta_id ? DB::table('seo_meta')->where('id', $p->seo_meta_id)->first() : null;

        return ['title' => $p->title, 'body_html' => $this->renderer->html($p->body), 'seo' => $this->presenter->seo($meta, $p->title, null, null)];
    }
}
