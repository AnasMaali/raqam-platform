<?php

namespace App\Http\Controllers\Api;

use App\Api\SiteToken;
use App\Payments\PaymentNotifications;
use App\Payments\PaymentRefused;
use App\Payments\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Online payment (decision 16), behind `feature:booking,online_payments`: while either switch is off every route is a 404.
 * Starting a payment and the customer's return come from the site's server; the notification is the bank's own call.
 */
class PaymentApiController extends ApiController
{
    /** POST /bookings/pay {token}: the bank's page for a booking that waits for payment. */
    public function start(Request $request, Payments $payments): JsonResponse
    {
        if (($refused = $this->siteJson($request)) !== null) {
            return $refused;
        }
        try {
            $url = $payments->start($request->json('token'), $request->json()->all());
        } catch (PaymentRefused $e) {
            return $this->error(match ($e->reason) {
                'not_payable' => 404, 'already_paid' => 409, 'off' => 404, 'no_gateway' => 503, default => 422
            },
                $e->reason, $e->getMessage())->withHeaders(['Cache-Control' => 'no-store']);
        }

        return response()->json(['data' => ['redirect_url' => $url]])->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** POST /payments/return {order_id}: the customer is back from the bank; the bank is asked, the browser is not believed. */
    public function return(Request $request, Payments $payments): JsonResponse
    {
        if (($refused = $this->siteJson($request)) !== null) {
            return $refused;
        }
        $order = $request->json('order_id');
        $payment = is_string($order) && strlen($order) <= 64 ? DB::table('payments as p')->join('bookings as b', 'b.id', '=', 'p.booking_id')
            ->where('p.gateway_order_id', $order)->first(['p.id', 'b.reference']) : null;
        if ($payment === null) {
            return $this->notFound()->withHeaders(['Cache-Control' => 'no-store']);
        }
        $status = $payments->sync((int) $payment->id);
        $shown = match ($status) {
            'captured', 'duplicate', 'refunded', 'partially_refunded', 'authorized' => 'paid', 'pending' => 'pending', default => 'not_paid'
        };

        return response()->json(['data' => ['status' => $shown, 'reference' => $payment->reference]])->withHeaders(['Cache-Control' => 'no-store']);
    }

    /** POST /payments/webhooks/{gateway}: kept always, acted on once, and only when signed by the bank. */
    public function webhook(Request $request, string $gateway, PaymentNotifications $notifications): JsonResponse
    {
        try {
            $result = $notifications->receive($gateway, $request);
        } catch (PaymentRefused) {
            return $this->notFound();
        }

        return $result === 'invalid' ? $this->error(401, 'invalid_signature', 'التوقيع غير صحيح.') : response()->json(['data' => ['received' => true]]);
    }

    private function siteJson(Request $request): ?JsonResponse
    {
        if (! $request->isJson()) {
            return $this->error(415, 'unsupported_media_type', 'أرسل الطلب بصيغة JSON.');
        }

        return SiteToken::visitor($request) === null ? $this->error(403, 'site_only', 'هذا الطلب يُرسَل من الموقع فقط.') : null;
    }
}
