<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Payments are voided, never deleted, so `delete` is refused outright and
 * `void` carries its own permission.
 */
class PaymentPolicy extends BasePolicy
{
    protected string $prefix = 'payments';

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('payments.update')
            && $this->sameBranch($user, $model)
            && ! $model->isVoided();
    }

    public function void(User $user, Payment $payment): bool
    {
        return $user->hasPermission('payments.void')
            && $this->sameBranch($user, $payment)
            && ! $payment->isVoided();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function downloadReceipt(User $user, Payment $payment): bool
    {
        return $user->hasPermission('payments.view') && $this->sameBranch($user, $payment);
    }
}
