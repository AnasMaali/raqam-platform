-- =====================================================================
--  Coverage: every rule the database enforces has a test that broke it.
--  Runs last, in the same session as schema_tests.sql and rules/*.sql.
--  A CHECK, a domain's CHECK or an EXCLUDE counts only when a statement
--  was refused by that very constraint (its name, from the error); a
--  trigger function counts when it raised the error, or when
--  expect_trigger checked what it does.
-- =====================================================================
CREATE TEMP VIEW rule_items AS
  SELECT 'check' AS kind, c.conname::text AS name, c.conrelid::regclass::text AS owner
  FROM pg_constraint c
  WHERE c.connamespace = 'public'::regnamespace AND c.contype = 'c' AND c.conrelid <> 0
  UNION ALL
  SELECT 'domain', c.conname::text, c.contypid::regtype::text
  FROM pg_constraint c
  WHERE c.connamespace = 'public'::regnamespace AND c.contypid <> 0
  UNION ALL
  SELECT 'exclude', c.conname::text, c.conrelid::regclass::text
  FROM pg_constraint c
  WHERE c.connamespace = 'public'::regnamespace AND c.contype = 'x'
  UNION ALL
  SELECT 'trigger', p.proname::text, string_agg(DISTINCT g.tgrelid::regclass::text, ', ' ORDER BY g.tgrelid::regclass::text)
  FROM pg_trigger g JOIN pg_proc p ON p.oid = g.tgfoid
  WHERE NOT g.tgisinternal AND p.pronamespace = 'public'::regnamespace
  GROUP BY p.proname;

-- Rules no statement can make the refusing one, because another rule always refuses the same rows first. Each counts
-- when that other rule was broken, and the reason is written here (docs/qa/database.md lists them).
CREATE TEMP TABLE rule_stand_ins (name text PRIMARY KEY, stand_in text NOT NULL, reason text NOT NULL);
INSERT INTO rule_stand_ins VALUES
  ('settings_check5', 'email_address_check',
   'value::email_address: a bad e-mail fails the cast, refused by the domain, before the CHECK is false'),
  ('settings_check6', 'phone_e164_check',
   'value::phone_e164: a bad phone fails the cast, refused by the domain, before the CHECK is false'),
  ('rate_overrides_sell_rate_check', 'rate_overrides_check',
   'sell_rate > 0 follows from buy_rate > 0 and sell_rate >= buy_rate, which PostgreSQL checks first (by name)');

INSERT INTO test_results (label, ok, detail)
SELECT format('coverage: %s %s (%s) has a test that breaks it', i.kind, i.name, i.owner), false, 'no test broke it'
FROM rule_items i
WHERE NOT EXISTS (SELECT 1 FROM test_results r WHERE r.ok AND r.broke = i.name)
  AND NOT EXISTS (SELECT 1 FROM rule_stand_ins x JOIN test_results r ON r.ok AND r.broke = x.stand_in WHERE x.name = i.name)
ORDER BY i.kind, i.owner, i.name;

-- A stand-in for a rule that no longer exists, or that a test now reaches itself, is out of date.
INSERT INTO test_results (label, ok, detail)
SELECT format('coverage: the stand-in for %s is still needed', x.name), false, 'remove it from rule_stand_ins'
FROM rule_stand_ins x
WHERE NOT EXISTS (SELECT 1 FROM rule_items i WHERE i.name = x.name)
   OR EXISTS (SELECT 1 FROM test_results r WHERE r.ok AND r.broke = x.name);

-- ---------------------------------------------------------------- report
\o
\set QUIET off
SELECT n AS "#", CASE WHEN ok THEN 'PASS' ELSE 'FAIL' END AS result, label, left(detail, 90) AS detail
FROM test_results ORDER BY n;
SELECT kind, count(*) AS rules,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM test_results r WHERE r.ok AND r.broke = i.name)) AS covered,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM rule_stand_ins x WHERE x.name = i.name)) AS by_stand_in
FROM rule_items i GROUP BY kind ORDER BY kind;
SELECT count(*) FILTER (WHERE ok) AS passed, count(*) FILTER (WHERE NOT ok) AS failed FROM test_results;
