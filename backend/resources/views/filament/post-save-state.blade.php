{{--
    The start of the editor's bar: the item's state as stored, and what has happened to the editor's changes.
    The editor's script (post-editor.blade.php) compares the form with what was last saved every second and announces
    it with the window event `raqam-post-state`; the last state is kept on window, because Livewire draws this bar again
    after every request.
--}}
<div
    class="raqam-save-state"
    x-data="{
        dirty: window.raqamPostState?.dirty ?? false,
        note: window.raqamPostState?.note ?? '',
    }"
    x-on:raqam-post-state.window="dirty = $event.detail.dirty; note = $event.detail.note"
>
    <x-filament::badge :color="match ($tone) { 'published' => 'success', 'scheduled' => 'warning', default => 'gray' }">
        {{ $status }}
    </x-filament::badge>
    <span class="raqam-save-state-text" role="status" aria-live="polite">
        <span x-show="dirty" class="raqam-save-state-dirty">تغييرات غير محفوظة</span><span x-show="dirty && note" x-text="' · ' + note"></span>
        <span x-show="! dirty" x-text="note || 'لا تغييرات غير محفوظة'"></span>
    </span>
</div>
