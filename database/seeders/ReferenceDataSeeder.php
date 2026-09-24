<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\TrainingSkill;
use Illuminate\Database\Seeder;

/**
 * Lookup tables the application depends on: payment methods, expense
 * categories and the default training syllabus.
 *
 * These carry is_system flags where removing a row would break a service
 * (payroll expects a salaries category, for instance).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->paymentMethods();
        $this->expenseCategories();
        $this->trainingSkills();
    }

    protected function paymentMethods(): void
    {
        $methods = [
            // code, label, affects cashbox, requires reference
            ['cash', 'نقداً', true, false],
            ['cashbox', 'صندوق المركز', true, false],
            ['bank_transfer', 'حوالة بنكية', false, true],
            ['cliq', 'كليك (CliQ)', false, true],
            ['card', 'بطاقة', false, true],
            ['cheque', 'شيك', false, true],
            ['other', 'أخرى', false, false],
        ];

        foreach ($methods as $i => [$code, $label, $cash, $reference]) {
            PaymentMethod::updateOrCreate(['code' => $code], [
                'label_ar' => $label,
                'affects_cashbox' => $cash,
                'requires_reference' => $reference,
                'sort_order' => $i,
                'status' => 'active',
            ]);
        }
    }

    protected function expenseCategories(): void
    {
        $categories = [
            // code, label, profit bucket, system
            ['trainer_compensation', 'أجور المدربين', 'trainer_compensation', true],
            ['employee_salaries', 'رواتب الموظفين', 'salaries', true],
            ['admin_salaries', 'رواتب إدارية', 'salaries', false],
            ['rent', 'إيجار', 'rent', false],
            ['electricity', 'كهرباء', 'utilities', false],
            ['water', 'مياه', 'utilities', false],
            ['internet', 'إنترنت', 'utilities', false],
            ['telephone', 'هاتف', 'utilities', false],
            ['fuel', 'وقود', 'vehicles', false],
            ['vehicle_maintenance', 'صيانة المركبات', 'vehicles', false],
            ['vehicle_parts', 'قطع غيار', 'vehicles', false],
            ['insurance', 'تأمين', 'vehicles', false],
            ['vehicle_registration', 'ترخيص المركبات', 'vehicles', false],
            ['office_supplies', 'قرطاسية ولوازم مكتبية', 'administrative', false],
            ['cleaning', 'نظافة', 'administrative', false],
            ['hospitality', 'ضيافة', 'administrative', false],
            ['marketing', 'تسويق', 'marketing', false],
            ['facebook_ads', 'إعلانات فيسبوك', 'marketing', false],
            ['instagram_ads', 'إعلانات إنستغرام', 'marketing', false],
            ['office_maintenance', 'صيانة المكتب', 'administrative', false],
            ['software', 'اشتراكات برمجية', 'administrative', false],
            ['equipment', 'معدات', 'administrative', false],
            ['government_fees', 'رسوم حكومية', 'administrative', false],
            ['bank_fees', 'عمولات بنكية', 'administrative', false],
            ['other', 'مصاريف أخرى', 'other', true],
        ];

        foreach ($categories as $i => [$code, $label, $bucket, $system]) {
            ExpenseCategory::updateOrCreate(['code' => $code], [
                'name_ar' => $label,
                'profit_bucket' => $bucket,
                'is_system' => $system,
                'sort_order' => $i,
                'status' => 'active',
            ]);
        }
    }

    protected function trainingSkills(): void
    {
        $skills = [
            ['steering_control', 'التحكم بالمقود'],
            ['start_stop', 'الانطلاق والتوقف'],
            ['mirrors', 'استخدام المرايا'],
            ['signals', 'استخدام الإشارات'],
            ['lane_change', 'تغيير المسار'],
            ['reverse', 'الرجوع للخلف'],
            ['parking', 'ركن المركبة'],
            ['roundabouts', 'الدوارات'],
            ['intersections', 'التقاطعات'],
            ['right_of_way', 'أولوية المرور'],
            ['speed_control', 'التحكم بالسرعة'],
            ['main_roads', 'الطرق الرئيسية'],
            ['traffic_driving', 'القيادة في الازدحام'],
            ['exam_readiness', 'الجاهزية للامتحان'],
        ];

        foreach ($skills as $i => [$code, $label]) {
            TrainingSkill::updateOrCreate(['code' => $code], [
                'name_ar' => $label,
                'sort_order' => $i,
                'status' => 'active',
            ]);
        }
    }
}
