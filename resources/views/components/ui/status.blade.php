@props(['value', 'type' => 'generic'])

@php
    /**
     * Status colour is decided in one place so the same state always reads the
     * same way — a cancelled payment and a cancelled lesson look alike, and a
     * user never has to relearn the palette per screen.
     */
    $maps = [
        'trainee' => [
            'new' => ['جديد', 'info'],
            'in_training' => ['قيد التدريب', 'brand'],
            'suspended' => ['موقوف مؤقتاً', 'warning'],
            'ready_for_exam' => ['جاهز للامتحان', 'purple'],
            'exam_scheduled' => ['موعد امتحان', 'purple'],
            'passed' => ['ناجح', 'success'],
            'failed' => ['راسب', 'danger'],
            'completed' => ['أنهى التدريب', 'success'],
            'cancelled' => ['ملغى', 'neutral'],
        ],
        'session' => [
            'scheduled' => ['مجدولة', 'info'],
            'completed' => ['منجزة', 'success'],
            'cancelled' => ['ملغاة', 'neutral'],
            'postponed' => ['مؤجلة', 'warning'],
            'no_show' => ['عدم حضور', 'danger'],
        ],
        'payment' => [
            'completed' => ['مكتملة', 'success'],
            'voided' => ['ملغاة', 'danger'],
        ],
        'expense' => [
            'recorded' => ['مسجّل', 'success'],
            'cancelled' => ['ملغى', 'danger'],
        ],
        'payroll' => [
            'unpaid' => ['غير مدفوع', 'warning'],
            'partially_paid' => ['مدفوع جزئياً', 'info'],
            'paid' => ['مدفوع', 'success'],
            'cancelled' => ['ملغى', 'neutral'],
        ],
        'compensation' => [
            'draft' => ['مسودة', 'neutral'],
            'approved' => ['معتمد', 'info'],
            'partially_paid' => ['مصروف جزئياً', 'warning'],
            'paid' => ['مصروف', 'success'],
            'cancelled' => ['ملغى', 'neutral'],
        ],
        'vehicle' => [
            'available' => ['متاحة', 'success'],
            'in_use' => ['قيد الاستخدام', 'info'],
            'maintenance' => ['في الصيانة', 'warning'],
            'inactive' => ['غير نشطة', 'neutral'],
        ],
        'person' => [
            'active' => ['نشط', 'success'],
            'on_leave' => ['في إجازة', 'warning'],
            'terminated' => ['منتهي الخدمة', 'neutral'],
            'inactive' => ['معطّل', 'neutral'],
            'suspended' => ['موقوف', 'danger'],
        ],
        'request' => [
            'pending' => ['معلّق', 'warning'],
            'approved' => ['موافق عليه', 'success'],
            'rejected' => ['مرفوض', 'danger'],
            'rescheduled' => ['أُعيدت جدولته', 'info'],
            'cancelled' => ['ملغى', 'neutral'],
        ],
        'bill' => [
            'unpaid' => ['غير مدفوعة', 'warning'],
            'paid' => ['مدفوعة', 'success'],
            'overdue' => ['متأخرة', 'danger'],
        ],
        'rating' => [
            'not_started' => ['لم يبدأ', 'neutral'],
            'needs_training' => ['يحتاج تدريب', 'danger'],
            'average' => ['متوسط', 'warning'],
            'good' => ['جيد', 'info'],
            'very_good' => ['جيد جداً', 'brand'],
            'excellent' => ['ممتاز', 'success'],
        ],
        'generic' => [
            'active' => ['نشط', 'success'],
            'inactive' => ['غير نشط', 'neutral'],
        ],
    ];

    [$label, $tone] = $maps[$type][$value] ?? [$value, 'neutral'];
@endphp

<x-ui.badge :tone="$tone" dot {{ $attributes }}>{{ $label }}</x-ui.badge>
