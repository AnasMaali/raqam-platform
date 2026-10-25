-- =====================================================================
--  12 · Reference data required by constraints (lookup seeds)
--  Content (posts, services, prices) is NOT seeded here.
-- =====================================================================

INSERT INTO currencies (code, name_ar, symbol, minor_units) VALUES
  ('ILS', 'شيكل إسرائيلي', '₪', 2),
  ('USD', 'دولار أمريكي', '$', 2),
  ('JOD', 'دينار أردني', 'د.أ', 3),
  ('EUR', 'يورو', '€', 2),
  ('GBP', 'جنيه إسترليني', '£', 2),
  ('EGP', 'جنيه مصري', 'ج.م', 2),
  ('AED', 'درهم إماراتي', 'د.إ', 2),
  ('SAR', 'ريال سعودي', 'ر.س', 2);

INSERT INTO rate_board_currencies (currency_code, sort_order, display_decimals) VALUES
  ('USD', 1, 3), ('JOD', 2, 3), ('EUR', 3, 3), ('GBP', 4, 3), ('EGP', 5, 4), ('AED', 6, 3), ('SAR', 7, 3);

-- Official fixed pegs (UAE Central Bank / Saudi Central Bank)
INSERT INTO currency_pegs (currency_code, anchor_code, units_per_anchor) VALUES
  ('AED', 'USD', 3.6725),
  ('SAR', 'USD', 3.75);

INSERT INTO cities (name_ar, slug, latitude, longitude) VALUES
  ('نابلس', 'نابلس', 32.221100, 35.254400),
  ('جنين', 'جنين', 32.464600, 35.293900),
  ('طولكرم', 'طولكرم', 32.310400, 35.028600),
  ('قلقيلية', 'قلقيلية', 32.189700, 34.970600),
  ('سلفيت', 'سلفيت', 32.083300, 35.180600),
  ('رام الله والبيرة', 'رام-الله-والبيرة', 31.903800, 35.203400),
  ('أريحا', 'أريحا', 31.866700, 35.450000),
  ('القدس', 'القدس', 31.768300, 35.213700),
  ('بيت لحم', 'بيت-لحم', 31.705400, 35.202400),
  ('الخليل', 'الخليل', 31.532600, 35.099800),
  ('طوباس', 'طوباس', 32.320900, 35.369700),
  ('غزة', 'غزة', 31.501700, 34.466800);

INSERT INTO weather_locations (city_id, is_default, sort_order)
SELECT id, slug = 'نابلس', row_number() OVER (ORDER BY id) FROM cities;

INSERT INTO data_providers (code, service, name, is_active, is_backup) VALUES
  ('bank_of_israel',    'currency',   'Bank of Israel representative rates (official, free)', true, false),
  ('exchangerate_api',  'currency',   'ExchangeRate-API Pro (paid, hourly) — not active', false, true),
  ('openweathermap',    'weather',    'OpenWeatherMap (free)',      true,  false),
  ('youtube',           'video',      'YouTube Data API v3',        true,  false),
  ('purelymail',        'email',      'Purelymail SMTP',            true,  false),
  ('brevo',             'newsletter', 'Brevo',                      true,  false);
INSERT INTO provider_health (provider_code) SELECT code FROM data_providers;

INSERT INTO social_platforms (code, name, sort_order) VALUES
  ('facebook', 'Facebook', 1), ('instagram', 'Instagram', 2), ('tiktok', 'TikTok', 3),
  ('youtube', 'YouTube', 4), ('whatsapp', 'WhatsApp', 5), ('x', 'X', 6),
  ('linkedin', 'LinkedIn', 7), ('website', 'Website', 8);

INSERT INTO sections (key, name_ar, slug, sort_order) VALUES
  ('podcast',         'البودكاست',              'podcast',          1),
  ('success_stories', 'قصص نجاح',               'success-stories',  2),
  ('made_in_nablus',  'صُنع في نابلس',           'made-in-nablus',   3),
  ('nablus_history',  'تاريخ نابلس الاقتصادي',  'history',          4),
  ('initiatives',     'مبادرات اقتصادية',       'initiatives',      5),
  ('round_table',     'طاولة مستديرة',          'round-table',      6),
  ('awareness',       'التوعية',                 'awareness',        7);

