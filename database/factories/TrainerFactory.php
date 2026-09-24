<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Trainer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Trainer> */
class TrainerFactory extends Factory
{
    protected $model = Trainer::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::query()->value('id') ?? Branch::factory(),
            'trainer_number' => 'MD-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name('male'),
            'phone' => '079'.fake()->unique()->numerify('#######'),
            'national_id' => fake()->unique()->numerify('##########'),
            'employment_date' => now()->subYears(2)->toDateString(),
            'license_types' => ['private'],
            // Sunday–Thursday, matching the seeded branch hours so the
            // availability checks in tests have a window to work with.
            'working_days' => [0, 1, 2, 3, 4],
            'work_start_time' => '08:00',
            'work_end_time' => '18:00',
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'terminated']);
    }
}
