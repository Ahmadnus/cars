<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RolePolicy extends BasePolicy
{
    protected string $prefix = 'roles';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('roles.manage');
    }

    /** System roles are structural and cannot be removed. */
    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('roles.manage')
            && ! $model->is_system
            && $model->users()->count() === 0;
    }
}
