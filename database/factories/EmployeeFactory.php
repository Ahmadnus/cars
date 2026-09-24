<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::query()->value('id') ?? Branch::factory(),
            'employee_number' => 'MW-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => '078'.fake()->unique()->numerify('#######'),
            'national_id' => fake()->unique()->numerify('##########'),
            'position' => 'administrative',
            'employment_date' => now()->subYear()->toDateString(),
            'base_salary' => 500,
            'allowances' => 50,
            'status' => 'active',
        ];
    }
}
