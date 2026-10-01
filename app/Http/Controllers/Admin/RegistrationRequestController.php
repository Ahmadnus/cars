<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RegistrationRequest;
use App\Models\Trainer;
use App\Services\RegistrationService;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The join-request queue.
 *
 * The screen a notification links to. Approving is the only path from a public
 * request into the training records, so it sits behind `registrations.manage`
 * while merely reading the queue needs only `registrations.view` — a supervisor
 * can see what is coming without being able to let someone in.
 */
class RegistrationRequestController extends Controller
{
    public function __construct(protected RegistrationService $registrations)
    {
    }

    public function index(Request $request, BranchContext $branches): View
    {
        $requests = RegistrationRequest::query()
            ->with('branch:id,uuid,name', 'reviewer:id,name', 'trainee:id,uuid,trainee_number', 'documents')
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('registration_requests.status', $request->input('status')),
                // Default to what still needs a decision: the queue is a to-do
                // list, not an archive.
                fn ($q) => $q->open(),
            )
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->input('q'));

                $q->where(function ($inner) use ($term) {
                    $inner->where('full_name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('reference', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.registrations.index', [
            'requests' => $requests,
            'statuses' => RegistrationRequest::statuses(),
            'openCount' => RegistrationRequest::open()->count(),
            'branches' => Branch::where('status', 'active')->orderBy('name')->get(['id', 'uuid', 'name']),
            'trainers' => Trainer::where('branch_id', $branches->currentId())
                ->where('status', 'active')
                ->orderBy('full_name')
                ->get(['id', 'uuid', 'full_name']),
        ]);
    }

    /**
     * How many requests are still open.
     *
     * Deliberately just a number: the queue page polls this every ten seconds to
     * notice an arrival, and returning the rows would mean sending the whole
     * visible list — with the applicants' personal details — on every tick.
     */
    public function count(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['open' => RegistrationRequest::open()->count()]);
    }

    public function approve(Request $request, RegistrationRequest $registrationRequest): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'trainer_id' => ['nullable', 'exists:trainers,id'],
            'create_login' => ['nullable', 'boolean'],
        ], [], ['branch_id' => 'الفرع', 'trainer_id' => 'المدرب']);

        $result = $this->registrations->approve(
            $registrationRequest,
            $request->user(),
            array_filter([
                'branch_id' => $data['branch_id'] ?? null,
                'trainer_id' => $data['trainer_id'] ?? null,
            ], static fn ($value) => $value !== null),
            $request->boolean('create_login', true),
        );

        $message = "تم قبول الطلب وإنشاء ملف المتدرب برقم {$result['trainee']->trainee_number}.";

        $redirect = redirect()
            ->route('admin.registrations.index')
            ->with('toast', ['type' => 'success', 'message' => $message]);

        // The password is never stored readable, so it is flashed for one render
        // and shown with copy buttons — the receptionist sends it on WhatsApp
        // within seconds of approving, and cannot come back for it later.
        if ($result['password']) {
            $redirect->with('issued_credentials', [
                'reference' => $registrationRequest->reference,
                'name' => $result['trainee']->full_name,
                'phone' => $result['trainee']->user?->phone ?? $result['trainee']->phone,
                'email' => $result['trainee']->user?->email,
                'password' => $result['password'],
            ]);
        }

        return $redirect;
    }

    public function reject(Request $request, RegistrationRequest $registrationRequest): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [], ['reason' => 'سبب الرفض']);

        $this->registrations->reject($registrationRequest, $request->user(), $data['reason']);

        return redirect()
            ->route('admin.registrations.index')
            ->with('toast', ['type' => 'success', 'message' => 'تم رفض الطلب وإبلاغ مقدّمه.']);
    }
}
