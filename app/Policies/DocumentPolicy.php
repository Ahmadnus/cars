<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Employee;
use App\Models\Trainee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Access to a private document is decided by the record it hangs off, not by
 * the document itself — if you may not see the trainee, you may not see their
 * identity papers.
 */
class DocumentPolicy extends BasePolicy
{
    protected string $prefix = 'documents';

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Model $model): bool
    {
        return $this->sameBranch($user, $model) && $this->canReachOwner($user, $model);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyPermission(
            'trainees.documents', 'employees.manage', 'vehicles.manage',
            'expenses.create', 'payments.create', 'utilities.manage',
        );
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->view($user, $model) && $this->canReachOwner($user, $model, write: true);
    }

    /** Map the owning record onto the permission that guards it. */
    protected function canReachOwner(User $user, Document $document, bool $write = false): bool
    {
        $owner = $document->documentable;

        if (! $owner) {
            return $user->isSuperAdmin();
        }

        return match (true) {
            $owner instanceof Trainee => $user->hasPermission($write ? 'trainees.documents' : 'trainees.view'),
            $owner instanceof \App\Models\Trainer => $user->hasPermission($write ? 'trainers.update' : 'trainers.view'),
            $owner instanceof Employee => $user->hasPermission($write ? 'employees.manage' : 'employees.view'),
            $owner instanceof \App\Models\Vehicle => $user->hasPermission($write ? 'vehicles.manage' : 'vehicles.view'),
            $owner instanceof \App\Models\Expense => $user->hasPermission($write ? 'expenses.update' : 'expenses.view'),
            $owner instanceof \App\Models\Payment => $user->hasPermission($write ? 'payments.update' : 'payments.view'),
            $owner instanceof \App\Models\UtilityBill => $user->hasPermission('utilities.manage'),
            default => $user->isSuperAdmin(),
        };
    }
}
