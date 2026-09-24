<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\Organization;
use App\Services\SettingsRepository;
use Illuminate\Database\Seeder;

/**
 * The organization, its branches and their cashboxes.
 *
 * Two branches are created so multi-branch scoping is exercised from the first
 * run rather than only appearing once a second branch is added in production.
 */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::updateOrCreate(
            ['name' => 'مركز الطريق الآمن لتعليم القيادة'],
            [
                'legal_name' => 'شركة الطريق الآمن لتعليم قيادة المركبات',
                'phone' => '+962 6 555 1234',
                'email' => 'info@safe-road.example',
                'address' => 'عمّان — شارع المدينة المنورة',
                'currency' => 'JOD',
                'timezone' => 'Asia/Amman',
            ],
        );

        // Sunday–Thursday working week, Friday closed, short Saturday.
        $workingHours = [
            ['day' => 0, 'open' => '08:00', 'close' => '19:00', 'closed' => false], // Sunday
            ['day' => 1, 'open' => '08:00', 'close' => '19:00', 'closed' => false],
            ['day' => 2, 'open' => '08:00', 'close' => '19:00', 'closed' => false],
            ['day' => 3, 'open' => '08:00', 'close' => '19:00', 'closed' => false],
            ['day' => 4, 'open' => '08:00', 'close' => '19:00', 'closed' => false], // Thursday
            ['day' => 5, 'open' => '00:00', 'close' => '00:00', 'closed' => true],  // Friday
            ['day' => 6, 'open' => '09:00', 'close' => '15:00', 'closed' => false], // Saturday
        ];

        $branches = [
            ['name' => 'الفرع الرئيسي — عمّان', 'code' => 'AMM', 'phone' => '+962 6 555 1234', 'address' => 'عمّان — شارع المدينة المنورة'],
            ['name' => 'فرع الزرقاء', 'code' => 'ZRQ', 'phone' => '+962 5 555 7788', 'address' => 'الزرقاء — شارع السعادة'],
        ];

        foreach ($branches as $data) {
            $branch = Branch::updateOrCreate(
                ['code' => $data['code']],
                array_merge($data, [
                    'organization_id' => $organization->id,
                    'email' => strtolower($data['code']).'@safe-road.example',
                    'working_hours' => $workingHours,
                    'status' => 'active',
                ]),
            );

            Cashbox::firstOrCreate(
                ['branch_id' => $branch->id, 'name' => 'الصندوق الرئيسي'],
                ['opening_balance' => 0, 'current_balance' => 0, 'status' => 'active'],
            );
        }

        app(SettingsRepository::class)->setMany([
            'center.name' => $organization->name,
            'center.phone' => $organization->phone,
            'center.address' => $organization->address,
            'center.email' => $organization->email,
            'center.currency' => 'JOD',
            'center.currency_label' => 'د.أ',
        ]);
    }
}
