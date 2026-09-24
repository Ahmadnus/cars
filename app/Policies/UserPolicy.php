<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserPolicy extends BasePolicy
{
    protected string $prefix = 'users';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function update(User $user, Model $model): bool
    {
        // Only a super admin may edit another super admin.
        if ($model->isSuperAdmin() && ! $user->isSuperAdmin()) {
            return false;
        }

        return $user->hasPermission('users.manage');
    }

    public function delete(User $user, Model $model): bool
    {
        // Nobody deactivates themselves out of the system by accident.
        return $user->id !== $model->id && $this->update($user, $model);
    }
}
