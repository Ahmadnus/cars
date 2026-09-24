@extends('layouts.portal')

@section('title', 'ملفي')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-ink-900">{{ $trainee->full_name }}</h1>
            <p class="mt-0.5 text-xs text-ink-400">
                رقم الملف {{ $trainee->trainee_number }}
                @if ($trainee->branch) · {{ $trainee->branch->name }} @endif
            </p>
        </div>
        <x-ui.status :value="$trainee->status" type="trainee" />
    </div>

    {{-- ------------------------------------------------------------- figures --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card
            label="الحصص المتبقية"
            :value="$balance ? $balance['remaining'] : '—'"
            :tone="$balance && $balance['remaining'] <= 2 ? 'danger' : 'brand'"
            :hint="$balance ? 'من أصل '.$balance['credited'].' حصة' : 'لا توجد باقة نشطة'" />

        <x-ui.stat-card
            label="الحصص المنجزة"
            :value="$balance ? $balance['completed'] : 0"
            tone="success" />

        <x-ui.stat-card
            label="الجاهزية للامتحان"
            :value="$readiness.'%'"
            tone="info" />

        <x-ui.stat-card
            label="المبلغ المتبقي"
            :value="money($outstanding)"
            :tone="$outstanding > 0 ? 'warning' : 'success'"
            :hint="'المدفوع '.money($paid)" />
    </div>

    @if ($balance && $balance['remaining'] <= 0)
        <x-ui.alert type="warning" class="mt-5" title="انتهى رصيد حصصك">
            يرجى مراجعة إدارة المركز لتجديد الباقة قبل حجز حصة جديدة.
        </x-ui.alert>
    @endif

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        {{-- ------------------------------------------------------ my package --}}
        <x-ui.card title="باقتي">
            @if ($package)
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">الباقة</dt>
                        <dd class="font-medium text-ink-900">{{ $package->package?->name ?? "—" }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">عدد الحصص</dt>
                        <dd class="font-medium text-ink-900">{{ $balance['credited'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">المستهلكة</dt>
                        <dd class="font-medium text-ink-900">{{ $balance['consumed'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-ink-500">مدرّبي</dt>
                        <dd class="font-medium text-ink-900">{{ $trainee->trainer?->full_name ?? 'لم يُسند بعد' }}</dd>
                    </div>
                    @if ($trainee->exam_date)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-500">موعد الامتحان</dt>
                            <dd class="font-medium text-ink-900">{{ $trainee->exam_date->format('Y-m-d') }}</dd>
                        </div>
                    @endif
                </dl>
            @else
                <x-ui.empty-state
                    title="لا توجد باقة نشطة"
                    description="يرجى مراجعة إدارة المركز لإسناد باقة تدريب." />
            @endif
        </x-ui.card>

        {{-- --------------------------------------------------- next lessons --}}
        <x-ui.card title="حصصي القادمة" class="lg:col-span-2" :padded="false">
            @if ($upcoming->isEmpty())
                <x-ui.empty-state
                    title="لا توجد حصص مجدولة"
                    description="ستظهر هنا مواعيدك بعد أن تحجزها إدارة المركز." />
            @else
                <x-ui.table :headers="['التاريخ', 'الوقت', 'المدرب', 'المركبة']">
                    @foreach ($upcoming as $session)
                        <tr class="border-b border-ink-100 last:border-0">
                            <td class="px-3 py-3">{{ $session->scheduled_date->format('Y-m-d') }}</td>
                            <td class="px-3 py-3 text-ink-500">
                                {{ substr((string) $session->start_time, 0, 5) }}
                                —
                                {{ substr((string) $session->end_time, 0, 5) }}
                            </td>
                            <td class="px-3 py-3">{{ $session->trainer?->full_name ?? '—' }}</td>
                            <td class="px-3 py-3 text-ink-500">
                                {{ $session->vehicle ? $session->vehicle->name.' · '.$session->vehicle->plate_number : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        {{-- -------------------------------------------------------- my skills --}}
        <x-ui.card title="مستوى مهاراتي" subtitle="يحدّثه مدرّبك بعد كل حصة">
            @if ($skills->isEmpty())
                <x-ui.empty-state title="لم يتم تقييم أي مهارة بعد" />
            @else
                <ul class="space-y-2.5">
                    @foreach ($skills as $evaluation)
                        <li class="flex items-center justify-between gap-3">
                            <span class="min-w-0 truncate text-sm">{{ $evaluation->skill?->name_ar ?? '—' }}</span>
                            <x-ui.status :value="$evaluation->level" type="rating" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        {{-- ------------------------------------------------------- my history --}}
        <x-ui.card title="سجل حصصي" :padded="false">
            @if ($history->isEmpty())
                <x-ui.empty-state title="لا يوجد سجل بعد" />
            @else
                <ul class="divide-y divide-ink-100">
                    @foreach ($history as $session)
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-ink-900">
                                    {{ $session->scheduled_date->format('Y-m-d') }}
                                </p>
                                <p class="text-xs text-ink-400">
                                    {{ $session->trainer?->full_name ?? '—' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($session->overall_rating)
                                    <x-ui.status :value="$session->overall_rating" type="rating" />
                                @endif
                                <x-ui.status :value="$session->status" type="session" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    {{-- ------------------------------------------------------------ payments --}}
    <x-ui.card title="مدفوعاتي" class="mt-5" :padded="false">
        @if ($payments->isEmpty())
            <x-ui.empty-state title="لا توجد مدفوعات مسجّلة" />
        @else
            <x-ui.table :headers="['التاريخ', 'المبلغ', 'الحالة', 'الإيصال']">
                @foreach ($payments as $payment)
                    <tr class="border-b border-ink-100 last:border-0">
                        <td class="px-3 py-3">{{ $payment->paid_on?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-3 py-3 font-medium">{{ money($payment->amount) }}</td>
                        <td class="px-3 py-3"><x-ui.status :value="$payment->status" type="payment" /></td>
                        <td class="px-3 py-3 text-ink-500">{{ $payment->receipt_number ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>
@endsection
