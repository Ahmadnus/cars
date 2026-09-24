@props([
    'action',
    'method' => 'POST',
    'title' => 'تأكيد العملية',
    'message' => 'هل أنت متأكد من تنفيذ هذه العملية؟',
    'confirmLabel' => 'تأكيد',
    'variant' => 'danger',
    'reasonLabel' => null,
    'reasonName' => 'reason',
])

{{-- Inline confirmation: the destructive action is only submitted from inside
     the dialog, so a stray click on the trigger can never fire it. A reason
     field appears when the operation records one in the audit trail. --}}
<div x-data="{ open: false }" class="inline-block">
    <div @click="open = true">{{ $trigger }}</div>

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
        <div x-show="open" x-transition.opacity @click="open = false" class="fixed inset-0 bg-ink-900/50"></div>

        <div x-show="open" x-transition class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-xl">
            <form method="POST" action="{{ $action }}">
                @csrf
                @if (! in_array(strtoupper($method), ['GET', 'POST']))
                    @method($method)
                @endif

                <div class="flex gap-3 p-5">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-600">
                        <x-ui.icon name="alert" class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-semibold text-ink-900">{{ $title }}</h2>
                        <p class="mt-1 text-sm text-ink-500">{{ $message }}</p>

                        @if ($reasonLabel)
                            <label class="mt-3 block">
                                <span class="mb-1 block text-xs font-medium text-ink-600">{{ $reasonLabel }}</span>
                                <textarea name="{{ $reasonName }}" rows="2" required
                                          class="w-full rounded-lg border-ink-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
                            </label>
                        @endif
                    </div>
                </div>

                <footer class="flex items-center justify-end gap-2 border-t border-ink-200 bg-ink-50 px-5 py-3">
                    <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
                    <x-ui.button type="submit" :variant="$variant" size="sm">{{ $confirmLabel }}</x-ui.button>
                </footer>
            </form>
        </div>
    </div>
</div>
