-- =====================================================================
--  Raqam schema — rule tests for 04-taxonomy-people.sql
--  Breaks the CHECKs of sections, categories, eras and guest_roles that
--  schema_tests.sql does not reach. Runs in the same session, after
--  schema_tests.sql; every value this file inserts carries "r04".
-- =====================================================================

-- ---------------------------------------------------------------- sections
SELECT pg_temp.expect_fail('sections: key outside the seven sections',
  $$INSERT INTO sections (key, name_ar, slug) VALUES ('r04_market', 'r04 قسم', 'r04-section')$$, 'sections_key_check');

-- ---------------------------------------------------------------- categories
INSERT INTO categories (name_ar, slug) VALUES ('r04 تصنيف', 'r04-category');
INSERT INTO categories (name_ar, slug) VALUES ('r04 تصنيف آخر', 'r04-category-other');
SELECT pg_temp.expect_fail('categories: a category cannot be its own parent',
  $$UPDATE categories SET parent_id = id WHERE slug = 'r04-category'$$, 'categories_check');
-- The same update with another category as the parent is accepted.
BEGIN;
UPDATE categories SET parent_id = (SELECT id FROM categories WHERE slug = 'r04-category-other')
WHERE slug = 'r04-category';
ROLLBACK;

-- ---------------------------------------------------------------- eras
INSERT INTO eras (name_ar, year_from, year_to) VALUES ('r04 حقبة', 1900, 1948);
INSERT INTO eras (name_ar, year_from, year_to) VALUES ('r04 حقبة سنة واحدة', 1950, 1950);
SELECT pg_temp.expect_fail('eras: end year before the start year',
  $$INSERT INTO eras (name_ar, year_from, year_to) VALUES ('r04 حقبة مقلوبة', 1948, 1900)$$, 'eras_check');

-- ---------------------------------------------------------------- guest roles
SELECT pg_temp.expect_fail('guest_roles: code must be lower-case latin letters only',
  $$INSERT INTO guest_roles (code, name_ar) VALUES ('Moderator', 'r04 مدير الجلسة')$$, 'guest_roles_code_check');
