<?php

namespace App\Console;

use App\Search\SearchForm;
use App\Search\SynonymLibrary;
use App\Search\SynonymRejected;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InstallSynonymsCommand extends Command
{
    protected $signature = 'synonyms:install';

    protected $description = 'Add the starter list of economic synonyms (10.9); groups that touch an existing word are skipped';

    /**
     * Words a reader of economic news uses for the same thing, and the plurals the search cannot guess (broken
     * plurals; the sound feminine plural too). The admin edits or removes any of them on the synonyms screen.
     */
    public const STARTER = [
        ['شيكل', 'شيقل'],
        ['دينار', 'دنانير'],
        ['دولار', 'دولارات'],
        ['سعر', 'أسعار'],
        ['سوق', 'أسواق'],
        ['بنك', 'بنوك', 'مصرف', 'مصارف'],
        ['شركة', 'شركات'],
        ['مصنع', 'مصانع'],
        ['تاجر', 'تجار'],
        ['قرض', 'قروض'],
        ['ضريبة', 'ضرائب'],
        ['استثمار', 'استثمارات'],
        ['تضخم', 'غلاء'],
        ['وقود', 'محروقات'],
        ['بنزين', 'بترول'],
        ['راتب', 'رواتب', 'أجر', 'أجور'],
        ['مشروع', 'مشاريع', 'مشروعات'],
        ['صادرات', 'تصدير'],
        ['واردات', 'استيراد'],
        ['سهم', 'أسهم'],
        ['بورصة', 'سوق الأسهم'],
        ['موازنة', 'ميزانية'],
        ['عملة', 'عملات'],
        ['صناعة', 'صناعات'],
        ['مستهلك', 'مستهلكين', 'مستهلكون'],
        ['غرفة التجارة', 'الغرفة التجارية'],
    ];

    public function handle(SynonymLibrary $library): int
    {
        $taken = DB::table('synonym_terms')->pluck('term')->map(fn (string $t) => SearchForm::text($t))->flip();
        $added = 0;
        $skipped = 0;

        foreach (self::STARTER as $group) {
            // Never on top of the admin's own work: a group with a word already in use is left out whole.
            if (collect($group)->contains(fn (string $t) => $taken->has(SearchForm::text($t)))) {
                $skipped++;

                continue;
            }
            try {
                $library->save(null, $group);
                $added++;
            } catch (SynonymRejected $e) {
                $this->warn(implode('، ', $group).': '.$e->getMessage());
                $skipped++;
            }
        }

        $this->info("Added {$added} synonym group(s), skipped {$skipped}.");

        return self::SUCCESS;
    }
}
