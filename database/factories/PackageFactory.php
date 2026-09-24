<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Package> */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            // Null branch = available across the whole organization.
            'branch_id' => null,
            'name' => 'باقة '.fake()->unique()->numerify('###'),
            'license_type' => 'private',
            'lessons_count' => 10,
            'lesson_duration_minutes' => 45,
            'price' => 150,
            'extra_lesson_price' => 18,
            'max_discount_percent' => 10,
            'validity_days' => 180,
            'status' => 'active',
        ];
    }
}
