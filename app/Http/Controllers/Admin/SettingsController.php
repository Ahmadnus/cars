<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use App\Services\SettingsRepository;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Center configuration.
 *
 * Settings are stored per branch when a branch is selected, and organization-wide
 * otherwise — so a group can keep one policy centrally and still let a branch
 * override its own working practice.
 */
class SettingsController extends Controller
{
    public function __construct(
        protected SettingsRepository $settings,
        protected BranchContext $branchContext,
        protected AuditLogger $audit,
    ) {
    }

    public function edit(Request $request): View
    {
        $this->authorize('settings.manage');

        $branchId = $this->branchContext->currentId();

        return view('admin.settings.edit', [
            'branchId' => $branchId,
            'branchName' => $this->branchContext->current()?->name,
            'center' => $this->settings->group('center', $branchId),
            'training' => $this->settings->group('training', $branchId),
            'cancellation' => $this->settings->group('cancellation', $branchId),
            'payroll' => $this->settings->group('payroll', $branchId),
            'notifications' => $this->settings->group('notifications', $branchId),
            'paymentMethods' => PaymentMethod::orderBy('sort_order')->get(),
            'expenseCategories' => ExpenseCategory::orderBy('sort_order')->get(),
            'buckets' => ExpenseCategory::BUCKETS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $request->validate([
            'center.name' => ['required', 'string', 'max:150'],
            'center.phone' => ['nullable', 'string', 'max:30'],
            'center.address' => ['nullable', 'string', 'max:255'],
            'center.email' => ['nullable', 'email', 'max:255'],
            'center.currency_label' => ['required', 'string', 'max:10'],

            'training.default_lesson_duration' => ['required', 'integer', 'min:15', 'max:300'],
            'training.slot_step_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'training.low_balance_threshold' => ['required', 'integer', 'min:0', 'max:20'],
            'training.allow_negative_balance' => ['nullable', 'boolean'],

            'cancellation.min_notice_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'cancellation.burn_lesson_on_late_cancel' => ['nullable', 'boolean'],
            'cancellation.burn_lesson_on_no_show' => ['nullable', 'boolean'],

            'payroll.pay_day' => ['required', 'integer', 'min:1', 'max:28'],
            'payroll.require_reason_on_edit' => ['nullable', 'boolean'],

            'notifications.lesson_reminder_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'notifications.enable_in_app' => ['nullable', 'boolean'],
            'notifications.enable_email' => ['nullable', 'boolean'],
            'notifications.enable_sms' => ['nullable', 'boolean'],
            'notifications.enable_whatsapp' => ['nullable', 'boolean'],
            'notifications.enable_push' => ['nullable', 'boolean'],
        ], [], [
            'center.name' => 'اسم المركز',
            'center.currency_label' => 'رمز العملة',
            'training.default_lesson_duration' => 'مدة الحصة الافتراضية',
            'training.slot_step_minutes' => 'فاصل المواعيد',
            'training.low_balance_threshold' => 'حد التنبيه للرصيد',
            'cancellation.min_notice_hours' => 'مهلة الإلغاء',
            'payroll.pay_day' => 'يوم صرف الرواتب',
            'notifications.lesson_reminder_hours' => 'مهلة التذكير بالحصة',
        ]);

        $branchId = $this->branchContext->currentId();
        $flat = [];

        foreach ($data as $group => $values) {
            foreach ($values as $key => $value) {
                $flat["{$group}.{$key}"] = $value;
            }
        }

        $before = collect(array_keys($flat))
            ->mapWithKeys(fn (string $key) => [$key => $this->settings->get($key, null, $branchId)])
            ->all();

        $this->settings->setMany($flat, $branchId);

        $this->audit->log(
            action: 'settings.updated',
            before: $before,
            after: $flat,
            description: $branchId ? 'تعديل إعدادات الفرع' : 'تعديل الإعدادات العامة',
            branchId: $branchId,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'تم حفظ الإعدادات.']);
    }

    public function storePaymentMethod(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('payment_methods', 'code')],
            'label_ar' => ['required', 'string', 'max:100'],
            'affects_cashbox' => ['nullable', 'boolean'],
            'requires_reference' => ['nullable', 'boolean'],
        ], [], ['code' => 'المعرّف', 'label_ar' => 'الاسم']);

        $method = PaymentMethod::create(array_merge($data, [
            'sort_order' => (int) PaymentMethod::max('sort_order') + 1,
            'status' => 'active',
        ]));

        $this->audit->logCreate('payment_method.created', $method);

        return back()->with('toast', ['type' => 'success', 'message' => 'تمت إضافة طريقة الدفع.']);
    }

    public function updatePaymentMethod(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $request->validate([
            'label_ar' => ['required', 'string', 'max:100'],
            'affects_cashbox' => ['nullable', 'boolean'],
            'requires_reference' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [], ['label_ar' => 'الاسم', 'status' => 'الحالة']);

        $original = $paymentMethod->getOriginal();
        $paymentMethod->update($data);
        $this->audit->logUpdate('payment_method.updated', $paymentMethod, $original);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تحديث طريقة الدفع.']);
    }

    public function storeExpenseCategory(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('expense_categories', 'code')],
            'name_ar' => ['required', 'string', 'max:100'],
            'profit_bucket' => ['required', Rule::in(array_keys(ExpenseCategory::BUCKETS))],
        ], [], ['code' => 'المعرّف', 'name_ar' => 'الاسم', 'profit_bucket' => 'بند الأرباح']);

        $category = ExpenseCategory::create(array_merge($data, [
            'sort_order' => (int) ExpenseCategory::max('sort_order') + 1,
            'is_system' => false,
            'status' => 'active',
        ]));

        $this->audit->logCreate('expense_category.created', $category);

        return back()->with('toast', ['type' => 'success', 'message' => 'تمت إضافة تصنيف المصاريف.']);
    }

    public function updateExpenseCategory(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:100'],
            'profit_bucket' => ['required', Rule::in(array_keys(ExpenseCategory::BUCKETS))],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [], ['name_ar' => 'الاسم', 'profit_bucket' => 'بند الأرباح', 'status' => 'الحالة']);

        $original = $expenseCategory->getOriginal();
        $expenseCategory->update($data);
        $this->audit->logUpdate('expense_category.updated', $expenseCategory, $original);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تحديث التصنيف.']);
    }
}
