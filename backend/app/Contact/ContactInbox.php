<?php

namespace App\Contact;

use App\Mail\SystemMail;
use App\Mail\TemplatedEmail;
use App\Panel\AdminAlerts;
use App\Support\PhoneNumber;
use App\Support\TrackingHash;
use App\Support\Turnstile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The contact form (20.13, 24.10): strict checks, a honeypot, Turnstile checked here, limits per visitor; the message
 * is kept for the panel, the admins are told (bell and e-mail) and the sender gets an automatic reply.
 *
 * The reply goes to whatever address was typed, so it is the part that could be abused to send mail to a stranger:
 * it says nothing the visitor wrote except the name (and a name with a link in it is refused), and one address gets at
 * most one reply a day, whoever asks (decision 2026-10-23). The answer to the visitor is the same either way.
 */
final class ContactInbox
{
    /** The hidden field a person never fills. */
    public const HONEYPOT = 'website';

    /**
     * @param  array<string, mixed>  $input  name, email, phone, subject, message, turnstile_token, website
     * @return int|null the message id; null when the honeypot caught a robot (nothing is kept or sent)
     *
     * @throws ValidationException
     * @throws ContactRefused (too many, or Turnstile said no)
     */
    public function submit(array $input, string $visitorIp): ?int
    {
        if (filled($input[self::HONEYPOT] ?? null)) {
            return null;
        }
        $clean = $this->validate($input);

        $visitor = TrackingHash::visitor($visitorIp);
        foreach (['hour' => [(int) config('raqam.contact.per_hour'), 3600], 'day' => [(int) config('raqam.contact.per_day'), 86400]] as $name => [$max, $seconds]) {
            if (RateLimiter::tooManyAttempts("contact:{$name}:{$visitor}", $max)) {
                throw new ContactRefused('too_many', 'أرسلت رسائل كثيرة؛ حاول لاحقاً أو تواصل معنا بالهاتف.');
            }
        }
        if (! Turnstile::passes(is_string($input['turnstile_token'] ?? null) ? $input['turnstile_token'] : null, $visitorIp, 'contact')) {
            throw new ContactRefused('turnstile_failed', 'تعذّر التحقق من أنك لست روبوتاً؛ أعد المحاولة.');
        }
        RateLimiter::hit("contact:hour:{$visitor}", 3600);
        RateLimiter::hit("contact:day:{$visitor}", 86400);

        $id = DB::table('contact_messages')->insertGetId([...$clean, 'ip_hash' => TrackingHash::of(TrackingHash::network($visitorIp))]);

        $this->alertAdmins($id, $clean);
        $this->reply($clean['email'], $clean['name']);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, email: string, phone: ?string, subject: string, message: string}
     */
    public function validate(array $input): array
    {
        $text = fn ($v) => is_string($v) ? trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v)) : $v;
        $data = [
            'name' => $text($input['name'] ?? null), 'email' => $text($input['email'] ?? null), 'phone' => $text($input['phone'] ?? null) ?: null,
            'subject' => $text($input['subject'] ?? null), 'message' => $text($input['message'] ?? null),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\r\n]/'],
            'email' => ['required', 'string', 'max:254', 'email:rfc,strict', 'not_regex:/[\r\n\s]/'],
            'phone' => ['nullable', 'string', 'max:30'],
            'subject' => ['required', 'string', 'min:2', 'max:200', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'min:2', 'max:5000'],
        ], [
            'name.*' => 'اكتب اسمك (2–120 حرفاً).',
            'email.*' => 'البريد الإلكتروني غير صحيح.',
            'phone.*' => 'رقم الهاتف غير صحيح.',
            'subject.*' => 'اكتب موضوع الرسالة (2–200 حرف، سطر واحد).',
            'message.*' => 'اكتب رسالتك (2–5000 حرف).',
        ]);
        $validator->after(function ($v) use ($data) {
            if (is_string($data['name']) && self::looksLikeLink($data['name'])) {
                $v->errors()->add('name', 'الاسم لا يمكن أن يحتوي رابطاً أو بريداً إلكترونياً.');
            }
            if (is_string($data['phone']) && PhoneNumber::e164($data['phone']) === null) {
                $v->errors()->add('phone', 'اكتب الرقم بالصيغة الدولية، مثال: +970599123456');
            }
        });
        $valid = $validator->validate();

        return [
            'name' => $valid['name'], 'email' => $valid['email'], 'phone' => PhoneNumber::e164($valid['phone'] ?? null),
            'subject' => $valid['subject'], 'message' => str_replace("\r\n", "\n", $valid['message']),
        ];
    }

    /** A link, an address or a domain: what a spammer would put in a name to have our reply carry it. */
    public static function looksLikeLink(string $text): bool
    {
        return preg_match('~(://|www\.|@|\b[a-z0-9-]{1,63}\.(?:[a-z]{2,24})\b)~iu', $text) === 1;
    }

    /** @param  array{name: string, email: string, phone: ?string, subject: string, message: string}  $m */
    private function alertAdmins(int $id, array $m): void
    {
        $withEmail = ! RateLimiter::tooManyAttempts('contact:alert-emails', (int) config('raqam.contact.alert_emails_per_hour'));
        if ($withEmail) {
            RateLimiter::hit('contact:alert-emails', 3600);
        }
        $link = url(Filament::getPanel('admin')->getPath()."/contact-messages/{$id}");

        AdminAlerts::raise('messages', 'contact:'.$id, 'رسالة جديدة من '.$m['name'], $m['subject'], "contact-messages/{$id}",
            $withEmail ? fn () => new TemplatedEmail('admin_contact_message', [
                'الاسم' => $m['name'], 'البريد' => $m['email'], 'الهاتف' => $m['phone'] ?? '—', 'الموضوع' => $m['subject'],
                'الرسالة' => $m['message'], 'الرابط' => $link,
            ], $m['email']) : null);
    }

    /** One automatic reply per address a day, whoever asks for it; the key holds an HMAC of the address, not the address. */
    private function reply(string $email, string $name): void
    {
        $key = 'contact:reply:'.hash_hmac('sha256', mb_strtolower($email), (string) config('app.key'));
        if (Cache::add($key, 1, (int) config('raqam.contact.reply_gap_hours') * 3600)) {
            SystemMail::queue(new TemplatedEmail('visitor_contact_reply', ['الاسم' => $name]), $email);
        }
    }
}
