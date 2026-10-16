<div dir="rtl">

# قاعدة بيانات رَقَم — المخطط الكامل (3NF)

المصدر: `database/raqam_schema.sql` (PostgreSQL 16). المخططات أدناه مولّدة تلقائياً من قاعدة البيانات نفسها.

**131** جدول | **197** مفتاح أجنبي | **222** قيد CHECK | **381** فهرس | **45** Trigger | **80 + 18** اختبار ناجح

## 1NF — قيم ذرية بدون قوائم داخل الحقول

| قبل | بعد |
|---|---|
| posts.extra (JSON لحقول كل نوع) | 7 جداول فرعية: podcast_episodes، success_stories، products، history_entries، initiatives، roundtable_sessions، awareness_cards (+ infographics) |
| «أرقام بارزة» كمصفوفة | success_story_figures: صف لكل رقم |
| روابط سوشيال الكاتب/الضيف كـ JSON | author_links و guest_links مع جدول social_platforms |
| services.deliverables كـ JSON | service_deliverables: صف لكل بند |
| صيغ وكلمات وأزرار البوت كـ JSON | bot_entry_variants، bot_entry_keywords، bot_entry_quick_replies، وأفعال بمفاتيح أجنبية واضحة |
| home_blocks.settings كـ JSON | أعمدة واضحة (category_id، section_id، playlist_id…) مع CHECK لكل نوع |
| خصائص الصورة (alt، تعليق، نقطة تركيز) كـ JSON | أعمدة في media_assets، والنسخ المصغرة في media_variants |
| الطقس كـ JSON | weather_observations و weather_forecasts + weather_conditions |
| بنود الفاتورة كـ JSON | invoice_lines |
| تغييرات سجل العمليات كـ JSON | audit_log_changes: صف لكل حقل تغيّر (قبل/بعد) |

## 2NF — كل حقل يعتمد على المفتاح المركّب كاملاً

| الجدول | السبب |
|---|---|
| post_guests (post_id, guest_id) | الدور (مقدّم/ضيف/مشارك) يعتمد على المادة والضيف معاً |
| playlist_videos (playlist_id, video_id) | الترتيب يعتمد على القائمة والفيديو معاً |
| ad_stats_daily (campaign, placement, date) | المشاهدات والنقرات تعتمد على المفتاح كاملاً |
| character_poses (character, pose) | صورة الوضعية تعتمد على الشخصية والوضعية معاً |

## 3NF — بدون اعتماد غير مباشر وبدون قيم محسوبة مخزنة

| أُزيل | البديل |
|---|---|
| posts.section_id | القسم يُعرف من نوع المادة: post_kinds.section_id (مادة ← نوع ← قسم) |
| awareness_cards.character_id | الشخصية تُعرف من الوضعية: character_poses.character_id |
| قطاع ومدينة وسنة تأسيس قصة النجاح | صفات الشركة نفسها ← جدول organizations |
| اسم الراعي وبيانات المعلن | organizations + advertisers |
| اسم وإيميل وهاتف العميل بكل حجز | customers، والحجز يشير إليه |
| is_breaking، is_sponsored، حالة «منتهية» للإعلان | تُستنتج: breaking_until > now()، sponsor_organization_id، ends_at |
| views_total، messages_count، hits، last_login_at | تُحسب من جداولها (Materialized View أو استعلام) |
| مجموع وضريبة الفاتورة | View اسمه invoice_totals يحسبها من البنود |
| عملة الاسترجاع، الحالة السابقة بسجل الحجز | عملة الدفعة نفسها، والصف السابق بالسجل |
| RQ-2026-00042 و INV-2026-00001 | أعمدة Generated تُبنى من (السنة، التسلسل) تلقائياً |
| أسعار الدرهم والريال وجدول «سعر الإغلاق» | الدرهم والريال يُحسبان من الدولار عبر currency_pegs، والسعر السابق من نفس جدول الأسعار (نشرة يومية) |
| صور وفيديوهات داخل نص الخبر (مخفية بالـ HTML) | post_body_media و post_body_videos: كل استخدام مسجّل ومحمي من الحذف |

## استثناءات مقصودة وموثقة

| العنصر | السبب |
|---|---|
| payment_events.payload (jsonb) | نسخة أرشيفية لرسالة البنك كما وصلت، لا تُقرأ حقلاً حقلاً |
| failed_jobs و job_batches (payload، exception، failed_job_ids، options) | بيانات داخلية لطوابير Laravel (مهمة مسلسلة ورسالة الخطأ) بالشكل اللي بيقرأه الإطار، لا تُقرأ حقلاً حقلاً، والمهام الفاشلة تنحذف بعد 30 يوماً |
| invoices.billed_* و bookings.price_amount | لقطة تاريخية قانونية لحظة الإصدار/الحجز، ولا تتغير إذا تغيّر العميل أو السعر |
| bookings.status_code مع سجل المراحل | الحالة الحالية لا تتغير إلا بإضافة صف للسجل، وTrigger يمنع أي تعديل مباشر |
| audit_log.subject_table / subject_id | بدون مفتاح أجنبي عمداً: السجل لازم يبقى حتى لو انحذف الشيء |
| أعمدة search_* (مثل posts.search_text) | نسخة موحّدة الحروف للبحث، تحسبها قاعدة البيانات بنفسها (Generated) فما بتختلف عن الأصل أبداً |