INSERT INTO categories (name_ar, slug, is_highlighted, sort_order) VALUES
  ('محلي (نابلس)', 'محلي-نابلس', true, 1),
  ('فلسطين', 'فلسطين', false, 2),
  ('أسواق ومال', 'أسواق-ومال', false, 3),
  ('أعمال وشركات', 'أعمال-وشركات', false, 4),
  ('طاقة', 'طاقة', false, 5),
  ('تكنولوجيا', 'تكنولوجيا', false, 6);

INSERT INTO post_kinds (code, name_ar, section_id, requires_category, schema_org_type, sort_order)
SELECT k.code, k.name_ar, s.id, k.requires_category, k.schema_org_type, k.sort_order
FROM (VALUES
  ('news',        'خبر',             NULL,              true,  'NewsArticle',          1),
  ('report',      'تقرير',           NULL,              true,  'ReportageNewsArticle', 2),
  ('opinion',     'مقال رأي',        NULL,              true,  'OpinionNewsArticle',   3),
  ('infographic', 'إنفوغراف',        NULL,              true,  'NewsArticle',          4),
  ('episode',     'حلقة بودكاست',    'podcast',         false, 'Article',              5),
  ('story',       'قصة نجاح',        'success_stories', false, 'Article',              6),
  ('product',     'منتج',            'made_in_nablus',  false, 'Article',              7),
  ('history',     'تاريخ',           'nablus_history',  false, 'Article',              8),
  ('initiative',  'مبادرة',          'initiatives',     false, 'Article',              9),
  ('roundtable',  'جلسة طاولة مستديرة', 'round_table',  false, 'Article',             10),
  ('awareness',   'بطاقة توعية',     'awareness',       false, 'Article',             11)
) AS k(code, name_ar, section_key, requires_category, schema_org_type, sort_order)
LEFT JOIN sections s ON s.key = k.section_key;

INSERT INTO guest_roles (code, name_ar) VALUES
  ('host', 'مقدّم'), ('guest', 'ضيف'), ('panelist', 'مشارك');

INSERT INTO pose_types (code, name_ar, sort_order) VALUES
  ('explaining', 'تشرح', 1), ('warning', 'تحذّر', 2), ('smiling', 'تبتسم', 3),
  ('pointing', 'تشير', 4), ('thinking', 'تفكّر', 5);

INSERT INTO awareness_types (code, name_ar, sort_order) VALUES
  ('did_you_know', 'هل تعلم؟', 1), ('warning', 'انتبه!', 2), ('advice', 'نصيحة', 3),
  ('saving', 'ادخار', 4), ('rights', 'حقوقك', 5), ('fraud', 'احتيال', 6),
  ('number_of_day', 'رقم اليوم', 7);

INSERT INTO ad_placements (code, name_ar, desktop_width, desktop_height, mobile_width, mobile_height,
                           is_enabled, frequency_cap_hours, lazy_load) VALUES
  ('header_leaderboard', 'بانر أعلى الصفحة', 970, 90, 320, 100, true,  NULL, false),
  ('sidebar_mpu',        'مربع جانبي',       300, 250, 300, 250, true,  NULL, true),
  ('in_article',         'داخل المادة',      728, 90, 300, 250, true,  NULL, true),
  ('between_sections',   'بين الأقسام',      970, 250, 320, 100, true, NULL, true),
  ('popup',              'نافذة منبثقة',     600, 500, 320, 400, false, 24,   true),
  ('mobile_sticky',      'شريط الجوال',      NULL, NULL, 320, 50, false, NULL, false);

INSERT INTO service_groups (name_ar, sort_order) VALUES
  ('تغطيات إعلامية', 1), ('إعلانات', 2);

