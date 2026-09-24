<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Employees use a single "manage" permission rather than separate CRUD verbs,
 * so every write maps onto it.
 */
class EmployeePolicy extends BasePolicy
{
    protected string $prefix = 'employees';

    public function create(User $user): bool
    {
        return $user->hasPermission('employees.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('employees.manage') && $this->sameBranch($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('employees.manage') && $this->sameBranch($user, $model);
    }
}
