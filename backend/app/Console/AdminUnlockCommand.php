<?php

namespace App\Console;

use App\Auth\AdminAudit;
use App\Auth\AdminSignIn;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AdminUnlockCommand extends Command
{
    protected $signature = 'admin:unlock {email : E-mail address that was locked out}';

    protected $description = 'Clear the sign-in limits of an e-mail address (emergency)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        foreach (AdminSignIn::emailLimitKeys($email) as $key) {
            RateLimiter::clear($key);
        }

        $user = User::query()->where('email', $email)->first();
        AdminAudit::account('update', null, $user, $user
            ? ['sign_in_limits' => 'cleared']
            : ['sign_in_limits' => 'cleared', 'email_sha256' => hash('sha256', $email)]);

        $this->info('Sign-in limits cleared for '.$email.($user ? '.' : ' (there is no such account).'));
        $this->line('The one-minute limit for this e-mail from a single IP address ends by itself.');

        return self::SUCCESS;
    }
}
