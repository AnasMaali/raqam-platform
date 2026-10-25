<?php

use App\Booking\BookingInbox;
use App\Booking\BookingRefused;
use App\Booking\BookingStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/* The admin's side of booking (15.10), switched off with the rest: filters, details, planned date, published item, notes. */

beforeEach(fn () => $this->inbox = app(BookingInbox::class));

test('the list filters by stage, service, Palestine days and a search on reference, name or e-mail, newest first', function () {
    $old = insertBooking(['created_at' => now()->subDays(10)]);
    $new = insertBooking();
    $other = insertBooking(['service_id' => insertService(['name_ar' => 'قصة نجاح'])]);
    app(BookingStatuses::class)->move($new, 'contacted');
    DB::table('customers')->where('id', DB::table('bookings')->where('id', $other)->value('customer_id'))->update(['name' => 'ليلى خليل']);
    $ids = fn (array $f) => collect($this->inbox->list($f)->items())->pluck('id')->all();

    expect($ids([]))->toBe([$other, $new, $old])
        ->and($ids(['status' => 'contacted']))->toBe([$new])
        ->and($ids(['service_id' => DB::table('bookings')->where('id', $other)->value('service_id')]))->toBe([$other])
        ->and($ids(['from' => now('Asia/Hebron')->subDay()->toDateString()]))->toBe([$other, $new])
        ->and($ids(['to' => now('Asia/Hebron')->subDays(5)->toDateString()]))->toBe([$old])
        ->and($ids(['q' => 'ليلى']))->toBe([$other])
        ->and($ids(['q' => DB::table('bookings')->where('id', $old)->value('reference')]))->toBe([$old])
        ->and($ids(['q' => '100%']))->toBe([]);
});

test('one booking comes with its customer, service and every stage', function () {
    $id = insertBooking();
    app(BookingStatuses::class)->move($id, 'contacted', 'اتصلنا به');

    $b = $this->inbox->show($id);

    expect($b['customer']->name)->toBe('سامي')->and($b['service'])->toBe('حلقة بودكاست')
        ->and(array_column($b['history'], 'status_code'))->toContain('contacted')->and($b['attachments'])->toBe(0)
        ->and($this->inbox->show(999999))->toBeNull();
});

test('the planned date is written in Palestine time, can be cleared, and each change is audited', function () {
    $id = insertBooking();

    $this->inbox->schedule($id, '2026-11-02 10:30', null);
    expect(DB::selectOne("SELECT to_char(scheduled_for AT TIME ZONE 'Asia/Hebron', 'YYYY-MM-DD HH24:MI') AS t FROM bookings WHERE id = ?", [$id])->t)->toBe('2026-11-02 10:30');

    $this->inbox->schedule($id, '2026-11-02 10:30', null);   // the same: no audit row
    $this->inbox->schedule($id, null, null);
    expect(DB::table('bookings')->where('id', $id)->value('scheduled_for'))->toBeNull()
        ->and(DB::table('audit_log')->where('subject_table', 'bookings')->where('subject_id', $id)->count())->toBe(2);
    expect(fn () => $this->inbox->schedule($id, 'غداً', null))->toThrow(BookingRefused::class);
});

test('only a published item can be linked; unlinking works', function () {
    $id = insertBooking();
    $live = insertPost(publishedPostValues());
    $draft = insertPost();

    expect(fn () => $this->inbox->linkPost($id, $draft, null))->toThrow(BookingRefused::class);
    $this->inbox->linkPost($id, $live, null);
    expect((int) DB::table('bookings')->where('id', $id)->value('published_post_id'))->toBe($live);
    $this->inbox->linkPost($id, null, null);
    expect(DB::table('bookings')->where('id', $id)->value('published_post_id'))->toBeNull();
});

test('internal notes are added as dated lines, never replacing earlier ones; empty or overlong notes are refused', function () {
    $id = insertBooking(['admin_notes' => 'ملاحظة سابقة']);

    $this->inbox->addNote($id, 'طلب تأجيل الموعد', null);
    $this->inbox->addNote($id, "سطر\x07 ثانٍ", null);

    $lines = explode("\n", DB::table('bookings')->where('id', $id)->value('admin_notes'));
    expect($lines)->toHaveCount(3)->and($lines[0])->toBe('ملاحظة سابقة')->and($lines[1])->toEndWith('— طلب تأجيل الموعد')->and($lines[2])->toEndWith('— سطر  ثانٍ');
    expect(fn () => $this->inbox->addNote($id, '   ', null))->toThrow(BookingRefused::class)
        ->and(fn () => $this->inbox->addNote($id, str_repeat('ا', BookingInbox::NOTE_MAX + 1), null))->toThrow(BookingRefused::class)
        ->and(fn () => $this->inbox->addNote(999999, 'x', null))->toThrow(BookingRefused::class);
});
