<?php

namespace App\Http\Controllers\Api;

use App\Api\SiteToken;
use App\Contact\ContactInbox;
use App\Contact\ContactRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /contact (20.13). Only the site's own server may send it, with its token and the visitor's address: the
 * browser posts to a route of the site on the same domain, never to us. The answer does not say whether anything
 * was kept or sent beyond "received".
 */
class ContactApiController extends ApiController
{
    public function store(Request $request, ContactInbox $inbox): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON.');
        }
        if (($visitor = SiteToken::visitor($request)) === null) {
            return $this->error(403, 'site_only', 'هذا النموذج يُرسَل من الموقع فقط.');
        }

        try {
            $inbox->submit($request->json()->all(), $visitor);
        } catch (ContactRefused $e) {
            return $this->error($e->reason === 'too_many' ? 429 : 422, $e->reason, $e->getMessage())->withHeaders(['Cache-Control' => 'no-store']);
        }

        return response()->json(['data' => ['received' => true]], 202)->withHeaders(['Cache-Control' => 'no-store']);
    }
}
