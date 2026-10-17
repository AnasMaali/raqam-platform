<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

/**
 * Refuses a password that appears in public breaches (decision 2.9), through the k-anonymity range
 * service: only the first five characters of the SHA-1 leave the server. Unlike Laravel's own rule,
 * an unreachable service is a refusal, not a pass: setting an admin password is rare and can wait.
 */
class NotLeaked implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $hash = strtoupper(sha1((string) $value));

        try {
            $response = Http::withHeaders(['Add-Padding' => 'true'])->timeout(5)
                ->get('https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5));
        } catch (\Throwable) {
            $response = null;
        }

        if (! $response?->successful()) {
            $fail('تعذّر التحقق من كلمة المرور الآن، حاول بعد قليل.');

            return;
        }

        foreach (explode("\n", $response->body()) as $line) {
            [$suffix, $count] = array_pad(explode(':', trim($line)), 2, '0');
            if (strcasecmp(substr($hash, 5), $suffix) === 0 && (int) $count > 0) {
                $fail('كلمة المرور هذه ظهرت في تسريبات علنية، اختر غيرها.');

                return;
            }
        }
    }
}
