<?php

namespace App\Services;

use App\Models\BookingRequest;
use App\Models\EmployeeAdvance;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\RecurringExpense;
use App\Models\Trainee;
use App\Models\TraineePackage;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\UtilityBill;
use App\Notifications\SystemNotification;
use App\Services\Notifications\ChannelGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Notification;

/**
 * Raises every system notification.
 *
 * Callers say what happened, not how it is delivered. Routing (who should be
 * told) and channel selection (in-app now; SMS/WhatsApp/push once a gateway is
 * bound) are decided here, so the rules live in one place.
 */
class NotificationService
{
    /** @param array<int, ChannelGateway> $gateways */
    public function __construct(
        protected SettingsRepository $settings,
        protected array $gateways = [],
    ) {
    }

    // ------------------------------------------------------------------
    // Appointments
    // ------------------------------------------------------------------

    public function appointmentBooked(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'appointment.booked',
            'تم حجز حصة تدريبية',
            $this->sessionSentence($session, 'تم حجز حصة'),
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
        );
    }

    public function appointmentChanged(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'appointment.changed',
            'تم تعديل موعد الحصة',
            $this->sessionSentence($session, 'تم تعديل موعد الحصة إلى'),
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
            'warning',
        );
    }

    /**
     * The trainer moved their own lesson — told to the trainee, and only them.
     *
     * The recipient list used to include every member of staff holding
     * `appointments.view`, which is a permission the trainer role itself holds:
     * so moving one lesson pinged every trainer at the branch about a trainee
     * who is not theirs. A notification everyone gets is one everyone learns to
     * ignore, and it leaked one trainee's schedule to colleagues with no reason
     * to see it.
     *
     * The office is not notified here either, by the center's decision. It stays
     * visible to them where a change belongs — the audit log records the move,
     * who made it and why, and the lesson itself shows its new slot.
     *
     * Written to the trainee in the second person, because they are now the only
     * reader: "your lesson", not "the trainee's lesson".
     */
    public function appointmentMovedByTrainer(TrainingSession $session, string $previous, ?string $reason = null): void
    {
        $session->loadMissing('trainee.user', 'trainer.user');

        $trainee = $session->trainee?->user;

        if (! $trainee) {
            return;
        }

        $trainerName = $session->trainer?->full_name ?? 'المدرب';

        $this->notify(
            collect([$trainee]),
            'appointment.moved_by_trainer',
            'تم تغيير موعد حصتك',
            "غيّر المدرب {$trainerName} موعد حصتك من {$previous} إلى "
                .$session->scheduled_date?->format('Y-m-d').' '
                .substr((string) $session->start_time, 0, 5).'.'
                .($reason ? " السبب: {$reason}" : ''),
            [
                'kind' => 'session',
                'session_uuid' => $session->uuid,
                'previous' => $previous,
            ],
            $this->safeRoute('admin.sessions.show', [$session]),
            'warning',
        );
    }

    public function appointmentCancelled(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'appointment.cancelled',
            'تم إلغاء الحصة',
            $this->sessionSentence($session, 'تم إلغاء حصة'),
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
            'error',
        );
    }

    public function appointmentReminder(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'appointment.reminder',
            'تذكير بحصة قادمة',
            $this->sessionSentence($session, 'لديك حصة قادمة'),
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
        );
    }

    public function sessionEvaluated(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'evaluation.created',
            'تم تسجيل تقييم جديد',
            "تم تسجيل تقييم لحصة المتدرب {$session->trainee->full_name}.",
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
        );
    }

    // ------------------------------------------------------------------
    // Booking requests
    // ------------------------------------------------------------------

    /**
     * A trainee raised a request — booking, reschedule or cancellation.
     *
     * Goes to whoever may act on it at that branch, which is a permission
     * question rather than a role one: the same call reaches reception at a small
     * center and a dedicated scheduler at a large one.
     *
     * The type is named in the title because "طلب جديد" tells a busy receptionist
     * nothing about whether a lesson is about to be missed.
     */
    public function bookingRequestRaised(\App\Models\BookingRequest $request): void
    {
        $request->loadMissing('trainee', 'preferredTrainer', 'trainingSession');

        $trainee = $request->trainee?->full_name ?? 'متدرب';

        [$title, $level] = match ($request->type) {
            'reschedule' => ['طلب تأجيل حصة', 'warning'],
            'cancellation' => ['طلب إلغاء حصة', 'warning'],
            default => ['طلب حجز جديد', 'info'],
        };

        $when = $request->requested_date
            ? $request->requested_date->format('Y-m-d')
                .($request->requested_start_time ? ' — '.substr((string) $request->requested_start_time, 0, 5) : '')
            : null;

        $body = match ($request->type) {
            'reschedule' => "يطلب {$trainee} تأجيل حصته".($when ? " إلى {$when}" : '').'.',
            'cancellation' => "يطلب {$trainee} إلغاء حصته".($when ? " بتاريخ {$when}" : '').'.',
            default => "يطلب {$trainee} حجز حصة".($when ? " في {$when}" : '').'.',
        };

        if ($request->trainee_note) {
            $body .= ' ملاحظته: '.\Illuminate\Support\Str::limit($request->trainee_note, 120);
        }

        $this->notify(
            $this->staffFor($request->branch_id, 'booking_requests.manage'),
            'booking_request.'.$request->type,
            $title,
            $body,
            [
                'kind' => 'booking_request',
                'request_uuid' => $request->uuid,
                'request_type' => $request->type,
            ],
            $this->safeRoute('admin.booking-requests.index'),
            $level,
        );
    }

    /**
     * The trainer answered a reschedule, and the office needs to know.
     *
     * Names the trainer and the trainee in the title, because that is what makes
     * it actionable: a receptionist reading "وافق المدرب عمر الزعبي على تأجيل حصة
     * سارة" can move the lesson; one reading "تم تحديث طلب" has to open it first.
     */
    public function bookingRequestTrainerDecision(
        BookingRequest $request,
        User $trainerUser,
        string $decision,
    ): void {
        $request->loadMissing('trainee', 'trainingSession');

        $trainer = $trainerUser->trainer?->full_name ?? $trainerUser->name;
        $trainee = $request->trainee?->full_name ?? 'المتدرب';

        $what = $request->type === 'cancellation' ? 'إلغاء' : 'تأجيل';
        $approved = $decision === BookingRequest::TRAINER_APPROVED;

        $title = $approved
            ? "وافق المدرب على {$what} حصة"
            : "رفض المدرب {$what} حصة";

        $body = $approved
            ? "وافق المدرب {$trainer} على طلب {$what} حصة {$trainee}. بانتظار تنفيذ الإدارة."
            : "رفض المدرب {$trainer} طلب {$what} حصة {$trainee}.";

        if ($request->trainer_note) {
            $body .= ' ملاحظته: '.Str::limit($request->trainer_note, 120);
        }

        $recipients = $this->staffFor($request->branch_id, 'booking_requests.manage');

        // The trainee hears the outcome too — it is their lesson.
        if ($request->trainee?->user) {
            $recipients = $recipients->merge(collect([$request->trainee->user]));
        }

        $this->notify(
            $recipients,
            'booking_request.trainer_'.$decision,
            $title,
            $body,
            [
                'kind' => 'booking_request',
                'request_uuid' => $request->uuid,
                'trainer_decision' => $decision,
                'trainer' => $trainer,
            ],
            $this->safeRoute('admin.booking-requests.index'),
            $approved ? 'success' : 'warning',
        );
    }

    /** A stranger applied to join through the public app. */
    public function registrationSubmitted(\App\Models\RegistrationRequest $request): void
    {
        $this->notify(
            $this->staffFor($request->branch_id ?? 0, 'registrations.manage'),
            'registration.submitted',
            'طلب انتساب جديد',
            "قدّم {$request->full_name} طلب انتساب برقم {$request->reference}.",
            [
                'kind' => 'registration',
                'reference' => $request->reference,
            ],
            $this->safeRoute('admin.registrations.index'),
            'info',
        );
    }

    /**
     * Someone cannot sign in and is asking the office for a new password.
     *
     * There is no self-service reset: no SMS gateway, and email addresses the
     * office invented for phone-only accounts go nowhere. So the request becomes
     * a job for a member of staff, who resets the password on the person's page
     * and reads it out — the same channel the account was handed over on in the
     * first place, which is also the one that proves who is asking.
     *
     * Deliberately carries no link to the record: staff search by the name and
     * number, and a request from a stranger's phone must not hand out a route
     * into a trainee's file.
     */
    public function passwordResetRequested(User $user, string $enteredPhone): void
    {
        $subject = $user->trainee ?? $user->trainer ?? $user->employee;

        $permission = match (true) {
            $user->trainee !== null => 'trainees.update',
            $user->trainer !== null => 'trainers.update',
            default => 'users.update',
        };

        $role = match (true) {
            $user->trainee !== null => 'متدرب',
            $user->trainer !== null => 'مدرب',
            default => 'مستخدم',
        };

        $this->notify(
            $this->staffFor($subject?->branch_id ?? $user->branch_id ?? 0, $permission),
            'account.password_reset_requested',
            'طلب كلمة مرور جديدة',
            "طلب {$role} «".($subject?->full_name ?? $user->name)."» كلمة مرور جديدة (".$enteredPhone.")."
                .' أعد تعيينها من صفحته وأرسلها له.',
            [
                'kind' => 'password_reset',
                'phone' => $enteredPhone,
            ],
            null,
            'warning',
        );
    }

    /** A trainee did not turn up, which costs them a lesson. */
    public function sessionMissed(TrainingSession $session): void
    {
        $session->loadMissing('trainee.user', 'trainer.user');

        $this->notify(
            $this->peopleFor($session)->merge(
                $this->staffFor($session->branch_id, 'appointments.view'),
            ),
            'session.no_show',
            'تم تسجيل عدم حضور',
            'لم يحضر '.($session->trainee?->full_name ?? 'المتدرب')
                .' حصة '.$session->scheduled_date?->format('Y-m-d').'.',
            ['kind' => 'session', 'session_uuid' => $session->uuid],
            null,
            'warning',
        );
    }

    /** A payment was reversed, which changes what a trainee owes. */
    public function paymentVoided(Payment $payment, string $reason): void
    {
        $payment->loadMissing('trainee.user');

        $this->notify(
            $this->staffFor($payment->branch_id, 'payments.view')
                ->merge(collect([$payment->trainee?->user])->filter()),
            'payment.voided',
            'تم إلغاء دفعة',
            'أُلغيت دفعة بمبلغ '.money($payment->amount)
                .' للمتدرب '.($payment->trainee?->full_name ?? '—').'. السبب: '.$reason,
            ['kind' => 'payment', 'payment_uuid' => $payment->uuid],
            null,
            'warning',
        );
    }

    /** A package was assigned, so the trainee can now book. */
    public function packageAssigned(TraineePackage $package): void
    {
        $package->loadMissing('trainee.user', 'package');

        $this->notify(
            collect([$package->trainee?->user])->filter(),
            'package.assigned',
            'تم إسناد باقة تدريب',
            'أُسندت إليك باقة '.($package->package?->name ?? 'تدريب')
                .'. يمكنك الآن حجز حصصك.',
            ['kind' => 'package'],
            null,
            'success',
        );
    }

    /** A trainee's standing changed — ready for the test, passed, failed. */
    public function traineeStatusChanged(Trainee $trainee, string $from): void
    {
        $labels = [
            'ready_for_exam' => 'أصبحت جاهزاً للامتحان',
            'exam_scheduled' => 'تم تحديد موعد امتحانك',
            'passed' => 'مبروك! نجحت في الامتحان',
            'failed' => 'لم تنجح في الامتحان هذه المرة',
            'suspended' => 'تم إيقاف تدريبك مؤقتاً',
            'completed' => 'أنهيت برنامج التدريب',
        ];

        if (! isset($labels[$trainee->status])) {
            return;
        }

        $this->notify(
            collect([$trainee->user])->filter(),
            'trainee.status',
            $labels[$trainee->status],
            'تغيّرت حالة ملفك. راجع إدارة المركز لأي استفسار.',
            ['kind' => 'trainee_status', 'status' => $trainee->status, 'from' => $from],
            null,
            in_array($trainee->status, ['passed', 'completed'], true) ? 'success' : 'info',
        );
    }

    public function bookingRequestResolved(\App\Models\BookingRequest $request): void
    {
        $status = match ($request->status) {
            'approved' => 'تمت الموافقة على طلبك',
            'rejected' => 'تم رفض طلبك',
            'rescheduled' => 'تم إعادة جدولة طلبك',
            default => 'تم تحديث حالة طلبك',
        };

        $this->notify(
            collect([$request->trainee?->user])->filter(),
            'booking_request.resolved',
            $status,
            $request->admin_note ?: $status,
            ['request_uuid' => $request->uuid],
            $this->safeRoute('admin.booking-requests.index'),
            $request->status === 'rejected' ? 'warning' : 'success',
        );
    }

    // ------------------------------------------------------------------
    // Training balance
    // ------------------------------------------------------------------

    /**
     * The trainee is near the end of their schedule.
     *
     * Worded as the center asked: "a low balance" reads like an accounting
     * warning, and what a trainer or a receptionist actually needs to know is
     * that this person is about to run out of lessons and should be offered the
     * next package before their training stops.
     */
    public function lowLessonBalance(TraineePackage $package, int $remaining): void
    {
        $trainee = $package->trainee;

        $this->notify(
            $this->staffFor($package->branch_id, 'appointments.view')->merge(collect([$trainee?->user])->filter()),
            'balance.low',
            'اقترب انتهاء جدول التدريب',
            "أوشك المتدرب {$trainee?->full_name} على إنهاء حصصه — تبقّت له "
                .$this->lessonsWord($remaining).'.',
            ['trainee_package_uuid' => $package->uuid, 'remaining' => $remaining],
            route('admin.trainees.show', $trainee, false),
            'warning',
        );
    }

    /**
     * "حصة واحدة" / "حصتان" / "3 حصص" — Arabic counts differently by number, and
     * "2 حصة" is the kind of wrong that makes a system look machine-written.
     */
    protected function lessonsWord(int $count): string
    {
        return match (true) {
            $count === 1 => 'حصة واحدة',
            $count === 2 => 'حصتان',
            $count >= 3 && $count <= 10 => $count.' حصص',
            default => $count.' حصة',
        };
    }

    // ------------------------------------------------------------------
    // Finance
    // ------------------------------------------------------------------

    public function paymentRecorded(Payment $payment): void
    {
        $this->notify(
            $this->staffFor($payment->branch_id, 'payments.view'),
            'payment.recorded',
            'تم تسجيل دفعة',
            "دفعة بقيمة {$payment->amount} من {$payment->trainee?->full_name} (إيصال {$payment->receipt_number}).",
            ['payment_uuid' => $payment->uuid],
            route('admin.payments.show', $payment, false),
            'success',
        );
    }

    public function payrollDue(Payroll $payroll): void
    {
        $this->notify(
            $this->staffFor($payroll->branch_id, 'payroll.pay'),
            'payroll.due',
            'راتب مستحق الصرف',
            "راتب {$payroll->employee?->full_name} لشهر {$payroll->period} مستحق الصرف.",
            ['payroll_uuid' => $payroll->uuid],
            route('admin.payroll.show', $payroll, false),
            'warning',
        );
    }

    public function recurringExpenseDue(RecurringExpense $expense): void
    {
        $this->notify(
            $this->staffFor($expense->branch_id, 'expenses.view'),
            'recurring_expense.due',
            'مصروف متكرر مستحق',
            "المصروف المتكرر «{$expense->name}» بقيمة {$expense->amount} أصبح مستحقاً.",
            ['recurring_expense_uuid' => $expense->uuid],
            $this->safeRoute('admin.recurring-expenses.index'),
            'warning',
        );
    }

    public function utilityBillDue(UtilityBill $bill): void
    {
        $this->notify(
            $this->staffFor($bill->branch_id, 'utilities.manage'),
            'utility_bill.due',
            'فاتورة خدمات مستحقة',
            "فاتورة {$bill->serviceLabel()} لشهر {$bill->billing_month} مستحقة بتاريخ {$bill->due_date->format('Y-m-d')}.",
            ['bill_uuid' => $bill->uuid],
            $this->safeRoute('admin.utilities.index'),
            'warning',
        );
    }

    public function advanceSettled(EmployeeAdvance $advance): void
    {
        $this->notify(
            $this->staffFor($advance->branch_id, 'advances.manage'),
            'advance.settled',
            'تم سداد سلفة بالكامل',
            "تم سداد سلفة الموظف {$advance->employee?->full_name} بالكامل.",
            ['advance_uuid' => $advance->uuid],
            $this->safeRoute('admin.advances.index'),
            'success',
        );
    }

    public function examScheduled(Trainee $trainee): void
    {
        $this->notify(
            $this->staffFor($trainee->branch_id, 'trainees.view')->merge(collect([$trainee->user])->filter()),
            'exam.scheduled',
            'موعد امتحان',
            "تم تحديد موعد امتحان للمتدرب {$trainee->full_name} بتاريخ {$trainee->exam_date?->format('Y-m-d')}.",
            ['trainee_uuid' => $trainee->uuid],
            route('admin.trainees.show', $trainee, false),
        );
    }

    // ------------------------------------------------------------------
    // Delivery
    // ------------------------------------------------------------------

    /** @param Collection<int, User> $recipients */
    public function notify(
        Collection $recipients,
        string $type,
        string $title,
        string $body,
        array $payload = [],
        ?string $url = null,
        string $level = 'info',
    ): void {
        $recipients = $recipients->filter()->unique('id')->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $channels = $this->channelsFor();

        if (in_array('database', $channels, true) || in_array('mail', $channels, true)) {
            Notification::send(
                $recipients,
                new SystemNotification($type, $title, $body, $payload, $url, $level, $channels),
            );
        }

        // External providers are opt-in and fail soft: a dead SMS provider must
        // never roll back the business operation that raised the notification.
        foreach ($this->gateways as $gateway) {
            if (! $gateway->isEnabled()) {
                continue;
            }

            foreach ($recipients as $recipient) {
                try {
                    $gateway->send($recipient, $title, $body, $payload);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * A dashboard URL, or null when that screen does not exist yet.
     *
     * `route()` throws on an unknown name. A notification is a side effect
     * wrapped in try/catch by its callers, so such a throw does not surface as an
     * error — it silently loses the notification. That happened: a link to a
     * registrations screen that had not been built yet meant staff were never
     * told a join request had arrived. A deep link is a nicety; the notification
     * is the point.
     */
    protected function safeRoute(string $name, array $parameters = []): ?string
    {
        try {
            return route($name, $parameters, false);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array<int, string> Laravel channels enabled by settings */
    protected function channelsFor(): array
    {
        $channels = [];

        if ($this->settings->bool('notifications.enable_in_app', true)) {
            $channels[] = 'database';
        }

        if ($this->settings->bool('notifications.enable_email', false)) {
            $channels[] = 'mail';
        }

        return $channels ?: ['database'];
    }

    /** Staff who should hear about something at a branch, filtered by permission. */
    protected function staffFor(int $branchId, string $permission): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhere('can_access_all_branches', true)
                    ->orWhereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
            })
            ->with('roles.permissions', 'permissionOverrides')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission($permission));
    }

    /** The trainee and trainer attached to a lesson, where they have logins. */
    protected function peopleFor(TrainingSession $session): Collection
    {
        $session->loadMissing('trainee.user', 'trainer.user');

        return collect([$session->trainee?->user, $session->trainer?->user])->filter();
    }

    protected function sessionSentence(TrainingSession $session, string $prefix): string
    {
        return sprintf(
            '%s بتاريخ %s الساعة %s مع المدرب %s.',
            $prefix,
            $session->scheduled_date->format('Y-m-d'),
            substr((string) $session->start_time, 0, 5),
            $session->trainer?->full_name ?? '—',
        );
    }
}
