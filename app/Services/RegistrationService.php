<?php

namespace App\Services;

use App\Events\RegistrationRequestUpdated;
use App\Exceptions\BusinessRuleException;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\Trainee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Public join requests, and the staff decision on them.
 *
 * The rule that shapes this class: a request is not a trainee. Anyone can
 * submit one, so nothing it contains touches the training records — no trainee
 * file, no trainee number, no appearance in a report — until a member of staff
 * approves it. Approval is the only path from one to the other, and it happens
 * in a single transaction so a half-created trainee is impossible.
 */
class RegistrationService
{
    public function __construct(
        protected NumberGenerator $numbers,
        protected OtpService $otp,
        protected AuditLogger $audit,
        protected PushService $push,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Record a request from the public app.
     *
     * The phone must already be proven by passcode: without that the form is an
     * open channel for junk submitted under other people's numbers.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data, string $code, ?string $ip = null): RegistrationRequest
    {
        $phone = $this->otp->normalisePhone((string) $data['phone']);

        $verification = $this->otp->verify($phone, $code, 'registration');

        if (! $verification['ok']) {
            throw BusinessRuleException::make(
                $verification['error'] ?? 'رمز التحقق غير صحيح.',
                ['code' => [$verification['error'] ?? 'رمز التحقق غير صحيح.']],
            );
        }

        $this->assertNotAlreadyKnown($phone);

        $request = RegistrationRequest::create([
            'branch_id' => $data['branch_id'] ?? null,
            'full_name' => $data['full_name'],
            'phone' => $phone,
            'secondary_phone' => $data['secondary_phone'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? null,
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'license_type' => $data['license_type'] ?? null,
            'notes' => $data['notes'] ?? null,
            'phone_verified_at' => now(),
            'status' => RegistrationRequest::STATUS_PENDING,
            'ip_address' => $ip,
        ]);

        $this->announce($request, 'created');

        // A broadcast only reaches a dashboard that happens to be open; the
        // notification reaches whoever may act on it, wherever they are.
        try {
            $this->notifications->registrationSubmitted($request);
        } catch (\Throwable $e) {
            report($e);
        }

        return $request;
    }

    /**
     * Approve a request and create the trainee it describes.
     *
     * A login is created only when the applicant will use the app, and the
     * password is returned rather than stored anywhere readable — the caller
     * shows it once. Passcode sign-in needs no password at all, which is why
     * this is optional.
     *
     * @param  array<string, mixed>  $overrides  Staff corrections to the applicant's own answers.
     * @return array{trainee: Trainee, password: ?string}
     */
    public function approve(
        RegistrationRequest $request,
        User $reviewer,
        array $overrides = [],
        bool $createLogin = true,
    ): array {
        if (! $request->isOpen()) {
            throw BusinessRuleException::make(
                'تم اتخاذ قرار بهذا الطلب مسبقاً ('.$request->statusLabel().').',
            );
        }

        $branchId = $overrides['branch_id'] ?? $request->branch_id;

        if (! $branchId) {
            throw BusinessRuleException::make(
                'يجب تحديد الفرع قبل قبول الطلب.',
                ['branch_id' => ['الفرع مطلوب.']],
            );
        }

        $password = null;

        $trainee = DB::transaction(function () use ($request, $reviewer, $overrides, $branchId, $createLogin, &$password) {
            // Re-read under a lock: two reviewers opening the same request must
            // not both create a trainee from it.
            $locked = RegistrationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, RegistrationRequest::OPEN_STATUSES, true)) {
                throw BusinessRuleException::make('تم اتخاذ قرار بهذا الطلب أثناء المراجعة.');
            }

            $trainee = Trainee::create([
                'branch_id' => $branchId,
                'trainee_number' => $this->numbers->traineeNumber(),
                'full_name' => $overrides['full_name'] ?? $locked->full_name,
                'phone' => $overrides['phone'] ?? $locked->phone,
                'secondary_phone' => $overrides['secondary_phone'] ?? $locked->secondary_phone,
                'national_id' => $overrides['national_id'] ?? $locked->national_id,
                'birth_date' => $overrides['birth_date'] ?? $locked->birth_date,
                'gender' => $overrides['gender'] ?? $locked->gender,
                'address' => $overrides['address'] ?? $locked->address,
                'license_type' => $overrides['license_type'] ?? $locked->license_type ?? 'private',
                'trainer_id' => $overrides['trainer_id'] ?? null,
                'registration_date' => now()->toDateString(),
                // A fresh file, not yet in training: a package has to be
                // assigned before any lesson can be booked.
                'status' => 'new',
                'notes' => $locked->notes,
            ]);

            if ($createLogin) {
                $password = Str::lower(Str::random(10));
                $this->createLogin($trainee, $password);
            }

            $locked->forceFill([
                'status' => RegistrationRequest::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'trainee_id' => $trainee->id,
                'decision_reason' => $overrides['decision_reason'] ?? null,
            ])->save();

            $this->audit->log('approve', $locked, after: [
                'trainee_id' => $trainee->id,
                'reference' => $locked->reference,
            ], description: 'قبول طلب انتساب '.$locked->reference);

            return $trainee;
        });

        $request->refresh();
        $this->announce($request, 'approved');
        $this->notifyApplicant($request, 'تم قبول طلب الانتساب', 'تم قبول طلبك. رقم ملفك: '.$trainee->trainee_number);

        return ['trainee' => $trainee, 'password' => $password];
    }

