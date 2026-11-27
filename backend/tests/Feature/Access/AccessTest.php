<?php

use App\Access\Access;
use App\Access\AccessRefused;
use App\Access\PermissionCatalog;
use App\Models\User;
use App\Settings\FeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    ensure('roles', ['code' => 'admin', 'name_ar' => 'مدير المنصة', 'is_system' => true]);
    DB::table('feature_flags')->insertOrIgnore(['key' => 'multi_admin', 'description_ar' => 'أكثر من مستخدم وأدوار لفريق الإدارة', 'is_enabled' => false]);
    $this->artisan('permissions:install')->assertSuccessful();
    $this->access = app(Access::class);
    $this->admin = User::query()->find(insertUser('staff', 'admin@raqam.example'));
    DB::table('user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => DB::table('roles')->where('code', 'admin')->value('id')]);
});

function editorRole(array $permissions): void
{
    $role = DB::table('roles')->insertGetId(['code' => 'editor', 'name_ar' => 'محرر']);
    foreach ($permissions as $code) {
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
    }
}

test('permissions:install writes the catalogue once, in the database\'s own shape, and never removes one', function () {
    expect(DB::table('permissions')->pluck('name_ar', 'code')->all())->toEqual(PermissionCatalog::PERMISSIONS);
    DB::table('permissions')->insert(['code' => 'legacy.thing', 'name_ar' => 'قديم']);
    $this->artisan('permissions:install')->expectsOutput('permissions added: 0')->assertSuccessful();
    expect(DB::table('permissions')->count())->toBe(count(PermissionCatalog::PERMISSIONS) + 1);
});

test('the admin may do everything in the catalogue, through the gate too, and nothing outside it', function () {
    foreach (array_keys(PermissionCatalog::PERMISSIONS) as $code) {
        expect($this->access->can($this->admin, $code))->toBeTrue($code)
            ->and(Gate::forUser($this->admin)->allows($code))->toBeTrue($code);
    }
    expect($this->access->can($this->admin, 'nothing.here'))->toBeFalse();
});

test('a staff account without a role, a stopped or deleted admin, a customer and nobody may do nothing', function () {
    $plain = User::query()->find(insertUser('staff', 'plain@raqam.example'));
    $customer = User::query()->find(insertUser('customer', 'c@example.com'));
    expect($this->access->can($plain, 'posts.manage'))->toBeFalse()
        ->and($this->access->can($customer, 'posts.manage'))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('posts.manage'))->toBeFalse()
        ->and($this->access->can(null, 'posts.manage'))->toBeFalse();

    // A role row left on an account that is no longer staff (changed behind the services' back) gives nothing.
    DB::table('users')->where('id', $this->admin->id)->update(['user_type' => 'customer']);
    expect($this->access->can($this->admin->fresh(), 'posts.manage'))->toBeFalse();
    DB::table('users')->where('id', $this->admin->id)->update(['user_type' => 'staff', 'is_active' => false]);
    expect($this->access->can($this->admin->fresh(), 'posts.manage'))->toBeFalse();
    DB::table('users')->where('id', $this->admin->id)->update(['is_active' => true, 'deleted_at' => now()]);
    expect($this->access->can(User::withTrashed()->find($this->admin->id), 'posts.manage'))->toBeFalse();
});

test('another role grants nothing and cannot be given while multi_admin is off; when on it grants its own permissions only', function () {
    editorRole(['posts.manage']);
    $editor = User::query()->find(insertUser('staff', 'editor@raqam.example'));

    expect(fn () => $this->access->assign($editor, 'editor', $this->admin))->toThrow(AccessRefused::class, 'أدوار فريق الإدارة غير مفعّلة');
    DB::table('user_roles')->insert(['user_id' => $editor->id, 'role_id' => DB::table('roles')->where('code', 'editor')->value('id')]);   // written behind the service's back
    expect($this->access->can($editor, 'posts.manage'))->toBeFalse();

    DB::table('feature_flags')->where('key', 'multi_admin')->update(['is_enabled' => true]);
    Access::forget();   // switched behind the service's back: seen from the next request on
    expect($this->access->can($editor, 'posts.manage'))->toBeTrue()
        ->and($this->access->can($editor, 'settings.manage'))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('settings.manage'))->toBeFalse();
});

test('a customer can never hold a role (the database refuses it)', function () {
    $customer = User::query()->find(insertUser('customer', 'c@example.com'));
    expect(fn () => $this->access->assign($customer, 'admin', $this->admin))->toThrow(AccessRefused::class, 'الأدوار لموظفي الإدارة فقط');
    expect(DB::table('user_roles')->where('user_id', $customer->id)->exists())->toBeFalse();
});

