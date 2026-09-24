<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Trainee;
use App\Services\PaymentService;
use App\Services\TraineeBalanceService;
use App\Services\TrainingSessionService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The trainee's own file.
 *
 * Every query here is rooted at the signed-in user's trainee record, so the
 * portal cannot reach another person's data even if a route or id is tampered
 * with: there is no id in the URL to tamper with. A trainee holds none of the
 * center-wide permissions (`trainees.view`, `payments.view`, …) — the portal is
 * the only surface they can reach, and it reads their own rows directly.
 */
class PortalController extends Controller
{
    public function __construct(
        protected TraineeBalanceService $balances,
        protected TrainingSessionService $sessions,
        protected PaymentService $payments,
    ) {}

    public function index(Request $request): View
    {
        $trainee = $this->trainee($request);
        $package = $trainee->activePackage();

        $upcoming = $trainee->trainingSessions()
            ->with(['trainer:id,uuid,full_name,phone', 'vehicle:id,uuid,name,plate_number'])
            ->scheduled()
            ->whereDate('scheduled_date', '>=', now()->toDateString())
            ->orderBy('scheduled_date')->orderBy('start_time')
            ->get();

        $history = $trainee->trainingSessions()
            ->with('trainer:id,uuid,full_name')
            ->whereIn('training_sessions.status', ['completed', 'no_show'])
            ->orderByDesc('scheduled_date')->orderByDesc('start_time')
            ->limit(15)
            ->get();

        return view('portal.index', [
            'trainee' => $trainee,
            'package' => $package,
            'balance' => $package ? $this->balances->summary($package) : null,
            'readiness' => $this->sessions->readinessPercent($trainee->id),
            'upcoming' => $upcoming,
            'history' => $history,
            'skills' => $trainee->skillEvaluations()->with('skill')->get()->sortBy('skill.sort_order'),
            // A trainee may always see what they themselves owe and have paid.
            'outstanding' => $this->payments->outstandingForTrainee($trainee),
            'paid' => $trainee->payments()->completed()->sum('amount'),
            'payments' => $trainee->payments()->latest('paid_on')->limit(10)->get(),
        ]);
    }

    /**
     * The trainee record behind the signed-in user, or a hard refusal.
     *
     * A `trainee` login with no linked file is a provisioning mistake, not a
     * state the portal should try to render around.
     */
    protected function trainee(Request $request): Trainee
    {
        $trainee = $request->user()->trainee;

        if (! $trainee) {
            throw new AccessDeniedHttpException('هذا الحساب غير مرتبط بملف متدرب.');
        }

        return $trainee->load('trainer:id,uuid,full_name,phone', 'branch:id,uuid,name');
    }
}