## قابلية التوسع

| الحالة | كيف |
|---|---|
| نوع مادة جديد | صف في post_kinds + جدول فرعي جديد، بدون أي تعديل على posts |
| قوائم قابلة للإضافة | الحالات والانتقالات، أماكن الإعلانات، المزوّدون، المنصات، القطاعات، الحقب، أنواع الأحداث: كلها جداول وليست قيم ثابتة بالكود |
| تبديل المزوّدين | data_providers: مزوّد أساسي واحد لكل خدمة، واحتياطي جاهز |
| أي عملة | كل مبلغ مرتبط بجدول currencies |
| فريق إدارة لاحقاً | roles و permissions و user_roles جاهزة |
| التخزين | عمود disk في media_assets (local أو r2) للنقل إلى Cloudflare R2 |
| الحجم الكبير | الجداول الزمنية (الأسعار، الطقس، المشاهدات، السجل) جاهزة للتقسيم الشهري (Partitioning) بدون تغيير في التطبيق |
| السرعة | فهرس على كل مفتاح أجنبي، وفهارس جزئية للمنشور فقط، و pg_trgm للبحث البديل |

## ميزات PostgreSQL المستخدمة

| الميزة | ببساطة | مثال عندنا |
|---|---|---|
| Domains | قاعدة وحدة لكل نوع بيانات (slug، إيميل، هاتف، رابط، مبلغ) | slug_text يرفض التشكيل والمسافات |
| CHECK | قاعدة البيانات ترفض القيم الغلط بنفسها | لا نشر بدون صورة رئيسية ونص |
| Partial index | فهرس على جزء من الصفوف | مدينة افتراضية وحدة، دفعة ناجحة وحدة لكل حجز |
| EXCLUDE | يمنع تداخل الفترات الزمنية | تعديلين يدويين لنفس العملة ما بيتداخلوا |
| Composite FK | يربط الجدول الفرعي بالنوع الصحيح | ما بتنضاف بيانات حلقة لمادة نوعها خبر |
| Deferred trigger | فحص عند حفظ المعاملة كاملة | مادة القسم لازم يكون إلها جدولها الفرعي قبل النشر |
| Deferrable unique | ترتيب مؤقت مكرر داخل المعاملة | سحب وإفلات ترتيب الفيديوهات |
| Generated column | عمود يحسب نفسه | الرقم المرجعي للحجز ورقم الفاتورة |
| دالة normalize_ar | توحيد الحروف العربية (أ/إ/آ، ة/ه، ى/ي، التشكيل، الأرقام) | نفس الدالة بالـ PHP، ومختبرة إنها بتعطي نفس النتيجة |
| pg_trgm | بحث تقريبي بيتحمل الأخطاء | «الدولر» بتلاقي «الدولار» لو توقف Meilisearch |
| Triggers | منطق داخل قاعدة البيانات | سجل العمليات غير قابل للتعديل، الفواتير ثابتة |
| Materialized view | نتيجة محسوبة تتحدث كل ساعة | مجموع مشاهدات كل مادة |
| citext / timestamptz | إيميل بدون حساسية أحرف، وقت عالمي UTC | كل الإيميلات وكل الأوقات |

## المخططات حسب الوحدة

> `users` و `media_assets` و `seo_meta` تُرسم في وحداتها فقط لأنها مرتبطة بأغلب الجداول. جداول Laravel (sessions، jobs، cache…) تُنشأ من الإطار نفسه.

### الهوية والصلاحيات — Identity & access

</div>

```mermaid
erDiagram
  users {
    bigint id PK
    varchar user_type
    varchar name
    email_address email UK
    phone_e164 phone
    varchar password_hash
    timestamptz email_verified_at
    boolean is_active
    varchar remember_token
    timestamptz created_at
    timestamptz updated_at
    timestamptz deleted_at
  }
  roles {
    smallint id PK
    varchar code UK
    varchar name_ar
    boolean is_system
  }
  permissions {
    smallint id PK
    varchar code UK
    varchar name_ar
  }
  role_permissions {
    smallint role_id PK,FK
    smallint permission_id PK,FK
  }
  user_roles {
    bigint user_id PK,FK
    smallint role_id PK,FK
  }
  verification_codes {
    bigint id PK
    varchar purpose
    email_address email
    varchar code_hash
    smallint attempts
    timestamptz expires_at
    timestamptz consumed_at
    timestamptz created_at
  }
  user_devices {
    bigint id PK
    bigint user_id FK
    sha256_hex device_hash
    varchar user_agent
    timestamptz first_seen_at
    timestamptz last_seen_at
  }
  login_attempts {
    bigint id PK
    email_address email
    bigint user_id FK
    boolean succeeded
    varchar failure_reason
    sha256_hex device_hash
    sha256_hex ip_hash
    timestamptz attempted_at
  }
  permissions ||--o{ role_permissions : "permission_id"
  roles ||--o{ role_permissions : "role_id"
  roles ||--o{ user_roles : "role_id"
  users |o--o{ login_attempts : "user_id"
  users ||--o{ user_devices : "user_id"
  users ||--o{ user_roles : "user_id"
```

<div dir="rtl">

### المنصة والإعدادات — Platform & settings

</div>

