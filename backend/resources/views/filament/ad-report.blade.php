@php /** @var array|null $report */ @endphp
<div class="grid gap-6">
    @if ($problem)
        <p class="text-sm text-danger-600">{{ $problem }}</p>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ([['المشاهدات', number_format($report['total']['impressions'])], ['النقرات', number_format($report['total']['clicks'])], ['نسبة النقر', $report['total']['ctr'] === null ? '—' : $report['total']['ctr'].'%']] as [$label, $value])
                <x-filament::section>
                    <div class="text-sm text-gray-500">{{ $label }}</div>
                    <div class="text-2xl font-semibold" dir="ltr" data-total="{{ $label }}">{{ $value }}</div>
                </x-filament::section>
            @endforeach
        </div>

        <p class="text-sm text-gray-500">المعلن: {{ $report['campaign']->advertiser?->organization?->name ?? '—' }} · الفترة <span dir="ltr">{{ $report['from'] }} → {{ $report['to'] }}</span> (بتوقيت فلسطين) · المشاهدة تُحسب عند ظهور نصف الإعلان على الشاشة، والنقرة قبل الانتقال لموقع المعلن.</p>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section heading="حسب المكان">
                <table class="w-full text-sm">
                    <thead><tr class="text-gray-500"><th class="py-1 text-start font-medium">المكان</th><th class="py-1 text-end font-medium">مشاهدات</th><th class="py-1 text-end font-medium">نقرات</th><th class="py-1 text-end font-medium">النسبة</th></tr></thead>
                    <tbody>
                        @foreach ($report['placements'] as $p)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1.5">{{ $p['name'] }}</td>
                                <td class="py-1.5 text-end" dir="ltr">{{ number_format($p['impressions']) }}</td>
                                <td class="py-1.5 text-end" dir="ltr">{{ number_format($p['clicks']) }}</td>
                                <td class="py-1.5 text-end" dir="ltr">{{ $p['ctr'] === null ? '—' : $p['ctr'].'%' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section heading="حسب اليوم">
                <div class="max-h-[32rem] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-gray-500"><th class="py-1 text-start font-medium">اليوم</th><th class="py-1 text-end font-medium">مشاهدات</th><th class="py-1 text-end font-medium">نقرات</th><th class="py-1 text-end font-medium">النسبة</th></tr></thead>
                        <tbody>
                            @foreach ($report['days'] as $d)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="py-1.5" dir="ltr">{{ $d['date'] }}</td>
                                    <td class="py-1.5 text-end" dir="ltr">{{ number_format($d['impressions']) }}</td>
                                    <td class="py-1.5 text-end" dir="ltr">{{ number_format($d['clicks']) }}</td>
                                    <td class="py-1.5 text-end" dir="ltr">{{ $d['ctr'] === null ? '—' : $d['ctr'].'%' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    @endif
</div>
