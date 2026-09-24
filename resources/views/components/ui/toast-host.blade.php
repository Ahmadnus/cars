@php
    /** Server-flashed toast, handed to the same client bus as JS-raised ones. */
    $flash = session('toast');
@endphp

<div
    x-data="{
        items: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.items.push({ id, ...detail });
            setTimeout(() => this.remove(id), detail.timeout ?? 4500);
        },
        remove(id) { this.items = this.items.filter(i => i.id !== id); },
    }"
    x-on:toast.window="push($event.detail)"
    @if ($flash)
        x-init="push({ message: @js($flash['message'] ?? ''), type: @js($flash['type'] ?? 'success') })"
    @endif
    class="pointer-events-none fixed bottom-4 z-[60] flex w-full max-w-sm flex-col gap-2 px-4 ltr:right-0 rtl:left-0"
>
    <template x-for="item in items" :key="item.id">
        <div x-transition
             class="pointer-events-auto flex items-start gap-3 rounded-xl px-4 py-3 text-sm shadow-lg ring-1 ring-inset"
             :class="{
                 'bg-emerald-50 text-emerald-800 ring-emerald-200': item.type === 'success',
                 'bg-rose-50 text-rose-800 ring-rose-200': item.type === 'error',
                 'bg-amber-50 text-amber-800 ring-amber-200': item.type === 'warning',
                 'bg-white text-ink-800 ring-ink-200': !['success','error','warning'].includes(item.type),
             }"
             role="status">
            <span class="min-w-0 flex-1" x-text="item.message"></span>
            <button type="button" @click="remove(item.id)" class="shrink-0 opacity-60 hover:opacity-100">
                <x-ui.icon name="x" class="size-4" />
            </button>
        </div>
    </template>
</div>
