@extends('layouts.app')

@section('title', 'المصاريف المتكررة')
@section('subtitle', 'الالتزام الشهري: ' . money($monthlyTotal))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المصاريف' => route('admin.expenses.index'), 'مصاريف متكررة' => null]" />
@endsection

@section('content')
    <x-layout.page-header
        title="المصاريف المتكررة"
        description="قوالب تُنشئ مصاريف تلقائياً عند استحقاقها — إيجار، كهرباء، اشتراكات."
    >
        <x-slot:actions>
            <x-ui.button type="button" @click="$dispatch('open-modal', 'new-recurring')" icon="plus">مصروف متكرر</x-ui.button>
        </x-slot:actions>
    </x-layout.page-header>

    @if ($due->isNotEmpty())
        <x-ui.alert type="warning" class="mb-4" title="مصاريف مستحقة الآن">
            <ul class="mt-1 space-y-1 text-xs">
                @foreach ($due as $template)
                    <li>{{ $template->name }} — {{ money($template->amount) }} (استحقاق {{ $template->nextDueDate()?->format('Y-m-d') }})</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <x-ui.card padded="false">
        @if ($templates->isEmpty())
            <x-ui.empty-state icon="repeat" title="لا توجد مصاريف متكررة"
                              description="أضف قالباً للإيجار أو الفواتير الثابتة ليُنشئها النظام تلقائياً." />
        @else
            <x-ui.table :headers="['المصروف', 'التصنيف', ['label' => 'المبلغ', 'align' => 'end'], 'التكرار', 'يوم الاستحقاق', 'الاستحقاق القادم', 'الحالة', ['label' => '', 'align' => 'end']]">
                @foreach ($templates as $template)
                    @php $next = $template->nextDueDate(); @endphp
                    <tr @class(['hover:bg-ink-50', 'opacity-60' => $template->status !== 'active'])>
                        <td class="px-3 py-3 font-medium text-ink-900">{{ $template->name }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ $template->category?->name_ar }}</td>
                        <td class="px-3 py-3 text-end font-semibold tabular-nums">{{ money($template->amount) }}</td>
                        <td class="px-3 py-3 text-ink-600">{{ $frequencies[$template->frequency] ?? $template->frequency }}</td>
                        <td class="px-3 py-3 text-center tabular-nums text-ink-600">{{ $template->due_day }}</td>
                        <td class="whitespace-nowrap px-3 py-3">
                            @if ($next)
                                <span @class(['text-amber-700 font-medium' => $template->isDue(), 'text-ink-600' => ! $template->isDue()])>
                                    {{ $next->format('Y-m-d') }}
                                </span>
                            @else
                                <span class="text-ink-400">منتهٍ</span>
                            @endif
                        </td>
                        <td class="px-3 py-3"><x-ui.status type="generic" :value="$template->status" /></td>
                        <td class="px-3 py-3 text-end">
                            <div class="flex justify-end gap-1">
                                @if ($template->status === 'active' && $next)
                                    <form method="POST" action="{{ route('admin.recurring-expenses.generate', $template) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="secondary" size="sm">تسجيل الآن</x-ui.button>
                                    </form>
                                @endif

                                <x-ui.confirm
                                    :action="route('admin.recurring-expenses.destroy', $template)"
                                    method="DELETE"
                                    title="حذف المصروف المتكرر"
                                    message="لن تتأثر المصاريف التي أُنشئت سابقاً من هذا القالب."
                                    confirm-label="حذف"
                                >
                                    <x-slot:trigger>
                                        <x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-rose-600" />
                                    </x-slot:trigger>
                                </x-ui.confirm>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="new-recurring" title="مصروف متكرر جديد" max-width="xl">
        <form method="POST" action="{{ route('admin.recurring-expenses.store') }}" id="new-recurring-form" class="grid gap-4 sm:grid-cols-2">
            @csrf
            <x-form.input name="name" label="اسم المصروف" required class="sm:col-span-2" />
            <x-form.select name="expense_category_id" label="التصنيف" :options="$categories" placeholder="اختر التصنيف" required />
            <x-form.select name="payment_method_id" label="طريقة الدفع" :options="$methods" placeholder="اختر الطريقة" />
            <x-form.input name="amount" type="number" step="0.01" min="0.01" label="المبلغ" required />
            <x-form.select name="frequency" label="التكرار" :options="$frequencies" required />
            <x-form.input name="due_day" type="number" min="1" max="31" value="1" label="يوم الاستحقاق" required />
            <x-form.input name="starts_on" type="date" label="تاريخ البدء" :value="now()->startOfMonth()->toDateString()" required />
            <x-form.input name="ends_on" type="date" label="تاريخ الانتهاء" hint="اتركه فارغاً لمصروف مستمر." />
            <x-form.select name="status" label="الحالة" :options="['active' => 'نشط', 'inactive' => 'غير نشط']" required />
            <x-form.textarea name="notes" label="ملاحظات" rows="2" class="sm:col-span-2" />
        </form>

        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
            <x-ui.button type="submit" size="sm" form="new-recurring-form">إنشاء</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