```mermaid
erDiagram
  settings {
    varchar key PK
    text value
    varchar value_type
    bigint updated_by FK
    timestamptz updated_at
  }
  feature_flags {
    varchar key PK
    varchar description_ar
    boolean is_enabled
    varchar depends_on FK
    bigint updated_by FK
    timestamptz updated_at
  }
  data_providers {
    varchar code PK
    varchar service UK
    varchar name
    boolean is_active
    boolean is_backup
  }
  provider_health {
    varchar provider_code PK,FK
    timestamptz last_success_at
    timestamptz last_failure_at
    integer consecutive_failures
    varchar last_error
    timestamptz updated_at
  }
  social_platforms {
    varchar code PK
    varchar name
    integer sort_order
  }
  site_social_links {
    varchar platform_code PK,FK
    url_text url
    integer sort_order
    boolean is_visible
  }
  seo_meta {
    bigint id PK
    varchar meta_title
    varchar meta_description
    url_text canonical_url
    boolean robots_noindex
    bigint share_media_id FK
    timestamptz updated_at
  }
  document_counters {
    varchar doc_type PK
    smallint year PK
    integer last_value
  }
  failed_jobs {
    bigint id PK
    uuid uuid UK
    text connection
    text queue
    text payload
    text exception
    timestamptz failed_at
  }
  job_batches {
    uuid id PK
    text name
    integer total_jobs
    integer pending_jobs
    integer failed_jobs
    text failed_job_ids
    text options
    bigint cancelled_at
    bigint created_at
    bigint finished_at
  }
  data_providers ||--o| provider_health : "provider_code"
  feature_flags |o--o{ feature_flags : "depends_on"
  social_platforms ||--o| site_social_links : "platform_code"
```

<div dir="rtl">

### الوسائط — Media

</div>

```mermaid
erDiagram
  media_assets {
    bigint id PK
    varchar visibility
    varchar disk
    varchar path
    varchar original_name
    varchar mime_type
    bigint size_bytes
    sha256_hex checksum_sha256
    integer width
    integer height
    numeric duration_seconds
    varchar alt_text
    varchar caption
    varchar credit
    numeric focal_x
    numeric focal_y
    bigint uploaded_by FK
    timestamptz created_at
    timestamptz updated_at
    timestamptz deleted_at
  }
  media_variants {
    bigint media_id PK,FK
    varchar variant PK
    varchar format PK
    integer width
    integer height
    varchar path
    bigint size_bytes
  }
  media_assets ||--o{ media_variants : "media_id"
```

<div dir="rtl">

### البيانات المرجعية — Reference data

</div>

```mermaid
erDiagram
  currencies {
    char code PK
    varchar name_ar UK
    varchar symbol
    smallint minor_units
  }
  cities {
    integer id PK
    varchar name_ar UK
    slug_text slug UK
    numeric latitude
    numeric longitude
  }
  sectors {
    smallint id PK
    varchar name_ar UK
    slug_text slug UK
    integer sort_order
  }
  organizations {
    bigint id PK
    varchar name
    slug_text slug UK
    smallint sector_id FK
    integer city_id FK
    smallint founded_year
    url_text website
    bigint logo_media_id FK
    text search_name "generated"
    timestamptz created_at
    timestamptz updated_at
  }
  cities |o--o{ organizations : "city_id"
  sectors |o--o{ organizations : "sector_id"
```

<div dir="rtl">

### الأقسام والتصنيفات والأشخاص — Taxonomy & people

</div>

```mermaid
erDiagram
  sections {
    smallint id PK
    varchar key UK
    varchar name_ar
    slug_text slug UK
    text description
    bigint cover_media_id FK
    bigint seo_meta_id FK,UK
    integer sort_order
    boolean is_visible
    timestamptz created_at
    timestamptz updated_at
  }
  categories {
    integer id PK
    integer parent_id FK
    varchar name_ar
    slug_text slug UK
    text description
    boolean is_highlighted
    bigint seo_meta_id FK,UK
    integer sort_order
    boolean is_active
    timestamptz created_at
    timestamptz updated_at
  }
  tags {
    bigint id PK
    varchar name_ar UK
    slug_text slug UK
    bigint seo_meta_id FK,UK
    text search_name "generated"
    timestamptz created_at
  }
  eras {
    smallint id PK
    varchar name_ar UK
    smallint year_from
    smallint year_to
    integer sort_order
  }
  authors {
    bigint id PK
    varchar name
    slug_text slug UK
    varchar job_title
    text bio
    bigint photo_media_id FK
    bigint user_id FK,UK
    boolean is_active
    smallint team_sort_order
    bigint seo_meta_id FK,UK
    text search_name "generated"
    timestamptz created_at
    timestamptz updated_at
    timestamptz deleted_at
  }
  author_links {
    bigint author_id PK,FK
    varchar platform_code PK,FK
    url_text url
  }
  guests {
    bigint id PK
    varchar name
    slug_text slug UK
    varchar job_title
    bigint organization_id FK
    text bio
    bigint photo_media_id FK
    bigint seo_meta_id FK,UK
    text search_name "generated"
    timestamptz created_at
    timestamptz updated_at
    timestamptz deleted_at
  }
  guest_links {
    bigint guest_id PK,FK
    varchar platform_code PK,FK
    url_text url
  }
  guest_roles {
    varchar code PK
    varchar name_ar UK
  }
  authors ||--o{ author_links : "author_id"
  categories |o--o{ categories : "parent_id"
  guests ||--o{ guest_links : "guest_id"
  organizations |o--o{ guests : "organization_id"
  social_platforms ||--o{ author_links : "platform_code"
  social_platforms ||--o{ guest_links : "platform_code"
```