INSERT INTO booking_statuses (code, name_ar, sort_order, is_terminal, notify_customer, requires_payment_flag) VALUES
  ('new',              'جديد',            1, false, false, false),
  ('awaiting_payment', 'بانتظار الدفع',    2, false, true,  true),
  ('paid',             'مدفوع',           3, false, true,  true),
  ('contacted',        'تم التواصل',      4, false, false, false),
  ('confirmed',        'مؤكد',            5, false, true,  false),
  ('scheduled',        'مجدول',           6, false, true,  false),
  ('completed',        'منفّذ',            7, false, true,  false),
  ('published',        'منشور',           8, true,  true,  false),
  ('cancelled',        'ملغي',            9, true,  true,  false);

INSERT INTO booking_status_transitions (from_code, to_code) VALUES
  ('new', 'contacted'), ('new', 'awaiting_payment'),
  ('awaiting_payment', 'paid'), ('paid', 'contacted'),
  ('contacted', 'confirmed'), ('confirmed', 'scheduled'),
  ('scheduled', 'completed'), ('scheduled', 'published'), ('completed', 'published'),
  ('new', 'cancelled'), ('awaiting_payment', 'cancelled'), ('paid', 'cancelled'),
  ('contacted', 'cancelled'), ('confirmed', 'cancelled'), ('scheduled', 'cancelled');

INSERT INTO payment_gateways (code, name_ar) VALUES
  ('bank_of_palestine', 'بنك فلسطين'),
  ('fake',              'بوابة تجريبية (للاختبار فقط)');

INSERT INTO feature_flags (key, description_ar, is_enabled, depends_on) VALUES
  ('booking',            'نظام الحجز الإلكتروني',                 false, NULL),
  ('customer_accounts',  'حسابات العملاء',                        false, NULL),
  ('multi_admin',        'أكثر من مستخدم وأدوار لفريق الإدارة',  false, NULL),
  ('chatbot',            'الشات بوت',                             true,  NULL),
  ('ad_popup',           'الإعلان المنبثق',                       false, NULL),
  ('ad_mobile_sticky',   'شريط الإعلان على الجوال',               false, NULL),
  ('public_view_counts', 'إظهار عدد المشاهدات للزوار',           false, NULL);
INSERT INTO feature_flags (key, description_ar, is_enabled, depends_on) VALUES
  ('online_payments',    'الدفع الإلكتروني',                       false, 'booking'),
  ('chatbot_ai',         'الشات بوت بالذكاء الاصطناعي (الإصدار الثاني)', false, 'chatbot');

INSERT INTO roles (code, name_ar, is_system) VALUES ('admin', 'مدير المنصة', true);

INSERT INTO menus (key, name_ar) VALUES
  ('header', 'القائمة الرئيسية'), ('footer_main', 'روابط الفوتر'), ('footer_legal', 'الروابط القانونية');

INSERT INTO traffic_sources (code, name_ar) VALUES
  ('google', 'جوجل'), ('bing', 'بينغ'), ('facebook', 'فيسبوك'), ('whatsapp', 'واتساب'),
  ('instagram', 'إنستغرام'), ('tiktok', 'تيك توك'), ('youtube', 'يوتيوب'), ('x', 'إكس'),
  ('newsletter', 'النشرة البريدية'), ('direct', 'مباشر'), ('other', 'مواقع أخرى'),
  ('chatgpt', 'شات جي بي تي'), ('perplexity', 'بربلكسيتي'), ('gemini', 'جيميناي'),
  ('copilot', 'كوبايلوت'), ('claude', 'كلود');

INSERT INTO event_types (code, name_ar) VALUES
  ('video_play',          'تشغيل فيديو'),
  ('share_click',         'مشاركة على السوشيال'),
  ('converter_use',       'استخدام محوّل العملات'),
  ('newsletter_signup',   'اشتراك بالنشرة'),
  ('chatbot_open',        'فتح الشات بوت'),
  ('infographic_download','تحميل إنفوغراف');

