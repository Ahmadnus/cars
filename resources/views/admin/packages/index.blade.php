@extends('layouts.app')

@section('title', 'الباقات التدريبية')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الباقات' => null]" />
@endsection

@section('content')
    <x-layout.page-header title="الباقات التدريبية" description="أسعار الباقات وعدد الحصص وقواعد الخصم.">
        <x-slot:actions>
            @canDo('packages.manage')
                <x-ui.button :href="route('admin.packages.create')" icon="plus">باقة جديدة</x-ui.button>
            @endcanDo
        </x-slot:actions>
    </x-layout.page-header>

    <x-ui.filters :action="route('admin.packages.index')" placeholder="ابحث باسم الباقة…">
        <x-slot:fields>
            <x-form.select name="status" label="الحالة" :options="['active' => 'نشطة', 'inactive' => 'غير نشطة']" :selected="request('status')" placeholder="الكل" />
        </x-slot:fields>
    </x-ui.filters>

    @if ($packages->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="package" title="لا توجد باقات" description="أنشئ أول باقة تدريبية لبدء تسجيل المتدربين.">
                @canDo('packages.manage')
                    <x-slot:action>
                        <x-ui.button :href="route('admin.packages.create')" icon="plus" size="sm">باقة جديدة</x-ui.button>
                    </x-slot:action>
                @endcanDo
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($packages as $package)
                <x-ui.card @class(['opacity-70' => $package->status !== 'active'])>
                    <div class="mb-3 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="truncate font-semibold text-ink-900">{{ $package->name }}</h3>
                            <p class="text-xs text-ink-400">
                                {{ $package->branch?->name ?? 'متاحة لكل الفروع' }}
                            </p>
                        </div>
                        <x-ui.status type="generic" :value="$package->status" />
                    </div>

                    <div class="mb-4 rounded-xl bg-brand-50 p-3 text-center">
                        <p class="text-2xl font-bold tabular-nums text-brand-800">{{ money($package->price) }}</p>
                        <p class="mt-0.5 text-xs text-brand-700">
                            {{ $package->lessons_count }} حصة · {{ money($package->pricePerLesson()) }} للحصة
                        </p>
                    </div>

                    <dl class="space-y-1.5 text-sm">
                        @foreach ([
                            'مدة الحصة' => $package->lesson_duration_minutes . ' دقيقة',
                            'سعر الحصة الإضافية' => money($package->extra_lesson_price),
                            'أقصى خصم' => percent($package->max_discount_percent, 0),
                            'مدة الصلاحية' => $package->validity_days ? $package->validity_days . ' يوم' : 'غير محدودة',
                            'عدد المشتركين' => $package->enrolments_count,
                        ] as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-500">{{ $label }}</dt>
                                <dd class="font-medium text-ink-800">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @canDo('packages.manage')
                        <div class="mt-4 flex gap-2 border-t border-ink-100 pt-3">
                            <x-ui.button :href="route('admin.packages.edit', $package)" variant="secondary" size="sm" icon="edit" class="flex-1">
                                تعديل
                            </x-ui.button>

                            <x-ui.confirm
                                :action="route('admin.packages.destroy', $package)"
                                method="DELETE"
                                title="حذف الباقة"
                                message="إذا كانت الباقة مستخدمة من متدربين فسيتم تعطيلها بدلاً من حذفها، حفاظاً على العقود القائمة."
                                confirm-label="متابعة"
                            >
                                <x-slot:trigger>
                                    <x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-rose-600" />
                                </x-slot:trigger>
                            </x-ui.confirm>
                        </div>
                    @endcanDo
                </x-ui.card>
            @endforeach
        </div>
    @endif

    <div class="mt-4">{{ $packages->links() }}</div>
@endsection
