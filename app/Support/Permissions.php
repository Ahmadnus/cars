<?php

namespace App\Support;

/**
 * The catalogue of every permission in the system.
 *
 * This is the single source of truth: the seeder builds the permissions table
 * from it, the roles UI renders from it, and middleware/policies reference the
 * constants. Adding a permission here and re-running the sync command is the
 * only supported way to introduce one.
 */
class Permissions
{
    /**
     * group => [label, permissions[name => [label, sensitive]]]
     *
     * "Sensitive" marks anything exposing money, salaries or personal
     * documents. Sensitive permissions are never granted to a role by default
     * and are flagged in the roles UI.
     */
    public const CATALOG = [
        'portal' => [
            'label' => 'بوابة المتدرب',
            'permissions' => [
                'portal.view' => ['الدخول إلى بوابة المتدرب', false],
            ],
        ],
        'registrations' => [
            'label' => 'طلبات الانتساب',
            'permissions' => [
                'registrations.view' => ['عرض طلبات الانتساب', false],
                'registrations.manage' => ['قبول ورفض طلبات الانتساب', false],
            ],
        ],
        'chat' => [
            'label' => 'المحادثات',
            'permissions' => [
                // Staff oversight of trainer-trainee threads. The participants
                // themselves need no permission: the conversation is theirs.
                'chat.monitor' => ['الاطلاع على محادثات المدربين والمتدربين', true],
                'chat.moderate' => ['إيقاف المحادثات', false],
            ],
        ],
        'dashboard' => [
            'label' => 'لوحة التحكم',
            'permissions' => [
                'dashboard.view' => ['عرض لوحة التحكم', false],
                'dashboard.financials' => ['عرض المؤشرات المالية في اللوحة', true],
            ],
        ],
        'trainees' => [
            'label' => 'المتدربون',
            'permissions' => [
                'trainees.view' => ['عرض المتدربين', false],
                'trainees.create' => ['إضافة متدرب', false],
                'trainees.update' => ['تعديل متدرب', false],
                'trainees.delete' => ['أرشفة متدرب', false],
                'trainees.documents' => ['إدارة مستندات المتدربين', true],
                'trainees.financial' => ['عرض الوضع المالي للمتدرب', true],
            ],
        ],
        'trainers' => [
            'label' => 'المدربون',
            'permissions' => [
                'trainers.view' => ['عرض المدربين', false],
                'trainers.create' => ['إضافة مدرب', false],
                'trainers.update' => ['تعديل مدرب', false],
                'trainers.delete' => ['أرشفة مدرب', false],
            ],
        ],
        'appointments' => [
            'label' => 'المواعيد والحصص',
            'permissions' => [
                'appointments.view' => ['عرض المواعيد', false],
                'appointments.create' => ['حجز موعد', false],
                'appointments.update' => ['تعديل موعد', false],
                'appointments.cancel' => ['إلغاء موعد', false],
                'appointments.complete' => ['إنهاء حصة وتقييمها', false],
                'booking_requests.manage' => ['إدارة طلبات الحجز', false],
            ],
        ],
        'packages' => [
            'label' => 'الباقات',
            'permissions' => [
                'packages.view' => ['عرض الباقات', false],
                'packages.manage' => ['إدارة الباقات والأسعار', true],
                'packages.assign' => ['إسناد باقة لمتدرب', false],
                'packages.discount' => ['منح خصم', true],
            ],
        ],
        'evaluations' => [
            'label' => 'التقييمات والمهارات',
            'permissions' => [
                'evaluations.view' => ['عرض التقييمات', false],
                'evaluations.manage' => ['إدارة التقييمات', false],
                'skills.manage' => ['إدارة مهارات التدريب', false],
            ],
        ],
        'payments' => [
            'label' => 'المدفوعات والإيرادات',
            'permissions' => [
                'payments.view' => ['عرض المدفوعات', true],
                'payments.create' => ['تسجيل دفعة', true],
                'payments.update' => ['تعديل دفعة', true],
                'payments.void' => ['إلغاء دفعة', true],
            ],
        ],
        'expenses' => [
            'label' => 'المصاريف',
            'permissions' => [
                'expenses.view' => ['عرض المصاريف', true],
                'expenses.create' => ['تسجيل مصروف', true],
                'expenses.update' => ['تعديل مصروف', true],
                'expenses.delete' => ['إلغاء مصروف', true],
                'recurring_expenses.manage' => ['إدارة المصاريف المتكررة', true],
                'utilities.manage' => ['إدارة فواتير الخدمات', true],
            ],
        ],
        'payroll' => [
            'label' => 'الرواتب',
            'permissions' => [
                'payroll.view' => ['عرض كشوف الرواتب', true],
                'payroll.create' => ['إنشاء كشف رواتب', true],
                'payroll.pay' => ['صرف الرواتب', true],
                'salaries.view' => ['عرض رواتب الموظفين', true],
                'advances.manage' => ['إدارة السلف', true],
            ],
        ],
        'trainer_compensation' => [
            'label' => 'أجور المدربين',
            'permissions' => [
                'trainer_compensation.view' => ['عرض أجور المدربين', true],
                'trainer_compensation.manage' => ['إدارة قواعد الأجور', true],
                'trainer_compensation.pay' => ['صرف أجور المدربين', true],
            ],
        ],
        'cashbox' => [
            'label' => 'الصندوق',
            'permissions' => [
                'cashbox.view' => ['عرض الصندوق', true],
                'cashbox.manage' => ['إدارة الصندوق والإقفال اليومي', true],
            ],
        ],
        'reports' => [
            'label' => 'التقارير',
            'permissions' => [
                'reports.view' => ['عرض التقارير', false],
                'reports.financial' => ['عرض التقارير المالية', true],
                'reports.export' => ['تصدير التقارير', false],
                'profit.view' => ['عرض الأرباح والخسائر', true],
            ],
        ],
        'employees' => [
            'label' => 'الموظفون',
            'permissions' => [
                'employees.view' => ['عرض الموظفين', false],
                'employees.manage' => ['إدارة الموظفين', true],
            ],
        ],
        'vehicles' => [
            'label' => 'المركبات',
            'permissions' => [
                'vehicles.view' => ['عرض المركبات', false],
                'vehicles.manage' => ['إدارة المركبات والصيانة', false],
            ],
        ],
        'administration' => [
            'label' => 'الإدارة والنظام',
            'permissions' => [
                'users.manage' => ['إدارة المستخدمين', true],
                'roles.manage' => ['إدارة الأدوار والصلاحيات', true],
                'branches.manage' => ['إدارة الفروع', true],
                'settings.manage' => ['إدارة الإعدادات', true],
                'audit_logs.view' => ['عرض سجل التدقيق', true],
            ],
        ],
    ];

