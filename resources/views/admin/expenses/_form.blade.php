{{-- Shared by create and edit. --}}
@php
    $expense ??= null;
    $categories ??= [];
    $methods ??= [];
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <x-ui.card title="تفاصيل المصروف">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="title" label="البيان" :value="$expense?->title" required class="sm:col-span-2" />
                <x-form.select name="expense_category_id" label="التصنيف" :options="$categories"
                               :selected="$expense?->expense_category_id" placeholder="اختر التصنيف" required />
                <x-form.select name="payment_method_id" label="طريقة الدفع" :options="$methods"
                               :selected="$expense?->payment_method_id" placeholder="اختر الطريقة" required />
                <x-form.input name="amount" type="number" step="0.01" min="0.01" label="المبلغ"
                              :value="$expense?->amount" required />
                <x-form.input name="spent_on" type="date" label="تاريخ الصرف"
                              :value="$expense?->spent_on?->format('Y-m-d') ?? now()->toDateString()" required />
                <x-form.input name="beneficiary" label="المستفيد" :value="$expense?->beneficiary" />
                <x-form.input name="invoice_number" label="رقم الفاتورة" :value="$expense?->invoice_number" />
                <x-form.textarea name="notes" label="ملاحظات" rows="2" :value="$expense?->notes" class="sm:col-span-2" />

                @if ($expense)
                    <x-form.input name="reason" label="سبب التعديل" required class="sm:col-span-2"
                                  hint="مطلوب لتسجيل التغيير في سجل التدقيق." />
                @endif
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-5">
        @unless ($expense)
            <x-ui.card title="مرفق الإيصال">
                <x-form.field label="الملف" name="receipt" hint="صورة أو PDF بحد أقصى 8 ميجابايت.">
                    <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.webp,.pdf"
                           class="block w-full text-sm text-ink-600 file:me-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                </x-form.field>
            </x-ui.card>
        @endunless

        <x-ui.alert type="info">
            المصاريف المدفوعة نقداً تُخصم تلقائياً من رصيد صندوق المركز.
        </x-ui.alert>

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $expense ? 'حفظ التعديلات' : 'تسجيل المصروف' }}</x-ui.button>
                <x-ui.button :href="route('admin.expenses.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