<div dir="rtl">

### المحتوى — Content

</div>

```mermaid
erDiagram
  post_kinds {
    varchar code PK
    varchar name_ar UK
    smallint section_id FK
    boolean requires_category
    varchar schema_org_type
    integer sort_order
  }
  posts {
    bigint id PK
    varchar kind_code FK
    integer category_id FK
    varchar title
    varchar subtitle
    slug_text slug
    varchar excerpt
    text body
    bigint cover_media_id FK
    bigint primary_video_id FK
    varchar status
    timestamptz published_at
    boolean is_featured
    timestamptz breaking_until
    bigint sponsor_organization_id FK
    text correction_note
    timestamptz corrected_at
    bigint seo_meta_id FK,UK
    text search_text "generated"
    bigint created_by FK
    bigint updated_by FK
    timestamptz created_at
    timestamptz updated_at
    timestamptz deleted_at
  }
  post_authors {
    bigint post_id PK,FK
    bigint author_id PK,FK
    smallint sort_order
  }
  post_tags {
    bigint post_id PK,FK
    bigint tag_id PK,FK
  }
  post_guests {
    bigint post_id PK,FK
    bigint guest_id PK,FK
    varchar role_code FK
    smallint sort_order
  }
  post_related {
    bigint post_id PK,FK
    bigint related_post_id PK,FK
    smallint sort_order
  }
  post_gallery_items {
    bigint post_id PK,FK
    bigint media_id PK,FK
    smallint sort_order
  }
  post_body_media {
    bigint post_id PK,FK
    bigint media_id PK,FK
  }
  post_body_videos {
    bigint post_id PK,FK
    bigint video_id PK,FK
  }
  post_revisions {
    bigint id PK
    bigint post_id FK
    integer revision_no
    varchar title
    varchar subtitle
    varchar excerpt
    text body
    bigint created_by FK
    timestamptz created_at
  }
  pages {
    integer id PK
    varchar system_key UK
    varchar title
    slug_text slug UK
    text body
    boolean is_published
    bigint seo_meta_id FK,UK
    bigint updated_by FK
    timestamptz created_at
    timestamptz updated_at
  }
  authors ||--o{ post_authors : "author_id"
  categories |o--o{ posts : "category_id"
  guest_roles ||--o{ post_guests : "role_code"
  guests ||--o{ post_guests : "guest_id"
  organizations |o--o{ posts : "sponsor_organization_id"
  post_kinds ||--o{ posts : "kind_code"
  posts ||--o{ post_authors : "post_id"
  posts ||--o{ post_body_media : "post_id"
  posts ||--o{ post_body_videos : "post_id"
  posts ||--o{ post_gallery_items : "post_id"
  posts ||--o{ post_guests : "post_id"
  posts ||--o{ post_related : "post_id"
  posts ||--o{ post_related : "related_post_id"
  posts ||--o{ post_revisions : "post_id"
  posts ||--o{ post_tags : "post_id"
  sections |o--o{ post_kinds : "section_id"
  tags ||--o{ post_tags : "tag_id"
  videos |o--o{ posts : "primary_video_id"
  videos ||--o{ post_body_videos : "video_id"
```

<div dir="rtl">

### أنواع المواد الخاصة — Post subtypes

</div>

```mermaid
erDiagram
  podcast_seasons {
    smallint id PK
    smallint season_number UK
    varchar title_ar
    bigint playlist_id FK,UK
  }
  podcast_episodes {
    bigint post_id PK,FK
    varchar kind_code FK
    smallint season_id FK
    smallint episode_number
  }
  success_stories {
    bigint post_id PK,FK
    varchar kind_code FK
    bigint organization_id FK
    varchar person_name
  }
  success_story_figures {
    bigint post_id PK,FK
    smallint sort_order PK
    varchar value
    varchar label
  }
  products {
    bigint post_id PK,FK
    varchar kind_code FK
    varchar product_name
    bigint organization_id FK
    url_text contact_url
    phone_e164 contact_phone
  }
  history_entries {
    bigint post_id PK,FK
    varchar kind_code FK
    smallint era_id FK
    varchar period_label
    smallint year_from
    smallint year_to
  }
  initiatives {
    bigint post_id PK,FK
    varchar kind_code FK
    varchar initiative_name
    bigint organizer_organization_id FK
    varchar status
    url_text url
  }
  roundtable_sessions {
    bigint post_id PK,FK
    varchar kind_code FK
    date held_on
  }
  awareness_cards {
    bigint post_id PK,FK
    varchar kind_code FK
    integer character_pose_id FK
    varchar awareness_type_code FK
  }
  infographics {
    bigint post_id PK,FK
    varchar kind_code FK
    bigint download_media_id FK
  }
  awareness_types ||--o{ awareness_cards : "awareness_type_code"
  character_poses ||--o{ awareness_cards : "character_pose_id"
  eras ||--o{ history_entries : "era_id"
  organizations ||--o{ initiatives : "organizer_organization_id"
  organizations ||--o{ products : "organization_id"
  organizations ||--o{ success_stories : "organization_id"
  playlists |o--o| podcast_seasons : "playlist_id"
  podcast_seasons ||--o{ podcast_episodes : "season_id"
  posts ||--o{ awareness_cards : "post_id, kind_code"
  posts ||--o{ history_entries : "post_id, kind_code"
  posts ||--o{ infographics : "post_id, kind_code"
  posts ||--o{ initiatives : "post_id, kind_code"
  posts ||--o{ podcast_episodes : "post_id, kind_code"
  posts ||--o{ products : "post_id, kind_code"
  posts ||--o{ roundtable_sessions : "post_id, kind_code"
  posts ||--o{ success_stories : "post_id, kind_code"
  success_stories ||--o{ success_story_figures : "post_id"
```

