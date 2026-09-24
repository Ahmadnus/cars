@extends('pdf._layout')

@section('content')
    <div class="doc-title">إيصال صرف أجر مدرب</div>

    <table class="kv">
        <tr><th>رقم الإيصال</th><td class="num">{{ $payment->receipt_number }}</td></tr>
        <tr><th>اسم المدرب</th><td>{{ $record->trainer->full_name }}</td></tr>
        <tr><th>رقم المدرب</th><td class="num">{{ $record->trainer->trainer_number }}</td></tr>
        <tr><th>عن شهر</th><td class="num">{{ $record->period }}</td></tr>
        <tr><th>نموذج الأجر</th><td>{{ \App\Models\TrainerCompensationRule::MODELS[$record->model] ?? $record->model }}</td></tr>
        <tr><th>تاريخ الصرف</th><td class="num">{{ $payment->paid_on->format('Y-m-d') }}</td></tr>
        <tr><th>طريقة الدفع</th><td>{{ $payment->paymentMethod?->label_ar }}</td></tr>
        <tr><th>صرفها</th><td>{{ $payment->payer?->name ?? '—' }}</td></tr>
    </table>

    <div class="doc-title" style="font-size: 11pt; margin-top: 18px;">تفصيل الأجر</div>

    <table class="data">
        <thead>
            <tr>
                <th>البند</th>
                <th style="width: 30%">القيمة</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>عدد الحصص المنجزة</td><td class="num">{{ $record->lessons_count }}</td></tr>
            <tr><td>ساعات التدريب</td><td class="num">{{ $record->trainingHours() }}</td></tr>
            <tr><td>الإيراد المنسوب</td><td class="num">{{ number_format((float) $record->attributed_revenue, 2) }}</td></tr>
            <tr><td>الراتب الأساسي</td><td class="num">{{ number_format((float) $record->base_salary, 2) }}</td></tr>
            <tr><td>أجر الحصص</td><td class="num">{{ number_format((float) $record->lesson_earnings, 2) }}</td></tr>
            <tr><td>نسبة من الإيراد</td><td class="num">{{ number_format((float) $record->percentage_earnings, 2) }}</td></tr>
            <tr><td>المكافآت</td><td class="num">{{ number_format((float) $record->bonuses, 2) }}</td></tr>
            <tr><td>الاستقطاعات</td><td class="num">({{ number_format((float) $record->deductions, 2) }})</td></tr>
        </tbody>
        <tfoot>
            <tr>
                <td>صافي الأجر</td>
                <td class="num">{{ number_format((float) $record->net_amount, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="amount-box">
        <div class="label">المبلغ المصروف بهذا الإيصال</div>
        <div class="value">{{ number_format((float) $payment->amount, 2) }} {{ $center['currency'] }}</div>
    </div>

    @if ($record->remainingAmount() > 0)
        <p class="muted">المتبقي من أجر هذا الشهر: {{ number_format($record->remainingAmount(), 2) }} {{ $center['currency'] }}</p>
    @endif

    <table class="signatures">
        <tr>
            <td style="width: 50%"><div class="sig-line">توقيع المدرب</div></td>
            <td style="width: 50%"><div class="sig-line">توقيع المحاسب</div></td>
        </tr>
    </table>
@endsection
