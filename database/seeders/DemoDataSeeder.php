<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\TrainingSession;
use App\Models\TrainingSkill;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AppointmentService;
use App\Services\ExpenseService;
use App\Services\NumberGenerator;
use App\Services\PackageService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\TrainerCompensationService;
use App\Services\TrainingSessionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Realistic demo data for the main branch.
 *
 * Everything is created through the same services the dashboard uses, not by
 * inserting rows directly. That is deliberate: the seeded database therefore
 * satisfies every invariant the application enforces — lesson balances match
 * their ledgers, the cashbox matches its transactions, and no seeded booking
 * conflicts with another.
 */
class DemoDataSeeder extends Seeder
{
    protected Branch $branch;

    /**
     * Target status per demo trainee, applied at the end of the seed.
     *
     * A spread of statuses so every dashboard tile and list filter has data.
     */
    protected const TRAINEE_STATUSES = [
        'in_training', 'in_training', 'in_training', 'in_training', 'in_training',
        'in_training', 'in_training', 'ready_for_exam', 'ready_for_exam', 'exam_scheduled',
        'new', 'new', 'in_training', 'in_training', 'completed',
        'passed', 'suspended', 'in_training', 'in_training', 'in_training',
    ];

    public function run(): void
    {
        $this->branch = Branch::where('code', 'AMM')->firstOrFail();

        // Services stamp created_by from the authenticated user; acting as the
        // manager keeps the audit trail coherent.
        Auth::login(User::where('email', 'manager@example.com')->firstOrFail());

        $this->openingFloat();
        $packages = $this->packages();
        $trainers = $this->trainers();
        $vehicles = $this->vehicles($trainers);
        $this->employees();
        $trainees = $this->trainees($trainers, $packages);
        $this->traineeLogin($trainees[0]);

        $this->sessions($trainees, $trainers, $vehicles);
        $this->payments($trainees);
        $this->expenses();
        $this->payrollAndCompensation($trainers);
        $this->finaliseTraineeStatuses($trainees);

        Auth::logout();
    }

    // ------------------------------------------------------------------

    /**
     * Give one demo trainee an app login.
     *
     * The phone is the one listed in config/otp.php, so the Trainee app can be
     * signed into with a fixed passcode. Trainees have no usable password —
     * the column is filled with an unguessable value the passcode flow never
     * consults, because the password route rejects them outright.
     */
    protected function traineeLogin(Trainee $trainee): void
    {
        $role = \App\Models\Role::where('name', 'trainee')->first();

        $user = User::updateOrCreate(
            ['email' => 'trainee@example.com'],
            [
                'name' => $trainee->full_name,
                'phone' => '0790000007',
                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(40)),
                'branch_id' => $trainee->branch_id,
                'is_super_admin' => false,
                'can_access_all_branches' => false,
                'status' => 'active',
                'locale' => 'ar',
            ],
        );

        if ($role) {
            $user->roles()->sync([$role->id]);
        }

