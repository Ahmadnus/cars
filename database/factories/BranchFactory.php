<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->value('id') ?? Organization::factory(),
            'name' => 'فرع '.fake()->unique()->city(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'status' => 'active',
            'working_hours' => collect(range(0, 6))->map(fn (int $day) => [
                'day' => $day,
                'open' => '08:00',
                'close' => '19:00',
                'closed' => $day === 5, // Friday
            ])->all(),
        ];
    }
}
