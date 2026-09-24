@extends('layouts.app')

@section('title', 'طلبات الحجز')
@section('subtitle', $pendingCount . ' طلب معلّق')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['طلبات الحجز' => null]" />
@endsection

@section('content')
    <x-layout.page-header
        title="طلبات الحجز والتأجيل"
        description="الطلبات الواردة من تطبيق المتدربين بانتظار المراجعة."
    />

    <x-ui.filters :action="route('admin.booking-requests.index')" :collapsible="false">
        <x-slot:fields>
            <x-form.select name="status" label="الحالة" :options="$statuses" :selected="request('status')" placeholder="المعلّقة فقط" />
            <x-form.select name="type" label="نوع الطلب" :options="$types" :selected="request('type')" placeholder="كل الأنواع" />
        </x-slot:fields>
    </x-ui.filters>

    @forelse ($requests as $bookingRequest)
        <x-ui.card class="mb-3">
            <div class="flex flex-wrap items-start gap-4">
                <div class="min-w-0 flex-1">
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <x-ui.badge tone="brand">{{ $types[$bookingRequest->type] ?? $bookingRequest->type }}</x-ui.badge>
                        <x-ui.status type="request" :value="$bookingRequest->status" />
                        <span class="text-xs text-ink-400">{{ $bookingRequest->created_at->diffForHumans() }}</span>
                    </div>

                    <a href="{{ route('admin.trainees.show', $bookingRequest->trainee) }}"
                       class="text-sm font-semibold text-ink-900 hover:text-brand-600">
                        {{ $bookingRequest->trainee->full_name }}
                    </a>

                    <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-ink-500">
                        <div><dt class="inline">الهاتف:</dt> <dd class="inline font-mono text-ink-700" dir="ltr">{{ $bookingRequest->trainee->phone }}</dd></div>
                        @if ($bookingRequest->requested_date)
                            <div><dt class="inline">الموعد المطلوب:</dt>
                                <dd class="inline text-ink-700">
                                    {{ $bookingRequest->requested_date->format('Y-m-d') }}
                                    {{ short_time($bookingRequest->requested_start_time) }}
                                </dd>
                            </div>
                        @endif
                        @if ($bookingRequest->preferredTrainer)
                            <div><dt class="inline">المدرب المفضل:</dt> <dd class="inline text-ink-700">{{ $bookingRequest->preferredTrainer->full_name }}</dd></div>
                        @endif
                    </dl>

                    @if ($bookingRequest->trainee_note)
                        <p class="mt-2 rounded-lg bg-ink-50 p-2.5 text-sm text-ink-600">{{ $bookingRequest->trainee_note }}</p>
                    @endif

                    @if ($bookingRequest->admin_note)
                        <p class="mt-2 text-xs text-ink-500">
                            <span class="font-medium">رد الإدارة:</span> {{ $bookingRequest->admin_note }}
                            @if ($bookingRequest->resolver) — {{ $bookingRequest->resolver->name }} @endif
                        </p>
                    @endif
                </div>

                @if ($bookingRequest->isPending())
                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if ($bookingRequest->type === 'cancellation')
                            <x-ui.confirm
                                :action="route('admin.booking-requests.cancel', $bookingRequest)"
                                title="الموافقة على الإلغاء"
                                message="سيتم إلغاء الحصة المرتبطة بالطلب وإشعار المتدرب."
                                confirm-label="موافقة وإلغاء الحصة"
                                reason-label="ملاحظة (اختياري)"
                                reason-name="admin_note"
                            >
                                <x-slot:trigger>
                                    <x-ui.button type="button" size="sm">موافقة</x-ui.button>
                                </x-slot:trigger>
                            </x-ui.confirm>
                        @else
                            <x-ui.button type="button" size="sm"
                                         @click="$dispatch('open-modal', 'approve-{{ $bookingRequest->id }}')">
                                موافقة وجدولة
                            </x-ui.button>
                        @endif

                        <x-ui.confirm
                            :action="route('admin.booking-requests.reject', $bookingRequest)"
                            title="رفض الطلب"
                            message="سيتم إشعار المتدرب بالرفض مع السبب."
                            confirm-label="رفض الطلب"
                            reason-label="سبب الرفض"
                            reason-name="admin_note"
                        >
                            <x-slot:trigger>
                                <x-ui.button type="button" variant="secondary" size="sm">رفض</x-ui.button>
                            </x-slot:trigger>
                        </x-ui.confirm>
                    </div>
                @endif
            </div>

            {{-- Approve-and-schedule modal: the booking goes through the same
                 conflict checks as a booking made from the calendar. --}}
            @if ($bookingRequest->isPending() && $bookingRequest->type !== 'cancellation')
                <x-ui.modal name="approve-{{ $bookingRequest->id }}" title="جدولة الحصة والموافقة على الطلب">
                    <form method="POST" action="{{ route('admin.booking-requests.approve', $bookingRequest) }}"
                          id="approve-form-{{ $bookingRequest->id }}" class="grid gap-4 sm:grid-cols-2">
                        @csrf
                        <x-form.select name="trainer_id" label="المدرب" :options="$trainers"
                                       :selected="$bookingRequest->preferred_trainer_id" required />
                        <x-form.input name="scheduled_date" type="date" label="التاريخ"
                                      :value="$bookingRequest->requested_date?->format('Y-m-d')" required />
                        <x-form.input name="start_time" type="time" label="وقت البداية"
                                      :value="short_time($bookingRequest->requested_start_time)" required />
                        <x-form.input name="duration_minutes" type="number" label="المدة (دقيقة)"
                                      :value="settings('training.default_lesson_duration', 45)" min="15" max="300" />
                        <x-form.textarea name="admin_note" label="ملاحظة للمتدرب" rows="2" class="sm:col-span-2" />
                    </form>

                    <x-slot:footer>
                        <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
                        <x-ui.button type="submit" size="sm" form="approve-form-{{ $bookingRequest->id }}">
                            تأكيد الحجز
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>
            @endif
        </x-ui.card>
    @empty
        <x-ui.card>
            <x-ui.empty-state
                icon="inbox"
                title="لا توجد طلبات"
                description="ستظهر هنا طلبات الحجز والتأجيل الواردة من تطبيق المتدربين."
            />
        </x-ui.card>
    @endforelse

    <div class="mt-4">{{ $requests->links() }}</div>
@endsection
