@php
    /*
     * A list's cover picture, as its own link to the record (the column's cell is not wrapped in the row's link).
     * When the title beside it already links to the same page, the picture's link is for the pointer only: out of the
     * tab order and hidden from screen readers, with an empty alt, so the title is not read twice (axe "link-name").
     * When the picture is the only link, its alt is the title, which names the link.
     */
    $record = $getRecord();
    $picture = $getState();
    $url = $column->getTable()->getRecordUrl($record);
    $titleLinks = $titleLinks ?? true;
    $title = (string) ($title ?? '');
@endphp
@if (filled($picture))
    @if (filled($url))
        <a href="{{ $url }}" class="raqam-cover-link" @if ($titleLinks) tabindex="-1" aria-hidden="true" @endif>
            <img src="{{ $picture }}" alt="{{ $titleLinks ? '' : $title }}" loading="lazy" class="raqam-cover-picture">
        </a>
    @else
        <img src="{{ $picture }}" alt="" loading="lazy" class="raqam-cover-picture">
    @endif
@endif