INSERT INTO settings (key, value, value_type) VALUES
  ('general.site_name',          'رَقَم',     'string'),
  ('general.timezone',           'Asia/Hebron', 'string'),
  ('contact.phone',              NULL,        'phone'),
  ('contact.whatsapp',           NULL,        'phone'),
  ('contact.whatsapp_message',   'مرحباً، أود الاستفسار عن', 'string'),
  ('contact.email',              NULL,        'email'),
  ('contact.address',            'نابلس، فلسطين', 'string'),
  ('seo.title_suffix',           ' | رَقَم',  'string'),
  ('seo.default_description',    NULL,        'text'),
  ('newsletter.send_day',        'saturday',  'string'),
  ('newsletter.send_time',       '09:00',     'time'),
  ('analytics.raw_retention_days', '90',      'integer'),
  ('audit.retention_days',       '730',       'integer');

-- OpenWeatherMap condition codes -> Arabic + Raqam icon key
INSERT INTO weather_conditions (code, description_ar, icon_key) VALUES
  (200, 'عاصفة رعدية مع مطر خفيف', 'thunder-rain'), (201, 'عاصفة رعدية مع مطر', 'thunder-rain'),
  (202, 'عاصفة رعدية مع مطر غزير', 'thunder-rain'), (210, 'عاصفة رعدية خفيفة', 'thunder'),
  (211, 'عاصفة رعدية', 'thunder'), (212, 'عاصفة رعدية قوية', 'thunder'),
  (221, 'عواصف رعدية متفرقة', 'thunder'), (230, 'عاصفة رعدية مع رذاذ خفيف', 'thunder-rain'),
  (231, 'عاصفة رعدية مع رذاذ', 'thunder-rain'), (232, 'عاصفة رعدية مع رذاذ كثيف', 'thunder-rain'),
  (300, 'رذاذ خفيف', 'drizzle'), (301, 'رذاذ', 'drizzle'), (302, 'رذاذ كثيف', 'drizzle'),
  (310, 'رذاذ ومطر خفيف', 'drizzle'), (311, 'رذاذ ومطر', 'drizzle'), (312, 'رذاذ ومطر كثيف', 'drizzle'),
  (313, 'زخات مطر ورذاذ', 'drizzle'), (314, 'زخات مطر ورذاذ كثيفة', 'drizzle'), (321, 'زخات رذاذ', 'drizzle'),
  (500, 'مطر خفيف', 'rain'), (501, 'مطر معتدل', 'rain'), (502, 'مطر غزير', 'rain-heavy'),
  (503, 'مطر غزير جداً', 'rain-heavy'), (504, 'مطر شديد', 'rain-heavy'), (511, 'مطر متجمد', 'sleet'),
  (520, 'زخات مطر خفيفة', 'showers'), (521, 'زخات مطر', 'showers'), (522, 'زخات مطر غزيرة', 'showers'),
  (531, 'زخات مطر متفرقة', 'showers'),
  (600, 'ثلج خفيف', 'snow'), (601, 'ثلج', 'snow'), (602, 'ثلج كثيف', 'snow'), (611, 'صقيع', 'sleet'),
  (612, 'زخات صقيع خفيفة', 'sleet'), (613, 'زخات صقيع', 'sleet'), (615, 'مطر وثلج خفيف', 'sleet'),
  (616, 'مطر وثلج', 'sleet'), (620, 'زخات ثلج خفيفة', 'snow'), (621, 'زخات ثلج', 'snow'),
  (622, 'زخات ثلج كثيفة', 'snow'),
  (701, 'ضباب خفيف', 'mist'), (711, 'دخان', 'haze'), (721, 'غبار خفيف', 'haze'), (731, 'غبار', 'dust'),
  (741, 'ضباب', 'fog'), (751, 'رمال', 'dust'), (761, 'غبار', 'dust'), (762, 'رماد بركاني', 'dust'),
  (771, 'رياح شديدة', 'wind'), (781, 'إعصار', 'tornado'),
  (800, 'صافٍ', 'clear'), (801, 'غيوم قليلة', 'partly-cloudy'), (802, 'غيوم متفرقة', 'partly-cloudy'),
  (803, 'غائم جزئياً', 'cloudy'), (804, 'غائم', 'overcast');
