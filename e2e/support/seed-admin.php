<?php

// Creates the one admin account of the browser-test template database. Arguments: e-mail, password.
// The password is generated for each run by the test setup; nothing is stored in the repository.

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../backend/vendor/autoload.php';

$app = require __DIR__.'/../../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $email, $password] = $argv;

DB::transaction(function () use ($email, $password) {
    $user = new User(['name' => 'مدير الاختبار', 'email' => $email, 'password_hash' => $password]);
    $user->forceFill(['user_type' => 'staff', 'is_active' => true])->save();
    DB::table('user_roles')->insert([
        'user_id' => $user->id,
        'role_id' => DB::table('roles')->where('code', 'admin')->value('id'),
    ]);
});
