<?php

namespace App\Booking;

use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use App\Settings\FeatureFlags;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Moves a booking to another stage (15.7, 15.8, 15.11, 15.12). The only way is a new row in booking_status_history: the
 * database checks the move against booking_status_transitions, updates the booking and refuses any direct change. This
 * adds what the database cannot know: the payment stages exist only while online payments are on, and the customer is
 * told when the stage is one the admin chose to tell (`notify_customer`).
 */
final class BookingStatuses
{
    /** @throws BookingRefused */
    public function move(int $bookingId, string $to, ?string $note = null, ?int $userId = null): void
    {
        $status = DB::table('booking_statuses')->where('code', $to)->first(['code', 'name_ar', 'notify_customer', 'requires_payment_flag']);
        if ($status === null) {
            throw new BookingRefused('unknown_status', 'هذه المرحلة غير موجودة.');
        }
        if ($status->requires_payment_flag && ! FeatureFlags::on('online_payments')) {
            throw new BookingRefused('payment_off', 'مراحل الدفع غير متاحة والدفع الإلكتروني مطفأ.');
        }
        $note = $note === null ? null : (mb_substr(trim($note), 0, 1000) ?: null);

        try {
            // A refused statement aborts a PostgreSQL transaction; the nested one keeps the caller's usable.
            $tell = DB::transaction(function () use ($bookingId, $status, $note, $userId) {
                $b = DB::selectOne('SELECT b.status_code, b.reference, c.name, c.email FROM bookings b JOIN customers c ON c.id = b.customer_id
                                    WHERE b.id = ? AND b.deleted_at IS NULL FOR UPDATE OF b', [$bookingId])
                    ?? throw new BookingRefused('not_allowed', 'الحجز غير موجود.');
                $tell = $status->notify_customer && $b->status_code !== $status->code;
                DB::insert('INSERT INTO booking_status_history (booking_id, status_code, note, changed_by, customer_notified_at)
                            VALUES (?, ?, ?, ?, '.($tell ? 'now()' : 'NULL').')', [$bookingId, $status->code, $note, $userId]);

                return $tell ? $b : null;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                throw new BookingRefused('not_allowed', 'لا يمكن نقل الحجز إلى مرحلة «'.$status->name_ar.'» من مرحلته الحالية.');
            }
            throw $e;
        }

        if ($tell !== null) {
            DB::afterCommit(fn () => SystemMail::queue(new TemplatedEmail('booking_status_changed',
                ['الاسم' => $tell->name, 'المرجع' => $tell->reference, 'الحالة' => $status->name_ar]), $tell->email));
        }
    }
}
