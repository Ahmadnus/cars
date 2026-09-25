<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts, one per system role.
 *
 * These exist so the dashboard can be explored under every permission set.
 * They are DEVELOPMENT ONLY — the README says so, and a production deployment
 * must create its own administrator and delete these.
 */
class UserSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'password123';

    public function run(): void
    {
        $main = Branch::where('code', 'AMM')->firstOrFail();
        $second = Branch::where('code', 'ZRQ')->first();

        // Phones match config/otp.php `fixed_codes`, so every role can be
        // signed into from the apps with a passcode that never changes and
        // never touches an SMS provider.
        $accounts = [
            [
                'name' => 'مدير النظام',
                'email' => 'admin@example.com',
                'phone' => '0790000001',
                'role' => 'system_admin',
                'super' => true,
                'all_branches' => true,
            ],
            [
                'name' => 'خالد المومني',
                'email' => 'manager@example.com',
                'phone' => '0790000002',
                'role' => 'center_manager',
                'super' => false,
                'all_branches' => true,
            ],
            [
                'name' => 'ليلى العبادي',
                'email' => 'accountant@example.com',
                'phone' => '0790000003',
                'role' => 'accountant',
                'super' => false,
                'all_branches' => true,
            ],
            [
                'name' => 'رنا الشوابكة',
                'email' => 'reception@example.com',
                'phone' => '0790000004',
                'role' => 'receptionist',
                'super' => false,
                'all_branches' => false,
            ],
            [
                'name' => 'سامي الحديد',
                'email' => 'supervisor@example.com',
                'phone' => '0790000005',
                'role' => 'training_supervisor',
                'super' => false,
                'all_branches' => false,
            ],
            [
                'name' => 'عمر الزعبي',
                'email' => 'trainer@example.com',
                'phone' => '0790000006',
                'role' => 'trainer',
                'super' => false,
                'all_branches' => false,
            ],
        ];

        foreach ($accounts as $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'phone' => $account['phone'],
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'branch_id' => $main->id,
                    'is_super_admin' => $account['super'],
                    'can_access_all_branches' => $account['all_branches'],
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'locale' => 'ar',
                ],
            );

            $role = Role::where('name', $account['role'])->first();

            if ($role) {
                $user->roles()->sync([$role->id]);
            }

            // Give the branch-limited staff an explicit grant on the main
            // branch, which is what the branch selector reads.
            if (! $account['all_branches']) {
                $user->branches()->syncWithoutDetaching([$main->id]);
            }
        }

        // One user spanning both branches, to exercise the branch selector.
        if ($second) {
            User::where('email', 'manager@example.com')->first()
                ?->branches()->syncWithoutDetaching([$main->id, $second->id]);
        }
    }
}
