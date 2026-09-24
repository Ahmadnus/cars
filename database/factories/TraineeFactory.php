<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Trainee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Trainee> */
class TraineeFactory extends Factory
{
    protected $model = Trainee::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::query()->value('id') ?? Branch::factory(),
            'trainee_number' => 'MT-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => '077'.fake()->unique()->numerify('#######'),
            'national_id' => fake()->unique()->numerify('##########'),
            'birth_date' => now()->subYears(22)->toDateString(),
            'license_type' => 'private',
            'registration_date' => now()->subMonth()->toDateString(),
            'status' => 'in_training',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'completed']);
    }
}
