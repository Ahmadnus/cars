<?php

namespace App\Http\Requests;

use App\Models\Trainee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTraineeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Trainee::class);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            // Jordanian mobile/landline shapes; kept permissive enough for
            // numbers entered with or without the country code.
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'secondary_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'national_id' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]{6,20}$/', Rule::unique('trainees', 'national_id')->whereNull('deleted_at')],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1920-01-01'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'address' => ['nullable', 'string', 'max:255'],
            'license_type' => ['required', 'string', 'max:40'],
            'registration_date' => ['required', 'date', 'before_or_equal:today'],
            'trainer_id' => ['nullable', 'integer', Rule::exists('trainers', 'id')->whereNull('deleted_at')],
            'branch_id' => ['nullable', 'integer', Rule::in($this->user()->accessibleBranchIds())],
            'status' => ['nullable', Rule::in([
                'new', 'in_training', 'suspended', 'ready_for_exam',
                'exam_scheduled', 'passed', 'failed', 'completed', 'cancelled',
            ])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ];
    }

    public function attributes(): array
    {
        return [
            'full_name' => 'الاسم الكامل',
            'phone' => 'رقم الهاتف',
            'secondary_phone' => 'رقم هاتف إضافي',
            'national_id' => 'الرقم الوطني',
            'birth_date' => 'تاريخ الميلاد',
            'gender' => 'الجنس',
            'address' => 'العنوان',
            'license_type' => 'نوع الرخصة',
            'registration_date' => 'تاريخ التسجيل',
            'trainer_id' => 'المدرب',
            'branch_id' => 'الفرع',
            'status' => 'الحالة',
            'notes' => 'ملاحظات',
            'photo' => 'الصورة الشخصية',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'صيغة رقم الهاتف غير صحيحة.',
            'secondary_phone.regex' => 'صيغة رقم الهاتف الإضافي غير صحيحة.',
            'national_id.regex' => 'الرقم الوطني يجب أن يتكون من أرقام فقط.',
            'national_id.unique' => 'هذا الرقم الوطني مسجل لمتدرب آخر.',
            'branch_id.in' => 'لا تملك صلاحية الوصول إلى هذا الفرع.',
        ];
    }
}
