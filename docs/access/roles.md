# Admin-team roles (decision 2.7) — founded, one role in use

Roles and permissions live in the database (`roles`, `permissions`, `role_permissions`, `user_roles`). There is no
screen for them. In V1 the only role in use is `admin` («مدير المنصة»), given by `admin:create`.

- `App\Access\PermissionCatalog` — the permissions, one per part of the panel, written `module.action`.
  `php artisan permissions:install` writes the missing ones (never removes or renames); run it at every deploy.
- `App\Access\Access`
  - `can($user, $permission)`: only a live, active **staff** account holds anything. `admin` holds every permission by
    rule (a permission added later is the admin's at once). Any other role grants its own permissions **only while the
    `multi_admin` switch is on**.
  - `assign()` / `revoke()`: a role other than `admin` cannot be given while `multi_admin` is off; the database refuses
    any role to a customer; the last active admin cannot lose `admin`. Each change is written to the audit log.
- The gate answers every ability that is a permission code: `Gate::allows('posts.manage')`, `@can('posts.manage')`.
  Any other ability is left to the rest of the gate. The panel's own entry check (live, active staff) is unchanged.
