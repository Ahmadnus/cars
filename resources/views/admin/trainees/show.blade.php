@extends('layouts.app')

@section('title', $trainee->full_name)
@section('subtitle', 'رقم المتدرب: ' . $trainee->trainee_number)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المتدربون' => route('admin.trainees.index'), $trainee->full_name => null]" />
@endsection

@section('content')
@php
    $canSeeMoney = auth()->user()->can('viewFinancials', $trainee);
    $canSeeDocs = auth()->user()->can('manageDocuments', $trainee);

    $tabs = array_filter([
        'overview' => 'نظرة عامة',
        'training' => 'التدريب',
        'sessions' => 'الحصص',
        'evaluations' => 'التقييمات',
        'financial' => $canSeeMoney ? 'المالية' : null,
        'documents' => $canSeeDocs ? 'المستندات' : null,
        'notes' => 'الملاحظات',
    ]);
@endphp

<div x-data="{ tab: window.location.hash?.replace('#','') || 'overview' }">

    {{-- Identity header --}}
    <x-ui.card class="mb-5">
        <div class="flex flex-wrap items-start gap-4">
            <span class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-brand-50 text-xl font-semibold text-brand-700">
                @if ($trainee->photo_path)
                    <img src="{{ Storage::disk('public')->url($trainee->photo_path) }}" alt="" class="size-full object-cover">
                @else
                    {{ mb_substr($trainee->full_name, 0, 1) }}
                @endif
            </span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-ink-900">{{ $trainee->full_name }}</h2>
                    <x-ui.status type="trainee" :value="$trainee->status" />
                </div>

                <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-ink-500">
                    <div class="flex items-center gap-1.5">
                        <x-ui.icon name="phone" class="size-3.5" />
                        <dd class="font-mono" dir="ltr">{{ $trainee->phone }}</dd>
                    </div>
                    <div><dt class="inline">المدرب:</dt> <dd class="inline text-ink-700">{{ $trainee->trainer?->full_name ?? '—' }}</dd></div>
                    <div><dt class="inline">الفرع:</dt> <dd class="inline text-ink-700">{{ $trainee->branch?->name }}</dd></div>
                    <div><dt class="inline">التسجيل:</dt> <dd class="inline text-ink-700">{{ $trainee->registration_date->format('Y-m-d') }}</dd></div>
                </dl>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @canDo('appointments.create')
                    <x-ui.button :href="route('admin.sessions.create', ['trainee_id' => $trainee->id])" icon="calendar" size="sm">
                        حجز حصة
                    </x-ui.button>
                @endcanDo

                @canDo('payments.create')
                    <x-ui.button :href="route('admin.payments.create', ['trainee_id' => $trainee->id])" variant="secondary" size="sm" icon="banknote">
                        تسجيل دفعة
                    </x-ui.button>
                @endcanDo

                @canDo('trainees.update')
                    <x-ui.button :href="route('admin.trainees.edit', $trainee)" variant="secondary" size="sm" icon="edit">تعديل</x-ui.button>
                @endcanDo
            </div>
        </div>
    </x-ui.card>

    {{-- Tabs --}}
    <div class="mb-5 overflow-x-auto scrollbar-thin">
        <nav class="flex gap-1 border-b border-ink-200" role="tablist">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab"
                        @click="tab = '{{ $key }}'; history.replaceState(null, '', '#{{ $key }}')"
                        :aria-selected="tab === '{{ $key }}'"
                        class="whitespace-nowrap border-b-2 px-4 py-2.5 text-sm transition"
                        :class="tab === '{{ $key }}'
                            ? 'border-brand-600 font-semibold text-brand-700'
                            : 'border-transparent text-ink-500 hover:text-ink-800'">
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Overview --}}
    <div x-show="tab === 'overview'" x-cloak class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.card title="المعلومات الشخصية">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    @foreach ([
                        'رقم المتدرب' => $trainee->trainee_number,
                        'الرقم الوطني' => $trainee->national_id ?: '—',
                        'تاريخ الميلاد' => $trainee->birth_date ? $trainee->birth_date->format('Y-m-d') . ' (' . $trainee->age() . ' سنة)' : '—',
                        'الجنس' => ['male' => 'ذكر', 'female' => 'أنثى'][$trainee->gender] ?? '—',
                        'هاتف إضافي' => $trainee->secondary_phone ?: '—',
                        'العنوان' => $trainee->address ?: '—',
                        'نوع الرخصة' => $trainee->license_type,
                        'موعد الامتحان' => $trainee->exam_date?->format('Y-m-d') ?: '—',
                    ] as $label => $value)
                        <div class="flex items-baseline justify-between gap-3 border-b border-ink-100 pb-2">
                            <dt class="text-sm text-ink-500">{{ $label }}</dt>
                            <dd class="text-sm font-medium text-ink-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($trainee->notes)
                    <div class="mt-4 rounded-lg bg-ink-50 p-3 text-sm text-ink-600">{{ $trainee->notes }}</div>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-5">
            <x-ui.card title="الجاهزية للامتحان">
                <div class="flex items-center gap-4">
                    <div class="relative size-20 shrink-0">
                        <svg viewBox="0 0 36 36" class="size-full -rotate-90">
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#eef0f1" stroke-width="3.5" />
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#2f7f79" stroke-width="3.5"
                                    stroke-linecap="round"
                                    stroke-dasharray="{{ $readiness }} 100" />
                        </svg>
                        <span class="absolute inset-0 flex items-center justify-center text-sm font-semibold tabular-nums text-ink-900">
                            {{ $readiness }}%
                        </span>
                    </div>
                    <p class="text-sm text-ink-500">
                        متوسط تقييم المهارات عبر منهاج التدريب الكامل.
                    </p>
                </div>
            </x-ui.card>

            @if ($balance)
                <x-ui.card title="رصيد الحصص">
                    <dl class="space-y-2 text-sm">
                        @foreach ([
                            'الحصص المضافة' => $balance['credited'],
                            'الحصص المنجزة' => $balance['completed'],
                            'مجدولة' => $balance['scheduled'],
                            'عدم حضور' => $balance['no_show'],
                            'ملغاة' => $balance['cancelled'],
                        ] as $label => $value)
                            <div class="flex items-center justify-between">
                                <dt class="text-ink-500">{{ $label }}</dt>
                                <dd class="font-medium tabular-nums text-ink-900">{{ $value }}</dd>
                            </div>
                        @endforeach
                        <div class="flex items-center justify-between border-t border-ink-200 pt-2">
                            <dt class="font-semibold text-ink-700">المتبقي</dt>
                            <dd class="text-lg font-bold tabular-nums {{ $balance['remaining'] > 2 ? 'text-emerald-600' : 'text-amber-600' }}">
                                {{ $balance['remaining'] }}
                            </dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endif
        </div>
    </div>

    {{-- Training --}}
    <div x-show="tab === 'training'" x-cloak class="space-y-5">
        @if ($activePackage && $balance)
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-ui.stat-card label="إجمالي الحصص" :value="$balance['credited']" icon="package" />
                <x-ui.stat-card label="منجزة" :value="$balance['completed']" icon="check" tone="success" />
                <x-ui.stat-card label="متبقية" :value="$balance['remaining']" icon="clock" :tone="$balance['remaining'] > 2 ? 'info' : 'warning'" />
                <x-ui.stat-card label="نسبة التقدّم" :value="percent($activePackage->progressPercent(), 0)" icon="chart" tone="brand" />
            </div>

            <x-ui.card :title="'الباقة الحالية — ' . $activePackage->package_name">
                <x-slot:actions>
                    <x-ui.button :href="route('admin.trainee-packages.ledger', $activePackage)" variant="secondary" size="sm">
                        سجل الحصص
                    </x-ui.button>
                </x-slot:actions>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-3">
                    @foreach ([
                        'عدد الحصص' => $activePackage->lessons_count,
                        'مدة الحصة' => $activePackage->lesson_duration_minutes . ' دقيقة',
                        'تاريخ البدء' => $activePackage->started_on->format('Y-m-d'),
                        'تاريخ الانتهاء' => $activePackage->expires_on?->format('Y-m-d') ?? 'غير محدد',
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs text-ink-500">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm font-medium text-ink-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @canDo('packages.assign')
                    <div class="mt-5 grid gap-4 border-t border-ink-200 pt-5 sm:grid-cols-2">
                        <form method="POST" action="{{ route('admin.trainee-packages.extra', $activePackage) }}" class="space-y-3">
                            @csrf
                            <p class="text-sm font-medium text-ink-700">إضافة حصص إضافية</p>
                            <div class="flex gap-2">
                                <x-form.input name="lessons" type="number" min="1" max="50" value="1" class="w-24" />
                                <x-form.input name="unit_price" type="number" step="0.01" min="0"
                                              :value="$activePackage->extra_lesson_price" label="" class="flex-1" />
                                <x-ui.button type="submit" size="md">إضافة</x-ui.button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('admin.trainee-packages.adjust', $activePackage) }}" class="space-y-3">
                            @csrf
                            <p class="text-sm font-medium text-ink-700">تعديل يدوي على الرصيد</p>
                            <div class="flex gap-2">
                                <x-form.select name="direction" :options="['credit' => 'إضافة', 'debit' => 'خصم']" class="w-28" />
                                <x-form.input name="lessons" type="number" min="1" max="50" value="1" class="w-20" />
                                <x-form.input name="reason" placeholder="السبب" class="flex-1" required />
                                <x-ui.button type="submit" variant="secondary" size="md">تنفيذ</x-ui.button>
                            </div>
                        </form>
                    </div>
                @endcanDo
            </x-ui.card>
        @else
            <x-ui.card>
                <x-ui.empty-state
                    icon="package"
                    title="لا توجد باقة نشطة"
                    description="يجب إسناد باقة تدريبية قبل حجز الحصص."
                >
                    @canDo('packages.assign')
                        <x-slot:action>
                            <x-ui.button :href="route('admin.trainees.packages.create', $trainee)" icon="plus" size="sm">
                                إسناد باقة
                            </x-ui.button>
                        </x-slot:action>
                    @endcanDo
                </x-ui.empty-state>
            </x-ui.card>
        @endif
    </div>

    {{-- Sessions --}}
    <div x-show="tab === 'sessions'" x-cloak>
        <x-ui.card padded="false" title="سجل الحصص">
            @if ($sessions->isEmpty())
                <x-ui.empty-state icon="calendar" title="لا توجد حصص مسجلة" />
            @else
                <x-ui.table :headers="['التاريخ', 'الوقت', 'المدرب', 'المركبة', 'التقييم', 'الحالة', '']">
                    @foreach ($sessions as $session)
                        <tr class="hover:bg-ink-50">
                            <td class="whitespace-nowrap px-3 py-3">{{ $session->scheduled_date->format('Y-m-d') }}</td>
                            <td class="whitespace-nowrap px-3 py-3 font-mono text-ink-600" dir="ltr">{{ $session->timeRange() }}</td>
                            <td class="px-3 py-3 text-ink-600">{{ $session->trainer?->full_name }}</td>
                            <td class="px-3 py-3 text-ink-600">{{ $session->vehicle?->name ?? '—' }}</td>
                            <td class="px-3 py-3">
                                @if ($session->overall_rating)
                                    <x-ui.status type="rating" :value="$session->overall_rating" />
                                @else
                                    <span class="text-ink-300">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3"><x-ui.status type="session" :value="$session->status" /></td>
                            <td class="px-3 py-3 text-end">
                                <x-ui.button :href="route('admin.sessions.show', $session)" variant="ghost" size="sm">تفاصيل</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <div class="mt-4">{{ $sessions->links() }}</div>
    </div>

    {{-- Evaluations --}}
    <div x-show="tab === 'evaluations'" x-cloak>
        <x-ui.card title="تقييم المهارات" subtitle="آخر مستوى مسجّل لكل مهارة.">
            <ul class="space-y-2.5">
                @foreach ($evaluations as $skill)
                    @php $evaluation = $skill->evaluations->first(); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 pb-2.5 last:border-0">
                        <span class="text-sm text-ink-700">{{ $skill->name_ar }}</span>
                        <span class="flex items-center gap-3">
                            @if ($evaluation)
                                <span class="hidden h-1.5 w-28 overflow-hidden rounded-full bg-ink-100 sm:block">
                                    <span class="block h-full rounded-full bg-brand-500" style="width: {{ $evaluation->score() }}%"></span>
                                </span>
                            @endif
                            <x-ui.status type="rating" :value="$evaluation?->level ?? 'not_started'" />
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>

    {{-- Financial --}}
    @if ($canSeeMoney)
        <div x-show="tab === 'financial'" x-cloak class="space-y-5">
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-ui.stat-card label="إجمالي العقود" :value="money($packages->sum('total_amount'))" icon="receipt" />
                <x-ui.stat-card label="المدفوع" :value="money($packages->sum('paid_amount'))" icon="banknote" tone="success" />
                <x-ui.stat-card label="المتبقي" :value="money($outstanding)" icon="alert" :tone="$outstanding > 0 ? 'danger' : 'success'" />
                <x-ui.stat-card label="عدد الدفعات" :value="$payments->count()" icon="file-text" tone="neutral" />
            </div>

            <x-ui.card padded="false" title="الباقات">
                <x-ui.table :headers="['الباقة', 'تاريخ البدء', 'الإجمالي', 'المدفوع', 'المتبقي', 'الحالة']">
                    @foreach ($packages as $package)
                        <tr>
                            <td class="px-3 py-3 font-medium text-ink-900">{{ $package->package_name }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-ink-600">{{ $package->started_on->format('Y-m-d') }}</td>
                            <td class="px-3 py-3 tabular-nums">{{ money($package->total_amount) }}</td>
                            <td class="px-3 py-3 tabular-nums text-emerald-700">{{ money($package->paid_amount) }}</td>
                            <td class="px-3 py-3 tabular-nums {{ $package->remainingAmount() > 0 ? 'text-rose-700' : 'text-ink-400' }}">
                                {{ money($package->remainingAmount()) }}
                            </td>
                            <td class="px-3 py-3"><x-ui.badge :tone="$package->status === 'active' ? 'brand' : 'neutral'">{{ $package->status }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card padded="false" title="سجل الدفعات">
                @if ($payments->isEmpty())
                    <x-ui.empty-state icon="banknote" title="لا توجد دفعات مسجلة" />
                @else
                    <x-ui.table :headers="['رقم الإيصال', 'التاريخ', 'الطريقة', 'المبلغ', 'الحالة', '']">
                        @foreach ($payments as $payment)
                            <tr>
                                <td class="px-3 py-3 font-mono text-ink-700" dir="ltr">{{ $payment->receipt_number }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-ink-600">{{ $payment->paid_on->format('Y-m-d') }}</td>
                                <td class="px-3 py-3 text-ink-600">{{ $payment->paymentMethod?->label_ar }}</td>
                                <td class="px-3 py-3 font-semibold tabular-nums">{{ money($payment->amount) }}</td>
                                <td class="px-3 py-3"><x-ui.status type="payment" :value="$payment->status" /></td>
                                <td class="px-3 py-3 text-end">
                                    <x-ui.button :href="route('admin.payments.show', $payment)" variant="ghost" size="sm">عرض</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>
    @endif

    {{-- Documents --}}
    @if ($canSeeDocs)
        <div x-show="tab === 'documents'" x-cloak class="space-y-5">
            <x-ui.card title="رفع مستند">
                <form method="POST" action="{{ route('admin.documents.store') }}" enctype="multipart/form-data"
                      class="grid gap-3 sm:grid-cols-4">
                    @csrf
                    <input type="hidden" name="owner_type" value="trainee">
                    <input type="hidden" name="owner_id" value="{{ $trainee->id }}">

                    <x-form.select
                        name="category"
                        label="نوع المستند"
                        :options="\App\Http\Controllers\Admin\DocumentController::categories()"
                        required
                    />
                    <x-form.input name="title" label="عنوان المستند" />
                    <x-form.input name="expires_on" type="date" label="تاريخ الانتهاء" />

                    <x-form.field label="الملف" name="file" hint="صورة أو PDF، بحد أقصى 8 ميجابايت.">
                        <input type="file" name="file" required accept=".jpg,.jpeg,.png,.webp,.pdf"
                               class="block w-full text-sm text-ink-600 file:me-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                    </x-form.field>

                    <div class="sm:col-span-4">
                        <x-ui.button type="submit" icon="upload">رفع المستند</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card padded="false" title="المستندات">
                @if ($documents->isEmpty())
                    <x-ui.empty-state icon="file" title="لا توجد مستندات" description="ارفع إثبات الهوية أو المستندات الطبية." />
                @else
                    <x-ui.table :headers="['المستند', 'النوع', 'الحجم', 'رفعه', 'التاريخ', '']">
                        @foreach ($documents as $document)
                            <tr>
                                <td class="px-3 py-3 font-medium text-ink-900">{{ $document->title }}</td>
                                <td class="px-3 py-3 text-ink-600">
                                    {{ \App\Http\Controllers\Admin\DocumentController::categories()[$document->category] ?? $document->category }}
                                </td>
                                <td class="px-3 py-3 text-ink-500" dir="ltr">{{ $document->humanSize() }}</td>
                                <td class="px-3 py-3 text-ink-600">{{ $document->uploader?->name }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-ink-500">{{ $document->created_at->format('Y-m-d') }}</td>
                                <td class="px-3 py-3 text-end">
                                    <div class="flex justify-end gap-1">
                                        <x-ui.button :href="route('admin.documents.view', $document)" variant="ghost" size="sm" target="_blank">عرض</x-ui.button>
                                        <x-ui.button :href="route('admin.documents.download', $document)" variant="ghost" size="sm" icon="download" />
                                        <x-ui.confirm
                                            :action="route('admin.documents.destroy', $document)"
                                            method="DELETE"
                                            title="حذف المستند"
                                            message="سيتم حذف الملف نهائياً مع تسجيل العملية في سجل التدقيق."
                                            reason-label="سبب الحذف"
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
        </div>
    @endif

    {{-- Notes --}}
    <div x-show="tab === 'notes'" x-cloak class="space-y-5">
        @canDo('trainees.update')
            <x-ui.card title="إضافة ملاحظة">
                <form method="POST" action="{{ route('admin.trainees.notes.store', $trainee) }}" class="space-y-3">
                    @csrf
                    <x-form.textarea name="body" rows="3" required placeholder="اكتب ملاحظة داخلية…" />
                    <div class="flex items-center justify-between gap-3">
                        <x-form.checkbox name="is_pinned" label="تثبيت الملاحظة" />
                        <x-ui.button type="submit" size="sm">حفظ الملاحظة</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endcanDo

        <x-ui.card title="الملاحظات">
            @forelse ($notes as $note)
                <div class="border-b border-ink-100 py-3 first:pt-0 last:border-0 last:pb-0">
                    <div class="mb-1 flex flex-wrap items-center gap-2 text-xs text-ink-400">
                        <span class="font-medium text-ink-600">{{ $note->author?->name ?? 'النظام' }}</span>
                        <span>{{ $note->created_at->format('Y-m-d H:i') }}</span>
                        @if ($note->is_pinned)<x-ui.badge tone="warning">مثبّتة</x-ui.badge>@endif
                    </div>
                    <p class="whitespace-pre-line text-sm text-ink-700">{{ $note->body }}</p>
                </div>
            @empty
                <x-ui.empty-state icon="file-text" title="لا توجد ملاحظات" />
            @endforelse
        </x-ui.card>
    </div>
</div>
@endsection
