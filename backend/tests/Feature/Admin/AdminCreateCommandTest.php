<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

const GOOD_PASSWORD = 'a long enough passphrase';

beforeEach(fn () => fakePwned());

function createAdmin(string $email = 'new.admin@raqam.ps', string $name = 'Anas', array $passwords = [[GOOD_PASSWORD, GOOD_PASSWORD]])
{
    $command = test()->artisan('admin:create', ['email' => $email])->expectsQuestion('Name', $name);
    foreach ($passwords as [$password, $again]) {
        $command->expectsQuestion('Password (10 to 128 characters)', $password)->expectsQuestion('Password again', $again);
    }

    return $command;
}

test('it creates an active staff account with the admin role and a hashed password', function () {
    createAdmin()->expectsOutputToContain('new.admin@raqam.ps created')->assertSuccessful();

    $user = User::where('email', 'new.admin@raqam.ps')->sole();
    expect($user->user_type)->toBe('staff')
        ->and($user->is_active)->toBeTrue()
        ->and($user->name)->toBe('Anas')
        ->and(password_get_info($user->password_hash)['algoName'])->toBe('argon2id')
        ->and(Hash::check(GOOD_PASSWORD, $user->password_hash))->toBeTrue()
        ->and($user->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeTrue();

    $roles = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_id', $user->id)->pluck('roles.code')->all();
    expect($roles)->toBe(['admin']);
});

test('it is written to the audit log, without the password', function () {
    createAdmin()->assertSuccessful();

    $user = User::where('email', 'new.admin@raqam.ps')->sole();
    $row = DB::table('audit_log')->sole();
    expect($row->action)->toBe('create')
        ->and($row->actor_user_id)->toBeNull()                    // done from the command line
        ->and($row->subject_table)->toBe('users')->and($row->subject_id)->toBe($user->id)
        ->and($row->ip_hash)->toBeNull();
    expect(DB::table('audit_log_changes')->where('audit_id', $row->id)->pluck('new_value', 'field')->all())
        ->toBe(['role' => 'admin', 'created_with' => 'admin:create']);
    expect(json_encode([DB::table('audit_log')->get(), DB::table('audit_log_changes')->get()]))->not->toContain(GOOD_PASSWORD);
});

test('it never prints the password', function () {
    createAdmin()->doesntExpectOutputToContain(GOOD_PASSWORD)->assertSuccessful();
});

test('it refuses an e-mail that already exists, in any letter case, even a customer', function (string $email) {
    User::factory()->staff()->create(['email' => 'taken@raqam.ps']);
    User::factory()->create(['email' => 'client@raqam.ps']);

    $this->artisan('admin:create', ['email' => $email])
        ->expectsOutputToContain('already exists')->assertFailed();

    expect(User::count())->toBe(2);
})->with(['same' => ['taken@raqam.ps'], 'other case' => ['Taken@Raqam.PS'], 'a customer' => ['client@raqam.ps']]);

test('an address freed by a deleted account can be used again', function () {
    $old = User::factory()->staff()->create(['email' => 'back@raqam.ps']);
    $old->delete();

    createAdmin('back@raqam.ps')->assertSuccessful();

    expect(User::where('email', 'back@raqam.ps')->count())->toBe(1);
});

test('it refuses an invalid e-mail without asking anything', function () {
    $this->artisan('admin:create', ['email' => 'not-an-email'])->expectsOutputToContain('not valid')->assertFailed();
});

test('the two passwords must be the same', function () {
    createAdmin(passwords: [[GOOD_PASSWORD, 'another passphrase!'], [GOOD_PASSWORD, 'another passphrase!'], [GOOD_PASSWORD, 'another passphrase!']])
        ->expectsOutputToContain('not the same')->assertFailed();

    expect(User::where('email', 'new.admin@raqam.ps')->exists())->toBeFalse();
});

test('a mistyped repetition can be retried', function () {
    createAdmin(passwords: [[GOOD_PASSWORD, 'oops'], [GOOD_PASSWORD, GOOD_PASSWORD]])->assertSuccessful();

    expect(User::where('email', 'new.admin@raqam.ps')->exists())->toBeTrue();
});

test('the length is 10 to 128, inclusive', function (string $password, bool $accepted) {
    // Accepted at the first try; a refused one is asked again, three times in all.
    createAdmin(passwords: array_fill(0, $accepted ? 1 : 3, [$password, $password]))
        ->{$accepted ? 'assertSuccessful' : 'assertFailed'}();

    expect(User::where('email', 'new.admin@raqam.ps')->exists())->toBe($accepted);
})->with([
    '9 characters' => [str_repeat('a', 9), false],
    '10 characters' => ['abcdefghij', true],
    '128 characters' => [str_repeat('ab', 64), true],
    '129 characters' => [str_repeat('a', 129), false],
    '10 Arabic letters' => ['كلمةالسرها', true],
]);

test('a password found in a public leak is refused', function () {
    fakePwned(['password12345']);

    createAdmin(passwords: [['password12345', 'password12345'], [GOOD_PASSWORD, GOOD_PASSWORD]])
        ->expectsOutputToContain('تسريبات')->assertSuccessful();   // refused once, the second try is clean

    expect(Hash::check(GOOD_PASSWORD, User::where('email', 'new.admin@raqam.ps')->value('password_hash')))->toBeTrue();
});

test('when the leak service does not answer, nothing is created', function () {
    fakePwned(reachable: false);

    createAdmin(passwords: [[GOOD_PASSWORD, GOOD_PASSWORD], [GOOD_PASSWORD, GOOD_PASSWORD], [GOOD_PASSWORD, GOOD_PASSWORD]])
        ->expectsOutputToContain('تعذّر التحقق')->assertFailed();

    expect(User::where('email', 'new.admin@raqam.ps')->exists())->toBeFalse();
});

test('the name is required', function () {
    $this->artisan('admin:create', ['email' => 'x@raqam.ps'])->expectsQuestion('Name', '   ')->expectsOutputToContain('name is required')->assertFailed();
});

test('a failure leaves nothing behind: no account, no role, no audit row', function () {
    createAdmin(passwords: [[GOOD_PASSWORD, 'no'], [GOOD_PASSWORD, 'no'], [GOOD_PASSWORD, 'no']])->assertFailed();

    expect(User::count())->toBe(0)->and(DB::table('user_roles')->count())->toBe(0)->and(DB::table('audit_log')->count())->toBe(0);
});
