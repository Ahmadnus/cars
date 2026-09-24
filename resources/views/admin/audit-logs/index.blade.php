@extends('layouts.app')

@section('title', 'سجل التدقيق')
@section('subtitle', $logs->total() . ' عملية مسجلة')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['سجل التدقيق' => null]" />
@endsection

@section('content')
    <x-layout.page-header
        title="سجل التدقيق"
        description="سجل غير قابل للتعديل لكل عملية مالية أو إدارية حساسة."
    />

    <x-ui.filters :action="route('admin.audit-logs.index')" placeholder="ابحث في الوصف أو السبب…">
        <x-slot:fields>
            <x-form.select name="action" label="نوع العملية" :options="$actions" :selected="request('action')" placeholder="كل العمليات" />
            <x-form.select name="user_id" label="المستخدم" :options="$users" :selected="request('user_id')" placeholder="كل المستخدمين" />
            <x-form.input name="from" type="date" label="من تاريخ" :value="request('from')" />
            <x-form.input name="to" type="date" label="إلى تاريخ" :value="request('to')" />
        </x-slot:fields>
    </x-ui.filters>

    <x-ui.card padded="false">
        @if ($logs->isEmpty())
            <x-ui.empty-state icon="shield" title="لا توجد سجلات مطابقة" />
        @else
            <x-ui.table :headers="['التاريخ', 'المستخدم', 'العملية', 'الوصف', 'الفرع', 'IP', ['label' => '', 'align' => 'end']]">
                @foreach ($logs as $log)
                    <tr class="hover:bg-ink-50">
                        <td class="whitespace-nowrap px-3 py-2.5 text-xs text-ink-600">
                            {{ $log->created_at?->format('Y-m-d H:i') }}
                        </td>
                        <td class="px-3 py-2.5 text-ink-800">{{ $log->user?->name ?? 'النظام' }}</td>
                        <td class="px-3 py-2.5">
                            <x-ui.badge tone="brand">
                                {{ \App\Http\Controllers\Admin\AuditLogController::actionLabel($log->action) }}
                            </x-ui.badge>
                        </td>
                        <td class="px-3 py-2.5 text-ink-600">
                            {{ $log->description ?: '—' }}
                            @if ($log->reason)
                                <span class="block text-xs text-ink-400">السبب: {{ $log->reason }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2.5 text-xs text-ink-500">{{ $log->branch?->name ?? 'عام' }}</td>
                        <td class="px-3 py-2.5 font-mono text-xs text-ink-400" dir="ltr">{{ $log->ip_address ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-end">
                            <x-ui.button :href="route('admin.audit-logs.show', $log)" variant="ghost" size="sm">تفاصيل</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
