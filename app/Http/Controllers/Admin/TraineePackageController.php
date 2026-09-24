<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Trainee;
use App\Models\TraineePackage;
use App\Services\PackageService;
use App\Services\TraineeBalanceService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Enrolling a trainee in a package, and adjusting their lesson balance.
 *
 * All of it delegates to services: the controller never writes a balance or a
 * contract total itself.
 */
class TraineePackageController extends Controller
{
    public function __construct(
        protected PackageService $packages,
        protected TraineeBalanceService $balances,
        protected BranchContext $branchContext,
    ) {
    }

    public function create(Request $request, Trainee $trainee): View
    {
        $this->authorize('view', $trainee);
        $this->authorize('packages.assign');

        return view('admin.trainees.assign-package', [
            'trainee' => $trainee,
            'packages' => Package::query()
                ->active()
                ->availableAt($trainee->branch_id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, Trainee $trainee): RedirectResponse
    {
        $this->authorize('view', $trainee);
        $this->authorize('packages.assign');

        $data = $request->validate([
            'package_id' => ['required', 'integer', Rule::exists('packages', 'id')->whereNull('deleted_at')],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'started_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'package_id' => 'الباقة',
            'discount_amount' => 'قيمة الخصم',
            'discount_reason' => 'سبب الخصم',
            'started_on' => 'تاريخ البدء',
        ]);

        // Granting a discount is a separate, more sensitive permission.
        if ((float) ($data['discount_amount'] ?? 0) > 0) {
            $this->authorize('packages.discount');
        }

        $package = Package::findOrFail($data['package_id']);
        abort_unless($request->user()->canAccessBranch($package->branch_id ?? $trainee->branch_id), 403);

        $enrolment = $this->packages->assign($trainee, $package, $data);

        return redirect()
            ->route('admin.trainees.show', $trainee)
            ->with('toast', [
                'type' => 'success',
                'message' => "تم إسناد الباقة «{$enrolment->package_name}» وإضافة {$enrolment->lessons_count} حصة للرصيد.",
            ]);
    }

    /** Sell additional lessons on an existing enrolment. */
    public function addExtraLessons(Request $request, TraineePackage $traineePackage): RedirectResponse
    {
        $this->authorize('view', $traineePackage->trainee);
        $this->authorize('packages.assign');

        $data = $request->validate([
            'lessons' => ['required', 'integer', 'min:1', 'max:50'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'lessons' => 'عدد الحصص',
            'unit_price' => 'سعر الحصة',
        ]);

        $this->packages->addExtraLessons(
            $traineePackage,
            (int) $data['lessons'],
            isset($data['unit_price']) ? (float) $data['unit_price'] : null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "تمت إضافة {$data['lessons']} حصة إضافية.",
        ]);
    }

    /**
     * Manually correct a lesson balance.
     *
     * Always recorded as a ledger row with a reason; the stored balance is
     * never edited directly.
     */
    public function adjustBalance(Request $request, TraineePackage $traineePackage): RedirectResponse
    {
        $this->authorize('view', $traineePackage->trainee);
        $this->authorize('packages.assign');

        $data = $request->validate([
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'lessons' => ['required', 'integer', 'min:1', 'max:50'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], [
            'direction' => 'نوع التعديل',
            'lessons' => 'عدد الحصص',
            'reason' => 'السبب',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $traineePackage) {
            $data['direction'] === 'credit'
                ? $this->balances->creditManual($traineePackage, (int) $data['lessons'], $data['reason'])
                : $this->balances->debitManual($traineePackage, (int) $data['lessons'], $data['reason']);
        });

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تعديل رصيد الحصص وتسجيل الحركة.']);
    }

    public function cancel(Request $request, TraineePackage $traineePackage): RedirectResponse
    {
        $this->authorize('view', $traineePackage->trainee);
        $this->authorize('packages.assign');

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            [],
            ['reason' => 'سبب الإلغاء'],
        );

        $this->packages->cancel($traineePackage, $data['reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'تم إلغاء الباقة.']);
    }

    /** The lesson ledger for one enrolment — the audit view of the balance. */
    public function transactions(Request $request, TraineePackage $traineePackage): View
    {
        $this->authorize('view', $traineePackage->trainee);

        return view('admin.trainees.lesson-ledger', [
            'enrolment' => $traineePackage->load('trainee'),
            'summary' => $this->balances->summary($traineePackage),
            'transactions' => $traineePackage->lessonTransactions()
                ->with(['creator:id,name', 'trainingSession:id,uuid,scheduled_date'])
                ->latest('id')
                ->paginate(30),
        ]);
    }
}
