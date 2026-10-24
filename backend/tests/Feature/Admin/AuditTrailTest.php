<?php

use App\Audit\Audited;
use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** A throw-away model on a real table, to exercise the trait every future content model will use. */
class AuditedCategory extends Model
{
    use Audited;

    protected $table = 'categories';

    protected $guarded = [];
}

class AuditedUser extends Model
{
    use Audited, SoftDeletes;

    protected $table = 'users';

    protected $guarded = [];
}

function changesOf(int $auditId): array
{
    return DB::table('audit_log_changes')->where('audit_id', $auditId)->get()
        ->mapWithKeys(fn ($row) => [$row->field => [$row->old_value, $row->new_value]])->all();
}

beforeEach(function () {
    $this->admin = User::factory()->staff()->create();
    config(['raqam.tracking_key' => 'test-only-tracking-key-0123456789abcdef']);
});

test('creating a model records who, what table, and every field as "after"', function () {
    $this->actingAs($this->admin);

    $category = AuditedCategory::create(['name_ar' => 'طاقة', 'slug' => 'energy']);

    $row = DB::table('audit_log')->sole();
    expect($row->action)->toBe('create')
        ->and($row->actor_user_id)->toBe($this->admin->id)
        ->and($row->subject_table)->toBe('categories')
        ->and($row->subject_id)->toBe($category->id);
    expect(changesOf($row->id))->toMatchArray(['name_ar' => [null, 'طاقة'], 'slug' => [null, 'energy']]);
});

test('saving without a real change leaves no trace', function () {
    $category = AuditedCategory::create(['name_ar' => 'طاقة', 'slug' => 'energy']);
    $before = DB::table('audit_log')->count();

    $category->update(['name_ar' => 'طاقة']);
    $category->touch();

    expect(DB::table('audit_log')->count())->toBe($before);
});

test('an update records the old and the new value of the changed field only', function () {
    $category = AuditedCategory::create(['name_ar' => 'طاقة', 'slug' => 'energy']);

    $category->update(['name_ar' => 'الطاقة']);

    $row = DB::table('audit_log')->where('action', 'update')->sole();
    expect(changesOf($row->id))->toBe(['name_ar' => ['طاقة', 'الطاقة']]);
});

test('a delete is recorded, and so are a soft delete and a restore', function () {
    $category = AuditedCategory::create(['name_ar' => 'طاقة', 'slug' => 'energy']);
    $category->delete();
    expect(DB::table('audit_log')->orderBy('id')->pluck('action')->all())->toBe(['create', 'delete']);

    $user = AuditedUser::create(['user_type' => 'staff', 'name' => 'X', 'email' => 'x@raqam.ps', 'password_hash' => 'x']);
    $user->delete();
    $user->restore();

    expect(DB::table('audit_log')->where('subject_table', 'users')->orderBy('id')->pluck('action')->all())->toBe(['create', 'delete', 'restore']);
});

test('a password hash or any secret is never written to the log, only that it changed', function () {
    $user = AuditedUser::create(['user_type' => 'staff', 'name' => 'X', 'email' => 'x@raqam.ps', 'password_hash' => 'super-secret-hash']);
    $user->update(['password_hash' => 'another-secret-hash']);

    $everything = DB::table('audit_log_changes')->get()->flatMap(fn ($r) => [$r->old_value, $r->new_value])->filter()->implode(' ');
    expect($everything)->not->toContain('secret-hash');

    $update = DB::table('audit_log')->where('action', 'update')->sole();
    expect(changesOf($update->id)['password_hash'])->toBe([AuditLogger::HIDDEN, AuditLogger::HIDDEN]);
});

test('fields named like secrets are hidden whatever the model', function (string $field) {
    $id = AuditLogger::record('update', 'users', 1, [$field => ['old-value-1', 'new-value-2']]);

    expect(changesOf($id)[$field])->toBe([AuditLogger::HIDDEN, AuditLogger::HIDDEN]);
})->with(['password', 'password_hash', 'api_key', 'client_secret', 'remember_token', 'code_hash', 'otp', 'private_key', 'signature']);

test('ordinary fields are kept as they are', function () {
    $id = AuditLogger::record('update', 'posts', 1, ['title' => ['قديم', 'جديد'], 'is_featured' => [false, true]]);

    expect(changesOf($id))->toBe(['title' => ['قديم', 'جديد'], 'is_featured' => ['false', 'true']]);
});

test('a very long value is shortened, not stored whole', function () {
    $id = AuditLogger::record('update', 'posts', 1, ['body' => [null, str_repeat('ب', 5000)]]);

    expect(mb_strlen(changesOf($id)['body'][1]))->toBe(2001);
});

test('the address is stored as an HMAC and never as the address', function () {
    $this->actingAs($this->admin);
    request()->server->set('REMOTE_ADDR', '203.0.113.77');
    request()->headers->set('User-Agent', 'Mozilla/5.0 Test');

    AuditLogger::record('update', 'posts', 1);

    $row = DB::table('audit_log')->sole();
    expect($row->ip_hash)->toMatch('/^[0-9a-f]{64}$/')->and($row->ip_hash)->not->toContain('203.0.113.77')
        ->and($row->user_agent)->toBe('Mozilla/5.0 Test');
});

test('from the command line there is no actor, address or browser', function () {
    AuditLogger::record('update', 'posts', 1);

    $row = DB::table('audit_log')->sole();
    expect($row->actor_user_id)->toBeNull()->and($row->ip_hash)->toBeNull()->and($row->user_agent)->toBeNull();
});

test('the log cannot be edited or deleted, not even by the code that wrote it', function () {
    $id = AuditLogger::record('update', 'posts', 1, ['title' => ['a', 'b']]);

    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $id)->update(['action' => 'delete'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('audit_log')->where('id', $id)->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('audit_log_changes')->where('audit_id', $id)->update(['new_value' => 'x'])))->toThrow(QueryException::class);
});

test('a failed audit write fails the whole entry: nothing half-written is left', function () {
    expect(fn () => AuditLogger::record('not-an-action', 'posts', 1, ['title' => ['a', 'b']]))->toThrow(QueryException::class);

    expect(DB::table('audit_log')->count())->toBe(0)->and(DB::table('audit_log_changes')->count())->toBe(0);
});
