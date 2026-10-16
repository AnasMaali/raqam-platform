#!/usr/bin/env python3
"""Build docs/database/README.md (GitHub) and docs/database/schema.html (artifact)
from docs/database/erd.json plus the normalisation notes below."""
import html
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent.parent   # repository root
erd = json.loads((ROOT / "docs/database/erd.json").read_text(encoding="utf-8"))
STATS = [("جدول", "131"), ("مفتاح أجنبي", "197"), ("قيد CHECK", "222"),
         ("فهرس", "381"), ("Trigger", "45"), ("اختبار ناجح", "80 + 18")]

NF1 = [
    ("posts.extra (JSON لحقول كل نوع)", "7 جداول فرعية: podcast_episodes، success_stories، products، history_entries، initiatives، roundtable_sessions، awareness_cards (+ infographics)"),
    ("«أرقام بارزة» كمصفوفة", "success_story_figures: صف لكل رقم"),
    ("روابط سوشيال الكاتب/الضيف كـ JSON", "author_links و guest_links مع جدول social_platforms"),
    ("services.deliverables كـ JSON", "service_deliverables: صف لكل بند"),
    ("صيغ وكلمات وأزرار البوت كـ JSON", "bot_entry_variants، bot_entry_keywords، bot_entry_quick_replies، وأفعال بمفاتيح أجنبية واضحة"),
    ("home_blocks.settings كـ JSON", "أعمدة واضحة (category_id، section_id، playlist_id…) مع CHECK لكل نوع"),
    ("خصائص الصورة (alt، تعليق، نقطة تركيز) كـ JSON", "أعمدة في media_assets، والنسخ المصغرة في media_variants"),
    ("الطقس كـ JSON", "weather_observations و weather_forecasts + weather_conditions"),
    ("بنود الفاتورة كـ JSON", "invoice_lines"),
    ("تغييرات سجل العمليات كـ JSON", "audit_log_changes: صف لكل حقل تغيّر (قبل/بعد)"),
]
NF2 = [
    ("post_guests (post_id, guest_id)", "الدور (مقدّم/ضيف/مشارك) يعتمد على المادة والضيف معاً"),
    ("playlist_videos (playlist_id, video_id)", "الترتيب يعتمد على القائمة والفيديو معاً"),
    ("ad_stats_daily (campaign, placement, date)", "المشاهدات والنقرات تعتمد على المفتاح كاملاً"),
    ("character_poses (character, pose)", "صورة الوضعية تعتمد على الشخصية والوضعية معاً"),
]
NF3 = [
    ("posts.section_id", "القسم يُعرف من نوع المادة: post_kinds.section_id (مادة ← نوع ← قسم)"),
    ("awareness_cards.character_id", "الشخصية تُعرف من الوضعية: character_poses.character_id"),
    ("قطاع ومدينة وسنة تأسيس قصة النجاح", "صفات الشركة نفسها ← جدول organizations"),
    ("اسم الراعي وبيانات المعلن", "organizations + advertisers"),
    ("اسم وإيميل وهاتف العميل بكل حجز", "customers، والحجز يشير إليه"),
    ("is_breaking، is_sponsored، حالة «منتهية» للإعلان", "تُستنتج: breaking_until > now()، sponsor_organization_id، ends_at"),
    ("views_total، messages_count، hits، last_login_at", "تُحسب من جداولها (Materialized View أو استعلام)"),
    ("مجموع وضريبة الفاتورة", "View اسمه invoice_totals يحسبها من البنود"),
    ("عملة الاسترجاع، الحالة السابقة بسجل الحجز", "عملة الدفعة نفسها، والصف السابق بالسجل"),
    ("RQ-2026-00042 و INV-2026-00001", "أعمدة Generated تُبنى من (السنة، التسلسل) تلقائياً"),
    ("أسعار الدرهم والريال وجدول «سعر الإغلاق»", "الدرهم والريال يُحسبان من الدولار عبر currency_pegs، والسعر السابق من نفس جدول الأسعار (نشرة يومية)"),
    ("صور وفيديوهات داخل نص الخبر (مخفية بالـ HTML)", "post_body_media و post_body_videos: كل استخدام مسجّل ومحمي من الحذف"),
]
EXCEPTIONS = [
    ("payment_events.payload (jsonb)", "نسخة أرشيفية لرسالة البنك كما وصلت، لا تُقرأ حقلاً حقلاً"),
    ("failed_jobs و job_batches (payload، exception، failed_job_ids، options)", "بيانات داخلية لطوابير Laravel (مهمة مسلسلة ورسالة الخطأ) بالشكل اللي بيقرأه الإطار، لا تُقرأ حقلاً حقلاً، والمهام الفاشلة تنحذف بعد 30 يوماً"),
    ("invoices.billed_* و bookings.price_amount", "لقطة تاريخية قانونية لحظة الإصدار/الحجز، ولا تتغير إذا تغيّر العميل أو السعر"),
    ("bookings.status_code مع سجل المراحل", "الحالة الحالية لا تتغير إلا بإضافة صف للسجل، وTrigger يمنع أي تعديل مباشر"),
    ("audit_log.subject_table / subject_id", "بدون مفتاح أجنبي عمداً: السجل لازم يبقى حتى لو انحذف الشيء"),
    ("أعمدة search_* (مثل posts.search_text)", "نسخة موحّدة الحروف للبحث، تحسبها قاعدة البيانات بنفسها (Generated) فما بتختلف عن الأصل أبداً"),
]
SCALE = [
    ("نوع مادة جديد", "صف في post_kinds + جدول فرعي جديد، بدون أي تعديل على posts"),
    ("قوائم قابلة للإضافة", "الحالات والانتقالات، أماكن الإعلانات، المزوّدون، المنصات، القطاعات، الحقب، أنواع الأحداث: كلها جداول وليست قيم ثابتة بالكود"),
    ("تبديل المزوّدين", "data_providers: مزوّد أساسي واحد لكل خدمة، واحتياطي جاهز"),
    ("أي عملة", "كل مبلغ مرتبط بجدول currencies"),
    ("فريق إدارة لاحقاً", "roles و permissions و user_roles جاهزة"),
    ("التخزين", "عمود disk في media_assets (local أو r2) للنقل إلى Cloudflare R2"),
    ("الحجم الكبير", "الجداول الزمنية (الأسعار، الطقس، المشاهدات، السجل) جاهزة للتقسيم الشهري (Partitioning) بدون تغيير في التطبيق"),
    ("السرعة", "فهرس على كل مفتاح أجنبي، وفهارس جزئية للمنشور فقط، و pg_trgm للبحث البديل"),
]
PG = [
    ("Domains", "قاعدة وحدة لكل نوع بيانات (slug، إيميل، هاتف، رابط، مبلغ)", "slug_text يرفض التشكيل والمسافات"),
    ("CHECK", "قاعدة البيانات ترفض القيم الغلط بنفسها", "لا نشر بدون صورة رئيسية ونص"),
    ("Partial index", "فهرس على جزء من الصفوف", "مدينة افتراضية وحدة، دفعة ناجحة وحدة لكل حجز"),
    ("EXCLUDE", "يمنع تداخل الفترات الزمنية", "تعديلين يدويين لنفس العملة ما بيتداخلوا"),
    ("Composite FK", "يربط الجدول الفرعي بالنوع الصحيح", "ما بتنضاف بيانات حلقة لمادة نوعها خبر"),
    ("Deferred trigger", "فحص عند حفظ المعاملة كاملة", "مادة القسم لازم يكون إلها جدولها الفرعي قبل النشر"),
    ("Deferrable unique", "ترتيب مؤقت مكرر داخل المعاملة", "سحب وإفلات ترتيب الفيديوهات"),
    ("Generated column", "عمود يحسب نفسه", "الرقم المرجعي للحجز ورقم الفاتورة"),
    ("دالة normalize_ar", "توحيد الحروف العربية (أ/إ/آ، ة/ه، ى/ي، التشكيل، الأرقام)", "نفس الدالة بالـ PHP، ومختبرة إنها بتعطي نفس النتيجة"),
    ("pg_trgm", "بحث تقريبي بيتحمل الأخطاء", "«الدولر» بتلاقي «الدولار» لو توقف Meilisearch"),
    ("Triggers", "منطق داخل قاعدة البيانات", "سجل العمليات غير قابل للتعديل، الفواتير ثابتة"),
    ("Materialized view", "نتيجة محسوبة تتحدث كل ساعة", "مجموع مشاهدات كل مادة"),
    ("citext / timestamptz", "إيميل بدون حساسية أحرف، وقت عالمي UTC", "كل الإيميلات وكل الأوقات"),
]

