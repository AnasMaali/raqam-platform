{{-- A card of the panel's home: a short list, each item a link to its screen with a quiet second line (Dashboard::list). --}}
<ul class="raqam-dash-list">
    @forelse ($items as $item)
        <li>
            @if ($item['url'])
                <a href="{{ $item['url'] }}" class="raqam-dash-link">{{ $item['label'] }}</a>
            @else
                <span>{{ $item['label'] }}</span>
            @endif
            @if ($item['meta'])
                <span class="raqam-dash-meta">{{ $item['meta'] }}</span>
            @endif
        </li>
    @empty
        <li class="raqam-dash-meta">{{ $empty }}</li>
    @endforelse
</ul>
