<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BranchPolicy extends BasePolicy
{
    protected string $prefix = 'branches';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('branches.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->canAccessBranch($model->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('branches.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('branches.manage') && $user->canAccessBranch($model->id);
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
