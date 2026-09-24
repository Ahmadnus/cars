@extends('layouts.app')

@section('title', 'تفاصيل عملية مسجلة')
@section('subtitle', \App\Http\Controllers\Admin\AuditLogController::actionLabel($log->action))

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['سجل التدقيق' => route('admin.audit-logs.index'), 'تفاصيل' => null]" />
@endsection

@section('content')
<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-ui.card title="ما الذي تغيّر">
            @if (empty($changes))
                <p class="text-sm text-ink-400">لا توجد تغييرات مفصّلة مسجلة لهذه العملية.</p>
            @else
                <x-ui.table :headers="['الحقل', 'القيمة السابقة', 'القيمة الجديدة']">
                    @foreach ($changes as $field => $change)
                        <tr>
                            <td class="px-3 py-2.5 font-mono text-xs text-ink-600" dir="ltr">{{ $field }}</td>
                            <td class="px-3 py-2.5">
                                <span class="rounded bg-rose-50 px-1.5 py-0.5 text-sm text-rose-800">
                                    {{ is_scalar($change['before']) ? ($change['before'] ?? '—') : json_encode($change['before'], JSON_UNESCAPED_UNICODE) }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-sm text-emerald-800">
                                    {{ is_scalar($change['after']) ? ($change['after'] ?? '—') : json_encode($change['after'], JSON_UNESCAPED_UNICODE) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        @foreach (['before' => 'البيانات قبل التغيير', 'after' => 'البيانات بعد التغيير'] as $key => $title)
            @if (! empty($log->{$key}))
                <x-ui.card :title="$title">
                    <pre class="scrollbar-thin overflow-x-auto rounded-lg bg-ink-900 p-3 text-xs text-ink-100" dir="ltr">{{ json_encode($log->{$key}, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </x-ui.card>
            @endif
        @endforeach
    </div>

    <div class="space-y-5">
        <x-ui.card title="معلومات العملية">
            <dl class="space-y-3 text-sm">
                @foreach ([
                    'العملية' => \App\Http\Controllers\Admin\AuditLogController::actionLabel($log->action),
                    'المفتاح' => $log->action,
                    'المستخدم' => $log->user?->name ?? 'النظام',
                    'الفرع' => $log->branch?->name ?? 'عام',
                    'التاريخ' => $log->created_at?->format('Y-m-d H:i:s'),
                    'نوع السجل' => $log->auditable_type ? class_basename($log->auditable_type) : '—',
                    'معرّف السجل' => $log->auditable_id ?? '—',
                    'عنوان IP' => $log->ip_address ?? '—',
                ] as $label => $value)
                    <div class="flex items-baseline justify-between gap-3 border-b border-ink-100 pb-2 last:border-0">
                        <dt class="shrink-0 text-ink-500">{{ $label }}</dt>
                        <dd class="min-w-0 truncate text-end font-medium text-ink-900">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($log->reason)
                <div class="mt-4 rounded-lg bg-amber-50 p-3">
                    <p class="text-xs font-medium text-amber-800">السبب المسجّل</p>
                    <p class="mt-1 text-sm text-amber-900">{{ $log->reason }}</p>
                </div>
            @endif

            @if ($log->user_agent)
                <p class="mt-3 break-all text-xs text-ink-400" dir="ltr">{{ $log->user_agent }}</p>
            @endif
        </x-ui.card>

        <x-ui.alert type="info">
            سجلات التدقيق تُكتب مرة واحدة ولا يمكن تعديلها أو حذفها من الواجهة.
        </x-ui.alert>
    </div>
</div>
@endsection
