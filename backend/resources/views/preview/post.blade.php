<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>معاينة — {{ $post->title }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; font-family: 'Noto Sans Arabic', system-ui, sans-serif; line-height: 1.9; background: #fafaf7; color: #1d1d1b; }
        @media (prefers-color-scheme: dark) { body { background: #151515; color: #eee; } }
        .bar { background: #b45309; color: #fff; padding: .6rem 1rem; text-align: center; font-size: .9rem; }
        main { max-width: 46rem; margin: 0 auto; padding: 1rem 1rem 4rem; }
        h1 { line-height: 1.4; margin: .5rem 0; }
        .meta { color: #777; font-size: .9rem; display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-bottom: 1rem; }
        picture, img { max-width: 100%; height: auto; display: block; }
        figure { margin: 1.5rem 0; }
        figcaption { color: #777; font-size: .85rem; }
        blockquote { border-inline-start: 4px solid #b45309; margin: 1rem 0; padding: .25rem 1rem; color: #555; }
        table { border-collapse: collapse; width: 100%; display: block; overflow-x: auto; }
        th, td { border: 1px solid #ccc; padding: .4rem .6rem; }
        .rq-gallery { list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: .5rem; }
        .rq-read-also, .rq-awareness { background: rgba(180, 83, 9, .08); border-radius: .5rem; padding: .75rem 1rem; margin: 1.5rem 0; }
        a { color: #b45309; }
        .tags a { font-size: .9rem; margin-inline-end: .6rem; }
    </style>
</head>
<body>
<div class="bar">معاينة خاصة — هذه الصفحة غير منشورة للزوار{{ $expires ? ' وينتهي رابطها '.\Illuminate\Support\Carbon::createFromTimestamp((int) $expires)->setTimezone('Asia/Hebron')->format('Y-m-d H:i') : '' }}. لا تشاركها.</div>
<main>
    @if ($post->cover)
        <figure>{!! app(\App\Media\MediaHtml::class)->picture($post->cover, '100vw', 'eager') !!}@if ($post->cover->caption || $post->cover->credit)<figcaption>{{ $post->cover->caption }} {{ $post->cover->credit }}</figcaption>@endif</figure>
    @endif
    <h1>{{ $post->title }}</h1>
    @if ($post->subtitle)<p><strong>{{ $post->subtitle }}</strong></p>@endif
    <div class="meta">
        <span>{{ \App\Models\Post::STATUSES[$post->status] }}</span>
        @if ($post->category)<span>{{ $post->category->name_ar }}</span>@endif
        @if ($post->authors->isNotEmpty())<span>{{ $post->authors->pluck('name')->implode('، ') }}</span>@else<span>فريق رَقَم</span>@endif
        @if ($post->published_at)<span>{{ $post->published_at->setTimezone('Asia/Hebron')->format('Y-m-d H:i') }}</span>@endif
    </div>
    @if ($post->excerpt)<p>{{ $post->excerpt }}</p>@endif
    <article>{!! $body !!}</article>
    @if ($post->correction_note)<p><strong>تصحيح:</strong> {{ $post->correction_note }}</p>@endif
    @if ($post->tags->isNotEmpty())<p class="tags">@foreach ($post->tags as $tag)<a>#{{ $tag->name_ar }}</a>@endforeach</p>@endif
</main>
</body>
</html>
