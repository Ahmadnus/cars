@extends('layouts.app')

@section('title', 'لوحة التحكم')
@section('subtitle', arabic_date(now()))

@section('content')
    @php
        $today = $data['today'];
        $training = $data['training'];
        $finance = $data['finance'] ?? null;
        $pending = $data['pending'];
        $charts = $data['charts'];
    @endphp

    {{-- Today's operations --}}
    <section class="mb-6">
        <h2 class="mb-3 text-sm font-semibold text-ink-700">عمليات اليوم</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat-card
                label="حصص اليوم"
                :value="$today['lessons_total']"
                icon="steering"
                :hint="$today['lessons_completed'] . ' منجزة · ' . $today['lessons_scheduled'] . ' مجدولة'"
            />
            <x-ui.stat-card label="متدربو اليوم" :value="$today['trainees_count']" icon="users" tone="info" />
            <x-ui.stat-card label="المدربون العاملون" :value="$today['trainers_count']" icon="badge" tone="neutral" />
            <x-ui.stat-card
                label="متدربون نشطون"
                :value="$training['active']"
                icon="check"
                tone="success"
                :hint="$training['new_this_month'] . ' جديد هذا الشهر'"
                :href="route('admin.trainees.index')"
            />
        </div>
    </section>

    {{-- Financial summary: rendered only when the user holds dashboard.financials --}}
    @if ($finance)
        <section class="mb-6">
            <h2 class="mb-3 text-sm font-semibold text-ink-700">الملخص المالي</h2>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-ui.stat-card label="إيراد اليوم" :value="money($finance['revenue_today'])" icon="banknote" tone="success" />
                <x-ui.stat-card label="مصاريف اليوم" :value="money($finance['expenses_today'])" icon="trending-down" tone="danger" />
                <x-ui.stat-card
                    label="إيراد الشهر"
                    :value="money($finance['revenue_month'])"
                    icon="chart"
                    tone="brand"
                    :hint="'مصاريف: ' . money($finance['expenses_month'])"
                />

                @if (! is_null($finance['net_month']))
                    <x-ui.stat-card
                        label="صافي ربح الشهر"
                        :value="money($finance['net_month'])"
                        icon="wallet"
                        :tone="$finance['net_month'] >= 0 ? 'success' : 'danger'"
                        :href="route('admin.profit.index')"
                    />
                @elseif (! is_null($finance['cashbox_balance']))
                    <x-ui.stat-card
                        label="رصيد الصندوق"
                        :value="money($finance['cashbox_balance'])"
                        icon="safe"
                        tone="info"
                        :href="route('admin.cashbox.index')"
                    />
                @endif
            </div>

            @if (! is_null($finance['net_month']) && ! is_null($finance['cashbox_balance']))
                <div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <x-ui.stat-card
                        label="رصيد الصندوق"
                        :value="money($finance['cashbox_balance'])"
                        icon="safe"
                        tone="info"
                        :href="route('admin.cashbox.index')"
                    />
                </div>
            @endif
        </section>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- Charts --}}
        <div class="space-y-5 lg:col-span-2">
            @isset($charts['revenue_expenses'])
                <x-ui.card title="الإيرادات مقابل المصاريف" subtitle="آخر ٦ أشهر">
                    <x-ui.chart :height="280" :spec="[
                        'type' => 'bar',
                        'data' => [
                            'labels' => collect($charts['revenue_expenses'])->pluck('period')->all(),
                            'datasets' => [
                                ['label' => 'الإيرادات', 'data' => collect($charts['revenue_expenses'])->pluck('revenue')->all()],
                                ['label' => 'المصاريف', 'data' => collect($charts['revenue_expenses'])->pluck('expenses')->all()],
                            ],
                        ],
                    ]" />
                </x-ui.card>

                @can('profit.view')
                    <x-ui.card title="صافي الربح الشهري" subtitle="آخر ٦ أشهر">
                        <x-ui.chart :height="220" :spec="[
                            'type' => 'line',
                            'data' => [
                                'labels' => collect($charts['revenue_expenses'])->pluck('period')->all(),
                                'datasets' => [
                                    ['label' => 'صافي الربح', 'data' => collect($charts['revenue_expenses'])->pluck('profit')->all()],
                                ],
                            ],
                        ]" />
                    </x-ui.card>
                @endcan
            @endisset

            <x-ui.card title="الحصص التدريبية" subtitle="آخر ٣٠ يوماً">
                <x-ui.chart :height="220" :spec="[
                    'type' => 'line',
                    'data' => [
                        'labels' => collect($charts['sessions'])->pluck('date')->map(fn ($d) => substr($d, 5))->all(),
                        'datasets' => [
                            ['label' => 'عدد الحصص', 'data' => collect($charts['sessions'])->pluck('total')->all()],
                        ],
                    ],
                ]" />
            </x-ui.card>

            @isset($charts['expense_categories'])
                @if (count($charts['expense_categories']))
                    <x-ui.card title="المصاريف حسب التصنيف" subtitle="الشهر الحالي">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.chart :height="220" :spec="[
                                'type' => 'doughnut',
                                'data' => [
                                    'labels' => collect($charts['expense_categories'])->pluck('category')->all(),
                                    'datasets' => [
                                        ['data' => collect($charts['expense_categories'])->pluck('amount')->all()],
                                    ],
                                ],
                            ]" />

                            <ul class="space-y-2 self-center">
                                @foreach ($charts['expense_categories'] as $row)
                                    <li class="flex items-center justify-between gap-3 text-sm">
                                        <span class="truncate text-ink-600">{{ $row['category'] }}</span>
                                        <span class="shrink-0 tabular-nums text-ink-900">
                                            {{ money($row['amount'], false) }}
                                            <span class="text-xs text-ink-400">({{ percent($row['percent'], 0) }})</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </x-ui.card>
                @endif
            @endisset
        </div>

        {{-- Side column --}}
        <div class="space-y-5">
            <x-ui.card title="الحصص القادمة اليوم">
                @forelse ($today['upcoming'] as $session)
                    <a href="{{ route('admin.sessions.show', $session) }}"
                       class="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-ink-50">
                        <span class="flex w-14 shrink-0 flex-col items-center rounded-lg bg-brand-50 py-1.5 text-brand-700">
                            <span class="text-sm font-semibold tabular-nums">{{ short_time($session->start_time) }}</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-ink-900">{{ $session->trainee->full_name }}</span>
                            <span class="block truncate text-xs text-ink-400">
                                {{ $session->trainer->full_name }}
                                @if ($session->vehicle) · {{ $session->vehicle->name }} @endif
                            </span>
                        </span>
                    </a>
                    @if (! $loop->last)<div class="border-t border-ink-100"></div>@endif
                @empty
                    <x-ui.empty-state icon="calendar" title="لا توجد حصص قادمة اليوم" />
                @endforelse
            </x-ui.card>

            <x-ui.card title="بنود بانتظار الإجراء">
                <ul class="space-y-3 text-sm">
                    @php
                        $items = [
                            ['label' => 'طلبات حجز معلّقة', 'value' => $pending['booking_requests'] ?? null, 'money' => false, 'route' => 'admin.booking-requests.index', 'tone' => 'warning'],
                            ['label' => 'ذمم على المتدربين', 'value' => $pending['trainee_debts'] ?? null, 'money' => true, 'route' => 'admin.payments.index', 'tone' => 'danger'],
                            ['label' => 'رواتب مستحقة', 'value' => $pending['payroll_due'] ?? null, 'money' => true, 'route' => 'admin.payroll.index', 'tone' => 'warning'],
                            ['label' => 'أجور مدربين مستحقة', 'value' => $pending['trainer_compensation_due'] ?? null, 'money' => true, 'route' => 'admin.trainer-compensation.index', 'tone' => 'warning'],
                            ['label' => 'مصاريف متكررة مستحقة', 'value' => $pending['recurring_due'] ?? null, 'money' => false, 'route' => 'admin.recurring-expenses.index', 'tone' => 'info'],
                            ['label' => 'فواتير خدمات قريبة', 'value' => $pending['utility_bills_due'] ?? null, 'money' => false, 'route' => 'admin.utilities.index', 'tone' => 'info'],
                            ['label' => 'سلف قائمة', 'value' => $pending['advances_outstanding'] ?? null, 'money' => true, 'route' => 'admin.advances.index', 'tone' => 'neutral'],
                            ['label' => 'متدربون برصيد منخفض', 'value' => $pending['low_balance_trainees'] ?? null, 'money' => false, 'route' => 'admin.trainees.index', 'tone' => 'warning'],
                        ];
                        $items = array_filter($items, fn ($i) => ! is_null($i['value']) && $i['value'] > 0);
                    @endphp

                    @forelse ($items as $item)
                        <li class="flex items-center justify-between gap-3">
                            <a href="{{ route($item['route']) }}" class="truncate text-ink-600 hover:text-brand-600">
                                {{ $item['label'] }}
                            </a>
                            <x-ui.badge :tone="$item['tone']">
                                {{ $item['money'] ? money($item['value']) : $item['value'] }}
                            </x-ui.badge>
                        </li>
                    @empty
                        <x-ui.empty-state icon="check" title="لا توجد بنود معلّقة" description="كل شيء على ما يرام." />
                    @endforelse
                </ul>
            </x-ui.card>

            <x-ui.card title="إحصائيات التدريب">
                <dl class="space-y-2.5 text-sm">
                    @foreach ([
                        'جاهزون للامتحان' => $training['ready_for_exam'],
                        'لديهم موعد امتحان' => $training['exam_scheduled'],
                        'أنهوا التدريب' => $training['completed'],
                        'تسجيلات هذا الشهر' => $training['new_this_month'],
                    ] as $label => $value)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-600">{{ $label }}</dt>
                            <dd class="font-semibold tabular-nums text-ink-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card title="نمو المتدربين" subtitle="آخر ٦ أشهر">
                <x-ui.chart :height="180" :spec="[
                    'type' => 'bar',
                    'data' => [
                        'labels' => collect($charts['trainee_growth'])->pluck('period')->all(),
                        'datasets' => [
                            ['label' => 'متدربون جدد', 'data' => collect($charts['trainee_growth'])->pluck('total')->all()],
                        ],
                    ],
                ]" />
            </x-ui.card>
        </div>
    </div>
@endsection
