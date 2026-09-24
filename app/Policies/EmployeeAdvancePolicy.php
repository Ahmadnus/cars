<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EmployeeAdvancePolicy extends BasePolicy
{
    protected string $prefix = 'advances';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('advances.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('advances.manage') && $this->sameBranch($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('advances.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('advances.manage')
            && $this->sameBranch($user, $model)
            && ! $model->isSettled();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