<div dir="rtl">

### الفيديو والشخصيات — Video & characters

</div>

```mermaid
erDiagram
  videos {
    bigint id PK
    varchar source
    varchar display_title
    text display_description
    integer duration_seconds
    bigint upload_media_id FK,UK
    bigint poster_media_id FK
    bigint created_by FK
    timestamptz created_at
    timestamptz updated_at
    timestamptz deleted_at
  }
  youtube_videos {
    bigint video_id PK,FK
    varchar source FK
    varchar youtube_id UK
    boolean is_short
    varchar source_title
    text source_description
    url_text thumbnail_url
    timestamptz published_on_youtube_at
    varchar availability
    timestamptz last_checked_at
    timestamptz fetched_at
  }
  video_chapters {
    bigint video_id PK,FK
    integer starts_at_seconds PK
    varchar title
  }
  playlists {
    bigint id PK
    varchar name_ar
    slug_text slug UK
    text description
    smallint section_id FK
    bigint cover_media_id FK
    bigint seo_meta_id FK,UK
    integer sort_order
    boolean is_visible
    timestamptz created_at
    timestamptz updated_at
  }
  playlist_videos {
    bigint playlist_id PK,FK
    bigint video_id PK,FK
    integer sort_order
  }
  characters {
    smallint id PK
    varchar name_ar UK
    slug_text slug UK
    varchar role_ar
    text description
    hex_color accent_color
    bigint rive_media_id FK
    bigint seo_meta_id FK,UK
    integer sort_order
    boolean is_active
    timestamptz created_at
    timestamptz updated_at
  }
  pose_types {
    varchar code PK
    varchar name_ar UK
    integer sort_order
  }
  character_poses {
    integer id PK
    smallint character_id FK
    varchar pose_type_code FK
    bigint static_media_id FK
    varchar rive_state
  }
  awareness_types {
    varchar code PK
    varchar name_ar UK
    integer sort_order
  }
  characters ||--o{ character_poses : "character_id"
  playlists ||--o{ playlist_videos : "playlist_id"
  pose_types ||--o{ character_poses : "pose_type_code"
  sections |o--o{ playlists : "section_id"
  videos ||--o{ playlist_videos : "video_id"
  videos ||--o{ video_chapters : "video_id"
  videos ||--o{ youtube_videos : "video_id, source"
```

<div dir="rtl">

### العملات والطقس — Currency & weather

</div>

```mermaid
erDiagram
  rate_board_currencies {
    char currency_code PK,FK
    basis_points buy_spread_bp
    basis_points sell_spread_bp
    smallint display_decimals
    integer sort_order
    boolean is_active
    timestamptz updated_at
  }
  exchange_rates {
    bigint id PK
    char currency_code FK
    varchar provider_code FK
    numeric mid_rate
    timestamptz effective_at
    timestamptz fetched_at
  }
  currency_pegs {
    char currency_code PK,FK
    char anchor_code FK
    numeric units_per_anchor
  }
  rate_overrides {
    bigint id PK
    char currency_code FK
    numeric buy_rate
    numeric sell_rate
    timestamptz valid_from
    timestamptz valid_until
    bigint created_by FK
    timestamptz created_at
  }
  weather_locations {
    integer city_id PK,FK
    boolean is_default
    integer sort_order
    boolean is_active
  }
  weather_conditions {
    smallint code PK
    varchar description_ar
    varchar icon_key
  }
  weather_observations {
    bigint id PK
    integer city_id FK
    varchar provider_code FK
    timestamptz observed_at
    numeric temperature_c
    numeric feels_like_c
    smallint humidity_pct
    numeric wind_kmh
    smallint wind_deg
    smallint condition_code FK
    boolean is_day
    timestamptz fetched_at
  }
  weather_forecasts {
    integer city_id PK,FK
    date forecast_date PK
    numeric temp_min_c
    numeric temp_max_c
    smallint condition_code FK
    varchar provider_code FK
    timestamptz fetched_at
  }
  cities ||--o| weather_locations : "city_id"
  currencies ||--o{ currency_pegs : "anchor_code"
  currencies ||--o{ exchange_rates : "currency_code"
  currencies ||--o| currency_pegs : "currency_code"
  currencies ||--o| rate_board_currencies : "currency_code"
  data_providers ||--o{ exchange_rates : "provider_code"
  data_providers ||--o{ weather_forecasts : "provider_code"
  data_providers ||--o{ weather_observations : "provider_code"
  rate_board_currencies ||--o{ rate_overrides : "currency_code"
  weather_conditions ||--o{ weather_forecasts : "condition_code"
  weather_conditions ||--o{ weather_observations : "condition_code"
  weather_locations ||--o{ weather_forecasts : "city_id"
  weather_locations ||--o{ weather_observations : "city_id"
```

<div dir="rtl">

### الإعلانات — Advertising

