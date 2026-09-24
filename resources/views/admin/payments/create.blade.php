@extends('layouts.app')

@section('title', 'تسجيل دفعة')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المدفوعات' => route('admin.payments.index'), 'تسجيل دفعة' => null]" />
@endsection

@section('content')
@php
    // Built here rather than inline in @js(): Blade's directive-argument parser
    // does not handle array literals nested inside an arrow function.
    $packageOptions = $packages->map(fn ($p) => [
        'id' => $p->id,
        'name' => $p->package_name,
        'remaining' => $p->remainingAmount(),
    ])->values();
@endphp

<form method="POST" action="{{ route('admin.payments.store') }}"
      x-data="paymentForm()" class="grid gap-5 lg:grid-cols-3">
    @csrf

    <div class="lg:col-span-2">
        <x-ui.card title="تفاصيل الدفعة">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.select
                    name="trainee_id"
                    label="المتدرب"
                    :options="$trainees"
                    :selected="$trainee?->id"
                    placeholder="اختر المتدرب"
                    required
                    class="sm:col-span-2"
                    x-model="traineeId"
                    @change="loadPackages()"
                />

                <x-form.field label="الباقة" name="trainee_package_id" hint="اختياري — اتركه فارغاً لدفعة غير مرتبطة بباقة.">
                    <select name="trainee_package_id" x-model="packageId" @change="syncAmount()"
                            class="block w-full rounded-lg border-ink-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">بدون باقة</option>
                        <template x-for="pkg in packages" :key="pkg.id">
                            <option :value="pkg.id" x-text="pkg.name + ' — المتبقي ' + pkg.remaining.toFixed(2)"></option>
                        </template>
                    </select>
                </x-form.field>

                <x-form.select
                    name="source"
                    label="نوع الإيراد"
                    :options="['package' => 'باقة تدريب', 'extra_lesson' => 'حصص إضافية', 'other' => 'أخرى']"
                    selected="package"
                    required
                />

                <x-form.input name="amount" type="number" step="0.01" min="0.01" label="المبلغ" required x-model="amount" />
                <x-form.input name="paid_on" type="date" label="تاريخ الدفع" :value="now()->toDateString()" required />

                <x-form.field label="طريقة الدفع" name="payment_method_id" required>
                    <select name="payment_method_id" x-model="methodId" required
                            class="block w-full rounded-lg border-ink-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">اختر طريقة الدفع</option>
                        @foreach ($methods as $method)
                            <option value="{{ $method->id }}"
                                    data-reference="{{ $method->requires_reference ? '1' : '0' }}"
                                    data-cash="{{ $method->affects_cashbox ? '1' : '0' }}">
                                {{ $method->label_ar }}
                            </option>
                        @endforeach
                    </select>
                </x-form.field>

                <x-form.input name="reference_number" label="رقم المرجع"
                              x-bind:required="requiresReference()"
                              hint="مطلوب للحوالات والشيكات والبطاقات." />

                <x-form.textarea name="notes" label="ملاحظات" rows="2" class="sm:col-span-2" />
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="ملخص">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-500">المبلغ</dt>
                    <dd class="font-semibold tabular-nums text-ink-900" x-text="formatMoney(amount)">—</dd>
                </div>
                <div class="flex justify-between gap-3" x-show="selectedPackage()" x-cloak>
                    <dt class="text-ink-500">المتبقي بعد الدفع</dt>
                    <dd class="font-medium tabular-nums text-ink-700" x-text="formatMoney(remainingAfter())">—</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs text-ink-400" x-show="affectsCashbox()" x-cloak>
                سيتم إيداع المبلغ في صندوق المركز تلقائياً.
            </p>
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">تسجيل الدفعة</x-ui.button>
                <x-ui.button :href="route('admin.payments.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</form>

@push('scripts')
<script>
    function paymentForm() {
        return {
            traineeId: '{{ old('trainee_id', $trainee?->id) }}',
            packageId: '{{ old('trainee_package_id') }}',
            methodId: '{{ old('payment_method_id') }}',
            amount: '{{ old('amount') }}',
            packages: @json($packageOptions),

            init() {
                if (this.traineeId && this.packages.length === 0) this.loadPackages();
            },

            async loadPackages() {
                this.packageId = '';

                if (!this.traineeId) {
                    this.packages = [];
                    return;
                }

                const url = '{{ url('payments/trainees') }}/' + this.traineeId + '/packages';

                try {
                    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    const data = await response.json();
                    this.packages = data.packages ?? [];
                } catch {
                    this.packages = [];
                }
            },

            selectedPackage() {
                return this.packages.find(p => String(p.id) === String(this.packageId));
            },

            /** Default the amount to whatever is still owed on the package. */
            syncAmount() {
                const pkg = this.selectedPackage();
                if (pkg && !this.amount) this.amount = pkg.remaining.toFixed(2);
            },

            remainingAfter() {
                const pkg = this.selectedPackage();
                if (!pkg) return 0;
                return Math.max(0, pkg.remaining - (parseFloat(this.amount) || 0));
            },

            selectedMethodOption() {
                const select = this.$el.querySelector('select[name="payment_method_id"]');
                return select?.selectedOptions?.[0];
            },

            requiresReference() {
                return this.selectedMethodOption()?.dataset.reference === '1';
            },

            affectsCashbox() {
                return this.selectedMethodOption()?.dataset.cash === '1';
            },

            formatMoney(value) {
                const number = parseFloat(value) || 0;
                return number.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    + ' {{ settings('center.currency_label', 'د.أ') }}';
            },
        };
    }
</script>
@endpush
@endsection
