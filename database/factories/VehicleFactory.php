<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::query()->value('id') ?? Branch::factory(),
            'name' => 'مركبة '.fake()->unique()->numerify('##'),
            'plate_number' => fake()->unique()->numerify('##-#####'),
            'model' => 'Hyundai i10',
            'year' => 2021,
            'transmission' => 'manual',
            'status' => 'available',
        ];
    }

    public function inMaintenance(): static
    {
        return $this->state(fn () => ['status' => 'maintenance']);
    }
}
