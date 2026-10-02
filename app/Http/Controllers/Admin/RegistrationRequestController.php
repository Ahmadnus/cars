<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RegistrationRequest;
use App\Models\Trainer;
use App\Services\IdCardService;
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
    public function __construct(
        protected RegistrationService $registrations,
        protected IdCardService $cards,
    ) {
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
            // Decides whether the queue offers to read a card at all: with no
            // provider configured the button would only ever apologise.
            'canReadIds' => $this->cards->isEnabled(),
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

    /**
     * Have the attached ID photo read, so the queue shows what the card says.
     *
     * A deliberate action rather than something that happens on arrival: every
     * reading costs the center money, and most requests are decided from the
     * photo in a glance. The reviewer asks for one when the details matter — and
     * asks again, with the same button, when the applicant sends a better photo.
     *
     * Behind `registrations.manage` with the decisions rather than with viewing:
     * it spends money and writes to the record, which is not what a read-only
     * supervisor's permission promises.
     */
    public function scanId(RegistrationRequest $registrationRequest): RedirectResponse
    {
        $reading = $this->cards->forRegistration($registrationRequest, refresh: true);

        return back()->with('toast', $reading->succeeded()
            ? ['type' => 'success', 'message' => 'تم قراءة بيانات الهوية. راجعها قبل القبول.']
            : ['type' => 'error', 'message' => $reading->message ?? 'تعذّرت قراءة الهوية.']);
    }

    public function approve(Request $request, RegistrationRequest $registrationRequest): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'trainer_id' => ['nullable', 'exists:trainers,id'],
            'create_login' => ['nullable', 'boolean'],
            'use_scan' => ['nullable', 'boolean'],
        ], [], ['branch_id' => 'الفرع', 'trainer_id' => 'المدرب']);

        $overrides = array_filter([
            'branch_id' => $data['branch_id'] ?? null,
            'trainer_id' => $data['trainer_id'] ?? null,
        ], static fn ($value) => $value !== null);

        /*
         | The reviewer ticked "use what was read off the ID".
         |
         | The values are taken from the stored reading, not from the form: the
         | browser sends one checkbox, so nothing it can send puts a different
         | name on the trainee than the one the reviewer was shown.
         */
        if ($request->boolean('use_scan')) {
            $overrides += $this->readingOverrides($registrationRequest);
        }

        $result = $this->registrations->approve(
            $registrationRequest,
            $request->user(),
            $overrides,
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

    /**
     * The read fields a trainee record has somewhere to put.
     *
     * The city is read off the card and shown to the reviewer, but a trainee has
     * no city column — only an address — so it stays on the request where it was
     * read rather than being forced into a field it does not belong in.
     *
     * @return array<string, string>
     */
    protected function readingOverrides(RegistrationRequest $request): array
    {
        $reading = $request->idReading();

        if (! $reading) {
            return [];
        }

        return array_intersect_key($reading->fields(), array_flip([
            'full_name', 'national_id', 'birth_date', 'gender', 'address',
        ]));
    }
}
