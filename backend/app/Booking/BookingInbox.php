<?php

namespace App\Booking;

use App\Audit\AuditLogger;
use App\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The admin's side of booking (15.10), built and switched off like the rest of it: the list with its filters, one booking
 * with its stage history, the date the work is planned for, the published item it led to, and internal notes. The panel
 * screen comes when booking is switched on (15.13); every write is in the audit trail. The stage itself moves only
 * through BookingStatuses.
 */
final class BookingInbox
{
    public const PER_PAGE = 25;

    public const NOTE_MAX = 2000;

    /**
     * @param  array{status?: ?string, service_id?: ?int, from?: ?string, to?: ?string, q?: ?string}  $filters  dates are Palestine days (Y-m-d)
     */
    public function list(array $filters = [], int $page = 1): LengthAwarePaginator
    {
        $day = fn (?string $d, bool $end) => $d === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1 ? null
            : Carbon::parse($d, 'Asia/Hebron')->{$end ? 'endOfDay' : 'startOfDay'}()->utc();
        $q = trim((string) ($filters['q'] ?? ''));

        return DB::table('bookings')->join('customers', 'customers.id', '=', 'bookings.customer_id')->join('services', 'services.id', '=', 'bookings.service_id')
            ->whereNull('bookings.deleted_at')
            ->when($filters['status'] ?? null, fn ($b, $v) => $b->where('bookings.status_code', $v))
            ->when($filters['service_id'] ?? null, fn ($b, $v) => $b->where('bookings.service_id', (int) $v))
            ->when($day($filters['from'] ?? null, false), fn ($b, $v) => $b->where('bookings.created_at', '>=', $v))
            ->when($day($filters['to'] ?? null, true), fn ($b, $v) => $b->where('bookings.created_at', '<=', $v))
            ->when($q !== '', function ($b) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $b->where(fn ($w) => $w->where('bookings.reference', 'ilike', $like)->orWhere('customers.name', 'ilike', $like)->orWhere('customers.email', 'ilike', $like));
            })
            ->orderByDesc('bookings.created_at')->orderByDesc('bookings.id')
            ->select('bookings.id', 'bookings.reference', 'bookings.status_code', 'bookings.created_at', 'bookings.scheduled_for', 'customers.name as customer', 'services.name_ar as service')
            ->paginate(self::PER_PAGE, page: max(1, $page));
    }

    /** @return array<string, mixed>|null one booking with its customer, service, stages and attachments */
    public function show(int $id): ?array
    {
        $b = DB::table('bookings')->whereNull('deleted_at')->where('id', $id)->first();
        if ($b === null) {
            return null;
        }

        return [
            'booking' => $b,
            'customer' => DB::table('customers')->where('id', $b->customer_id)->first(['name', 'email', 'phone', 'company_name']),
            'service' => DB::table('services')->where('id', $b->service_id)->value('name_ar'),
            'history' => DB::table('booking_status_history')->where('booking_id', $id)->orderBy('id')->get(['status_code', 'note', 'changed_by', 'created_at'])->all(),
            'attachments' => DB::table('booking_attachments')->where('booking_id', $id)->count(),
        ];
    }

    /** The date and time the work is planned for, in Palestine time; null clears it. @throws BookingRefused */
    public function schedule(int $id, ?string $at, ?int $userId): void
    {
        $when = null;
        if ($at !== null && trim($at) !== '') {
            try {
                $when = Carbon::createFromFormat('Y-m-d H:i', trim($at), 'Asia/Hebron')->utc();
            } catch (\Throwable) {
                throw new BookingRefused('invalid', 'الموعد غير صحيح (سنة-شهر-يوم ساعة:دقيقة).');
            }
        }
        $this->change($id, 'scheduled_for', $when?->format('Y-m-d H:i:sP'), $userId);
    }

    /** The published item the booking led to (a podcast episode, a story …); null unlinks it. @throws BookingRefused */
    public function linkPost(int $id, ?int $postId, ?int $userId): void
    {
        if ($postId !== null && ! Post::query()->live()->whereKey($postId)->exists()) {
            throw new BookingRefused('invalid', 'اختر مادة منشورة.');
        }
        $this->change($id, 'published_post_id', $postId, $userId);
    }

    /** Adds a dated line to the internal notes (never shown to the customer). @throws BookingRefused */
    public function addNote(int $id, string $note, ?int $userId): void
    {
        $note = trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $note));
        if ($note === '' || mb_strlen($note) > self::NOTE_MAX) {
            throw new BookingRefused('invalid', 'اكتب الملاحظة (حتى '.self::NOTE_MAX.' حرف).');
        }
        $line = Carbon::now('Asia/Hebron')->format('Y-m-d H:i').' — '.$note;
        $current = $this->row($id)->admin_notes;
        $this->change($id, 'admin_notes', $current === null || $current === '' ? $line : $current."\n".$line, $userId);
    }

    private function change(int $id, string $column, mixed $value, ?int $userId): void
    {
        DB::transaction(function () use ($id, $column, $value, $userId) {
            $before = $this->row($id, lock: true)->{$column};
            DB::table('bookings')->where('id', $id)->update([$column => $value]);
            $after = DB::table('bookings')->where('id', $id)->value($column);
            if ((string) $before !== (string) $after) {
                AuditLogger::record('update', 'bookings', $id, [$column => [$before, $after]], $userId);
            }
        });
    }

    /** @throws BookingRefused */
    private function row(int $id, bool $lock = false): object
    {
        return DB::table('bookings')->whereNull('deleted_at')->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw new BookingRefused('not_allowed', 'الحجز غير موجود.');
    }
}