</div>

```mermaid
erDiagram
  ad_placements {
    varchar code PK
    varchar name_ar
    smallint desktop_width
    smallint desktop_height
    smallint mobile_width
    smallint mobile_height
    boolean is_enabled
    varchar empty_behavior
    smallint frequency_cap_hours
    boolean lazy_load
  }
  house_ads {
    varchar placement_code PK,FK
    bigint desktop_media_id FK
    bigint mobile_media_id FK
    varchar target_path
    boolean is_active
    timestamptz updated_at
  }
  advertisers {
    bigint id PK
    bigint organization_id FK,UK
    varchar contact_name
    phone_e164 contact_phone
    email_address contact_email
    text notes
    timestamptz created_at
    timestamptz updated_at
  }
  ad_campaigns {
    bigint id PK
    bigint advertiser_id FK
    varchar name
    url_text target_url
    timestamptz starts_at
    timestamptz ends_at
    smallint priority
    varchar status
    timestamptz ending_alert_sent_at
    bigint created_by FK
    timestamptz created_at
    timestamptz updated_at
    bigint booking_id FK
  }
  ad_creatives {
    bigint campaign_id PK,FK
    varchar device PK
    bigint media_id FK
  }
  ad_campaign_placements {
    bigint campaign_id PK,FK
    varchar placement_code PK,FK
  }
  ad_stats_daily {
    bigint campaign_id PK,FK
    varchar placement_code PK,FK
    date stat_date PK
    bigint impressions
    bigint clicks
  }
  ad_campaigns ||--o{ ad_campaign_placements : "campaign_id"
  ad_campaigns ||--o{ ad_creatives : "campaign_id"
  ad_campaigns ||--o{ ad_stats_daily : "campaign_id"
  ad_placements ||--o{ ad_campaign_placements : "placement_code"
  ad_placements ||--o{ ad_stats_daily : "placement_code"
  ad_placements ||--o| house_ads : "placement_code"
  advertisers ||--o{ ad_campaigns : "advertiser_id"
  bookings |o--o{ ad_campaigns : "booking_id"
  organizations ||--o| advertisers : "organization_id"
```

<div dir="rtl">

### الخدمات والحجز — Services & booking

</div>

```mermaid
erDiagram
  service_groups {
    smallint id PK
    varchar name_ar UK
    integer sort_order
  }
  services {
    integer id PK
    smallint group_id FK
    smallint section_id FK
    varchar name_ar
    slug_text slug UK
    varchar summary
    text description
    bigint cover_media_id FK
    varchar price_mode
    money_minor price_amount
    char currency_code FK
    varchar price_note
    money_minor sale_price_amount
    timestamptz sale_ends_at
    bigint seo_meta_id FK,UK
    integer sort_order
    boolean is_active
    timestamptz created_at
    timestamptz updated_at
    timestamptz deleted_at
  }
  service_deliverables {
    integer service_id PK,FK
    smallint sort_order PK
    varchar label
  }
  faqs {
    integer id PK
    integer service_id FK
    varchar question
    text answer
    integer sort_order
    boolean is_published
    timestamptz created_at
    timestamptz updated_at
  }
  customers {
    bigint id PK
    varchar name
    email_address email UK
    phone_e164 phone
    varchar company_name
    timestamptz created_at
    timestamptz updated_at
  }
  booking_statuses {
    varchar code PK
    varchar name_ar UK
    integer sort_order
    boolean is_terminal
    boolean notify_customer
    boolean requires_payment_flag
  }
  booking_status_transitions {
    varchar from_code PK,FK
    varchar to_code PK,FK
  }
  bookings {
    bigint id PK
    smallint reference_year
    integer reference_seq
    varchar reference UK "generated"
    integer service_id FK
    bigint customer_id FK
    bigint user_id FK
    varchar preferred_contact
    varchar preferred_dates
    text details
    varchar status_code FK
    money_minor price_amount
    char currency_code FK
    sha256_hex access_token_hash UK
    timestamptz scheduled_for
    bigint published_post_id FK
    text admin_notes
    timestamptz consent_at
    sha256_hex ip_hash
    timestamptz created_at
    timestamptz updated_at
    timestamptz deleted_at
  }
  booking_status_history {
    bigint id PK
    bigint booking_id FK
    varchar status_code FK
    varchar note
    bigint changed_by FK
    timestamptz customer_notified_at
    timestamptz created_at
  }
  booking_attachments {
    bigint booking_id PK,FK
    bigint media_id PK,FK
  }
  booking_statuses ||--o{ booking_status_history : "status_code"
  booking_statuses ||--o{ booking_status_transitions : "from_code"
  booking_statuses ||--o{ booking_status_transitions : "to_code"
  booking_statuses ||--o{ bookings : "status_code"
  bookings ||--o{ booking_attachments : "booking_id"
  bookings ||--o{ booking_status_history : "booking_id"
  currencies |o--o{ bookings : "currency_code"
  currencies |o--o{ services : "currency_code"
  customers ||--o{ bookings : "customer_id"
  posts |o--o{ bookings : "published_post_id"
  sections |o--o{ services : "section_id"
  service_groups ||--o{ services : "group_id"
  services |o--o{ faqs : "service_id"
  services ||--o{ bookings : "service_id"
  services ||--o{ service_deliverables : "service_id"
```

<div dir="rtl">

### الدفع والفواتير — Payments & invoices

