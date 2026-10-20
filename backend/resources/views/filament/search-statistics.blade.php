@php /** @var \App\Search\SearchStatsReport $report */ @endphp
<div class="grid gap-6">
    @if ($report->problem)
        <p class="text-sm text-warning-600">{{ $report->problem }}</p>
    @endif

    <div class="grid gap-4 sm:grid-cols-3">
        <x-filament::section>
            <div class="text-sm text-gray-500">عمليات البحث</div>
            <div class="text-2xl font-semibold" dir="ltr">{{ number_format($report->searches) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">بدون نتائج</div>
            <div class="text-2xl font-semibold" dir="ltr">{{ number_format($report->zero) }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">الفترة</div>
            <div class="text-base font-medium" dir="ltr">{{ $report->from }} → {{ $report->to }}</div>
        </x-filament::section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([['أكثر الكلمات بحثاً', $report->top, 'لا يوجد بحث في هذه الفترة.'], ['كلمات بلا نتائج', $report->nothingFound, 'كل عمليات البحث وجدت نتائج.']] as [$heading, $rows, $empty])
            <x-filament::section :heading="$heading">
                @if ($rows === [])
                    <p class="text-sm text-gray-500">{{ $empty }}</p>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-gray-500">
                                <th class="py-1 text-start font-medium">الكلمة</th>
                                <th class="py-1 text-end font-medium">مرات البحث</th>
                                <th class="py-1 text-end font-medium">بلا نتائج</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1.5">{{ $row->term }}</td>
                                    <td class="py-1.5 text-end" dir="ltr">{{ number_format($row->searches) }}</td>
                                    <td class="py-1.5 text-end" dir="ltr">{{ number_format($row->zero) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</div>
