{{-- Inline styles on purpose: the panel ships a fixed stylesheet, so utility classes written here would not exist. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            point: $wire.$entangle('{{ $getStatePath() }}'),
            pick(event) {
                const box = this.$refs.picture.getBoundingClientRect();
                this.point = {
                    x: Math.min(1, Math.max(0, +((event.clientX - box.left) / box.width).toFixed(3))),
                    y: Math.min(1, Math.max(0, +((event.clientY - box.top) / box.height).toFixed(3))),
                };
            },
        }"
    >
        @if ($getImageUrl())
            <div dir="ltr" style="position: relative; display: inline-block; max-width: 100%;">
                <img
                    x-ref="picture"
                    src="{{ $getImageUrl() }}"
                    alt=""
                    x-on:click="pick($event)"
                    style="display: block; max-width: 100%; max-height: 420px; cursor: crosshair; touch-action: manipulation;"
                >
                <span
                    x-show="point && point.x !== null && point.x !== undefined"
                    x-bind:style="point ? `left: ${point.x * 100}%; top: ${point.y * 100}%` : ''"
                    class="raqam-focal-mark"
                ></span>
            </div>
            <p style="margin-top: .5rem; font-size: .875rem;">
                اضغط على أهم جزء في الصورة كي لا يُقصّ عند تغيير المقاس.
                <button type="button" x-show="point" x-on:click="point = null" style="text-decoration: underline;">إزالة النقطة</button>
            </p>
        @endif
    </div>
</x-dynamic-component>
