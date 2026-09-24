<?php

namespace App\Http\Requests;

use App\Models\TrainingSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTrainingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TrainingSession::class);
    }

    public function rules(): array
    {
        return [
            'trainee_id' => ['required', 'integer', Rule::exists('trainees', 'id')->whereNull('deleted_at')],
            'trainer_id' => ['required', 'integer', Rule::exists('trainers', 'id')->whereNull('deleted_at')],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'scheduled_date' => ['required', 'date', 'after_or_equal:'.now()->subYear()->toDateString()],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:300'],
        ];
    }

    public function attributes(): array
    {
        return [
            'trainee_id' => 'المتدرب',
            'trainer_id' => 'المدرب',
            'vehicle_id' => 'المركبة',
            'scheduled_date' => 'تاريخ الحصة',
            'start_time' => 'وقت البداية',
            'duration_minutes' => 'مدة الحصة',
        ];
    }

    public function messages(): array
    {
        return [
            'start_time.date_format' => 'صيغة الوقت غير صحيحة. استخدم الصيغة 24 ساعة مثل 09:30.',
        ];
    }
}
