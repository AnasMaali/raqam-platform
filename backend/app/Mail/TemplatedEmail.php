<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;

/**
 * A system message made from its editable template (20.6, 20.7): the RTL frame of Raqam around the admin's text, and
 * a plain-text copy. The template is read when the message is sent, so the newest wording is the one that goes out.
 * Send it with App\Mail\SystemMail, which logs it (20.9) and retries it (20.8).
 */
class TemplatedEmail extends Mailable
{
    /** @param  array<string, scalar|null>  $vars  variable name (without braces) => value */
    public function __construct(public readonly string $templateKey, public readonly array $vars = [], public readonly ?string $replyToAddress = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: self::sender(EmailCatalog::get($this->templateKey)['sender']),
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
            subject: $this->rendered()['subject'],
        );
    }

    public function content(): Content
    {
        $rendered = $this->rendered();

        return new Content(view: 'mail.system', text: 'mail.system-text', with: [
            'bodyHtml' => $rendered['html'], 'bodyText' => $rendered['text'], 'siteName' => self::siteName(),
        ]);
    }

    /** The address a message of this sender comes from (20.1): the setting, or the mailer's default address. */
    public static function sender(string $sender): Address
    {
        $setting = EmailCatalog::SENDERS[$sender] ?? EmailCatalog::SENDERS['no_reply'];
        $address = DB::table('settings')->where('key', $setting)->value('value') ?: config('mail.from.address');

        return new Address((string) $address, self::siteName());
    }

    public static function siteName(): string
    {
        return TemplateRenderer::line((string) (DB::table('settings')->where('key', 'general.site_name')->value('value') ?: config('mail.from.name')));
    }

    /** @return array{subject: string, html: string, text: string} */
    public function rendered(): array
    {
        return TemplateRenderer::render($this->templateKey, $this->vars);
    }
}
