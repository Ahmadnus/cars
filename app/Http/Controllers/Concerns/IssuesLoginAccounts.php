<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Role;
use App\Services\AccountService;
use App\Support\IssuedPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared bits of "give this person a login", used by the trainee, trainer and
 * employee screens.
 *
 * The rules live here rather than in each controller because they are the same
 * three questions every time — is this email free, is the password strong
 * enough, does the account get the right roles — and a copy that drifts is how a
 * weak password gets in through one screen.
 *
 * The field names are prefixed `login_` on purpose: a plain `password` field on
 * the trainee form would collide with the record's own validation and, worse,
 * read as if the record had a password of its own.
 */
trait IssuesLoginAccounts
{
    /**
     * @param  int|null  $currentUserId  the account already linked, excluded from the email check
     * @return array<string, mixed>
     */
    protected function validateCredentials(Request $request, ?int $currentUserId = null, bool $rolesRequired = false): array
    {
        return $request->validate([
            'login_email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($currentUserId)->whereNull('deleted_at'),
            ],

            // Optional: left blank, one is generated. Typed, it is held to the
            // handed-out-password rules — letters or digits, six to eight —
            // because the office dictates these over the phone. See
            // IssuedPassword for why that is looser than a staff password.
            'login_password' => IssuedPassword::rules(),

            'login_roles' => [$rolesRequired ? 'required' : 'nullable', 'array', 'min:1'],
            'login_roles.*' => ['integer', 'exists:roles,id'],
        ], [], [
            'login_email' => 'البريد الإلكتروني',
            'login_password' => 'كلمة المرور',
            'login_roles' => 'الأدوار',
        ]);
    }

    /**
     * Issue the account and flash the credentials for the page to show once.
     *
     * @param  array<string, mixed>  $data  from validateCredentials()
     */
    protected function issueAccountFor(
        Model $subject,
        array $data,
        string $route,
        ?string $message = null,
    ): RedirectResponse {
        $existing = (bool) $subject->user_id;

        $password = app(AccountService::class)->issue(
            $subject,
            $data['login_email'] ?? null,
            $data['login_password'] ?? null,
            $data['login_roles'] ?? [],
        );

        $subject->refresh();

        return redirect()
            ->route($route, $subject)
            ->with('toast', [
                'type' => 'success',
                'message' => $message ?? ($existing
                    ? 'تم تعيين كلمة مرور جديدة.'
                    : 'تم إنشاء حساب الدخول.'),
            ])
            // Shown once. Nothing keeps it readable, so a lost password is reset
            // rather than looked up.
            ->with('issued_credentials', [
                'phone' => $subject->user?->phone ?? $subject->phone,
                'email' => $subject->user?->email,
                'password' => $password,
            ]);
    }

    /**
     * Roles a login may be given, for the employee form.
     *
     * Trainee and trainer logins take their role from what the record is, so
     * they never reach this.
     */
    protected function assignableRoles(Request $request): array
    {
        return Role::query()
            // Only a super admin may hand out a role that can mint other
            // accounts, so the rest of the office cannot promote anyone.
            ->when(
                ! $request->user()->isSuperAdmin(),
                fn ($q) => $q->where('name', '!=', 'system_admin'),
            )
            ->orderBy('name')
            ->pluck('label_ar', 'id')
            ->all();
    }
}