        $user->branches()->syncWithoutDetaching([$trainee->branch_id]);
        $trainee->update(['user_id' => $user->id]);
    }

    /**
     * Seed the cashbox with an opening float.
     *
     * Posted as a normal ledger entry rather than written onto the balance, so
     * the demo cashbox reconciles the same way a real one does.
     */
    protected function openingFloat(): void
    {
        $service = app(\App\Services\CashboxService::class);
        $cashbox = $service->forBranch($this->branch);

        if ($cashbox->transactions()->where('category', 'opening')->exists()) {
            return;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($service, $cashbox) {
            $service->deposit(
                cashbox: $cashbox,
                amount: 3000,
                category: 'opening',
                description: 'رصيد افتتاحي للصندوق',
                date: now()->subMonths(3)->startOfMonth(),
            );
        });
    }

    protected function packages(): array
    {
        $definitions = [
            ['اشتراك أساسي — 10 حصص', 10, 45, 150, 18, 10, 180],
            ['اشتراك متوسط — 15 حصة', 15, 45, 210, 16, 10, 240],
            ['اشتراك مكثّف — 20 حصة', 20, 60, 300, 15, 15, 300],
            ['اشتراك تجديد — 5 حصص', 5, 45, 85, 20, 5, 90],
        ];

        $packages = [];

        foreach ($definitions as [$name, $lessons, $duration, $price, $extra, $discount, $validity]) {
            $packages[] = Package::updateOrCreate(
                ['name' => $name, 'branch_id' => null],
                [
                    'license_type' => 'private',
                    'lessons_count' => $lessons,
                    'lesson_duration_minutes' => $duration,
                    'price' => $price,
                    'extra_lesson_price' => $extra,
                    'max_discount_percent' => $discount,
                    'validity_days' => $validity,
                    'description' => "باقة تدريب تشمل {$lessons} حصة بمدة {$duration} دقيقة للحصة.",
                    'status' => 'active',
                ],
            );
        }

        return $packages;
    }

    /** @return array<int, Trainer> */
    protected function trainers(): array
    {
        $numbers = app(NumberGenerator::class);
        $compensation = app(TrainerCompensationService::class);

        $definitions = [
            // name, phone, working days, start, end, model, salary, per-lesson, percent
            ['عمر الزعبي', '0791234501', [0, 1, 2, 3, 4], '08:00', '16:00', 'salary_plus_per_lesson', 250, 4, 0],
            ['أحمد الخطيب', '0791234502', [0, 1, 2, 3, 4], '09:00', '17:00', 'per_lesson', 0, 7, 0],
            ['محمود السعدي', '0791234503', [0, 1, 2, 3, 4, 6], '08:00', '18:00', 'revenue_percentage', 0, 0, 45],
            ['يوسف الرواشدة', '0791234504', [1, 2, 3, 4, 6], '10:00', '18:00', 'monthly_salary', 520, 0, 0],
        ];

        $trainers = [];

        foreach ($definitions as $i => [$name, $phone, $days, $start, $end, $model, $salary, $rate, $percent]) {
            $trainer = Trainer::updateOrCreate(
                ['phone' => $phone],
                [
                    'branch_id' => $this->branch->id,
                    'trainer_number' => $numbers->trainerNumber(),
                    'full_name' => $name,
                    'national_id' => '99000'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                    'birth_date' => now()->subYears(34 + $i)->toDateString(),
                    'employment_date' => now()->subYears(3)->subMonths($i * 5)->toDateString(),
                    'license_types' => ['private'],
                    'working_days' => $days,
                    'work_start_time' => $start,
                    'work_end_time' => $end,
                    'status' => 'active',
                ],
            );

            // Link the demo trainer login to the first trainer record.
            if ($i === 0) {
                $trainerUser = User::where('email', 'trainer@example.com')->first();

                if ($trainerUser) {
                    $trainer->update(['user_id' => $trainerUser->id]);
                }
            }

            if ($trainer->compensationRules()->doesntExist()) {
                $compensation->setRule($trainer, [
                    'model' => $model,
                    'base_salary' => $salary,
                    'per_lesson_rate' => $rate,
                    'revenue_percentage' => $percent,
                    'effective_from' => now()->subYear()->startOfYear()->toDateString(),
                ]);
            }

            $trainers[] = $trainer;
        }

        return $trainers;
    }

    /** @return array<int, Vehicle> */
    protected function vehicles(array $trainers): array
    {
        $definitions = [
            ['هيونداي i10', '21-45678', 'Hyundai i10', 2021, 'manual'],
            ['كيا بيكانتو', '22-11223', 'Kia Picanto', 2022, 'manual'],
            ['تويوتا يارس', '20-77889', 'Toyota Yaris', 2020, 'automatic'],
            ['نيسان صني', '19-33445', 'Nissan Sunny', 2019, 'manual'],
        ];

        $vehicles = [];

        foreach ($definitions as $i => [$name, $plate, $model, $year, $transmission]) {
            $vehicles[] = Vehicle::updateOrCreate(
                ['plate_number' => $plate],
                [
                    'branch_id' => $this->branch->id,
                    'assigned_trainer_id' => $trainers[$i % count($trainers)]->id,
                    'name' => $name,
                    'model' => $model,
                    'year' => $year,
                    'transmission' => $transmission,
                    'license_type' => 'private',
                    'status' => $i === 3 ? 'maintenance' : 'available',
                    'insurance_number' => 'INS-'.(10000 + $i),
                    'insurance_expires_on' => now()->addMonths(2 + $i)->toDateString(),
                    'registration_number' => 'REG-'.(20000 + $i),
                    'registration_expires_on' => now()->addMonths(5 + $i)->toDateString(),
                    'odometer_km' => 60000 + ($i * 14000),
                ],
            );
        }

        return $vehicles;
    }

    protected function employees(): void
    {
        $numbers = app(NumberGenerator::class);

        $definitions = [
            ['خالد المومني', '0796000001', 'manager', 900, 120, 'manager@example.com'],
            ['ليلى العبادي', '0796000002', 'accountant', 650, 80, 'accountant@example.com'],
            ['رنا الشوابكة', '0796000003', 'receptionist', 420, 50, 'reception@example.com'],
            ['سامي الحديد', '0796000004', 'supervisor', 580, 70, 'supervisor@example.com'],
            ['هبة القيسي', '0796000005', 'administrative', 400, 40, null],
        ];

        foreach ($definitions as $i => [$name, $phone, $position, $salary, $allowances, $email]) {
            Employee::updateOrCreate(
                ['phone' => $phone],
                [
                    'branch_id' => $this->branch->id,
                    'user_id' => $email ? User::where('email', $email)->value('id') : null,
                    'employee_number' => $numbers->employeeNumber(),
                    'full_name' => $name,
                    'national_id' => '88000'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                    'position' => $position,
                    'employment_date' => now()->subYears(2)->subMonths($i * 3)->toDateString(),
                    'base_salary' => $salary,
                    'allowances' => $allowances,
                    'working_days' => [0, 1, 2, 3, 4],
                    'work_start_time' => '08:00',
                    'work_end_time' => '16:00',
                    'status' => 'active',
                ],
            );
        }
    }

    /** @return array<int, Trainee> */
    protected function trainees(array $trainers, array $packages): array
    {
        $numbers = app(NumberGenerator::class);
        $packageService = app(PackageService::class);

        $names = [
            'سارة عبدالله الناصر', 'محمد فايز العمري', 'دانا وليد الحوراني', 'عبدالرحمن سامي الدباس',
            'لينا أحمد الطراونة', 'زيد ماهر الشريف', 'رهف نبيل القضاة', 'كرم عصام البشير',
            'نور سليم المجالي', 'أنس طارق الفاعوري', 'جنى رامي السقا', 'حمزة عادل الزبيدي',
            'ملك هاني الحسن', 'طارق وائل العجلوني', 'ريم خالد الشوبكي', 'فارس نضال العتوم',
            'شهد ياسر الخالدي', 'إياد منذر الصمادي', 'تالا فراس الرفاعي', 'بشار جميل الغزاوي',
        ];

        $statuses = self::TRAINEE_STATUSES;
        $trainees = [];

        foreach ($names as $i => $name) {
            $trainee = Trainee::updateOrCreate(
                ['phone' => '07'.(70000000 + $i * 111)],
                [
                    'branch_id' => $this->branch->id,
                    'trainer_id' => $trainers[$i % count($trainers)]->id,
                    'trainee_number' => $numbers->traineeNumber(),
                    'full_name' => $name,
                    'national_id' => '20000'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                    'birth_date' => now()->subYears(19 + ($i % 14))->toDateString(),
                    'gender' => $i % 2 === 0 ? 'female' : 'male',
                    'address' => 'عمّان — حي '.['الرابية', 'الجبيهة', 'خلدا', 'الصويفية', 'طبربور'][$i % 5],
                    'license_type' => 'private',
                    'registration_date' => now()->subDays(150 - ($i * 7))->toDateString(),
                    // Start every trainee open so the package can be assigned;
                    // the final status is applied once the history exists.
                    'status' => 'in_training',
                    'exam_date' => $statuses[$i] === 'exam_scheduled' ? now()->addDays(12)->toDateString() : null,
                ],
            );

            if ($trainee->packages()->doesntExist() && $statuses[$i] !== 'new') {
                $package = $packages[$i % count($packages)];

                // Give a couple of them a discount so the discount audit path
                // has real data behind it — stay inside the package's own cap,
                // which PackageService enforces.
                $discount = $i % 7 === 0
                    ? round((float) $package->price * min(8, (float) $package->max_discount_percent) / 100, 2)
                    : 0;

                $packageService->assign($trainee, $package, [
                    'started_on' => $trainee->registration_date->toDateString(),
                    'discount_amount' => $discount,
                    'discount_reason' => $discount > 0 ? 'خصم تسجيل مبكر' : null,
                ]);
            }

            $trainees[] = $trainee;
        }

        return $trainees;
    }

    /**
     * Apply the final statuses once lessons and payments exist.
     *
     * Done last because a closed trainee cannot be booked or enrolled, which is
     * exactly the rule the services enforce.
     */
    protected function finaliseTraineeStatuses(array $trainees): void
    {
        foreach ($trainees as $i => $trainee) {
            $target = self::TRAINEE_STATUSES[$i] ?? null;

            if ($target && $trainee->fresh()->status !== $target) {
                $trainee->update(['status' => $target]);
            }
        }
    }

    /**
     * Book and deliver lessons.
     *
     * Slots are taken from AppointmentService's own availability calculation,
     * so the seeded schedule is guaranteed conflict-free.
     */
    protected function sessions(array $trainees, array $trainers, array $vehicles): void
    {
        if (TrainingSession::count() > 0) {
            return; // already seeded
        }

        $appointments = app(AppointmentService::class);
        $sessionService = app(TrainingSessionService::class);
        $skills = TrainingSkill::active()->get();
        $ratings = ['needs_training', 'average', 'good', 'very_good', 'excellent'];

        $active = collect($trainees)->filter(fn (Trainee $t) => $t->activePackage() !== null)->values();

        // Four weeks behind us, two ahead.
        for ($dayOffset = -28; $dayOffset <= 14; $dayOffset++) {
            $date = now()->addDays($dayOffset)->startOfDay();

            if ($date->dayOfWeek === Carbon::FRIDAY) {
                continue;
            }

            $bookingsToday = $dayOffset < 0 ? 5 : 3;

            for ($n = 0; $n < $bookingsToday; $n++) {
                $trainee = $active[($dayOffset + 30 + $n * 3) % $active->count()];
                $trainer = $trainers[($dayOffset + 30 + $n) % count($trainers)];
                $vehicle = $vehicles[($n + $dayOffset + 30) % 3]; // skip the one in maintenance

                $slots = $appointments->availableSlots($trainer, $date, 45, $vehicle->id);

                if (empty($slots)) {
                    continue;
                }

                $slot = $slots[min($n * 2, count($slots) - 1)];

                try {
                    $session = $appointments->schedule([
                        'trainee_id' => $trainee->id,
                        'trainer_id' => $trainer->id,
                        'vehicle_id' => $vehicle->id,
                        'scheduled_date' => $date->toDateString(),
                        'start_time' => $slot['start'],
                        'duration_minutes' => 45,
                    ], $this->branch->id);
                } catch (\Throwable) {
                    // Balance exhausted or a clash we could not foresee — skip
                    // this one rather than abort the whole seed.
                    continue;
                }

                if ($dayOffset >= 0) {
                    continue; // future lessons stay scheduled
                }

                // Past lessons: mostly completed, with a few absences.
                if (($dayOffset + $n) % 11 === 0) {
                    $sessionService->markNoShow($session, 'لم يحضر المتدرب');

                    continue;
                }

                $sessionService->complete($session, [
                    'overall_rating' => $ratings[abs($dayOffset + $n) % count($ratings)],
                    'strengths' => 'تحسّن ملحوظ في التحكم بالمركبة.',
                    'weaknesses' => 'يحتاج مزيداً من التدريب على الركن الجانبي.',
                    'trainer_notes' => 'أداء جيد خلال الحصة.',
                    'next_requirements' => 'التدريب على الدوارات والتقاطعات.',
                    'skills' => $skills->take(5)->map(fn ($skill, $k) => [
                        'skill_id' => $skill->id,
                        'rating' => $ratings[($k + abs($dayOffset)) % count($ratings)],
                    ])->all(),
                ]);
            }
        }
    }

    /** Collect payments against the seeded enrolments. */
    protected function payments(array $trainees): void
    {
        $service = app(PaymentService::class);
        $cash = PaymentMethod::where('code', 'cash')->firstOrFail();
        $cliq = PaymentMethod::where('code', 'cliq')->firstOrFail();

        foreach ($trainees as $i => $trainee) {
            $package = $trainee->activePackage() ?? $trainee->packages()->first();

            if (! $package || $package->payments()->exists()) {
                continue;
            }

            $total = (float) $package->total_amount;

            // A mix of paid-in-full, part-paid and unpaid, so the debts report
            // and the dashboard's pending tile both have something to show.
            $portion = match ($i % 4) {
                0 => $total,
                1 => round($total * 0.6, 2),
                2 => round($total * 0.35, 2),
                default => 0.0,
            };

            if ($portion <= 0) {
                continue;
            }

            $method = $i % 3 === 0 ? $cliq : $cash;

            $service->record([
                'trainee_id' => $trainee->id,
                'trainee_package_id' => $package->id,
                'payment_method_id' => $method->id,
                'source' => 'package',
                'amount' => $portion,
                'paid_on' => $package->started_on->copy()->addDays(2)->toDateString(),
                'reference_number' => $method->requires_reference ? 'CLQ'.(100000 + $i) : null,
            ], $this->branch->id);
        }
    }

    protected function expenses(): void
    {
        $service = app(ExpenseService::class);
        $cash = PaymentMethod::where('code', 'cash')->firstOrFail();
        $transfer = PaymentMethod::where('code', 'bank_transfer')->firstOrFail();

        $definitions = [
            ['rent', 'إيجار المقر', 450, $transfer],
            ['electricity', 'فاتورة كهرباء', 96, $cash],
            ['water', 'فاتورة مياه', 28, $cash],
            ['internet', 'اشتراك إنترنت', 35, $cash],
            ['fuel', 'وقود المركبات', 210, $cash],
            ['vehicle_maintenance', 'صيانة دورية للمركبات', 165, $cash],
            ['marketing', 'حملة تسويقية', 120, $transfer],
            ['facebook_ads', 'إعلانات فيسبوك', 75, $transfer],
            ['office_supplies', 'قرطاسية ولوازم', 42, $cash],
            ['cleaning', 'خدمات نظافة', 60, $cash],
        ];

        // Three months of history so the P&L chart has a trend to draw.
        for ($monthsAgo = 2; $monthsAgo >= 0; $monthsAgo--) {
            $month = now()->subMonths($monthsAgo);

            foreach ($definitions as $i => [$code, $title, $amount, $method]) {
                $category = ExpenseCategory::where('code', $code)->first();

                if (! $category) {
                    continue;
                }

                $spentOn = $month->copy()->startOfMonth()->addDays(2 + $i)->min(now());

                $exists = \App\Models\Expense::where('branch_id', $this->branch->id)
                    ->where('title', $title)
                    ->whereDate('spent_on', $spentOn->toDateString())
                    ->exists();

                if ($exists) {
                    continue;
                }

                $service->record([
                    'expense_category_id' => $category->id,
                    'payment_method_id' => $method->id,
                    'title' => $title,
                    // Small variation month to month so the chart is not flat.
                    'amount' => round($amount * (1 + ($monthsAgo * 0.04)), 2),
                    'spent_on' => $spentOn->toDateString(),
                    'beneficiary' => null,
                ], $this->branch->id);
            }
        }
    }

    /** Last month's payroll and trainer statements, so both screens have data. */
    protected function payrollAndCompensation(array $trainers): void
    {
        $period = now()->subMonth()->format('Y-m');

        app(PayrollService::class)->generateForBranch($this->branch->id, $period);
        app(TrainerCompensationService::class)->calculateForBranch($this->branch->id, $period);
    }
}