test('the last active admin keeps the admin role; with two, one can go, and each change is audited', function () {
    expect(fn () => $this->access->revoke($this->admin, 'admin', $this->admin))->toThrow(AccessRefused::class, 'آخر مدير فعّال');

    $second = User::query()->find(insertUser('staff', 'second@raqam.example'));
    $this->access->assign($second, 'admin', $this->admin);
    $this->access->assign($second, 'admin', $this->admin);   // already held: nothing new, nothing audited
    DB::table('users')->where('id', $second->id)->update(['is_active' => false]);
    expect(fn () => $this->access->revoke($this->admin, 'admin', $second))->toThrow(AccessRefused::class);   // a stopped admin does not count

    DB::table('users')->where('id', $second->id)->update(['is_active' => true]);
    $this->access->revoke($this->admin, 'admin', $second);
    expect($this->access->hasRole($this->admin->id, 'admin'))->toBeFalse();

    expect(DB::table('audit_log_changes')->orderBy('audit_id')->get(['field', 'new_value'])->map(fn ($c) => "{$c->field}={$c->new_value}")->all())
        ->toBe(['role_added=admin', 'role_removed=admin']);
});

/** The queries the service sends to the roles tables while $ask runs. */
function accessQueries(callable $ask): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count) {
        $count += preg_match('/\b(user_roles|feature_flags)\b/', $query->sql);
    });
    $ask();

    return $count;
}

test('within one request each answer is asked of the database once, however often the screens ask', function () {
    editorRole(['posts.manage']);
    $editor = User::query()->find(insertUser('staff', 'editor@raqam.example'));
    DB::table('user_roles')->insert(['user_id' => $editor->id, 'role_id' => DB::table('roles')->where('code', 'editor')->value('id')]);
    DB::table('feature_flags')->where('key', 'multi_admin')->update(['is_enabled' => true]);
    Access::forget();

    $asked = accessQueries(function () use ($editor) {
        foreach (range(1, 5) as $_) {
            expect(app(Access::class)->can($this->admin, 'taxonomy.manage'))->toBeTrue()
                ->and(Gate::forUser($this->admin)->allows('videos.manage'))->toBeTrue()
                ->and(app(Access::class)->mayEnterPanel($this->admin))->toBeTrue()
                ->and(app(Access::class)->can($editor, 'posts.manage'))->toBeTrue()
                ->and(app(Access::class)->can($editor, 'taxonomy.manage'))->toBeFalse();
        }
    });
    // The admin's role, the switch, whether the admin holds any role (the panel's rule once the switch is on); the
    // editor's admin role, posts.manage, taxonomy.manage.
    expect($asked)->toBe(6);
});

test('a role given or taken through the service, or the switch moved, counts at once in the same request', function () {
    editorRole(['posts.manage']);
    $editor = User::query()->find(insertUser('staff', 'editor@raqam.example'));
    $flags = app(FeatureFlags::class);

    expect($this->access->can($editor, 'posts.manage'))->toBeFalse()->and($this->access->mayEnterPanel($editor))->toBeFalse();
    $flags->set('multi_admin', true, $this->admin->id, fromServer: true);
    expect($this->access->can($editor, 'posts.manage'))->toBeFalse();

    $this->access->assign($editor, 'editor', $this->admin);
    expect($this->access->can($editor, 'posts.manage'))->toBeTrue()->and($this->access->mayEnterPanel($editor))->toBeTrue();

    $flags->set('multi_admin', false, $this->admin->id);
    expect($this->access->can($editor, 'posts.manage'))->toBeFalse()->and($this->access->mayEnterPanel($editor))->toBeFalse();

    $flags->set('multi_admin', true, $this->admin->id, fromServer: true);
    expect($this->access->can($editor, 'posts.manage'))->toBeTrue()->and($this->access->mayEnterPanel($editor))->toBeTrue();
    $this->access->revoke($editor, 'editor', $this->admin);
    expect($this->access->can($editor, 'posts.manage'))->toBeFalse()->and($this->access->mayEnterPanel($editor))->toBeFalse();
});

test('a role given inside a transaction that is then undone gives nothing afterwards', function () {
    $second = User::query()->find(insertUser('staff', 'second@raqam.example'));

    try {
        DB::transaction(function () use ($second) {
            $this->access->assign($second, 'admin', $this->admin);
            expect($this->access->can($second, 'posts.manage'))->toBeTrue();
            throw new RuntimeException('undone');
        });
    } catch (RuntimeException) {
    }

    expect($this->access->can($second, 'posts.manage'))->toBeFalse();
});

test('the account itself is read each time: an admin stopped in this request may do nothing at once', function () {
    expect($this->access->can($this->admin, 'posts.manage'))->toBeTrue();
    $this->admin->is_active = false;
    expect($this->access->can($this->admin, 'posts.manage'))->toBeFalse();
});
