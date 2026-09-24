<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BookingRequestPolicy extends BasePolicy
{
    protected string $prefix = 'booking_requests';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('booking_requests.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('booking_requests.manage') && $this->sameBranch($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('booking_requests.manage') || $user->trainee !== null;
    }

    public function resolve(User $user, Model $model): bool
    {
        return $user->hasPermission('booking_requests.manage')
            && $this->sameBranch($user, $model)
            && $model->isPending();
    }

    public function update(User $user, Model $model): bool
    {
        return $this->resolve($user, $model);
    }
}
