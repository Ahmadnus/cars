@extends('pdf._layout')

@section('content')
    <div class="doc-title">إيصال صرف راتب</div>

    <table class="kv">
        <tr><th>رقم الإيصال</th><td class="num">{{ $payment->receipt_number }}</td></tr>
        <tr><th>اسم الموظف</th><td>{{ $payroll->employee->full_name }}</td></tr>
        <tr><th>رقم الموظف</th><td class="num">{{ $payroll->employee->employee_number }}</td></tr>
        <tr><th>الوظيفة</th><td>{{ \App\Http\Controllers\Admin\EmployeeController::positions()[$payroll->employee->position] ?? '' }}</td></tr>
        <tr><th>عن شهر</th><td class="num">{{ $payroll->period }}</td></tr>
        <tr><th>تاريخ الصرف</th><td class="num">{{ $payment->paid_on->format('Y-m-d') }}</td></tr>
        <tr><th>طريقة الدفع</th><td>{{ $payment->paymentMethod?->label_ar }}</td></tr>
        @if ($payment->reference_number)
            <tr><th>رقم المرجع</th><td class="num">{{ $payment->reference_number }}</td></tr>
        @endif
        <tr><th>صرفها</th><td>{{ $payment->payer?->name ?? '—' }}</td></tr>
    </table>

    <div class="doc-title" style="font-size: 11pt; margin-top: 18px;">تفصيل الراتب</div>

    <table class="data">
        <thead>
            <tr>
                <th>البند</th>
                <th style="width: 30%">المبلغ ({{ $center['currency'] }})</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>الراتب الأساسي</td><td class="num">{{ number_format((float) $payroll->base_salary, 2) }}</td></tr>
            <tr><td>البدلات</td><td class="num">{{ number_format((float) $payroll->allowances, 2) }}</td></tr>
            <tr><td>المكافآت</td><td class="num">{{ number_format((float) $payroll->bonuses, 2) }}</td></tr>
            <tr><td>الاستقطاعات</td><td class="num">({{ number_format((float) $payroll->deductions, 2) }})</td></tr>
            <tr><td>أقساط السلف</td><td class="num">({{ number_format((float) $payroll->advance_deductions, 2) }})</td></tr>
        </tbody>
        <tfoot>
            <tr>
                <td>صافي الراتب</td>
                <td class="num">{{ number_format((float) $payroll->net_salary, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="amount-box">
        <div class="label">المبلغ المصروف بهذا الإيصال</div>
        <div class="value">{{ number_format((float) $payment->amount, 2) }} {{ $center['currency'] }}</div>
    </div>

    @if ($payroll->remainingAmount() > 0)
        <p class="muted">المتبقي من راتب هذا الشهر: {{ number_format($payroll->remainingAmount(), 2) }} {{ $center['currency'] }}</p>
    @endif

    @if ($payment->notes)
        <p class="muted" style="margin-top: 10px;">ملاحظات: {{ $payment->notes }}</p>
    @endif

    <table class="signatures">
        <tr>
            <td style="width: 50%"><div class="sig-line">توقيع الموظف</div></td>
            <td style="width: 50%"><div class="sig-line">توقيع المحاسب</div></td>
        </tr>
    </table>
@endsection
