<?php

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** Helpers for the e-mail tests: what the "array" mailer kept, and a mailer that always fails. */

/** @return list<Email> every message the array mailer delivered, oldest first */
function sentEmails(): array
{
    /** @var ArrayTransport $transport */
    $transport = Mail::mailer('array')->getSymfonyTransport();

    return $transport->messages()->map(fn (SentMessage $m) => $m->getOriginalMessage())->values()->all();
}

/** @return list<Email> */
function sentEmailsTo(string $address): array
{
    return array_values(array_filter(sentEmails(), fn (Email $m) => collect($m->getTo())->contains(fn ($a) => strcasecmp($a->getAddress(), $address) === 0)));
}

/** Every message now fails with this text (an SMTP refusal), $failures times, then goes through. */
function failingMailer(string $message = 'Expected response code "235" but got code "535", with message "535 authentication failed".', int $failures = PHP_INT_MAX): void
{
    $transport = new class($message, $failures) extends AbstractTransport
    {
        public int $tries = 0;

        public function __construct(private string $text, private int $failures)
        {
            parent::__construct();
        }

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $this->tries++;
            if ($this->tries <= $this->failures) {
                throw new TransportException($this->text);
            }

            return parent::send($message, $envelope);
        }

        protected function doSend(SentMessage $message): void {}

        public function __toString(): string
        {
            return 'failing://';
        }
    };
    app()->instance('test.failing-transport', $transport);
    Mail::extend('failing', fn () => app('test.failing-transport'));
    config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
    Mail::purge('failing');
}

function staffAdmin(string $email = 'admin@raqam.example', array $extra = []): int
{
    return insertUser('staff', $email, $extra);
}
