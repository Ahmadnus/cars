<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\RegistrationRequestResource;
use App\Models\Branch;
use App\Models\RegistrationRequest;
use App\Services\OtpService;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function __construct(
        protected RegistrationService $registrations,
        protected OtpService $otp,
    ) {
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
            'license_types' => [
                ['value' => 'private', 'label' => 'خصوصي'],
                ['value' => 'motorcycle', 'label' => 'دراجة نارية'],
                ['value' => 'light_truck', 'label' => 'شحن خفيف'],
                ['value' => 'heavy_truck', 'label' => 'شحن ثقيل'],
                ['value' => 'public', 'label' => 'عمومي'],
            ],
            'genders' => [
                ['value' => 'male', 'label' => 'ذكر'],
                ['value' => 'female', 'label' => 'أنثى'],
            ],
        ]);
    }

    /**
     * Send a passcode to the applicant's phone.
     *
     * A separate purpose from login, so a code issued to join cannot be replayed
     * to sign in to an existing account, or the reverse.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:9', 'max:20'],
        ], [], ['phone' => 'رقم الهاتف']);

        $phone = $this->otp->normalisePhone($data['phone']);

        $wait = $this->otp->cooldownFor($phone, 'registration');

        if ($wait > 0) {
            return $this->failed(
                'تم إرسال رمز حديثاً. حاول بعد '.$wait.' ثانية.',
                ['phone' => ['انتظر قليلاً قبل طلب رمز جديد.']],
                429,
            );
        }

        if ($this->otp->issuedLastHour($phone, 'registration') >= (int) config('otp.hourly_limit', 5)) {
            return $this->failed(
                'تم تجاوز عدد الرموز المسموح بها في الساعة. حاول لاحقاً.',
                status: 429,
            );
        }

        $result = $this->otp->requestForPhone($phone, $request->ip(), 'registration');

        return $this->ok([
            'expires_in' => $result['expires_in'],
            'channel' => $result['channel'],
            'code' => $result['code'],
        ], 'تم إرسال رمز التحقق إلى رقم هاتفك.');
    }

    /** Submit the request, proving the phone with the passcode just sent. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:8'],
            'full_name' => ['required', 'string', 'min:3', 'max:255'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'secondary_phone' => ['nullable', 'string', 'max:20'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'license_type' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', 'string', 'exists:branches,uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'code' => 'رمز التحقق',
            'full_name' => 'الاسم الكامل',
            'phone' => 'رقم الهاتف',
            'birth_date' => 'تاريخ الميلاد',
        ]);

        if (! empty($data['branch_id'])) {
            $data['branch_id'] = Branch::where('uuid', $data['branch_id'])->value('id');
        }

        $registration = $this->registrations->submit(
            $data,
            (string) $data['code'],
            $request->ip(),
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