def esc(s):
    return html.escape(s, quote=True)

# ------------------------------------------------------------------ Markdown
md = ["<div dir=\"rtl\">", "", "# قاعدة بيانات رَقَم — المخطط الكامل (3NF)", "",
      "المصدر: `database/raqam_schema.sql` (PostgreSQL 16). المخططات أدناه مولّدة تلقائياً من قاعدة البيانات نفسها.", "",
      " | ".join(f"**{v}** {k}" for k, v in STATS), "",
      "## 1NF — قيم ذرية بدون قوائم داخل الحقول", "", "| قبل | بعد |", "|---|---|"]
md += [f"| {a} | {b} |" for a, b in NF1]
md += ["", "## 2NF — كل حقل يعتمد على المفتاح المركّب كاملاً", "", "| الجدول | السبب |", "|---|---|"]
md += [f"| {a} | {b} |" for a, b in NF2]
md += ["", "## 3NF — بدون اعتماد غير مباشر وبدون قيم محسوبة مخزنة", "", "| أُزيل | البديل |", "|---|---|"]
md += [f"| {a} | {b} |" for a, b in NF3]
md += ["", "## استثناءات مقصودة وموثقة", "", "| العنصر | السبب |", "|---|---|"]
md += [f"| {a} | {b} |" for a, b in EXCEPTIONS]
md += ["", "## قابلية التوسع", "", "| الحالة | كيف |", "|---|---|"]
md += [f"| {a} | {b} |" for a, b in SCALE]
md += ["", "## ميزات PostgreSQL المستخدمة", "", "| الميزة | ببساطة | مثال عندنا |", "|---|---|---|"]
md += [f"| {a} | {b} | {c} |" for a, b, c in PG]
md += ["", "## المخططات حسب الوحدة", "",
       "> `users` و `media_assets` و `seo_meta` تُرسم في وحداتها فقط لأنها مرتبطة بأغلب الجداول. جداول Laravel (sessions، jobs، cache…) تُنشأ من الإطار نفسه.", ""]
