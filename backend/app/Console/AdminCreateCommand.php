<?php

namespace App\Console;

use App\Auth\AdminAudit;
use App\Auth\AdminPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminCreateCommand extends Command
{
    protected $signature = 'admin:create {email : E-mail address of the new admin}';

    protected $description = 'Create an admin account (asks for the name and the password)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        $emailError = Validator::make(['email' => $email], ['email' => ['required', 'email:strict', 'max:254']])->errors()->first();
        if ($emailError) {
            $this->error('The e-mail address is not valid.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('An account with this e-mail already exists.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        if ($name === '' || mb_strlen($name) > 120) {
            $this->error('The name is required and at most 120 characters.');

            return self::FAILURE;
        }

        if (($password = $this->askForPassword()) === null) {
            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($email, $name, $password) {
            $user = new User(['name' => $name, 'email' => $email, 'password_hash' => $password]);
            $user->forceFill(['user_type' => 'staff', 'is_active' => true])->save();
            DB::table('user_roles')->insert([
                'user_id' => $user->id,
                'role_id' => DB::table('roles')->where('code', 'admin')->value('id'),
            ]);

            return $user;
        });

        AdminAudit::account('create', null, $user, ['role' => 'admin', 'created_with' => 'admin:create']);

        $this->info("Admin {$user->email} created.");

        return self::SUCCESS;
    }

    private function askForPassword(): ?string
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $password = (string) $this->secret('Password ('.AdminPassword::MIN.' to '.AdminPassword::MAX.' characters)');
            $again = (string) $this->secret('Password again');

            if ($password !== $again) {
                $this->error('The two passwords are not the same.');

                continue;
            }

            $error = Validator::make(['password' => $password], ['password' => AdminPassword::rules()], AdminPassword::messages())->errors()->first();
            if ($error) {
                $this->error($error);

                continue;
            }

            return $password;
        }

        $this->error('No valid password after 3 tries.');

        return null;
    }
}