    /** Refuse a request, with a reason the applicant will read. */
    public function reject(RegistrationRequest $request, User $reviewer, string $reason): RegistrationRequest
    {
        if (! $request->isOpen()) {
            throw BusinessRuleException::make(
                'تم اتخاذ قرار بهذا الطلب مسبقاً ('.$request->statusLabel().').',
            );
        }

        $request->forceFill([
            'status' => RegistrationRequest::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        $this->audit->log(
            'reject',
            $request,
            description: 'رفض طلب انتساب '.$request->reference,
            reason: $reason,
        );

        $this->announce($request, 'rejected');
        $this->notifyApplicant($request, 'تم رفض طلب الانتساب', $reason);

        return $request;
    }

    /** Claim a request for review, so two people do not work the same one. */
    public function markReviewing(RegistrationRequest $request, User $reviewer): RegistrationRequest
    {
        if ($request->status !== RegistrationRequest::STATUS_PENDING) {
            return $request;
        }

        $request->forceFill([
            'status' => RegistrationRequest::STATUS_REVIEWING,
            'reviewed_by' => $reviewer->id,
        ])->save();

        $this->announce($request, 'reviewing');

        return $request;
    }

    /**
     * Give the new trainee a portal/app login.
     *
     * Reuses a dormant account on the same phone rather than failing: an
     * applicant who was registered once before and archived should get their
     * old login back, not a duplicate user row.
     */
    protected function createLogin(Trainee $trainee, string $password): User
    {
        $role = Role::where('name', 'trainee')->first();

        $email = $trainee->phone.'@trainee.local';

        $user = User::withTrashed()->firstOrNew(['email' => $email]);

        $user->fill([
            'name' => $trainee->full_name,
            'phone' => $trainee->phone,
            'branch_id' => $trainee->branch_id,
            'locale' => 'ar',
        ]);

        $user->password = Hash::make($password);
        $user->status = 'active';
        $user->email_verified_at ??= now();
        $user->deleted_at = null;
        $user->save();

        if ($role) {
            $user->roles()->sync([$role->id]);
        }

        $user->branches()->syncWithoutDetaching([$trainee->branch_id]);

        $trainee->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    /**
     * Refuse a second request from a number the center already knows.
     *
     * The message is deliberately the same whether the phone belongs to an
     * existing trainee or to an open request, so the endpoint cannot be used to
     * check who is enrolled.
     */
    protected function assertNotAlreadyKnown(string $phone): void
    {
        $exists = Trainee::where('phone', $phone)->exists()
            || RegistrationRequest::where('phone', $phone)->open()->exists();

        if ($exists) {
            throw BusinessRuleException::make(
                'هذا الرقم مسجّل لدينا بالفعل أو لديه طلب قيد المعالجة. تواصل مع المركز.',
                ['phone' => ['الرقم مستخدم.']],
            );
        }
    }

    protected function announce(RegistrationRequest $request, string $action): void
    {
        try {
            RegistrationRequestUpdated::dispatch($request, $action);
        } catch (\Throwable $e) {
            // Real-time is a convenience; the decision is already stored.
            report($e);
        }
    }

    protected function notifyApplicant(RegistrationRequest $request, string $title, string $body): void
    {
        $user = $request->trainee?->user;

        if (! $user) {
            return;
        }

        try {
            $this->push->send($user, $title, $body, [
                'kind' => 'registration',
                'reference' => $request->reference,
                'status' => $request->status,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
