<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    protected Branch $branch;

    /**
     * Seed the structural data every test needs: permissions, roles, lookup
     * tables, the organization and its branches. Demo records are not seeded —
     * each test builds exactly the data it is about.
     */
    protected function seedFoundation(): void
    {
        $this->seed([
            PermissionSeeder::class,
            ReferenceDataSeeder::class,
            OrganizationSeeder::class,
        ]);

        $this->branch = Branch::where('code', 'AMM')->firstOrFail();
    }

    /** A user holding exactly the named role, scoped to the main branch. */
    protected function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ], $attributes));

        $user->roles()->sync([Role::where('name', $role)->firstOrFail()->id]);
        $user->branches()->sync([$this->branch->id]);
        $user->forgetPermissionCache();

        app(BranchContext::class)->forget();

        return $user->fresh();
    }

    /** A super admin, for tests that are not about authorization. */
    protected function admin(): User
    {
        return $this->userWithRole('system_admin', [
            'is_super_admin' => true,
            'can_access_all_branches' => true,
        ]);
    }

    /** Reset the memoised branch context between acting-as switches. */
    protected function actingAsUser(User $user): static
    {
        app(BranchContext::class)->forget();

        return $this->actingAs($user);
    }

    /**
     * A date both the seeded branch and the factory trainers are open on.
     *
     * The branch closes on Friday and factory trainers work Sunday-Thursday, so
     * a naive "tomorrow" fails roughly two days in seven -- which made these
     * tests pass or fail depending on the day they happened to run.
     */
    protected function workingDay(int $direction = 1, int $minimumOffset = 1): \Illuminate\Support\Carbon
    {
        $date = now()->addDays($direction * $minimumOffset);

        while (! in_array((int) $date->dayOfWeek, [0, 1, 2, 3, 4], true)) {
            $date = $date->addDays($direction);
        }

        return $date;
    }

    /** A past date both the branch and the trainer are open on. */
    protected function pastWorkingDay(int $minimumOffset = 2): \Illuminate\Support\Carbon
    {
        return $this->workingDay(-1, $minimumOffset);
    }
}
