<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('Argon2id is the default hash driver', function () {
    expect(config('hashing.driver'))->toBe('argon2id')
        ->and(password_get_info(Hash::make('a-long-password'))['algoName'])->toBe('argon2id');
});

test('a password is stored as an Argon2id hash in password_hash', function () {
    $user = User::factory()->staff()->create(['password_hash' => 'correct horse battery']);

    expect(password_get_info($user->password_hash)['algoName'])->toBe('argon2id')
        ->and($user->password_hash)->not->toContain('correct horse battery')
        ->and(Hash::check('correct horse battery', $user->password_hash))->toBeTrue();
});

test('the password hash is never serialized', function () {
    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKeys(['password_hash', 'remember_token']);
});

test('a user signs in with the password checked against password_hash', function () {
    User::factory()->staff()->create(['email' => 'admin@raqam.ps', 'password_hash' => 'correct horse battery']);

    expect(Auth::attempt(['email' => 'Admin@Raqam.PS', 'password' => 'correct horse battery']))->toBeTrue()
        ->and(Auth::user()->isStaff())->toBeTrue();
});

test('a wrong password is refused', function () {
    User::factory()->staff()->create(['email' => 'admin@raqam.ps', 'password_hash' => 'correct horse battery']);

    expect(Auth::attempt(['email' => 'admin@raqam.ps', 'password' => 'wrong horse battery']))->toBeFalse();
});

test('a deleted account cannot sign in', function () {
    $user = User::factory()->staff()->create(['email' => 'admin@raqam.ps', 'password_hash' => 'correct horse battery']);
    $user->delete();

    expect($user->fresh()->trashed())->toBeTrue()
        ->and(Auth::attempt(['email' => 'admin@raqam.ps', 'password' => 'correct horse battery']))->toBeFalse();
});

test('user_type cannot be mass assigned', function () {
    $user = User::factory()->create();
    $user->fill(['user_type' => 'staff'])->save();

    expect($user->fresh()->user_type)->toBe('customer');
});
