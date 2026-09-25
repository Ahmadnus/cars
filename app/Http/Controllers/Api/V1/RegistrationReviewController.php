<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\RegistrationRequestResource;
use App\Models\Branch;
use App\Models\RegistrationRequest;
use App\Models\Trainer;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff side of self-registration.
 *
 * Every route is gated by `registrations.view` or `registrations.manage`, so
 * deciding who may let a stranger into the training records is a role question
 * rather than something hard-coded here.
 */
class RegistrationReviewController extends ApiController
{
    public function __construct(protected RegistrationService $registrations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $requests = RegistrationRequest::query()
            ->with('branch:id,uuid,name', 'trainee:id,uuid,trainee_number')
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('registration_requests.status', $request->input('status')),
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
            ->paginate($this->perPage());

        return $this->paginated($requests, RegistrationRequestResource::class, meta: [
            'open_count' => RegistrationRequest::open()->count(),
        ]);
    }

    public function show(RegistrationRequest $registrationRequest): JsonResponse
    {
        return $this->ok(
            new RegistrationRequestResource(
                $registrationRequest->load('branch:id,uuid,name', 'reviewer:id,name', 'trainee:id,uuid,trainee_number'),
            ),
        );
    }

    /** Claim a request, so two people do not review the same one. */
    public function claim(Request $request, RegistrationRequest $registrationRequest): JsonResponse
    {
        $updated = $this->registrations->markReviewing($registrationRequest, $request->user());

        return $this->ok(new RegistrationRequestResource($updated), 'الطلب الآن قيد مراجعتك.');
    }

    /**
     * Accept the request and create the trainee.
     *
     * Staff may correct what the applicant typed on the way through — people
     * mistype their own national id — so the corrections are validated here and
     * applied by the service in the same transaction as the approval.
     */
    public function approve(Request $request, RegistrationRequest $registrationRequest): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'string', 'exists:branches,uuid'],
            'trainer_id' => ['nullable', 'string', 'exists:trainers,uuid'],
            'full_name' => ['nullable', 'string', 'min:3', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'license_type' => ['nullable', 'string', 'max:30'],
            'create_login' => ['nullable', 'boolean'],
        ], [], [
            'branch_id' => 'الفرع',
            'trainer_id' => 'المدرب',
        ]);

        $overrides = array_filter([
            'branch_id' => isset($data['branch_id'])
                ? Branch::where('uuid', $data['branch_id'])->value('id')
                : null,
            'trainer_id' => isset($data['trainer_id'])
                ? Trainer::where('uuid', $data['trainer_id'])->value('id')
                : null,
            'full_name' => $data['full_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'license_type' => $data['license_type'] ?? null,
        ], static fn ($value) => $value !== null);

        $result = $this->registrations->approve(
            $registrationRequest,
            $request->user(),
            $overrides,
            (bool) ($data['create_login'] ?? true),
        );

        return $this->ok([
            'trainee' => [
                'id' => $result['trainee']->uuid,
                'trainee_number' => $result['trainee']->trainee_number,
                'full_name' => $result['trainee']->full_name,
            ],
            // Shown once and never stored in readable form. The applicant can
            // also sign in by passcode, so this is a convenience, not the key.
            'login' => $result['password'] === null ? null : [
                'email' => $result['trainee']->user?->email,
                'password' => $result['password'],
            ],
        ], 'تم قبول الطلب وإنشاء ملف المتدرب.');
    }

    public function reject(Request $request, RegistrationRequest $registrationRequest): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [], ['reason' => 'سبب الرفض']);

        $updated = $this->registrations->reject(
            $registrationRequest,
            $request->user(),
            $data['reason'],
        );

        return $this->ok(new RegistrationRequestResource($updated), 'تم رفض الطلب.');
    }
}
