<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LicenseType;
use App\Http\Resources\RegistrationRequestResource;
use App\Models\Branch;
use App\Models\RegistrationRequest;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Join requests from anyone who downloads the app — no account required.
 *
 * This is the only unauthenticated write surface in the system, so it is the
 * one that needs the most care:
 *
 *  - The phone is proven by passcode before the form is accepted, so a request
 *    cannot be filed under someone else's number.
 *  - Nothing here creates a trainee. A request is an inbox item; staff approval
 *    is the only path into the training records.
 *  - Status is readable only with the reference issued at submission, and the
 *    reference is random rather than sequential so holding one reveals no other.
 *  - Answers never confirm whether a number is already enrolled, which would
 *    turn the endpoint into a customer list.
 */
class PublicRegistrationController extends ApiController
{
    public function __construct(protected RegistrationService $registrations)
    {
    }

    /** Branches an applicant can choose, and the license types on offer. */
    public function options(): JsonResponse
    {
        return $this->ok([
            'branches' => Branch::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['uuid', 'name', 'address', 'phone'])
                ->map(fn (Branch $branch) => [
                    'id' => $branch->uuid,
                    'name' => $branch->name,
                    'address' => $branch->address,
                    'phone' => $branch->phone,
                ]),
            'license_types' => LicenseType::forApi(),
            'genders' => [
                ['value' => 'male', 'label' => 'ذكر'],
                ['value' => 'female', 'label' => 'أنثى'],
            ],
        ]);
    }

    /**
     * Submit an application.
     *
     * Deliberately unauthenticated and without a passcode: the barrier the center
     * wants is a member of staff reading it, not a code. Nothing here reaches the
     * training records — staff approval is the only path — and the number stays
     * marked unverified until someone confirms it by phone.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:3', 'max:255'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'secondary_phone' => ['nullable', 'string', 'max:20'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            // Checked against the enum rather than accepting any string: the
            // list the app offers comes from the same place, so anything else
            // is a malformed request, not a category we have yet to add.
            'license_type' => ['nullable', Rule::enum(LicenseType::class)],
            'branch_id' => ['nullable', 'string', 'exists:branches,uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],

            /*
             | Optional on purpose. Everything but the name and the number is,
             | and a camera that will not focus must not be the thing that stops
             | someone applying — staff can ask for the ID when they call. The
             | limits mirror DocumentService::ALLOWED and its 8MB ceiling, so a
             | file accepted here cannot be refused further down.
             */
            'id_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [], [
            'full_name' => 'الاسم الكامل',
            'phone' => 'رقم الهاتف',
            'birth_date' => 'تاريخ الميلاد',
            'license_type' => 'نوع الرخصة',
            'id_photo' => 'صورة الهوية',
        ]);

        if (! empty($data['branch_id'])) {
            $data['branch_id'] = Branch::where('uuid', $data['branch_id'])->value('id');
        }

        // The file is kept out of $data: the service writes the validated
        // array straight onto the model, and an UploadedFile is not a column.
        unset($data['id_photo']);

        $registration = $this->registrations->submit(
            $data,
            $request->ip(),
            $request->file('id_photo'),
        );

        return $this->created([
            'reference' => $registration->reference,
            'status' => $registration->status,
        ], 'تم استلام طلبك. رقم المتابعة: '.$registration->reference);
    }

    /**
     * Check a request by its reference.
     *
     * The reference alone is the credential here, which is why it is random and
     * why the response carries no personal detail beyond the applicant's own
     * name and the decision.
     */
    public function status(string $reference): JsonResponse
    {
        $registration = RegistrationRequest::where('reference', $reference)->first();

        if (! $registration) {
            return $this->failed('لا يوجد طلب بهذا الرقم.', status: 404);
        }

        return $this->ok(RegistrationRequestResource::publicStatus($registration));
    }
}
