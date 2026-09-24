<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Packages use a single "manage" permission rather than separate CRUD verbs,
 * so every write maps onto it.
 */
class PackagePolicy extends BasePolicy
{
    protected string $prefix = 'packages';

    public function create(User $user): bool
    {
        return $user->hasPermission('packages.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('packages.manage') && $this->sameBranch($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('packages.manage') && $this->sameBranch($user, $model);
    }
}