</div>

```mermaid
erDiagram
  payment_gateways {
    varchar code PK
    varchar name_ar
    boolean is_active
  }
  payments {
    bigint id PK
    bigint booking_id FK,UK
    varchar gateway_code FK
    smallint attempt_no
    varchar gateway_order_id UK
    varchar gateway_transaction_id
    money_minor amount
    char currency_code FK
    varchar status
    varchar idempotency_key UK
    varchar failure_reason
    timestamptz paid_at
    timestamptz created_at
    timestamptz updated_at
  }
  payment_events {
    bigint id PK
    varchar gateway_code FK
    varchar gateway_event_id
    bigint payment_id FK
    varchar event_type
    boolean signature_valid
    jsonb payload
    timestamptz received_at
    timestamptz processed_at
  }
  invoices {
    bigint id PK
    smallint number_year
    integer number_seq
    varchar number UK "generated"
    bigint booking_id FK
    bigint payment_id FK,UK
    varchar billed_name
    email_address billed_email
    phone_e164 billed_phone
    varchar billed_company
    varchar billed_tax_number
    varchar seller_tax_number
    char currency_code FK
    basis_points tax_rate_bp
    timestamptz issued_at
    bigint pdf_media_id FK
  }
  invoice_lines {
    bigint invoice_id PK,FK
    smallint line_no PK
    varchar description
    numeric quantity
    money_minor unit_amount
  }
  refunds {
    bigint id PK
    bigint payment_id FK
    money_minor amount
    varchar status
    varchar gateway_refund_id
    varchar reason
    bigint created_by FK
    timestamptz created_at
  }
  bookings ||--o{ invoices : "booking_id"
  bookings ||--o| payments : "booking_id"
  currencies ||--o{ invoices : "currency_code"
  currencies ||--o{ payments : "currency_code"
  invoices ||--o{ invoice_lines : "invoice_id"
  payment_gateways ||--o{ payment_events : "gateway_code"
  payment_gateways ||--o{ payments : "gateway_code"
  payments |o--o{ payment_events : "payment_id"
  payments ||--o{ refunds : "payment_id"
  payments ||--o| invoices : "payment_id"
```

<div dir="rtl">

### حسابات العملاء — Customer accounts

</div>

```mermaid
erDiagram
  saved_posts {
    bigint user_id PK,FK
    bigint post_id PK,FK
    timestamptz saved_at
  }
  followed_sections {
    bigint user_id PK,FK
    smallint section_id PK,FK
    timestamptz followed_at
  }
  followed_categories {
    bigint user_id PK,FK
    integer category_id PK,FK
    timestamptz followed_at
  }
  categories ||--o{ followed_categories : "category_id"
  posts ||--o{ saved_posts : "post_id"
  sections ||--o{ followed_sections : "section_id"
```

<div dir="rtl">

### الشات بوت — Chatbot

</div>

```mermaid
erDiagram
  bot_entries {
    integer id PK
    integer faq_id FK,UK
    varchar question
    text answer
    varchar action_type
    integer action_service_id FK
    integer action_page_id FK
    url_text action_url
    boolean is_active
    bigint created_by FK
    timestamptz created_at
    timestamptz updated_at
  }
  bot_entry_variants {
    integer entry_id PK,FK
    varchar phrase PK
  }
  bot_entry_keywords {
    integer entry_id PK,FK
    varchar keyword PK
  }
  bot_quick_replies {
    smallint id PK
    varchar label_ar
    varchar action_type
    integer target_entry_id FK
    integer target_service_id FK
    integer target_page_id FK
    url_text target_url
    boolean is_root
    integer sort_order
    boolean is_active
  }
  bot_entry_quick_replies {
    integer entry_id PK,FK
    smallint quick_reply_id PK,FK
    smallint sort_order
  }
  bot_conversations {
    bigint id PK
    uuid public_id UK
    sha256_hex session_hash
    varchar entry_path
    timestamptz started_at
  }
  bot_messages {
    bigint id PK
    bigint conversation_id FK
    varchar role
    varchar content
    integer matched_entry_id FK
    numeric match_score
    timestamptz reviewed_at
    timestamptz created_at
  }
  bot_conversations ||--o{ bot_messages : "conversation_id"
  bot_entries |o--o{ bot_messages : "matched_entry_id"
  bot_entries |o--o{ bot_quick_replies : "target_entry_id"
  bot_entries ||--o{ bot_entry_keywords : "entry_id"
  bot_entries ||--o{ bot_entry_quick_replies : "entry_id"
  bot_entries ||--o{ bot_entry_variants : "entry_id"
  bot_quick_replies ||--o{ bot_entry_quick_replies : "quick_reply_id"
  faqs |o--o| bot_entries : "faq_id"
  pages |o--o{ bot_entries : "action_page_id"
  pages |o--o{ bot_quick_replies : "target_page_id"
  services |o--o{ bot_entries : "action_service_id"
  services |o--o{ bot_quick_replies : "target_service_id"
```

<div dir="rtl">

### النشرة والرسائل — Newsletter & messaging

</div>

