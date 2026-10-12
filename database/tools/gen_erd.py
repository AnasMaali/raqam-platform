#!/usr/bin/env python3
"""Generate per-module Mermaid ER diagrams from the live PostgreSQL catalog.

Usage: python3 tools/gen_erd.py <database> <out_dir>
Writes <out_dir>/erd.json (modules + mermaid source) used by the docs build.
The diagrams are generated from the database itself, so they can never
drift from raqam_schema.sql.
"""
import json
import os
import re
import subprocess
import sys

def psql_cmd(db):
    """psql over TCP when PGHOST is set (CI), else as the local postgres user."""
    if os.environ.get("PGHOST"):
        return ["psql", "-v", "ON_ERROR_STOP=1", "-d", db, "-At"]
    return ["su", "postgres", "-c", f"psql -v ON_ERROR_STOP=1 -d {db} -At"]


DB, OUT = sys.argv[1], sys.argv[2]

MODULES = [
    ("identity", "الهوية والصلاحيات", "Identity & access",
     ["users", "roles", "permissions", "role_permissions", "user_roles",
      "verification_codes", "user_devices", "login_attempts"]),
    ("platform", "المنصة والإعدادات", "Platform & settings",
     ["settings", "feature_flags", "data_providers", "provider_health",
      "social_platforms", "site_social_links", "seo_meta", "document_counters"]),
    ("media", "الوسائط", "Media", ["media_assets", "media_variants"]),
    ("reference", "البيانات المرجعية", "Reference data",
     ["currencies", "cities", "sectors", "organizations"]),
    ("taxonomy", "الأقسام والتصنيفات والأشخاص", "Taxonomy & people",
     ["sections", "categories", "tags", "eras", "authors", "author_links",
      "guests", "guest_links", "guest_roles"]),
    ("content", "المحتوى", "Content",
     ["post_kinds", "posts", "post_authors", "post_tags", "post_guests",
      "post_related", "post_gallery_items", "post_body_media", "post_body_videos",
      "post_revisions", "pages"]),
    ("subtypes", "أنواع المواد الخاصة", "Post subtypes",
     ["podcast_seasons", "podcast_episodes", "success_stories", "success_story_figures",
      "products", "history_entries", "initiatives", "roundtable_sessions",
      "awareness_cards", "infographics"]),
    ("video", "الفيديو والشخصيات", "Video & characters",
     ["videos", "youtube_videos", "video_chapters", "playlists", "playlist_videos",
      "characters", "pose_types", "character_poses", "awareness_types"]),
    ("markets", "العملات والطقس", "Currency & weather",
     ["rate_board_currencies", "exchange_rates", "currency_pegs",
      "rate_overrides", "weather_locations", "weather_conditions",
      "weather_observations", "weather_forecasts"]),
    ("ads", "الإعلانات", "Advertising",
     ["ad_placements", "house_ads", "advertisers", "ad_campaigns", "ad_creatives",
      "ad_campaign_placements", "ad_stats_daily"]),
    ("booking", "الخدمات والحجز", "Services & booking",
     ["service_groups", "services", "service_deliverables", "faqs", "customers",
      "booking_statuses", "booking_status_transitions", "bookings",
      "booking_status_history", "booking_attachments"]),
    ("payment", "الدفع والفواتير", "Payments & invoices",
     ["payment_gateways", "payments", "payment_events", "invoices",
      "invoice_lines", "refunds"]),
    ("accounts", "حسابات العملاء", "Customer accounts",
     ["saved_posts", "followed_sections", "followed_categories"]),
    ("chatbot", "الشات بوت", "Chatbot",
     ["bot_entries", "bot_entry_variants", "bot_entry_keywords", "bot_quick_replies",
      "bot_entry_quick_replies", "bot_conversations", "bot_messages"]),
    ("messaging", "النشرة والرسائل", "Newsletter & messaging",
     ["subscribers", "newsletter_issues", "newsletter_issue_posts", "email_templates",
      "email_log", "admin_notifications", "contact_messages"]),
    ("navigation", "SEO والبحث والتنقل", "SEO, search & navigation",
     ["redirects", "synonym_groups", "synonym_terms", "search_terms",
      "search_stats_daily", "menus", "menu_items", "home_blocks"]),
    ("analytics", "الإحصائيات وسجل العمليات", "Analytics & audit",
     ["post_views_daily", "site_stats_daily", "traffic_sources", "traffic_sources_daily",
      "device_stats_daily", "event_types", "event_stats_daily",
      "service_contact_clicks_daily", "video_plays_daily", "not_found_paths",
      "audit_log", "audit_log_changes"]),
]

# Referenced almost everywhere (created_by, cover image, SEO). Drawn only in
# their own module to keep the other diagrams readable.
UBIQUITOUS = {"users", "media_assets", "seo_meta"}

