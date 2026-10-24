<?php

namespace App\Http\Controllers\Api;

use App\Api\SiteToken;
use App\Booking\BookingDesk;
use App\Booking\BookingRefused;
use App\Booking\BookingTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Online booking (decision 15), behind `feature:booking`: while the switch is off both routes are a 404.
 * Like the contact form, only the site's own server may call them, with its token and the visitor's address.
 * A request is JSON, or multipart/form-data when it carries attachments (`attachments[]`, up to 3).
 */
class BookingApiController extends ApiController
{
    public function store(Request $request, BookingDesk $desk): JsonResponse
    {
        $multipart = str_starts_with((string) $request->header('Content-Type'), 'multipart/form-data');
        if (! $request->isJson() && ! $multipart) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON أو multipart/form-data.');
        }
        if (($visitor = SiteToken::visitor($request)) === null) {
            return $this->error(403, 'site_only', 'هذا النموذج يُرسَل من الموقع فقط.');
        }

        $input = $multipart ? $request->except('attachments') : $request->json()->all();
        $files = $multipart ? $request->file('attachments', []) : [];
        try {
            $booking = $desk->submit($input, is_array($files) ? array_values($files) : [$files], $visitor);
        } catch (BookingRefused $e) {
            return $this->error(match ($e->reason) {
                'too_many' => 429, 'off' => 404, default => 422
            }, $e->reason, $e->getMessage())->withHeaders(['Cache-Control' => 'no-store']);
        }

        // A robot caught by the honeypot gets the same shape of answer, with nothing behind it.
        return response()->json(['data' => $booking === null ? ['received' => true] : ['received' => true, ...$booking]], 202)
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** POST /bookings/track {token}: the status page (15.9). POST keeps the token out of URLs and logs. */
    public function track(Request $request, BookingTracker $tracker): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON.');
        }
        if (SiteToken::visitor($request) === null) {
            return $this->error(403, 'site_only', 'هذه الصفحة تُطلب من الموقع فقط.');
        }
        $booking = $tracker->find($request->json('token'));

        return ($booking === null ? $this->notFound() : response()->json(['data' => $booking]))
            ->withHeaders(['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
