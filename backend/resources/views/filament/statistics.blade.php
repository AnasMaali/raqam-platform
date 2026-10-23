@php
    /** @var array<string, mixed> $r */
    $t = $r['totals'];
    $m = $r['modules'];
    $change = \App\Analytics\StatsReport::change($t['page_views'], $t['previous_page_views']);
    $grid = 'display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(min(100%,16rem),1fr))';
    $tiles = 'display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(min(100%,10rem),1fr))';
    $num = fn ($n) => $n === null ? '—' : number_format((int) $n);

    // The chart: one bar a day (page views) and a line (visitors), drawn on the server (no script, nothing polls).
    $days = $r['days'];
    $count = max(1, count($days));
    $max = max(1, ...array_column($days, 'page_views'));
    [$w, $h, $pad] = [720, 200, 24];
    $step = ($w - 2 * $pad) / $count;
    $bar = max(1, $step * 0.7);
    $y = fn (int $v) => $h - $pad - ($h - 2 * $pad) * $v / $max;
    $line = collect($days)->values()->map(fn ($d, $i) => round($pad + $step * ($i + 0.5), 1).','.round($y($d['visitors']), 1))->implode(' ');
    $labelEvery = (int) ceil($count / 8);
@endphp
<div style="display:grid;gap:1.5rem" data-statistics>
    @if ($r['problem'])
        <p style="color:var(--warning-600,#b45309);font-size:.875rem">{{ $r['problem'] }}</p>
    @endif

    <div style="{{ $tiles }}">
        @foreach ([
            ['مشاهدات الصفحات', $num($t['page_views']), $change === null ? null : $change.' عن الفترة السابقة'],
            ['الزوار', $num($t['visitors']), 'مجموع زوار كل يوم'],
            ['الزيارات', $num($t['visits']), null],
            ['صفحات لكل زيارة', $t['pages_per_visit'] ?? '—', null],
            ['قراءات المواد', $num($t['readings']), null],
        ] as [$label, $value, $note])
            <x-filament::section compact>
                <div style="font-size:.8rem;opacity:.7">{{ $label }}</div>
                <div style="font-size:1.6rem;font-weight:600" dir="ltr" data-tile="{{ $label }}">{{ $value }}</div>
                @if ($note)<div style="font-size:.75rem;opacity:.7">{{ $note }}</div>@endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section :heading="'المشاهدات والزوار يوماً بيوم ('.$r['from'].' → '.$r['to'].')'">
        <figure style="margin:0">
            <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="مشاهدات الصفحات والزوار لكل يوم" style="width:100%;height:auto;display:block" dir="ltr">
                <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}" style="stroke:currentColor;opacity:.25" />
                @foreach ($days as $i => $d)
                    @php $x = $pad + $step * $i + ($step - $bar) / 2; $top = $y($d['page_views']); @endphp
                    <rect x="{{ round($x, 1) }}" y="{{ round($top, 1) }}" width="{{ round($bar, 1) }}" height="{{ round($h - $pad - $top, 1) }}" rx="2"
                          style="fill:var(--primary-500,#f59e0b)" data-day="{{ $d['date'] }}"><title>{{ $d['date'] }}: {{ $d['page_views'] }} مشاهدة، {{ $d['visitors'] }} زائر</title></rect>
                    @if ($i % $labelEvery === 0)
                        <text x="{{ round($pad + $step * ($i + 0.5), 1) }}" y="{{ $h - 6 }}" text-anchor="middle" style="font-size:10px;fill:currentColor;opacity:.7">{{ substr($d['date'], 5) }}</text>
                    @endif
                @endforeach
                @if ($count > 1)
                    <polyline points="{{ $line }}" style="fill:none;stroke:var(--gray-500,#6b7280);stroke-width:2" />
                @endif
                <text x="{{ $pad }}" y="14" style="font-size:10px;fill:currentColor;opacity:.7">{{ number_format($max) }}</text>
            </svg>
            <figcaption style="display:flex;gap:1rem;font-size:.8rem;opacity:.8;margin-top:.5rem">
                <span><span style="display:inline-block;width:.75rem;height:.75rem;background:var(--primary-500,#f59e0b);border-radius:2px"></span> مشاهدات الصفحات</span>
                <span><span style="display:inline-block;width:.75rem;height:2px;background:var(--gray-500,#6b7280);vertical-align:middle"></span> الزوار</span>
                @if ($r['live'])<span>اليوم يُحسب لحظياً؛ الأيام السابقة كما خُزّنت.</span>@endif
            </figcaption>
            <details style="margin-top:.5rem;font-size:.85rem">
                <summary>عرض كجدول</summary>
                <table style="width:100%;margin-top:.5rem">
                    <thead><tr><th style="text-align:start">اليوم</th><th style="text-align:end">المشاهدات</th><th style="text-align:end">الزوار</th></tr></thead>
                    <tbody>
                        @foreach (array_reverse($days) as $d)
                            <tr><td dir="ltr" style="text-align:start">{{ $d['date'] }}</td><td style="text-align:end" dir="ltr">{{ number_format($d['page_views']) }}</td><td style="text-align:end" dir="ltr">{{ number_format($d['visitors']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        </figure>
    </x-filament::section>

    <div style="{{ $grid }}">
        @foreach ([['مصادر الزيارات', $r['sources'], 'لا زيارات في هذه الفترة.'], ['الأجهزة', $r['devices'], 'لا زيارات في هذه الفترة.']] as [$heading, $rows, $empty])
            <x-filament::section :heading="$heading">
                @forelse ($rows as $row)
                    <div style="margin-bottom:.6rem" data-row="{{ $row['code'] }}">
                        <div style="display:flex;justify-content:space-between;font-size:.875rem"><span>{{ $row['name'] }}</span><span dir="ltr">{{ number_format($row['count']) }} · {{ $row['share'] }}%</span></div>
                        <div style="height:.4rem;border-radius:.2rem;background:rgba(127,127,127,.2)"><div style="height:100%;border-radius:.2rem;width:{{ (float) $row['share'] }}%;background:var(--primary-500,#f59e0b)"></div></div>
                    </div>
                @empty
                    <p style="font-size:.875rem;opacity:.7">{{ $empty }}</p>
                @endforelse
            </x-filament::section>
        @endforeach

        <x-filament::section heading="التفاعلات">
            <table style="width:100%;font-size:.875rem">
                <tbody>
                    @forelse ($r['events'] as $row)
                        <tr><td>{{ $row['name'] }}</td><td style="text-align:end" dir="ltr">{{ number_format($row['count']) }}</td></tr>
                    @empty
                        <tr><td style="opacity:.7">لا تفاعلات في هذه الفترة.</td></tr>
                    @endforelse
                    @foreach ($r['contact_buttons'] as $row)
                        <tr><td>زر تواصل للخدمات: {{ $row['name'] }}</td><td style="text-align:end" dir="ltr">{{ number_format($row['count']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            @if ($r['videos'] !== [])
                <div style="font-size:.8rem;opacity:.7;margin-top:.75rem">أكثر الفيديوهات تشغيلاً</div>
                <table style="width:100%;font-size:.875rem">
                    @foreach ($r['videos'] as $row)
                        <tr><td>{{ $row['title'] }}</td><td style="text-align:end" dir="ltr">{{ number_format($row['count']) }}</td></tr>
                    @endforeach
                </table>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section heading="الأكثر قراءة">
        @if ($r['posts'] === [])
            <p style="font-size:.875rem;opacity:.7">لا قراءات في هذه الفترة.</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:.875rem;min-width:28rem">
                    <thead><tr style="opacity:.7"><th style="text-align:start">المادة</th><th style="text-align:start">النوع</th><th style="text-align:start">التصنيف</th><th style="text-align:end">القراءات</th></tr></thead>
                    <tbody>
                        @foreach ($r['posts'] as $row)
                            <tr data-post="{{ $row['id'] }}">
                                <td>{{ $row['title'] }}@unless ($row['live']) <span style="opacity:.6">(غير منشورة الآن)</span>@endunless</td>
                                <td>{{ $row['kind'] }}</td><td>{{ $row['category'] ?? '—' }}</td>
                                <td style="text-align:end" dir="ltr">{{ number_format($row['count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <div style="{{ $grid }}">
        @foreach ([['القراءات حسب التصنيف', $r['by_category']], ['القراءات حسب القسم الخاص', $r['by_section']], ['القراءات حسب الكاتب', $r['by_author']]] as [$heading, $rows])
            <x-filament::section :heading="$heading">
                <table style="width:100%;font-size:.875rem">
                    @forelse ($rows as $row)
                        <tr><td>{{ $row['name'] }} <span style="opacity:.6">({{ $row['posts'] }} مادة)</span></td><td style="text-align:end" dir="ltr">{{ number_format($row['count']) }}</td></tr>
                    @empty
                        <tr><td style="opacity:.7">لا قراءات في هذه الفترة.</td></tr>
                    @endforelse
                </table>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="باقي الأقسام في نفس الفترة">
        <div style="{{ $grid }}">
            <div data-module="search">
                <strong>البحث</strong>
                <div style="font-size:.875rem">عمليات البحث: <span dir="ltr">{{ number_format($m['search']['searches']) }}</span> — بلا نتائج: <span dir="ltr">{{ number_format($m['search']['zero']) }}</span></div>
                @if ($m['search']['top'] !== [])<div style="font-size:.8rem;opacity:.75">الأكثر: {{ implode('، ', $m['search']['top']) }}</div>@endif
                <a href="{{ \App\Filament\Pages\SearchStatistics::getUrl() }}" style="font-size:.8rem;color:var(--primary-600,#d97706)">إحصائيات البحث ←</a>
            </div>
            <div data-module="ads">
                <strong>الإعلانات</strong>
                <div style="font-size:.875rem">مشاهدات: <span dir="ltr">{{ number_format($m['ads']['views']) }}</span> — نقرات: <span dir="ltr">{{ number_format($m['ads']['clicks']) }}</span> — نسبة النقر: <span dir="ltr">{{ $m['ads']['ctr'] === null ? '—' : $m['ads']['ctr'].'%' }}</span></div>
                <div style="font-size:.8rem;opacity:.75">حملات ظهرت: {{ $m['ads']['campaigns'] }}</div>
            </div>
            <div data-module="newsletter">
                <strong>النشرة البريدية</strong>
                <div style="font-size:.875rem">جدد: <span dir="ltr">{{ number_format($m['newsletter']['joined']) }}</span> — ألغوا: <span dir="ltr">{{ number_format($m['newsletter']['left']) }}</span></div>
                <div style="font-size:.8rem;opacity:.75">المشتركون الآن: {{ number_format($m['newsletter']['active']) }}</div>
            </div>
            <div data-module="chatbot">
                <strong>الشات بوت</strong>
                <div style="font-size:.875rem">محادثات: <span dir="ltr">{{ number_format($m['chatbot']['conversations']) }}</span> — أسئلة: <span dir="ltr">{{ number_format($m['chatbot']['questions']) }}</span></div>
                <div style="font-size:.8rem;opacity:.75">نسبة الإجابة: <span dir="ltr">{{ $m['chatbot']['rate'] === null ? '—' : $m['chatbot']['rate'].'%' }}</span></div>
            </div>
            <div data-module="contact">
                <strong>«اتصل بنا»</strong>
                <div style="font-size:.875rem">رسائل: <span dir="ltr">{{ number_format($m['contact']['messages']) }}</span></div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="صفحات غير موجودة يصلها الزوار">
        @if ($r['not_found'] === [])
            <p style="font-size:.875rem;opacity:.7">لا صفحات مفقودة مفتوحة.</p>
        @else
            <table style="width:100%;font-size:.875rem">
                @foreach ($r['not_found'] as $row)
                    <tr><td dir="ltr" style="text-align:start;word-break:break-all">{{ $row['path'] }}</td><td style="text-align:end" dir="ltr">{{ number_format($row['hits']) }}</td></tr>
                @endforeach
            </table>
        @endif
        <a href="{{ \App\Filament\Resources\NotFoundPaths\NotFoundPathResource::getUrl() }}" style="font-size:.8rem;color:var(--primary-600,#d97706)">كل الصفحات غير الموجودة ←</a>
    </x-filament::section>
</div>