    /** Every permission name, flattened. @return array<int, string> */
    public static function all(): array
    {
        $names = [];

        foreach (self::CATALOG as $group) {
            foreach (array_keys($group['permissions']) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return array<int, array{name: string, group: string, label_ar: string, is_sensitive: bool}> */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::CATALOG as $groupKey => $group) {
            foreach ($group['permissions'] as $name => [$label, $sensitive]) {
                $rows[] = [
                    'name' => $name,
                    'group' => $groupKey,
                    'label_ar' => $label,
                    'is_sensitive' => $sensitive,
                ];
            }
        }

        return $rows;
    }

    /**
     * Every permission the catalogue flags as sensitive — money, salaries,
     * personal documents, private conversations.
     *
     * Exists so a caller can ask "is this account privileged?" without
     * hard-coding a list that would drift from the catalogue above.
     *
     * @return array<int, string>
     */
    public static function sensitive(): array
    {
        $names = [];

        foreach (self::CATALOG as $group) {
            foreach ($group['permissions'] as $name => [$label, $isSensitive]) {
                if ($isSensitive) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    public static function groupLabel(string $group): string
    {
        return self::CATALOG[$group]['label'] ?? $group;
    }

    /**
     * Default permission sets per system role.
     *
     * Note what the receptionist does NOT get: no profit, no salaries, no
     * cashbox, no trainer compensation. That separation is a product
     * requirement, not a UI preference.
     */
    public const ROLE_DEFAULTS = [
        'system_admin' => ['*'],

        'center_manager' => [
            'dashboard.view', 'dashboard.financials',
            'trainees.view', 'trainees.create', 'trainees.update', 'trainees.delete',
            'trainees.documents', 'trainees.financial',
            'registrations.view', 'registrations.manage',
            'chat.monitor', 'chat.moderate',
            'trainers.view', 'trainers.create', 'trainers.update', 'trainers.delete',
            'appointments.view', 'appointments.create', 'appointments.update',
            'appointments.cancel', 'appointments.complete', 'booking_requests.manage',
            'packages.view', 'packages.manage', 'packages.assign', 'packages.discount',
            'evaluations.view', 'evaluations.manage', 'skills.manage',
            'payments.view', 'payments.create', 'payments.void',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
            'recurring_expenses.manage', 'utilities.manage',
            'payroll.view', 'payroll.create', 'payroll.pay', 'salaries.view', 'advances.manage',
            'trainer_compensation.view', 'trainer_compensation.manage', 'trainer_compensation.pay',
            'cashbox.view', 'cashbox.manage',
            'reports.view', 'reports.financial', 'reports.export', 'profit.view',
            'employees.view', 'employees.manage',
            'vehicles.view', 'vehicles.manage',
            'users.manage', 'branches.manage', 'settings.manage', 'audit_logs.view',
        ],

        'receptionist' => [
            'dashboard.view',
            'trainees.view', 'trainees.create', 'trainees.update', 'trainees.documents',
            'registrations.view', 'registrations.manage',
            'trainers.view',
            'appointments.view', 'appointments.create', 'appointments.update',
            'appointments.cancel', 'booking_requests.manage',
            'packages.view', 'packages.assign',
            'evaluations.view',
            'vehicles.view',
            'reports.view',
        ],

        'accountant' => [
            'dashboard.view', 'dashboard.financials',
            'trainees.view', 'trainees.financial',
            'trainers.view',
            'appointments.view',
            'packages.view',
            'payments.view', 'payments.create', 'payments.update', 'payments.void',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.delete',
            'recurring_expenses.manage', 'utilities.manage',
            'payroll.view', 'payroll.create', 'payroll.pay', 'salaries.view', 'advances.manage',
            'trainer_compensation.view', 'trainer_compensation.manage', 'trainer_compensation.pay',
            'cashbox.view', 'cashbox.manage',
            'reports.view', 'reports.financial', 'reports.export', 'profit.view',
            'employees.view',
            'vehicles.view',
        ],

        'training_supervisor' => [
            'dashboard.view',
            'trainees.view', 'trainees.create', 'trainees.update', 'trainees.documents',
            'registrations.view',
            'trainers.view', 'trainers.create', 'trainers.update',
            'appointments.view', 'appointments.create', 'appointments.update',
            'appointments.cancel', 'appointments.complete', 'booking_requests.manage',
            'packages.view', 'packages.assign',
            'evaluations.view', 'evaluations.manage', 'skills.manage',
            'trainer_compensation.view',
            'reports.view', 'reports.export',
            'vehicles.view', 'vehicles.manage',
        ],

        'trainer' => [
            'dashboard.view',
            'trainees.view',
            'appointments.view', 'appointments.complete',
            'evaluations.view', 'evaluations.manage',
        ],

        // A trainee sees only their own file, through the portal. Deliberately
        // no `trainees.view`, `appointments.view` or `payments.view`: those are
        // center-wide permissions, and the portal reads the signed-in trainee's
        // own records directly instead.
        'trainee' => [
            'portal.view',
        ],
    ];

    public const ROLE_LABELS = [
        'system_admin' => 'مدير النظام',
        'center_manager' => 'مدير المركز',
        'receptionist' => 'موظف استقبال',
        'accountant' => 'محاسب',
        'training_supervisor' => 'مشرف تدريب',
        'trainer' => 'مدرب',
        'trainee' => 'متدرب',
    ];
}