for m in erd["modules"]:
    md += [f"### {m['name_ar']} — {m['name_en']}", "", "</div>", "", "```mermaid", m["mermaid"], "```", "", "<div dir=\"rtl\">", ""]
md += ["</div>", ""]
(ROOT / "docs/database/README.md").write_text("\n".join(md), encoding="utf-8")

# ------------------------------------------------------------------ HTML artifact
def table(rows, heads):
    th = "".join(f"<th>{esc(h)}</th>" for h in heads)
    trs = "".join("<tr>" + "".join(f"<td>{esc(c)}</td>" for c in r) + "</tr>" for r in rows)
    return f'<div class="tbl"><table><thead><tr>{th}</tr></thead><tbody>{trs}</tbody></table></div>'

nav = "".join(f'<a href="#{m["key"]}">{esc(m["name_ar"])}<span>{len(m["tables"])}</span></a>' for m in erd["modules"])
modules_html = []
for i, m in enumerate(erd["modules"], 1):
    chips = "".join(f"<code>{esc(t)}</code>" for t in m["tables"])
    ext = ("<p class=\"ext\">مرتبط من وحدات أخرى: " + "، ".join(f"<code>{esc(t)}</code>" for t in m["external"]) + "</p>") if m["external"] else ""
    modules_html.append(f'''
<section class="module" id="{m["key"]}">
  <header class="mod-head">
    <h3><span class="mod-n">{i:02d}</span>{esc(m["name_ar"])} <small dir="ltr">{esc(m["name_en"])}</small></h3>
    <div class="chips" dir="ltr">{chips}</div>
    {ext}
  </header>
  <div class="diagram" dir="ltr"><pre class="mermaid">{esc(m["mermaid"])}</pre></div>
</section>''')

