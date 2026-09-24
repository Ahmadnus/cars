@extends('pdf._layout')

@section('content')
    <div class="doc-title">
        إيصال قبض
        @if ($payment->isVoided())
            <span class="void">— ملغى</span>
        @endif
    </div>

    <table class="kv">
        <tr><th>رقم الإيصال</th><td class="num">{{ $payment->receipt_number }}</td></tr>
        <tr><th>التاريخ</th><td class="num">{{ $payment->paid_on->format('Y-m-d') }}</td></tr>
        <tr><th>اسم المتدرب</th><td>{{ $payment->trainee?->full_name ?? '—' }}</td></tr>
        <tr><th>رقم المتدرب</th><td class="num">{{ $payment->trainee?->trainee_number ?? '—' }}</td></tr>
        <tr><th>رقم الهاتف</th><td class="num">{{ $payment->trainee?->phone ?? '—' }}</td></tr>
        <tr><th>الباقة</th><td>{{ $payment->traineePackage?->package_name ?? '—' }}</td></tr>
        <tr><th>طريقة الدفع</th><td>{{ $payment->paymentMethod?->label_ar }}</td></tr>
        @if ($payment->reference_number)
            <tr><th>رقم المرجع</th><td class="num">{{ $payment->reference_number }}</td></tr>
        @endif
        <tr><th>استلمها</th><td>{{ $payment->receiver?->name ?? '—' }}</td></tr>
    </table>

    <div class="amount-box">
        <div class="label">المبلغ المستلم</div>
        <div class="value">{{ number_format((float) $payment->amount, 2) }} {{ $center['currency'] }}</div>
    </div>

    @if ($payment->traineePackage)
        @php $enrolment = $payment->traineePackage; @endphp
        <table class="data">
            <thead>
                <tr>
                    <th>إجمالي العقد</th>
                    <th>المدفوع حتى الآن</th>
                    <th>المتبقي</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="num">{{ number_format((float) $enrolment->total_amount, 2) }}</td>
                    <td class="num">{{ number_format((float) $enrolment->paid_amount, 2) }}</td>
                    <td class="num">{{ number_format($enrolment->remainingAmount(), 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    @if ($payment->notes)
        <p class="muted" style="margin-top: 12px;">ملاحظات: {{ $payment->notes }}</p>
    @endif

    @if ($payment->isVoided())
        <p class="void" style="margin-top: 12px;">
            تم إلغاء هذا الإيصال بتاريخ {{ $payment->voided_at?->format('Y-m-d') }} — السبب: {{ $payment->void_reason }}
        </p>
    @endif

    <table class="signatures">
        <tr>
            <td style="width: 50%"><div class="sig-line">توقيع المستلم</div></td>
            <td style="width: 50%"><div class="sig-line">توقيع الدافع</div></td>
        </tr>
    </table>
@endsection