```mermaid
erDiagram
  subscribers {
    bigint id PK
    email_address email UK
    varchar name
    varchar status
    varchar source_path
    sha256_hex consent_ip_hash
    timestamptz subscribed_at
    timestamptz confirmed_at
    timestamptz unsubscribed_at
    varchar brevo_contact_id UK
    timestamptz updated_at
  }
  newsletter_issues {
    bigint id PK
    date week_start UK
    varchar subject
    text intro
    varchar status
    varchar brevo_campaign_id
    timestamptz sent_at
    bigint created_by FK
    timestamptz created_at
    timestamptz updated_at
  }
  newsletter_issue_posts {
    bigint issue_id PK,FK
    bigint post_id PK,FK
    smallint sort_order
  }
  email_templates {
    varchar key PK
    varchar name_ar
    varchar audience
    varchar subject
    text body_html
    boolean is_active
    bigint updated_by FK
    timestamptz updated_at
  }
  email_log {
    bigint id PK
    varchar template_key FK
    email_address recipient
    varchar status
    smallint attempts
    varchar error
    timestamptz queued_at
    timestamptz sent_at
  }
  admin_notifications {
    bigint id PK
    bigint user_id FK
    varchar category
    varchar title
    varchar body
    varchar action_path
    varchar dedupe_key
    timestamptz read_at
    timestamptz created_at
  }
  contact_messages {
    bigint id PK
    varchar name
    email_address email
    phone_e164 phone
    varchar subject
    text message
    varchar status
    sha256_hex ip_hash
    bigint handled_by FK
    timestamptz handled_at
    timestamptz created_at
  }
  email_templates ||--o{ email_log : "template_key"
  newsletter_issues ||--o{ newsletter_issue_posts : "issue_id"
  posts ||--o{ newsletter_issue_posts : "post_id"
```

<div dir="rtl">

### SEO والبحث والتنقل — SEO, search & navigation

</div>

```mermaid
erDiagram
  redirects {
    bigint id PK
    varchar from_path UK
    varchar to_path
    smallint status_code
    bigint hits
    timestamptz last_hit_at
    bigint created_by FK
    timestamptz created_at
  }
  synonym_groups {
    integer id PK
    timestamptz created_at
  }
  synonym_terms {
    varchar term PK
    integer group_id FK
  }
  search_terms {
    bigint id PK
    varchar normalized_term UK
  }
  search_stats_daily {
    bigint term_id PK,FK
    date stat_date PK
    integer searches
    integer zero_result_searches
  }
  menus {
    smallint id PK
    varchar key UK
    varchar name_ar
  }
  menu_items {
    integer id PK
    smallint menu_id FK
    integer parent_id FK
    varchar label_ar
    integer page_id FK
    integer category_id FK
    smallint section_id FK
    varchar url
    boolean opens_new_tab
    integer sort_order
    boolean is_active
  }
  home_blocks {
    smallint id PK
    varchar block_type
    varchar title_ar
    integer category_id FK
    smallint section_id FK
    bigint playlist_id FK
    varchar placement_code FK
    smallint item_limit
    integer sort_order UK
    boolean is_active
  }
  ad_placements |o--o{ home_blocks : "placement_code"
  categories |o--o{ home_blocks : "category_id"
  categories |o--o{ menu_items : "category_id"
  menu_items |o--o{ menu_items : "parent_id"
  menus ||--o{ menu_items : "menu_id"
  pages |o--o{ menu_items : "page_id"
  playlists |o--o{ home_blocks : "playlist_id"
  search_terms ||--o{ search_stats_daily : "term_id"
  sections |o--o{ home_blocks : "section_id"
  sections |o--o{ menu_items : "section_id"
  synonym_groups ||--o{ synonym_terms : "group_id"
```

<div dir="rtl">

### الإحصائيات وسجل العمليات — Analytics & audit

</div>

```mermaid
erDiagram
  post_views_daily {
    bigint post_id PK,FK
    date stat_date PK
    integer views
  }
  site_stats_daily {
    date stat_date PK
    bigint page_views
    bigint unique_visitors
  }
  traffic_sources {
    varchar code PK
    varchar name_ar UK
  }
  traffic_sources_daily {
    date stat_date PK
    varchar source_code PK,FK
    bigint visits
  }
  device_stats_daily {
    date stat_date PK
    varchar device_type PK
    bigint visits
  }
  event_types {
    varchar code PK
    varchar name_ar UK
  }
  event_stats_daily {
    date stat_date PK
    varchar event_code PK,FK
    bigint count
  }
  service_contact_clicks_daily {
    integer service_id PK,FK
    varchar channel PK
    date stat_date PK
    integer clicks
  }
  video_plays_daily {
    bigint video_id PK,FK
    date stat_date PK
    integer plays
  }
  not_found_paths {
    varchar path PK
    bigint hits
    timestamptz first_seen_at
    timestamptz last_seen_at
    timestamptz resolved_at
  }
  audit_log {
    bigint id PK
    timestamptz occurred_at
    bigint actor_user_id FK
    varchar action
    varchar subject_table
    bigint subject_id
    sha256_hex ip_hash
    varchar user_agent
  }
  audit_log_changes {
    bigint audit_id PK,FK
    varchar field PK
    text old_value
    text new_value
  }
  audit_log ||--o{ audit_log_changes : "audit_id"
  event_types ||--o{ event_stats_daily : "event_code"
  posts ||--o{ post_views_daily : "post_id"
  services ||--o{ service_contact_clicks_daily : "service_id"
  traffic_sources ||--o{ traffic_sources_daily : "source_code"
  videos ||--o{ video_plays_daily : "video_id"
```

<div dir="rtl">

</div>
