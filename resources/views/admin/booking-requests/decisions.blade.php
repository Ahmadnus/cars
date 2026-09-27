@extends('layouts.app')

@section('title', 'قرارات المدربين')
@section('subtitle', $counts['approved'] . ' طلب وافق عليه المدرب')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'طلبات الحجز' => route('admin.booking-requests.index'),
        'قرارات المدربين' => null,
    ]" />
@endsection

@section('content')
    <x-layout.page-header
        title="طلبات التأجيل والإلغاء — قرار المدرب"
        description="ما وافق عليه المدربون أو رفضوه، ومن اتخذ القرار ومتى. الموافقة عليها من المدرب تُنفّذها الإدارة من هنا."
    />

    {{-- Tabs rather than a dropdown: these three are the whole question, and the
         counts are what a receptionist is scanning for. --}}
    @php
        $tabs = [
            'approved' => ['وافق المدرب', 'success'],
            'pending' => ['بانتظار المدرب', 'warning'],
            'rejected' => ['رفض المدرب', 'danger'],
        ];
    @endphp

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $value => [$label, $tone])
            @php $active = $decision === $value; @endphp
            <a href="{{ route('admin.booking-requests.decisions', ['decision' => $value, 'type' => request('type')]) }}"
               @class([
                   'flex items-center gap-2 rounded-lg border px-3.5 py-2 text-sm transition',
                   'border-brand-600 bg-brand-50 font-semibold text-brand-700' => $active,
                   'border-ink-200 text-ink-600 hover:bg-ink-50' => ! $active,
               ])>
                {{ $label }}
                <span @class([
                    'rounded-full px-2 py-0.5 text-xs font-semibold',
                    'bg-brand-600 text-white' => $active,
                    'bg-ink-100 text-ink-600' => ! $active,
                ])>{{ $counts[$value] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <x-ui.filters :action="route('admin.booking-requests.decisions')" :collapsible="false">
        <x-slot:fields>
            <input type="hidden" name="decision" value="{{ $decision }}">
            <x-form.select name="type" label="نوع الطلب" :options="$types" :selected="request('type')" placeholder="كل الأنواع" />
        </x-slot:fields>
    </x-ui.filters>

    @forelse ($requests as $bookingRequest)
        @php
            $trainerName = $bookingRequest->trainerDecider?->trainer?->full_name
                ?? $bookingRequest->trainerDecider?->name
                ?? $bookingRequest->decidingTrainer()?->full_name
                ?? '—';

            // Still the office's to carry out. A request the office already
            // closed is history and offers no buttons.
            $awaitingOffice = $bookingRequest->isPending();
        @endphp

        <x-ui.card class="mb-3">
            <div class="flex flex-wrap items-start gap-4">
                <div class="min-w-0 flex-1">
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <x-ui.badge tone="brand">{{ $types[$bookingRequest->type] ?? $bookingRequest->type }}</x-ui.badge>
                        <x-ui.status type="request" :value="$bookingRequest->status" />

                        <x-ui.badge :tone="match ($bookingRequest->trainer_decision) {
                            'approved' => 'success',
                            'rejected' => 'danger',
                            default => 'warning',
                        }">{{ $bookingRequest->trainerDecisionLabel() }}</x-ui.badge>

                        @if ($awaitingOffice && $bookingRequest->trainerApproved())
                            <x-ui.badge tone="warning">بانتظار تنفيذ الإدارة</x-ui.badge>
                        @endif
                    </div>

                    {{-- Named, because that is the whole point of the page: the
                         office needs to know who agreed before it moves a lesson. --}}
                    <p class="text-sm text-ink-800">
                        @if ($bookingRequest->trainerApproved())
                            وافق المدرب <span class="font-semibold">{{ $trainerName }}</span>
                        @elseif ($bookingRequest->trainerRejected())
                            رفض المدرب <span class="font-semibold">{{ $trainerName }}</span>
                        @else
                            بانتظار قرار المدرب <span class="font-semibold">{{ $trainerName }}</span>
                        @endif

                        على طلب
                        <a href="{{ route('admin.trainees.show', $bookingRequest->trainee) }}"
                           class="font-semibold text-brand-700 hover:underline">
                            {{ $bookingRequest->trainee->full_name }}
                        </a>

                        @if ($bookingRequest->trainer_decided_at)
                            <span class="text-xs text-ink-400">
                                — {{ $bookingRequest->trainer_decided_at->format('Y-m-d H:i') }}
                                ({{ $bookingRequest->trainer_decided_at->diffForHumans() }})
                            </span>
                        @endif
                    </p>

                    {{-- The two slots together: the office is about to move a
                         lesson from one to the other, so both belong on screen. --}}
                    <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-ink-500">
                        @if ($bookingRequest->trainingSession)
                            <div>
                                <dt class="inline">الموعد الحالي:</dt>
                                <dd class="inline text-ink-700">
                                    {{ $bookingRequest->trainingSession->scheduled_date?->format('Y-m-d') }}
                                    {{ short_time($bookingRequest->trainingSession->start_time) }}
                                </dd>
                            </div>
                        @endif
                        @if ($bookingRequest->requested_date)
                            <div>
                                <dt class="inline">الموعد المطلوب:</dt>
                                <dd class="inline font-semibold text-ink-800">
                                    {{ $bookingRequest->requested_date->format('Y-m-d') }}
                                    {{ short_time($bookingRequest->requested_start_time) }}
                                </dd>
                            </div>
                        @endif
                        <div>
                            <dt class="inline">الهاتف:</dt>
                            <dd class="inline font-mono text-ink-700" dir="ltr">{{ $bookingRequest->trainee->phone }}</dd>
                        </div>
                    </dl>

                    @if ($bookingRequest->trainer_note)
                        <p class="mt-2 rounded-lg border border-ink-200 bg-ink-50 p-2.5 text-xs text-ink-600">
                            <span class="font-semibold">ملاحظة المدرب:</span> {{ $bookingRequest->trainer_note }}
                        </p>
                    @endif

                    @if ($bookingRequest->trainee_note)
                        <p class="mt-2 rounded-lg bg-ink-50 p-2.5 text-xs text-ink-600">
                            <span class="font-semibold">ملاحظة المتدرب:</span> {{ $bookingRequest->trainee_note }}
                        </p>
                    @endif

                    @if ($bookingRequest->admin_note)
                        <p class="mt-2 text-xs text-ink-500">
                            <span class="font-medium">رد الإدارة:</span> {{ $bookingRequest->admin_note }}
                            @if ($bookingRequest->resolver) — {{ $bookingRequest->resolver->name }} @endif
                        </p>
                    @endif
                </div>

                @if ($awaitingOffice)
                    <div class="flex shrink-0 flex-wrap gap-2">
                        {{-- Only offered once the trainer has agreed. A refused
                             request is blocked in the controller too, so this is
                             the door being shut rather than the only lock. --}}
                        @if ($bookingRequest->trainerApproved())
                            @if ($bookingRequest->type === 'cancellation')
                                <x-ui.confirm
                                    :action="route('admin.booking-requests.cancel', $bookingRequest)"
                                    title="تنفيذ الإلغاء"
                                    message="سيتم إلغاء الحصة وإشعار المتدرب. المدرب وافق على الإلغاء."
                                    confirm-label="تنفيذ الإلغاء"
                                    reason-label="ملاحظة (اختياري)"
                                    reason-name="admin_note"
                                >
                                    <x-slot:trigger>
                                        <x-ui.button type="button" size="sm">تنفيذ الإلغاء</x-ui.button>
                                    </x-slot:trigger>
                                </x-ui.confirm>
                            @else
                                <x-ui.button type="button" size="sm"
                                             @click="$dispatch('open-modal', 'apply-{{ $bookingRequest->id }}')">
                                    تنفيذ التأجيل
                                </x-ui.button>
                            @endif
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

            {{-- Pre-filled with the slot the trainer agreed to, so the office is
                 confirming a decision rather than retyping it — and it still goes
                 through the same conflict checks as any booking. --}}
            @if ($awaitingOffice && $bookingRequest->trainerApproved() && $bookingRequest->type !== 'cancellation')
                <x-ui.modal name="apply-{{ $bookingRequest->id }}" title="تنفيذ الموعد الذي وافق عليه المدرب">
                    <form method="POST" action="{{ route('admin.booking-requests.approve', $bookingRequest) }}"
                          id="apply-form-{{ $bookingRequest->id }}" class="grid gap-4 sm:grid-cols-2">
                        @csrf
                        <x-form.select name="trainer_id" label="المدرب" :options="$trainers"
                                       :selected="$bookingRequest->trainingSession?->trainer_id ?? $bookingRequest->preferred_trainer_id"
                                       required />
                        <x-form.input name="scheduled_date" type="date" label="التاريخ"
                                      :value="$bookingRequest->requested_date?->format('Y-m-d')" required />
                        <x-form.input name="start_time" type="time" label="وقت البداية"
                                      :value="short_time($bookingRequest->requested_start_time)" required />
                        <x-form.input name="duration_minutes" type="number" label="المدة (دقيقة)"
                                      :value="$bookingRequest->trainingSession?->duration_minutes ?? settings('training.default_lesson_duration', 45)"
                                      min="15" max="300" />
                        <x-form.textarea name="admin_note" label="ملاحظة للمتدرب" rows="2" class="sm:col-span-2" />
                    </form>

                    <x-slot:footer>
                        <x-ui.button type="button" variant="secondary" size="sm" @click="open = false">إلغاء</x-ui.button>
                        <x-ui.button type="submit" size="sm" form="apply-form-{{ $bookingRequest->id }}">
                            تأكيد التنفيذ
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>
            @endif
        </x-ui.card>
    @empty
        <x-ui.card>
            <x-ui.empty-state
                icon="inbox"
                :title="match ($decision) {
                    'approved' => 'لا توجد طلبات وافق عليها المدربون',
                    'rejected' => 'لا توجد طلبات رفضها المدربون',
                    default => 'لا توجد طلبات بانتظار المدربين',
                }"
                description="طلبات التأجيل والإلغاء تُعرض على المدرب أولاً، ويظهر قراره هنا."
            />
        </x-ui.card>
    @endforelse

    <div class="mt-4">{{ $requests->links() }}</div>
@endsection
