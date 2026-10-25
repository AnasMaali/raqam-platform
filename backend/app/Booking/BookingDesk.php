<?php

namespace App\Booking;

use App\Contact\ContactInbox;
use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use App\Media\FileInspector;
use App\Media\MediaRejected;
use App\Media\MediaUploader;
use App\Models\Service;
use App\Panel\AdminAlerts;
use App\Settings\FeatureFlags;
use App\Support\Honeypot;
use App\Support\PhoneNumber;
use App\Support\TrackingHash;
use App\Support\Turnstile;
use App\Support\VisitorText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A booking request from the site (decision 15), switched off until the `booking` feature is on.
 *
 * Strict checks, a honeypot, Turnstile and limits per visitor (15.3); the price and currency the visitor saw are copied
 * onto the booking (15.5); up to 3 private attachments through the media library (15.2); the reference number and the
 * first history row come from the database. The visitor gets a private status link (15.9) whose token is stored only as
 * a hash, so nobody can rebuild the link from the database; the customer gets a confirmation and the admins an alert (15.6).
 *
 * A contact is kept once per e-mail (`customers`). A new request never overwrites the stored name or phone (anybody can
 * type anybody's address); when they differ, the booking's internal note says so for the admin.
 */
final class BookingDesk
{
    public const HONEYPOT = Honeypot::FIELD;

    public const CONTACT_WAYS = ['phone' => 'اتصال هاتفي', 'whatsapp' => 'واتساب', 'email' => 'البريد الإلكتروني'];

    /** What an attachment may be: pictures and PDF (a brief, a logo, a profile), never a script, an SVG or a video. */
    public const ATTACHMENT_TYPES = [FileInspector::JPEG, FileInspector::PNG, FileInspector::WEBP, FileInspector::PDF];

    public const MAX_ATTACHMENTS = 3;

    public function __construct(private MediaUploader $uploader, private FileInspector $inspector) {}

    /**
     * @param  array<string, mixed>  $input  service, name, company, phone, email, preferred_contact, preferred_dates, details, consent, turnstile_token, website
     * @param  list<UploadedFile>  $files
     * @param  int|null  $userId  the signed-in customer (17.8); a booking is never linked afterwards (2.6)
     * @return array{reference: string, token: string, url: string}|null null when the honeypot caught a robot (nothing is kept)
     *
     * @throws ValidationException
     * @throws BookingRefused
     */
    public function submit(array $input, array $files, string $visitorIp, ?int $userId = null): ?array
    {
        if (! FeatureFlags::on('booking')) {
            throw new BookingRefused('off', 'الحجز الإلكتروني غير متاح.');
        }
        if (filled($input[self::HONEYPOT] ?? null)) {
            return null;
        }
        [$clean, $service] = $this->validate($input);
        $attachments = $this->checkFiles($files);

        $visitor = TrackingHash::visitor($visitorIp);
        $limits = ['hour' => [(int) config('raqam.booking.per_hour'), 3600], 'day' => [(int) config('raqam.booking.per_day'), 86400]];
        foreach ($limits as $name => [$max]) {
            if (RateLimiter::tooManyAttempts("booking:{$name}:{$visitor}", $max)) {
                throw new BookingRefused('too_many', 'أرسلت طلبات كثيرة؛ حاول لاحقاً أو تواصل معنا بالهاتف.');
            }
        }
        if (! Turnstile::passes(is_string($input['turnstile_token'] ?? null) ? $input['turnstile_token'] : null, $visitorIp, 'booking')) {
            throw new BookingRefused('turnstile_failed', 'تعذّر التحقق من أنك لست روبوتاً؛ أعد المحاولة.');
        }
        foreach ($limits as $name => [, $seconds]) {
            RateLimiter::hit("booking:{$name}:{$visitor}", $seconds);
        }

        $token = BookingLink::newToken();
        $booking = DB::transaction(function () use ($clean, $service, $attachments, $token, $visitorIp, $userId) {
            [$customerId, $note] = $this->customer($clean);
            [$amount, $currency] = $this->price($service);
            $row = DB::selectOne(
                'INSERT INTO bookings (service_id, customer_id, user_id, preferred_contact, preferred_dates, details, price_amount, currency_code,
                                       access_token_hash, admin_notes, consent_at, ip_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), ?) RETURNING id, reference',
                [$service->id, $customerId, $userId, $clean['preferred_contact'], $clean['preferred_dates'], $clean['details'], $amount, $currency,
                    BookingLink::hash($token), $note, TrackingHash::of(TrackingHash::network($visitorIp))],
            );
            foreach ($attachments as $file) {
                $media = $this->uploader->store($file->getRealPath(), (string) $file->getClientOriginalName(), ['visibility' => 'private'], null)->asset;
                DB::table('booking_attachments')->insertOrIgnore(['booking_id' => $row->id, 'media_id' => $media->id]);   // the same file twice is one attachment
            }

            return $row;
        });

        DB::afterCommit(fn () => $this->tell($booking->id, $booking->reference, $clean, $service, $token));

        return ['reference' => $booking->reference, 'token' => $token, 'url' => BookingLink::url($token)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array{name: string, company: ?string, phone: string, email: string, preferred_contact: string, preferred_dates: ?string, details: string}, 1: Service}
     *
     * @throws ValidationException
     */
    public function validate(array $input): array
    {
        $text = fn ($v) => is_string($v) ? VisitorText::clean($v) : $v;
        $data = [
            'service' => $input['service'] ?? null, 'name' => $text($input['name'] ?? null), 'company' => $text($input['company'] ?? null) ?: null,
            'phone' => $text($input['phone'] ?? null), 'email' => $text($input['email'] ?? null),
            'preferred_contact' => $input['preferred_contact'] ?? null, 'preferred_dates' => $text($input['preferred_dates'] ?? null) ?: null,
            'details' => $text($input['details'] ?? null), 'consent' => $input['consent'] ?? null,
        ];

        $validator = Validator::make($data, [
            'service' => ['required', 'string', 'max:200'],
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\r\n]/'],
            'company' => ['nullable', 'string', 'max:160', 'not_regex:/[\r\n]/'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'string', 'max:254', 'email:rfc,strict', 'not_regex:/[\r\n\s]/'],
            'preferred_contact' => ['required', 'string', 'in:'.implode(',', array_keys(self::CONTACT_WAYS))],
            'preferred_dates' => ['nullable', 'string', 'max:500'],
            'details' => ['required', 'string', 'min:10', 'max:5000'],
            'consent' => ['accepted'],
        ], [
            'service.*' => 'اختر الخدمة.',
            'name.*' => 'اكتب اسمك (2–120 حرفاً).',
            'company.*' => 'اسم الشركة طويل جداً (160 حرفاً على الأكثر، سطر واحد).',
            'phone.*' => 'اكتب رقم الهاتف.',
            'email.*' => 'البريد الإلكتروني غير صحيح.',
            'preferred_contact.*' => 'اختر طريقة التواصل المفضلة.',
            'preferred_dates.*' => 'المواعيد المناسبة طويلة جداً (500 حرف على الأكثر).',
            'details.*' => 'اكتب تفاصيل طلبك (10–5000 حرف).',
            'consent.*' => 'يجب الموافقة على سياسة الخصوصية لإرسال الطلب.',
        ]);
        $service = null;
        $validator->after(function ($v) use ($data, &$service) {
            foreach (['name', 'company'] as $field) {
                if (is_string($data[$field]) && ContactInbox::looksLikeLink($data[$field])) {
                    $v->errors()->add($field, 'لا يمكن أن يحتوي هذا الحقل رابطاً أو بريداً إلكترونياً.');
                }
            }
            if (is_string($data['phone']) && PhoneNumber::e164($data['phone']) === null) {
                $v->errors()->add('phone', 'اكتب الرقم بالصيغة الدولية، مثال: +970599123456');
            }
            $service = is_string($data['service']) ? Service::query()->where('slug', $data['service'])->where('is_active', true)->first() : null;
            if ($service === null && ! $v->errors()->has('service')) {
                $v->errors()->add('service', 'هذه الخدمة غير متاحة للحجز.');
            }
        });
        $valid = $validator->validate();

        return [[
            'name' => $valid['name'], 'company' => $valid['company'] ?? null, 'phone' => PhoneNumber::e164($valid['phone']), 'email' => $valid['email'],
            'preferred_contact' => $valid['preferred_contact'], 'preferred_dates' => $valid['preferred_dates'] ?? null,
            'details' => str_replace("\r\n", "\n", $valid['details']),
        ], $service];
    }

    /**
     * Up to 3 files, each a picture or a PDF by its bytes (not its name) and within the size limit. The media library
     * then cleans them as it cleans every upload (re-encoded pictures, no EXIF).
     *
     * @param  list<mixed>  $files
     * @return list<UploadedFile>
     *
     * @throws ValidationException
     */
    private function checkFiles(array $files): array
    {
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages(['attachments' => 'ثلاثة مرفقات على الأكثر.']);
        }
        foreach ($files as $i => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages(["attachments.{$i}" => 'تعذّر استلام الملف؛ أعد إرفاقه.']);
            }
            if ($file->getSize() > (int) config('raqam.booking.attachment_bytes')) {
                throw ValidationException::withMessages(["attachments.{$i}" => 'الملف أكبر من '.intdiv((int) config('raqam.booking.attachment_bytes'), 1024 * 1024).' ميغابايت.']);
            }
            try {
                $mime = $this->inspector->mimeOf((string) file_get_contents($file->getRealPath()));
            } catch (MediaRejected) {
                $mime = null;
            }
            if (! in_array($mime, self::ATTACHMENT_TYPES, true)) {
                throw ValidationException::withMessages(["attachments.{$i}" => 'المرفق يجب أن يكون صورة (JPEG أو PNG أو WebP) أو ملف PDF.']);
            }
        }

        return array_values($files);
    }

    /**
     * The contact of this e-mail: made on its first request, never overwritten afterwards (see the class comment).
     *
     * @param  array{name: string, company: ?string, phone: string, email: string}  $c
     * @return array{0: int, 1: ?string} the customer id, and a note for the admin when this request names somebody else
     */
    private function customer(array $c): array
    {
        DB::statement('INSERT INTO customers (name, email, phone, company_name) VALUES (?, ?, ?, ?) ON CONFLICT (email) DO NOTHING',
            [$c['name'], $c['email'], $c['phone'], $c['company']]);
        $stored = DB::selectOne('SELECT id, name, phone, company_name FROM customers WHERE email = ? FOR UPDATE', [$c['email']]);

        $differs = array_filter([
            'الاسم' => $stored->name !== $c['name'] ? $c['name'] : null,
            'الهاتف' => $stored->phone !== $c['phone'] ? $c['phone'] : null,
            'الشركة' => $c['company'] !== null && $stored->company_name !== $c['company'] ? $c['company'] : null,
        ]);
        $note = $differs === [] ? null : 'بيانات هذا الطلب تختلف عن المسجّلة لهذا البريد: '
            .implode('، ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($differs), $differs)).'.';

        return [(int) $stored->id, $note];
    }

    /**
     * The price the visitor saw, fixed on the booking (15.5): the special offer while it lasts, else the price; nothing for
     * a price on request. Read from the row locked for this transaction, so a price saved at the same moment is either
     * wholly before or wholly after.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function price(Service $service): array
    {
        $s = DB::selectOne('SELECT price_mode, price_amount, currency_code, sale_price_amount, sale_ends_at > now() AS offer
                            FROM services WHERE id = ? FOR SHARE', [$service->id]);
        if ($s->price_mode === 'on_request') {
            return [null, null];
        }

        return [(int) ($s->offer ? $s->sale_price_amount : $s->price_amount), trim($s->currency_code)];
    }

    /** @param  array{name: string, company: ?string, phone: string, email: string, preferred_contact: string, preferred_dates: ?string, details: string}  $c */
    private function tell(int $id, string $reference, array $c, Service $service, string $token): void
    {
        AdminAlerts::raise('messages', 'booking:'.$id, 'طلب حجز جديد '.$reference, $service->name_ar.' — '.$c['name'], null,
            fn () => new TemplatedEmail('admin_booking_received', [
                'المرجع' => $reference, 'الخدمة' => $service->name_ar, 'الاسم' => $c['name'], 'الشركة' => $c['company'] ?? '—',
                'البريد' => $c['email'], 'الهاتف' => $c['phone'], 'التواصل' => self::CONTACT_WAYS[$c['preferred_contact']],
                'المواعيد' => $c['preferred_dates'] ?? '—', 'التفاصيل' => $c['details'],
            ], $c['email']));

        // The confirmation goes to whatever address was typed: a few a day per address, whoever asks (like the contact reply).
        $key = 'booking:confirm:'.hash_hmac('sha256', mb_strtolower($c['email']), (string) config('app.key'));
        Cache::add($key, 0, 86400);
        if (Cache::increment($key) <= (int) config('raqam.booking.confirmations_per_address_day')) {
            SystemMail::queue(new TemplatedEmail('booking_received', [
                'الاسم' => $c['name'], 'الخدمة' => $service->name_ar, 'المرجع' => $reference, 'الرابط' => BookingLink::url($token),
            ]), $c['email']);
        }
    }
}
