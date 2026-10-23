<?php

namespace App\Console;

use App\Chat\BotLibrary;
use App\Chat\KnowledgeBase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The chatbot's starting knowledge and its five first buttons (18.6): services and prices, advertise with us, today's
 * currencies, the weather, contact us. Never touches an entry or a button that exists (same question or label); the
 * admin rewords everything from the panel.
 */
class InstallChatbotCommand extends Command
{
    protected $signature = 'bot:install';

    protected $description = 'Add the chatbot\'s starting entries and its five first buttons, without touching existing ones';

    /** question => [answer, action, variants, keywords, button label] */
    public const ENTRIES = [
        'ما هي خدماتكم وأسعارها؟' => ['<p>نقدّم تغطيات إعلامية (بودكاست، قصص نجاح، صُنع في نابلس، طاولة مستديرة، مبادرات) وباقات إعلانية. التفاصيل والأسعار في <a href="/services">صفحة الخدمات والأسعار</a>، والحجز بالتواصل معنا مباشرة.</p>',
            'none', ['كم سعر حلقة البودكاست', 'أسعار الخدمات', 'كيف أحجز خدمة', 'بدي أطلع ببودكاست'], ['خدمات', 'خدمة', 'حجز', 'بودكاست'], 'الخدمات والأسعار'],
        'كيف أعلن معكم؟' => ['<p>عندنا أماكن إعلانية واضحة بمقاسات محجوزة وتقارير مشاهدات ونقرات. التفاصيل والباقات في <a href="/advertise">صفحة أعلن معنا</a>.</p>',
            'none', ['بدي أعلن', 'أسعار الإعلانات', 'كيف أحط إعلان'], ['إعلان', 'اعلان', 'أعلن', 'معلن', 'إعلانات'], 'أعلن معنا'],
        'أسعار العملات اليوم' => ['<p>أسعار العملات مقابل الشيكل:</p>', 'rates', ['سعر الدولار اليوم', 'كم سعر الدينار', 'أسعار الصرف', 'سعر اليورو', 'سعر صرف الشيكل'],
            ['دولار', 'دينار', 'يورو', 'عملة', 'عملات', 'صرف', 'شيكل', 'جنيه', 'درهم', 'ريال'], 'العملات اليوم'],
        'الطقس اليوم' => ['<p>الطقس الآن:</p>', 'weather', ['كيف الجو اليوم', 'درجة الحرارة', 'طقس نابلس', 'هل في مطر'], ['طقس', 'جو', 'حرارة', 'مطر', 'أمطار', 'رياح'], 'الطقس'],
        'كيف أتواصل معكم؟' => ['<p>يسعدنا تواصلك: من <a href="/contact">صفحة اتصل بنا</a>، أو مباشرة على واتساب من الزر.</p>',
            'whatsapp', ['رقم التلفون', 'وين مكانكم', 'بدي أحكي مع حدا'], ['تواصل', 'هاتف', 'تلفون', 'واتساب', 'عنوانكم', 'إيميل'], 'تواصل معنا'],
        'كيف أشترك في النشرة البريدية؟' => ['<p>اشترك من <a href="/newsletter">صفحة النشرة</a> أو من أسفل أي صفحة: تكتب بريدك ويصلك رمز تأكيد من 6 أرقام.</p>',
            'none', ['بدي أشترك بالنشرة', 'النشرة الأسبوعية'], ['نشرة', 'اشتراك', 'النشرة'], null],
    ];

    public function handle(BotLibrary $library): int
    {
        $added = 0;
        $order = 0;
        foreach (self::ENTRIES as $question => [$answer, $action, $variants, $keywords, $button]) {
            $order++;
            $id = DB::table('bot_entries')->where('question', $question)->value('id');
            if ($id === null) {
                $id = $library->save(null, ['question' => $question, 'answer' => $answer, 'action_type' => $action, 'variants' => $variants, 'keywords' => $keywords], null);
                $added++;
                $this->line("added: {$question}");
            } else {
                $this->line("skipped: {$question} (exists)");
            }
            if ($button !== null && ! DB::table('bot_quick_replies')->where('label_ar', $button)->exists()) {
                DB::table('bot_quick_replies')->insert(['label_ar' => $button, 'action_type' => 'entry', 'target_entry_id' => $id, 'is_root' => true, 'sort_order' => $order]);
                $this->line("button: {$button}");
            }
        }
        KnowledgeBase::changed();
        $this->info("Added {$added} entries.");

        return self::SUCCESS;
    }
}
