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

    {{-- Straight after an approval: the password exists for this one render, so
         it is put in front of the receptionist with a WhatsApp link rather than
         buried in a toast that disappears. --}}
    @if (session('issued_credentials'))
        <x-account.credentials
            class="mb-4"
            :name="session('issued_credentials')['name'] ?? null"
            :phone="session('issued_credentials')['phone'] ?? null"
            :password="session('issued_credentials')['password'] ?? null"
            :email="session('issued_credentials')['email'] ?? null" />
    @endif

    <x-ui.live-queue
        :url="route('admin.registrations.count')"
        :current="$openCount"
        key="open"
        message="وصل :count طلب انتساب جديد." />

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
                        @if ($registration->license_type)
                            <div>
                                <dt class="inline">نوع الرخصة:</dt>
                                <dd class="inline text-ink-700">{{ \App\Enums\LicenseType::labelFor($registration->license_type) }}</dd>
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

                    @php
                        $idPhoto = $registration->documents->firstWhere('category', 'identity');

                        $reading = $registration->idReading();
                        $readFields = $reading?->fields() ?? [];

                        // What the applicant typed, to put beside what the card
                        // says: the two disagree often — a nickname on the form,
                        // the full four-part name on the card — and that
                        // disagreement is the whole reason a human is reviewing.
                        $typedByApplicant = [
                            'full_name' => $registration->full_name,
                            'national_id' => $registration->national_id,
                            'birth_date' => $registration->birth_date?->format('Y-m-d'),
                            'gender' => $registration->gender,
                            'city' => $registration->city,
                            'address' => $registration->address,
                        ];

                        $readLabels = [
                            'full_name' => 'الاسم',
                            'national_id' => 'الرقم الوطني',
                            'birth_date' => 'تاريخ الميلاد',
                            'gender' => 'الجنس',
                            'city' => 'المدينة',
                            'address' => 'العنوان',
                        ];

                        $genderLabels = ['male' => 'ذكر', 'female' => 'أنثى'];
                    @endphp

                    @if ($idPhoto)
                        {{-- Shown, not linked. The reason the centre asked for
                             the photo is so a reviewer can read the name and
                             the national number off the ID and check them
                             against the form — which they cannot do from a
                             filename, and will not do if it costs them a new
                             tab and their place in the queue. Clicking opens
                             it full size, still without leaving the page. --}}
                        <div x-data="{ zoom: false }" class="mt-3">
                            <p class="mb-1.5 text-xs font-medium text-ink-500">صورة الهوية</p>

                            <button type="button" @click="zoom = true"
                                    class="block overflow-hidden rounded-xl border border-ink-200 bg-ink-50 transition hover:border-ink-400"
                                    title="اضغط للتكبير">
                                <img src="{{ route('admin.documents.view', $idPhoto) }}"
                                     alt="صورة هوية {{ $registration->full_name }}"
                                     loading="lazy"
                                     class="h-36 w-auto max-w-full object-contain" />
                            </button>

                            <div x-show="zoom" x-cloak
                                 @click="zoom = false"
                                 @keydown.escape.window="zoom = false"
                                 class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/80 p-4"
                                 role="dialog" aria-modal="true"
                                 aria-label="صورة هوية {{ $registration->full_name }}">
                                <img src="{{ route('admin.documents.view', $idPhoto) }}"
                                     alt="صورة هوية {{ $registration->full_name }}"
                                     @click.stop
                                     class="max-h-full max-w-full rounded-lg object-contain shadow-2xl" />

                                <button type="button" @click="zoom = false"
                                        class="absolute end-4 top-4 rounded-lg bg-white/90 p-2 text-ink-700 shadow hover:bg-white"
                                        aria-label="إغلاق">
                                    <x-ui.icon name="x" class="size-4" />
                                </button>
                            </div>
                        </div>
                    @endif

                    @if ($idPhoto)
                        {{-- What the card itself says.

                             Shown beside the applicant's own answers rather than
                             instead of them: the reviewer decides which goes onto
                             the trainee's file, and the form they filled in is
                             the only record of what they claimed. --}}
                        <div class="mt-3 rounded-xl border border-ink-200 bg-white p-3">
                            <div class="mb-2 flex flex-wrap items-center gap-2">
                                <p class="text-xs font-semibold text-ink-700">قراءة الهوية</p>

                                @if ($reading?->confidence === 'low')
                                    <x-ui.badge tone="warning">صورة غير واضحة</x-ui.badge>
                                @endif

                                @if ($registration->id_scanned_at)
                                    <span class="text-[11px] text-ink-400">{{ $registration->id_scanned_at->diffForHumans() }}</span>
                                @endif

                                @canDo('registrations.manage')
                                    @if ($canReadIds)
                                        <form method="POST" class="ms-auto"
                                              action="{{ route('admin.registrations.scan-id', $registration) }}">
                                            @csrf
                                            <x-ui.button size="sm" variant="secondary" type="submit">
                                                {{ $reading ? 'إعادة القراءة' : 'اقرأ بيانات الهوية' }}
                                            </x-ui.button>
                                        </form>
                                    @endif
                                @endcanDo
                            </div>

                            @if ($readFields !== [])
                                <dl class="grid gap-x-6 gap-y-1.5 text-xs sm:grid-cols-2">
                                    @foreach ($readLabels as $field => $label)
                                        @continue (! isset($readFields[$field]))

                                        @php
                                            $readValue = $readFields[$field];
                                            $typedValue = $typedByApplicant[$field] ?? null;
                                            $differs = filled($typedValue)
                                                && trim((string) $typedValue) !== trim((string) $readValue);
                                            $isNumeric = in_array($field, ['national_id', 'birth_date'], true);
                                        @endphp

                                        <div class="flex flex-wrap items-baseline gap-1.5">
                                            <dt class="text-ink-500">{{ $label }}:</dt>
                                            <dd class="font-medium text-ink-900 {{ $isNumeric ? 'font-mono' : '' }}"
                                                @if ($isNumeric) dir="ltr" @endif>
                                                {{ $field === 'gender' ? ($genderLabels[$readValue] ?? $readValue) : $readValue }}
                                            </dd>

                                            @if ($differs)
                                                <span class="text-[11px] text-amber-600">
                                                    (كتب: {{ $field === 'gender' ? ($genderLabels[$typedValue] ?? $typedValue) : $typedValue }})
                                                </span>
                                            @endif
                                        </div>
                                    @endforeach
                                </dl>

                                @if ($reading && $reading->unclear !== [])
                                    <p class="mt-2 text-[11px] text-amber-600">
                                        حقول لم تُقرأ بوضوح فتُركت فارغة:
                                        {{ collect($reading->unclear)->map(fn ($field) => $readLabels[$field] ?? $field)->join('، ') }}
                                    </p>
                                @endif
                            @elseif ($reading)
                                <p class="text-xs text-ink-500">{{ $reading->message ?? 'لم تُقرأ بيانات من الصورة.' }}</p>
                            @else
                                <p class="text-xs text-ink-500">
                                    لم تُقرأ الهوية بعد.
                                    @unless ($canReadIds)
                                        قراءة الهوية غير مفعّلة على هذا الخادم.
                                    @endunless
                                </p>
                            @endif
                        </div>
                    @endif

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

                                {{-- Offered only when there is something to
                                     take across, and left unticked: the card is
                                     on screen above, and which of the two
                                     versions goes onto the file is the
                                     reviewer's call, not the reader's. --}}
                                @if ($readFields !== [])
                                    <label class="flex items-start gap-2 rounded-lg bg-brand-50 p-2 text-xs text-ink-700">
                                        <input type="checkbox" name="use_scan" value="1"
                                               class="mt-0.5 rounded border-ink-300 text-brand-600">
                                        <span>
                                            استخدام البيانات المقروءة من الهوية
                                            <span class="block text-[11px] text-ink-500">
                                                {{ collect(array_keys($readFields))
                                                    ->reject(fn ($field) => $field === 'city')
                                                    ->map(fn ($field) => $readLabels[$field] ?? $field)
                                                    ->join('، ') }}
                                            </span>
                                        </span>
                                    </label>
                                @endif

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
