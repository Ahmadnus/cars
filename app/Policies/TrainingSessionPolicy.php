<?php

namespace App\Policies;

use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lesson authorization.
 *
 * A trainer may see and complete their own lessons but not reschedule or
 * cancel them — scheduling stays with reception and supervision.
 */
class TrainingSessionPolicy extends BasePolicy
{
    protected string $prefix = 'appointments';

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('appointments.view')
            && $this->sameBranch($user, $model)
            && $this->ownedByTrainerIfTrainer($user, $model);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('appointments.update')
            && $this->sameBranch($user, $model)
            && ! $model->isSettled();
    }

    public function cancel(User $user, TrainingSession $session): bool
    {
        return $user->hasPermission('appointments.cancel')
            && $this->sameBranch($user, $session)
            && ! $session->isSettled();
    }

    public function complete(User $user, TrainingSession $session): bool
    {
        return $user->hasPermission('appointments.complete')
            && $this->sameBranch($user, $session)
            && $this->ownedByTrainerIfTrainer($user, $session)
            && ! $session->isSettled();
    }

    /** Reopening undoes a financial-adjacent action, so it is manager-level. */
    public function reopen(User $user, TrainingSession $session): bool
    {
        return $user->hasPermission('appointments.update')
            && $user->hasPermission('appointments.cancel')
            && $this->sameBranch($user, $session);
    }

    public function delete(User $user, Model $model): bool
    {
        return false; // lessons are cancelled, never deleted
    }

    protected function ownedByTrainerIfTrainer(User $user, TrainingSession $session): bool
    {
        $trainer = $user->trainer;

        if (! $trainer || $user->hasPermission('appointments.update')) {
            return true;
        }

        return (int) $session->trainer_id === (int) $trainer->id;
    }
}