CATALOG_SQL = r"""
WITH cols AS (
  SELECT c.relname AS tbl, a.attname AS col, a.attnum AS pos,
         format_type(a.atttypid, a.atttypmod) AS typ, a.attnotnull AS notnull,
         a.attgenerated <> '' AS generated
  FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
  WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'r'
    AND a.attnum > 0 AND NOT a.attisdropped),
cons AS (
  SELECT c.conrelid::regclass::text AS tbl, c.contype AS kind, c.conname AS name,
         ARRAY(SELECT attname FROM unnest(c.conkey) WITH ORDINALITY k(n, o)
               JOIN pg_attribute ON attrelid = c.conrelid AND attnum = k.n ORDER BY k.o) AS cols,
         c.confrelid::regclass::text AS ref_tbl
  FROM pg_constraint c
  WHERE c.connamespace = 'public'::regnamespace AND c.contype IN ('p', 'u', 'f')),
uidx AS (  -- partial/expression unique indexes are not constraints
  SELECT i.indrelid::regclass::text AS tbl,
         ARRAY(SELECT attname FROM unnest(i.indkey::int2[]) k(n)
               JOIN pg_attribute ON attrelid = i.indrelid AND attnum = k.n) AS cols
  FROM pg_index i JOIN pg_class ic ON ic.oid = i.indexrelid
  WHERE i.indisunique AND NOT i.indisprimary
    AND ic.relnamespace = 'public'::regnamespace)
SELECT json_build_object(
  'columns', (SELECT json_agg(cols ORDER BY tbl, pos) FROM cols),
  'constraints', (SELECT json_agg(cons) FROM cons),
  'unique_indexes', (SELECT json_agg(uidx) FROM uidx));
"""


def catalog():
    out = subprocess.run(
        psql_cmd(DB),
        input=CATALOG_SQL, capture_output=True, text=True, check=True).stdout
    return json.loads(out)


def short_type(t: str) -> str:
    t = t.replace("timestamp with time zone", "timestamptz")
    t = t.replace("character varying", "varchar").replace("character", "char")
    t = re.sub(r"\(.*\)", "", t).strip()
    return re.sub(r"[^A-Za-z0-9_]", "_", t)


def main():
    cat = catalog()
    tables = {}
    for c in cat["columns"]:
        tables.setdefault(c["tbl"], []).append(c)

    all_in_modules = [t for _, _, _, ts in MODULES for t in ts]
    missing = set(tables) - set(all_in_modules)
    extra = set(all_in_modules) - set(tables)
    assert not missing and not extra, f"module map mismatch: missing={missing} extra={extra}"
    assert len(all_in_modules) == len(set(all_in_modules)), "table listed twice"

    pk, uq, fks = {}, {}, []
    for k in cat["constraints"]:
        if k["kind"] == "p":
            pk[k["tbl"]] = k["cols"]
        elif k["kind"] == "u":
            uq.setdefault(k["tbl"], []).append(k["cols"])
        else:
            fks.append(k)
    for u in cat["unique_indexes"] or []:
        if u["cols"]:
            uq.setdefault(u["tbl"], []).append(u["cols"])

    fk_cols = {}
    for f in fks:
        for col in f["cols"]:
            fk_cols.setdefault(f["tbl"], set()).add(col)

    module_of = {t: key for key, _, _, ts in MODULES for t in ts}
    result = []
    for key, name_ar, name_en, ts in MODULES:
        lines = ["erDiagram"]
        stubs = set()
        rels = []
        for f in fks:
            child, parent = f["tbl"], f["ref_tbl"]
            if child not in ts and parent not in ts:
                continue
            if child in ts and parent not in ts and parent in UBIQUITOUS:
                continue
            if parent in ts and child not in ts:
                continue  # drawn in the child's module
            if parent not in ts:
                stubs.add(parent)
            notnull = all(next(c for c in tables[child] if c["col"] == col)["notnull"]
                          for col in f["cols"])
            one_to_one = (pk.get(child) == f["cols"]) or (f["cols"] in uq.get(child, []))
            left = "||" if notnull else "|o"
            right = "o|" if one_to_one else "o{"
            label = ", ".join(f["cols"])
            rels.append(f'  {parent} {left}--{right} {child} : "{label}"')
        for t in ts:
            lines.append(f"  {t} {{")
            for c in tables[t]:
                keys = []
                if c["col"] in pk.get(t, []):
                    keys.append("PK")
                if c["col"] in fk_cols.get(t, set()):
                    keys.append("FK")
                if any(u == [c["col"]] for u in uq.get(t, [])):
                    keys.append("UK")
                note = ' "generated"' if c["generated"] else ""
                lines.append(f"    {short_type(c['typ'])} {c['col']}"
                             f"{' ' + ','.join(keys) if keys else ''}{note}")
            lines.append("  }")
        lines += sorted(set(rels))
        result.append({
            "key": key, "name_ar": name_ar, "name_en": name_en,
            "tables": ts, "external": sorted(stubs),
            "mermaid": "\n".join(lines),
        })

    with open(f"{OUT}/erd.json", "w", encoding="utf-8") as fh:
        json.dump({"modules": result, "table_count": len(tables),
                   "fk_count": len(fks)}, fh, ensure_ascii=False, indent=1)
    print(f"{len(result)} modules, {len(tables)} tables, {len(fks)} foreign keys")


if __name__ == "__main__":
    main()
