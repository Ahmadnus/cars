@extends('layouts.app')

@section('title', 'طلبات الانتساب')
@section('subtitle', $openCount . ' طلب بانتظار القرار')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['طلبات الانتساب' => null]" />
@endsection

@section('content')
    <x-layout.page-header
        title="طلبات الانتساب"
        description="الطلبات الواردة من تطبيق المتدربين. لا يُنشأ ملف متدرب إلا بعد القبول."
    />

    <x-ui.filters :action="route('admin.registrations.index')" :collapsible="false">
        <x-slot:fields>
            <x-form.select name="status" label="الحالة" :options="$statuses" :selected="request('status')" placeholder="المعلّقة فقط" />
            <x-form.input name="q" label="بحث" :value="request('q')" placeholder="الاسم أو الهاتف أو رقم الطلب" />
        </x-slot:fields>
    </x-ui.filters>

    @forelse ($requests as $registration)
        <x-ui.card class="mb-3">
            <div class="flex flex-wrap items-start gap-4">
                <div class="min-w-0 flex-1">
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <x-ui.badge tone="brand">{{ $registration->reference }}</x-ui.badge>
                        <x-ui.badge :tone="match ($registration->status) {
                            'approved' => 'success',
                            'rejected' => 'danger',
                            'reviewing' => 'warning',
                            default => 'info',
                        }">{{ $registration->statusLabel() }}</x-ui.badge>

                        {{-- A verified phone is the only thing standing between the
                             queue and anonymous junk, so it is stated plainly. --}}
                        @if ($registration->isVerified())
                            <x-ui.badge tone="success">رقم موثّق</x-ui.badge>
                        @else
                            <x-ui.badge tone="danger">رقم غير موثّق</x-ui.badge>
                        @endif

                        <span class="text-xs text-ink-400">{{ $registration->created_at->diffForHumans() }}</span>
                    </div>

                    <p class="text-sm font-semibold text-ink-900">{{ $registration->full_name }}</p>

                    <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-xs text-ink-500">
                        <div>
                            <dt class="inline">الهاتف:</dt>
                            <dd class="inline font-mono text-ink-700" dir="ltr">{{ $registration->phone }}</dd>
                        </div>
                        @if ($registration->national_id)
                            <div>
                                <dt class="inline">الرقم الوطني:</dt>
                                <dd class="inline font-mono text-ink-700" dir="ltr">{{ $registration->national_id }}</dd>
                            </div>
                        @endif
                        @if ($registration->city)
                            <div><dt class="inline">المدينة:</dt> <dd class="inline text-ink-700">{{ $registration->city }}</dd></div>
                        @endif
                        @if ($registration->branch)
                            <div><dt class="inline">الفرع المطلوب:</dt> <dd class="inline text-ink-700">{{ $registration->branch->name }}</dd></div>
                        @endif
                        @if ($registration->trainee)
                            <div>
                                <dt class="inline">رقم الملف:</dt>
                                <dd class="inline font-mono text-ink-700">{{ $registration->trainee->trainee_number }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($registration->notes)
                        <p class="mt-2 rounded-lg bg-ink-50 p-2.5 text-xs text-ink-600">{{ $registration->notes }}</p>
                    @endif

                    @if ($registration->decision_reason)
                        <p class="mt-2 text-xs text-rose-600">سبب القرار: {{ $registration->decision_reason }}</p>
                    @endif
                </div>

                @canDo('registrations.manage')
                    @if ($registration->isOpen())
                        <div x-data="{ mode: null }" class="w-full shrink-0 sm:w-72">
                            <div class="flex gap-2" x-show="mode === null">
                                <x-ui.button size="sm" @click="mode = 'approve'" class="flex-1">قبول</x-ui.button>
                                <x-ui.button size="sm" variant="danger" @click="mode = 'reject'" class="flex-1">رفض</x-ui.button>
                            </div>

                            {{-- Approving creates a real trainee, so the branch and
                                 trainer are chosen here rather than guessed. --}}
                            <form x-show="mode === 'approve'" x-cloak method="POST"
                                  action="{{ route('admin.registrations.approve', $registration) }}"
                                  class="space-y-2 rounded-lg border border-ink-200 p-3">
                                @csrf
                                <x-form.select
                                    name="branch_id"
                                    label="الفرع"
                                    :options="$branches->pluck('name', 'id')->all()"
                                    :selected="$registration->branch_id"
                                    placeholder="اختر الفرع" />

                                <x-form.select
                                    name="trainer_id"
                                    label="المدرب (اختياري)"
                                    :options="$trainers->pluck('full_name', 'id')->all()"
                                    placeholder="يُسند لاحقاً" />

                                <label class="flex items-center gap-2 text-xs text-ink-600">
                                    <input type="checkbox" name="create_login" value="1" checked
                                           class="rounded border-ink-300 text-brand-600">
                                    إنشاء حساب دخول للمتدرب
                                </label>

                                <div class="flex gap-2">
                                    <x-ui.button size="sm" type="submit" class="flex-1">تأكيد القبول</x-ui.button>
                                    <x-ui.button size="sm" variant="secondary" type="button" @click="mode = null">إلغاء</x-ui.button>
                                </div>
                            </form>

                            {{-- The reason is required: the applicant reads it. --}}
                            <form x-show="mode === 'reject'" x-cloak method="POST"
                                  action="{{ route('admin.registrations.reject', $registration) }}"
                                  class="space-y-2 rounded-lg border border-ink-200 p-3">
                                @csrf
                                <x-form.textarea name="reason" label="سبب الرفض" rows="2" required
                                                 placeholder="يقرأه مقدّم الطلب في التطبيق." />
                                <div class="flex gap-2">
                                    <x-ui.button size="sm" variant="danger" type="submit" class="flex-1">تأكيد الرفض</x-ui.button>
                                    <x-ui.button size="sm" variant="secondary" type="button" @click="mode = null">إلغاء</x-ui.button>
                                </div>
                            </form>
                        </div>
                    @endif
                @endcanDo
            </div>
        </x-ui.card>
    @empty
        <x-ui.card>
            <x-ui.empty-state
                title="لا توجد طلبات"
                description="ستظهر هنا طلبات الانتساب الواردة من التطبيق." />
        </x-ui.card>
    @endforelse

    {{ $requests->links() }}
@endsection
