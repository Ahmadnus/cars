<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The login attached to a person's record — trainee, trainer or employee.
 *
 * None of them register themselves: the office creates the account and hands
 * the password over by phone or WhatsApp. One service for all three because the
 * risky parts are identical — linking the right user row, granting the right
 * role, and refusing a phone or email that already signs someone else in — and
 * three copies of that is three places for it to drift.
 *
 * The password may be typed by the member of staff (they often want to give a
 * memorable one over the phone) or left blank to be generated. Either way it is
 * returned once and never stored readable, so a lost password is reset rather
 * than looked up.
 */
class AccountService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /** The role a record's login gets when none is named. */
    protected const DEFAULT_ROLES = [
        Trainee::class => 'trainee',
        Trainer::class => 'trainer',
    ];

    /**
     * Create the record's login, or reset the password of the existing one.
     *
     * @param  Trainee|Trainer|Employee  $subject
     * @param  int[]  $roleIds  roles for an employee; ignored for a trainee or trainer,
     *                          whose role follows from what they are
     * @return string the password, to be read out once
     */
    public function issue(
        Model $subject,
        ?string $email = null,
        ?string $password = null,
        array $roleIds = [],
    ): string {
        $this->assertSupported($subject);

        $password = $password ?: $this->generatePassword();
        $phone = $this->normalisePhone($subject->phone);

        DB::transaction(function () use ($subject, $email, $password, $phone, $roleIds) {
            $user = $subject->user;

            if ($user) {
                $user->password = Hash::make($password);
                $user->status = 'active';

                // An email typed on a reset is a correction, so it is applied —
                // but only after checking it is not someone else's.
                if ($email && $email !== $user->email) {
                    $this->assertEmailFree($email, $user->id);
                    $user->email = $email;
                }

                $user->save();

                $this->applyRoles($user, $subject, $roleIds);

                $this->audit->log(
                    action: $this->key($subject).'.password_reset',
                    subject: $subject,
                    description: 'تعيين كلمة مرور جديدة لحساب الدخول',
                );

                return;
            }

            // Both are unique on users, and a collision mid-transaction would
            // surface as a database error. Name the account that holds it
            // instead, so the office can correct the record or link it.
            $this->assertPhoneFree($phone);

            if ($email) {
                $this->assertEmailFree($email);
            }

            $user = User::create([
                'name' => $subject->full_name,
                'email' => $email ?: $this->fallbackEmail($subject, $phone),
                // The number the office already calls. It is what the person
                // will type, since an email the office invented for them is not
                // something they will remember.
                'phone' => $phone,
                'password' => Hash::make($password),
                'branch_id' => $subject->branch_id,
                'status' => 'active',
            ]);

            // Not fillable on User, and deliberately so: set explicitly. The
            // office vouched for this account, so there is no mail to confirm.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->applyRoles($user, $subject, $roleIds);
            $user->branches()->sync([$subject->branch_id]);

            $subject->forceFill(['user_id' => $user->id])->save();

            $this->audit->log(
                action: $this->key($subject).'.account_created',
                subject: $subject,
                after: ['email' => $user->email, 'phone' => $user->phone],
                description: 'إنشاء حساب دخول',
            );
        });

        return $password;
    }

    /**
     * Close the login without touching the person's records.
     *
     * Lessons, evaluations, payments and payroll are the center's records, not
     * the account holder's, so the account is disabled and never deleted.
     */
    public function suspend(Model $subject): void
    {
        $this->assertSupported($subject);

        $user = $subject->user;

        if (! $user) {
            throw BusinessRuleException::make('لا يوجد حساب دخول لهذا السجل.');
        }

        $user->forceFill(['status' => 'suspended'])->save();
        $user->tokens()->delete();

        $this->audit->log(
            action: $this->key($subject).'.account_suspended',
            subject: $subject,
            description: 'تعطيل حساب الدخول',
        );
    }

    /**
     * Roles for the new login.
     *
     * A trainee or trainer gets exactly the role that matches what they are —
     * making that selectable would be a way to hand a trainee staff permissions
     * from a page nobody thinks of as the users screen. An employee's roles are
     * chosen, because "employee" is not one job.
     */
    protected function applyRoles(User $user, Model $subject, array $roleIds): void
    {
        $default = self::DEFAULT_ROLES[$subject::class] ?? null;

        if ($default !== null) {
            $role = Role::where('name', $default)->first();

            if (! $role) {
                throw BusinessRuleException::make('دور «'.$default.'» غير موجود في النظام.');
            }

            $user->roles()->syncWithoutDetaching([$role->id]);
            $user->forgetPermissionCache();

            return;
        }

        if ($roleIds === []) {
            throw BusinessRuleException::make('اختر دوراً واحداً على الأقل لحساب الموظف.');
        }

        $user->roles()->sync($roleIds);
        $user->forgetPermissionCache();
    }

    protected function assertSupported(Model $subject): void
    {
        if (! $subject instanceof Trainee && ! $subject instanceof Trainer && ! $subject instanceof Employee) {
            throw BusinessRuleException::make('لا يمكن إنشاء حساب دخول لهذا النوع من السجلات.');
        }
    }

    protected function assertPhoneFree(?string $phone): void
    {
        if (! $phone) {
            throw BusinessRuleException::make('أضف رقم هاتف للسجل أولاً — هو اسم الدخول.');
        }

        $clash = User::where('phone', $phone)->first();

        if ($clash) {
            throw BusinessRuleException::make(
                'رقم الهاتف مستخدم في حساب آخر ('.$clash->name.'). عدّل الرقم أو اربط السجل بذلك الحساب.',
                ['phone' => ['رقم الهاتف مستخدم في حساب آخر.']],
            );
        }
    }

    protected function assertEmailFree(string $email, ?int $exceptUserId = null): void
    {
        $clash = User::where('email', $email)
            ->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))
            ->first();

        if ($clash) {
            throw BusinessRuleException::make(
                'البريد الإلكتروني مستخدم في حساب آخر ('.$clash->name.').',
                ['email' => ['البريد الإلكتروني مستخدم في حساب آخر.']],
            );
        }
    }

    /** Digits only, so 079… and +96279… do not become two accounts. */
    protected function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits === '' ? null : $digits;
    }

    /**
     * Readable but not guessable, for the common case where the office does not
     * type one: it has to survive being read out over a phone line.
     */
    protected function generatePassword(): string
    {
        return Str::upper(Str::random(3)).random_int(10000, 99999);
    }

    /**
     * An email is required on a user, but these accounts sign in by phone, so
     * one is derived rather than demanded from the office.
     */
    protected function fallbackEmail(Model $subject, ?string $phone): string
    {
        $handle = $phone ?: Str::lower(Str::random(8));

        return $this->key($subject).'.'.$handle.'@'.config('accounts.generated_email_domain');
    }

    /** `trainee` / `trainer` / `employee`, for audit actions and emails. */
    protected function key(Model $subject): string
    {
        return Str::lower(class_basename($subject));
    }
}