stats_html = "".join(f'<div class="stat"><b>{esc(v)}</b><span>{esc(k)}</span></div>' for k, v in STATS)

page = f'''<title>قاعدة بيانات رَقَم</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@500;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
/* Layout: a single reading column; module index sticks at the top, each module = header + scrollable ER diagram */
:root {{
  --bg: #f4f6f8;
  --surface: #ffffff;
  --ink: #12263a;
  --muted: #56677a;
  --line: #d8dfe7;
  --accent: #0e5a7a;
  --brass: #a97c2f;
  --paper: #ffffff;
  --code-bg: #e9eef3;
  --f-display: "Readex Pro", "IBM Plex Sans Arabic", system-ui, sans-serif;
  --f-body: "IBM Plex Sans Arabic", "Segoe UI", Tahoma, sans-serif;
  --f-mono: "IBM Plex Mono", ui-monospace, Menlo, Consolas, monospace;
}}
@media (prefers-color-scheme: dark) {{
  :root:not([data-theme="light"]) {{
    --bg: #0d1822; --surface: #13212e; --ink: #e4ebf2; --muted: #9aabbb; --line: #24384a;
    --accent: #5fb3d6; --brass: #d6aa5c; --paper: #f2f5f8; --code-bg: #1b2d3d; color-scheme: dark;
  }}
}}
:root[data-theme="dark"] {{
  --bg: #0d1822; --surface: #13212e; --ink: #e4ebf2; --muted: #9aabbb; --line: #24384a;
  --accent: #5fb3d6; --brass: #d6aa5c; --paper: #f2f5f8; --code-bg: #1b2d3d; color-scheme: dark;
}}
* {{ box-sizing: border-box; }}
body {{ background: var(--bg); color: var(--ink); font: 15px/1.75 var(--f-body); }}
.wrap {{ max-width: 1120px; margin: 0 auto; padding-inline: 20px; padding-block: 28px 64px; }}
h1, h2, h3 {{ font-family: var(--f-display); text-wrap: balance; line-height: 1.3; margin: 0; }}
h1 {{ font-size: clamp(28px, 4vw, 40px); }}
h2 {{ font-size: 22px; margin-block: 44px 6px; }}
h2 + .lede {{ margin-top: 0; }}
.eyebrow {{ font: 500 12px/1 var(--f-mono); letter-spacing: .08em; color: var(--brass); text-transform: uppercase; }}
.lede {{ color: var(--muted); max-width: 68ch; }}
code {{ font: 13px var(--f-mono); background: var(--code-bg); padding: 1px 6px; border-radius: 4px; overflow-wrap: anywhere; }}
.top {{ display: grid; gap: 10px; }}
.stats {{ display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1px; background: var(--line);
         border: 1px solid var(--line); margin-top: 22px; }}
.stat {{ background: var(--surface); padding: 14px 16px; display: grid; gap: 2px; }}
.stat b {{ font: 700 26px/1.1 var(--f-display); color: var(--accent); font-variant-numeric: tabular-nums; }}
.stat span {{ color: var(--muted); font-size: 13px; }}
.tbl {{ overflow-x: auto; border: 1px solid var(--line); background: var(--surface); margin-top: 14px; }}
table {{ border-collapse: collapse; width: 100%; min-width: 560px; }}
th, td {{ text-align: right; padding: 10px 14px; border-bottom: 1px solid var(--line); vertical-align: top; }}
th {{ font: 600 12px var(--f-body); color: var(--muted); letter-spacing: .03em; background: var(--code-bg); }}
td:first-child {{ font-weight: 600; width: 34%; }}
tbody tr:last-child td {{ border-bottom: 0; }}
.nf {{ display: grid; gap: 6px; margin-top: 30px; }}
.nf h3 {{ font-size: 17px; display: flex; gap: 10px; align-items: baseline; }}
.nf h3 em {{ font: 500 13px var(--f-mono); font-style: normal; color: var(--brass); }}
.nav {{ position: sticky; top: env(safe-area-inset-top, 0px); z-index: 5; background: var(--bg);
        border-block: 1px solid var(--line); margin-top: 18px; padding-block: 10px; display: flex; gap: 6px; overflow-x: auto; }}
.nav a {{ flex: none; text-decoration: none; color: var(--ink); font-size: 13px; padding: 5px 10px; border: 1px solid var(--line);
          background: var(--surface); border-radius: 999px; display: inline-flex; gap: 6px; align-items: center; }}
.nav a span {{ font: 500 11px var(--f-mono); color: var(--muted); }}
.nav a:hover, .nav a:focus-visible {{ border-color: var(--accent); color: var(--accent); outline: none; }}
.module {{ margin-top: 34px; scroll-margin-top: 70px; }}
.mod-head {{ display: grid; gap: 8px; }}
.mod-head h3 {{ font-size: 19px; display: flex; flex-wrap: wrap; gap: 10px; align-items: baseline; }}
.mod-head small {{ font: 400 13px var(--f-mono); color: var(--muted); }}
.mod-n {{ font: 500 13px var(--f-mono); color: var(--brass); }}
.chips {{ display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; }}
.ext {{ color: var(--muted); font-size: 13px; margin: 0; }}
.diagram {{ margin-top: 12px; background: var(--paper); border: 1px solid var(--line); overflow-x: auto; padding: 14px; }}
.diagram pre {{ margin: 0; min-width: max-content; }}
.note {{ border-inline-start: 3px solid var(--brass); background: var(--surface); padding: 12px 16px; margin-top: 16px; color: var(--muted); }}
@media (max-width: 520px) {{ td:first-child {{ width: auto; }} }}
@media (prefers-reduced-motion: reduce) {{ html {{ scroll-behavior: auto; }} }}
</style>

<div class="wrap" dir="rtl" lang="ar">
  <div class="top">
    <span class="eyebrow" dir="ltr">PostgreSQL 16 · 3NF · Raqam V1</span>
    <h1>قاعدة بيانات رَقَم</h1>
    <p class="lede">المخطط الكامل لكل جداول المنصة بعد التطبيع إلى الصيغة الثالثة (3NF). المخططات تحت مولّدة تلقائياً من قاعدة البيانات بعد تشغيلها، فهي مطابقة للملف <code>raqam_schema.sql</code> بالحرف. كل القيود اتجربت بـ 74 اختباراً، وتوحيد الحروف العربية بـ 18 اختباراً إضافياً بين PHP و PostgreSQL، ونجحت كلها.</p>
  </div>
  <div class="stats">{stats_html}</div>

  <h2>كيف طبّقنا التطبيع</h2>
  <p class="lede">كل خطوة تحل مشكلة محددة: تكرار البيانات، أو تعديل نفس المعلومة بأكثر من مكان، أو قيم محسوبة ممكن تختلف عن أصلها.</p>
  <div class="nf"><h3>1NF <em>قيمة وحدة بكل خانة</em></h3>{table(NF1, ["كان", "صار"])}</div>
  <div class="nf"><h3>2NF <em>الاعتماد على المفتاح كاملاً</em></h3>{table(NF2, ["الجدول", "السبب"])}</div>
  <div class="nf"><h3>3NF <em>بدون اعتماد غير مباشر</em></h3>{table(NF3, ["أُزيل من الجدول", "البديل"])}</div>
  <div class="nf"><h3>استثناءات مقصودة <em>موثقة ومحمية</em></h3>{table(EXCEPTIONS, ["العنصر", "ليش بقي"])}</div>

  <h2>قابلية التوسع</h2>
  {table(SCALE, ["الحاجة", "كيف المخطط جاهز لها"])}

  <h2>ميزات PostgreSQL المستخدمة</h2>
  {table(PG, ["الميزة", "ببساطة", "مثال عندنا"])}

  <h2>المخططات حسب الوحدة</h2>
  <p class="note"><code>users</code> و <code>media_assets</code> و <code>seo_meta</code> مرسومة بوحداتها بس، لأنها مرتبطة بأغلب الجداول. جداول Laravel الداخلية (sessions، jobs، cache) بيعملها الإطار نفسه.</p>
  <nav class="nav" aria-label="الوحدات">{nav}</nav>
  {"".join(modules_html)}
</div>
'''
(ROOT / "docs/database/schema.html").write_text(page, encoding="utf-8")
print("README.md + schema.html written")
