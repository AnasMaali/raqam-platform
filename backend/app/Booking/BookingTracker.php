<?php

namespace App\Booking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the private status page shows (15.9): the reference, the service, where the booking is and when it got there.
 * Nothing the customer typed and none of the admin's notes: whoever holds the link sees only the progress.
 */
final class BookingTracker
{
    /** @return array<string, mixed>|null null for a token that matches no live booking */
    public function find(mixed $token): ?array
    {
        if (! BookingLink::wellFormed($token)) {
            return null;
        }
        $b = DB::table('bookings as b')->join('services as s', 's.id', '=', 'b.service_id')->join('booking_statuses as st', 'st.code', '=', 'b.status_code')
            ->where('b.access_token_hash', BookingLink::hash($token))->whereNull('b.deleted_at')
            ->first(['b.id', 'b.reference', 'b.status_code', 'b.scheduled_for', 'b.created_at', 's.name_ar as service', 'st.name_ar as status', 'st.is_terminal']);
        if ($b === null) {
            return null;
        }
        $iso = fn (?string $t) => $t === null ? null : CarbonImmutable::parse($t)->utc()->format('Y-m-d\TH:i:s\Z');

        // One line per stage reached: a note-only row (same stage again) adds nothing the customer needs.
        $steps = [];
        foreach (DB::table('booking_status_history as h')->join('booking_statuses as st', 'st.code', '=', 'h.status_code')
            ->where('h.booking_id', $b->id)->orderBy('h.created_at')->orderBy('h.id')->get(['h.status_code', 'st.name_ar', 'h.created_at']) as $h) {
            if ($steps === [] || end($steps)['code'] !== $h->status_code) {
                $steps[] = ['code' => $h->status_code, 'name' => $h->name_ar, 'at' => $iso($h->created_at)];
            }
        }

        return [
            'reference' => $b->reference, 'service' => $b->service, 'created_at' => $iso($b->created_at),
            'status' => ['code' => $b->status_code, 'name' => $b->status, 'final' => (bool) $b->is_terminal],
            'scheduled_for' => $iso($b->scheduled_for), 'history' => $steps,
        ];
    }
}
