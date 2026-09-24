@php
    /**
     * Navigation is permission-driven: a section with no reachable item is not
     * rendered at all, so the sidebar always matches what the user can do.
     */
    $nav = [
        [
            'label' => null,
            'items' => [
                ['route' => 'admin.dashboard', 'label' => 'لوحة التحكم', 'icon' => 'home', 'can' => ['dashboard.view']],
            ],
        ],
        [
            'label' => 'العمليات',
            'items' => [
                ['route' => 'admin.calendar.index', 'label' => 'التقويم', 'icon' => 'calendar', 'can' => ['appointments.view']],
                ['route' => 'admin.sessions.index', 'label' => 'الحصص التدريبية', 'icon' => 'steering', 'can' => ['appointments.view']],
                ['route' => 'admin.booking-requests.index', 'label' => 'طلبات الحجز', 'icon' => 'inbox', 'can' => ['booking_requests.manage'], 'badge' => 'booking_requests'],
            ],
        ],
        [
            'label' => 'المتدربون',
            'items' => [
                ['route' => 'admin.trainees.index', 'label' => 'جميع المتدربين', 'icon' => 'users', 'can' => ['trainees.view']],
                ['route' => 'admin.packages.index', 'label' => 'الباقات', 'icon' => 'package', 'can' => ['packages.view']],
                ['route' => 'admin.skills.index', 'label' => 'مهارات التدريب', 'icon' => 'check', 'can' => ['evaluations.view']],
            ],
        ],
        [
            'label' => 'المدربون',
            'items' => [
                ['route' => 'admin.trainers.index', 'label' => 'المدربون', 'icon' => 'badge', 'can' => ['trainers.view']],
                ['route' => 'admin.trainer-compensation.index', 'label' => 'أجور المدربين', 'icon' => 'wallet', 'can' => ['trainer_compensation.view']],
            ],
        ],
        [
            'label' => 'الموظفون',
            'items' => [
                ['route' => 'admin.employees.index', 'label' => 'الموظفون', 'icon' => 'briefcase', 'can' => ['employees.view']],
                ['route' => 'admin.payroll.index', 'label' => 'الرواتب', 'icon' => 'receipt', 'can' => ['payroll.view']],
                ['route' => 'admin.advances.index', 'label' => 'السلف', 'icon' => 'hand-coins', 'can' => ['advances.manage']],
            ],
        ],
        [
            'label' => 'المركبات',
            'items' => [
                ['route' => 'admin.vehicles.index', 'label' => 'المركبات', 'icon' => 'car', 'can' => ['vehicles.view']],
                ['route' => 'admin.maintenance.index', 'label' => 'الصيانة', 'icon' => 'wrench', 'can' => ['vehicles.view']],
            ],
        ],
        [
            'label' => 'المالية',
            'items' => [
                ['route' => 'admin.payments.index', 'label' => 'المدفوعات', 'icon' => 'banknote', 'can' => ['payments.view']],
                ['route' => 'admin.expenses.index', 'label' => 'المصاريف', 'icon' => 'trending-down', 'can' => ['expenses.view']],
                ['route' => 'admin.recurring-expenses.index', 'label' => 'مصاريف متكررة', 'icon' => 'repeat', 'can' => ['recurring_expenses.manage']],
                ['route' => 'admin.utilities.index', 'label' => 'فواتير الخدمات', 'icon' => 'zap', 'can' => ['utilities.manage']],
                ['route' => 'admin.cashbox.index', 'label' => 'الصندوق', 'icon' => 'safe', 'can' => ['cashbox.view']],
                ['route' => 'admin.profit.index', 'label' => 'الأرباح والخسائر', 'icon' => 'chart', 'can' => ['profit.view']],
            ],
        ],
        [
            'label' => 'التقارير والنظام',
            'items' => [
                ['route' => 'admin.reports.index', 'label' => 'التقارير', 'icon' => 'file-text', 'can' => ['reports.view']],
                ['route' => 'admin.notifications.index', 'label' => 'الإشعارات', 'icon' => 'bell', 'can' => ['dashboard.view']],
                ['route' => 'admin.audit-logs.index', 'label' => 'سجل التدقيق', 'icon' => 'shield', 'can' => ['audit_logs.view']],
                ['route' => 'admin.users.index', 'label' => 'المستخدمون', 'icon' => 'user-cog', 'can' => ['users.manage']],
                ['route' => 'admin.roles.index', 'label' => 'الأدوار والصلاحيات', 'icon' => 'key', 'can' => ['roles.manage']],
                ['route' => 'admin.branches.index', 'label' => 'الفروع', 'icon' => 'building', 'can' => ['branches.manage']],
                ['route' => 'admin.settings.edit', 'label' => 'الإعدادات', 'icon' => 'settings', 'can' => ['settings.manage']],
            ],
        ],
    ];

    $pendingRequests = auth()->user()?->hasPermission('booking_requests.manage')
        ? \App\Models\BookingRequest::query()->visibleTo()->pending()->count()
        : 0;
@endphp

<aside
    {{-- The slide transform is scoped to max-lg so it cannot compete with the
         static desktop layout: at lg and above no transform applies at all. --}}
    class="fixed inset-y-0 z-40 flex w-72 flex-col border-s border-ink-200 bg-white transition-transform duration-200 ltr:left-0 rtl:right-0 lg:static"
    :class="sidebarOpen ? 'max-lg:translate-x-0' : 'max-lg:ltr:-translate-x-full max-lg:rtl:translate-x-full'"
>
    <div class="flex h-16 shrink-0 items-center gap-3 border-b border-ink-200 px-5">
        <span class="flex size-9 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">
            {{ mb_substr(settings('center.name', 'مركز'), 0, 1) }}
        </span>
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-ink-900">{{ settings('center.name', config('app.name')) }}</p>
            <p class="text-xs text-ink-400">نظام إدارة التدريب</p>
        </div>
        <button type="button" @click="sidebarOpen = false"
                class="ms-auto rounded-md p-1.5 text-ink-400 hover:bg-ink-100 lg:hidden">
            <x-ui.icon name="x" class="size-5" />
        </button>
    </div>

    <nav class="scrollbar-thin flex-1 overflow-y-auto px-3 py-4">
        @foreach ($nav as $section)
            @php
                $visible = collect($section['items'])
                    ->filter(fn ($item) => auth()->user()?->hasAnyPermission(...$item['can']));
            @endphp

            @if ($visible->isNotEmpty())
                <div class="{{ $loop->first ? '' : 'mt-5' }}">
                    @if ($section['label'])
                        <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wide text-ink-400">
                            {{ $section['label'] }}
                        </p>
                    @endif

                    <ul class="space-y-0.5">
                        @foreach ($visible as $item)
                            @php $active = request()->routeIs($item['route']) || request()->routeIs(str_replace('.index', '.*', $item['route'])); @endphp
                            <li>
                                <a href="{{ route($item['route']) }}"
                                   @class([
                                       'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition',
                                       'bg-brand-50 font-semibold text-brand-700' => $active,
                                       'text-ink-600 hover:bg-ink-100 hover:text-ink-900' => ! $active,
                                   ])>
                                    <x-ui.icon :name="$item['icon']" class="size-[18px] shrink-0" />
                                    <span class="truncate">{{ $item['label'] }}</span>

                                    @if (($item['badge'] ?? null) === 'booking_requests' && $pendingRequests > 0)
                                        <span class="ms-auto rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">
                                            {{ $pendingRequests }}
                                        </span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </nav>
</aside>
