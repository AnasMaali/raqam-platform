{{-- The bell (20.10). Titles and bodies are escaped like any text. It never polls the server (phase 1 rule). --}}
<div class="me-2">
    <x-filament::dropdown placement="bottom-end" width="sm" max-height="28rem">
        <x-slot name="trigger">
            <x-filament::icon-button icon="heroicon-o-bell" label="الإشعارات" color="gray"
                :badge="$unread > 0 ? ($unread > 99 ? '99+' : $unread) : null" badge-color="danger" data-bell />
        </x-slot>
        <div class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
            <span class="font-semibold">الإشعارات</span>
            @if ($unread > 0)
                <button type="button" wire:click="readAll" class="text-primary-600 hover:underline">تعليم الكل كمقروء</button>
            @endif
        </div>
        <x-filament::dropdown.list>
            @forelse ($items as $item)
                <x-filament::dropdown.list.item wire:click="open({{ (int) $item->id }})" :color="$item->read_at ? 'gray' : 'primary'">
                    <span class="block text-xs text-gray-500">{{ $categories[$item->category] ?? '' }} — {{ \Illuminate\Support\Carbon::parse($item->created_at)->timezone('Asia/Hebron')->format('Y-m-d H:i') }}</span>
                    <span @class(['block', 'font-semibold' => ! $item->read_at])>{{ $item->title }}</span>
                    @if ($item->body)
                        <span class="block text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($item->body, 140) }}</span>
                    @endif
                </x-filament::dropdown.list.item>
            @empty
                <div class="px-3 py-4 text-sm text-gray-500">لا توجد إشعارات.</div>
            @endforelse
        </x-filament::dropdown.list>
        <div class="px-3 py-2 text-sm"><a href="{{ $allUrl }}" class="text-primary-600 hover:underline">كل الإشعارات</a></div>
    </x-filament::dropdown>
</div>
