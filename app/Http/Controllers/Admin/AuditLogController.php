<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only view of the audit trail.
 *
 * There is deliberately no create, update or delete path here — the only writer
 * is AuditLogger, from inside the transactions it records.
 */
class AuditLogController extends Controller
{
    public function __construct(protected BranchContext $branchContext)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('audit_logs.view');

        $branchIds = $this->branchContext->scopeIds($request->user()) ?: [0];

        $logs = AuditLog::query()
            ->with(['user:id,name', 'branch:id,uuid,name'])
            // Entries with no branch are organization-wide (settings, roles).
            ->where(fn ($q) => $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id'))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim($request->string('search'));

                $q->where(fn ($i) => $i
                    ->where('description', 'like', "%{$term}%")
                    ->orWhere('reason', 'like', "%{$term}%")
                    ->orWhere('auditable_type', 'like', "%{$term}%"));
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'actions' => AuditLog::query()
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->mapWithKeys(fn (string $a) => [$a => self::actionLabel($a)])
                ->all(),
            'users' => User::orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        $this->authorize('audit_logs.view');

        return view('admin.audit-logs.show', [
            'log' => $auditLog->load(['user', 'branch']),
            'changes' => $auditLog->changedFields(),
        ]);
    }

    /** Human label for an action key, falling back to the key itself. */
    public static function actionLabel(string $action): string
    {
        $labels = [
            'payment.recorded' => 'تسجيل دفعة',
            'payment.voided' => 'إلغاء دفعة',
            'expense.recorded' => 'تسجيل مصروف',
            'expense.updated' => 'تعديل مصروف',
            'expense.cancelled' => 'إلغاء مصروف',
            'payroll.created' => 'إنشاء كشف راتب',
            'payroll.recalculated' => 'إعادة احتساب راتب',
            'payroll.paid' => 'صرف راتب',
            'advance.granted' => 'منح سلفة',
            'trainer_compensation.created' => 'احتساب أجر مدرب',
            'trainer_compensation.approved' => 'اعتماد أجر مدرب',
            'trainer_compensation.paid' => 'صرف أجر مدرب',
            'trainer_compensation.rule_changed' => 'تغيير قاعدة أجر',
            'cashbox.closed' => 'إقفال الصندوق',
            'cashbox.reversed' => 'عكس حركة صندوق',
            'package.assigned' => 'إسناد باقة',
            'package.price_changed' => 'تغيير سعر باقة',
            'package.discount_granted' => 'منح خصم',
            'lesson_balance.manual_credit' => 'إضافة حصص يدوياً',
            'lesson_balance.manual_debit' => 'خصم حصص يدوياً',
            'session.completed' => 'إنهاء حصة',
            'session.no_show' => 'تسجيل عدم حضور',
            'session.reopened' => 'إعادة فتح حصة',
            'appointment.created' => 'حجز موعد',
            'appointment.rescheduled' => 'تعديل موعد',
            'appointment.cancelled' => 'إلغاء موعد',
            'trainee.created' => 'إضافة متدرب',
            'trainee.updated' => 'تعديل متدرب',
            'trainee.archived' => 'أرشفة متدرب',
            'employee.salary_changed' => 'تعديل راتب موظف',
            'user.created' => 'إنشاء مستخدم',
            'user.permissions_changed' => 'تعديل صلاحيات',
            'user.deactivated' => 'تعطيل مستخدم',
            'role.permissions_changed' => 'تعديل صلاحيات دور',
            'settings.updated' => 'تعديل الإعدادات',
            'document.uploaded' => 'رفع مستند',
            'document.deleted' => 'حذف مستند',
        ];

        return $labels[$action] ?? $action;
    }
}
