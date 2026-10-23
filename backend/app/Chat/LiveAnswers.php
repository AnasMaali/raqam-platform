<?php

namespace App\Chat;

use App\Markets\RateBoard;
use App\Search\SearchForm;
use App\Weather\WeatherBoard;

/**
 * Answers with today's numbers (18.7): the currency board and the weather, read from the same cached boards the site
 * uses (never from the outside sources). The question picks the currency or the city when it names one.
 */
final class LiveAnswers
{
    public function __construct(private RateBoard $rates, private WeatherBoard $weather) {}

    /** @return array{html: string, links: list<array{label: string, url: string}>} */
    public function rates(string $question): array
    {
        $rows = collect($this->rates->rows());
        if ($rows->isEmpty()) {
            return ['html' => '<p>أسعار العملات غير متوفرة الآن.</p>', 'links' => [['label' => 'صفحة العملات', 'url' => '/currencies']]];
        }
        $form = ' '.SearchForm::text($question).' ';
        $named = $rows->filter(fn ($r) => str_contains($form, ' '.SearchForm::text((string) $r['name']).' ')
            || collect(SearchForm::of((string) $r['name'])->words())->contains(fn ($w) => mb_strlen($w) >= 4 && str_contains($form, ' '.$w.' '))
            || stripos($question, (string) $r['code']) !== false);
        $show = $named->isNotEmpty() ? $named : $rows->whereIn('code', ['USD', 'JOD', 'EUR']);
        if ($show->isEmpty()) {
            $show = $rows->take(3);
        }

        $lines = $show->take(5)->map(fn ($r) => '<li>'.e($r['name']).': شراء '.e((string) $r['buy']).' — بيع '.e((string) $r['sell']).' شيكل</li>')->implode('');
        $date = $this->rates->meta()['rate_date'] ?? null;

        return [
            'html' => '<p>أسعار اليوم'.($date ? ' ('.e((string) $date).')' : '').':</p><ul>'.$lines.'</ul><p>الأسعار استرشادية، حسب السعر التمثيلي لبنك إسرائيل.</p>',
            'links' => [['label' => 'كل العملات والمحوّل', 'url' => '/currencies']],
        ];
    }

    /** @return array{html: string, links: list<array{label: string, url: string}>} */
    public function weather(string $question): array
    {
        $cities = collect($this->weather->cities());
        if ($cities->isEmpty()) {
            return ['html' => '<p>الطقس غير متوفر الآن.</p>', 'links' => [['label' => 'صفحة الطقس', 'url' => '/weather']]];
        }
        $form = ' '.SearchForm::text($question).' ';
        $city = $cities->first(fn ($c) => collect(SearchForm::of((string) $c['name'])->words())->contains(fn ($w) => mb_strlen($w) >= 3 && str_contains($form, ' '.$w.' ')))
            ?? $cities->firstWhere('is_default', true) ?? $cities->first();
        $w = $this->weather->show((string) $city['slug']);
        $now = $w['now'] ?? null;
        if ($now === null) {
            return ['html' => '<p>طقس '.e($city['name']).' غير متوفر الآن.</p>', 'links' => [['label' => 'طقس '.$city['name'], 'url' => $city['path']]]];
        }
        $today = $now['today'] ?? null;

        return [
            'html' => '<p>طقس '.e($city['name']).' الآن: '.e((string) round((float) $now['temperature'])).'° — '.e((string) $now['condition']['text']).'.</p>'
                .($today ? '<p>اليوم: العظمى '.e((string) round((float) $today['max'])).'° والصغرى '.e((string) round((float) $today['min'])).'°.</p>' : ''),
            'links' => [['label' => 'طقس '.$city['name'].' و5 أيام', 'url' => $city['path']]],
        ];
    }
}
