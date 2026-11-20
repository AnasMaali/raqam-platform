-- =====================================================================
--  Raqam schema — rule tests for 01-identity.sql
--  users, roles, permissions, verification codes, devices and login
--  attempts. Runs after schema_tests.sql in the same session; unique
--  values carry the prefix r01.
-- =====================================================================

-- ---------------------------------------------------------------- users
SELECT pg_temp.expect_fail('users: only staff and customer accounts exist',
  $$INSERT INTO users (user_type, name, email, password_hash) VALUES ('guest', 'r01', 'r01-guest@example.com', 'x')$$, 'users_user_type_check');
INSERT INTO users (user_type, name, email, password_hash) VALUES ('staff', 'r01 Staff', 'r01-staff@example.com', 'x');

-- ---------------------------------------------------------------- roles & permissions
SELECT pg_temp.expect_fail('roles: a code with digits or dashes is refused',
  $$INSERT INTO roles (code, name_ar) VALUES ('r01-editor', 'محرر')$$, 'roles_code_check');
SELECT pg_temp.expect_fail('roles: an upper-case code is refused',
  $$INSERT INTO roles (code, name_ar) VALUES ('Editor', 'محرر')$$, 'roles_code_check');
SELECT pg_temp.expect_fail('permissions: a code without module.action is refused',
  $$INSERT INTO permissions (code, name_ar) VALUES ('posts_edit', 'تعديل الأخبار')$$, 'permissions_code_check');
SELECT pg_temp.expect_fail('permissions: a code with digits is refused',
  $$INSERT INTO permissions (code, name_ar) VALUES ('r01.edit', 'تعديل')$$, 'permissions_code_check');

-- ---------------------------------------------------------------- verification_codes
SELECT pg_temp.expect_fail('verification_codes: an unknown purpose is refused',
  $$INSERT INTO verification_codes (purpose, email, code_hash, expires_at)
    VALUES ('r01_purpose', 'r01-staff@example.com', 'h', now() + interval '10 min')$$, 'verification_codes_purpose_check');
SELECT pg_temp.expect_fail('verification_codes: a code that expires when it is made is refused',
  $$INSERT INTO verification_codes (purpose, email, code_hash, created_at, expires_at)
    VALUES ('admin_login', 'r01-staff@example.com', 'h', now(), now())$$, 'verification_codes_check');
SELECT pg_temp.expect_fail('verification_codes: a code already expired at creation is refused',
  $$INSERT INTO verification_codes (purpose, email, code_hash, expires_at)
    VALUES ('admin_login', 'r01-staff@example.com', 'h', now() - interval '1 min')$$, 'verification_codes_check');
SELECT pg_temp.expect_fail('verification_codes: consumed before it was made is refused',
  $$INSERT INTO verification_codes (purpose, email, code_hash, created_at, expires_at, consumed_at)
    VALUES ('admin_login', 'r01-staff@example.com', 'h', now(), now() + interval '10 min', now() - interval '1 s')$$, 'verification_codes_check1');
INSERT INTO verification_codes (purpose, email, code_hash, created_at, expires_at, consumed_at)
VALUES ('admin_login', 'r01-staff@example.com', 'h', now(), now() + interval '10 min', now());

-- ---------------------------------------------------------------- user_devices
INSERT INTO user_devices (user_id, device_hash)
SELECT id, encode(sha256('r01-device-a'), 'hex') FROM users WHERE email = 'r01-staff@example.com';
SELECT pg_temp.expect_fail('user_devices: last seen before first seen is refused',
  $$INSERT INTO user_devices (user_id, device_hash, first_seen_at, last_seen_at)
    SELECT id, encode(sha256('r01-device-b'), 'hex'), now(), now() - interval '1 day'
    FROM users WHERE email = 'r01-staff@example.com'$$, 'user_devices_check');
SELECT pg_temp.expect_fail('user_devices: moving last seen back before first seen is refused',
  $$UPDATE user_devices SET last_seen_at = first_seen_at - interval '1 s'
    WHERE device_hash = encode(sha256('r01-device-a'), 'hex')$$, 'user_devices_check');

-- ---------------------------------------------------------------- login_attempts
SELECT pg_temp.expect_fail('login_attempts: an unknown failure reason is refused',
  $$INSERT INTO login_attempts (email, succeeded, failure_reason) VALUES ('r01-staff@example.com', false, 'r01_unknown')$$, 'login_attempts_failure_reason_check');
INSERT INTO login_attempts (email, succeeded, failure_reason) VALUES ('r01-staff@example.com', false, 'bad_code');
