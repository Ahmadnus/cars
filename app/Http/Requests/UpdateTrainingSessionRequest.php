<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTrainingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('session'));
    }

    public function rules(): array
    {
        return [
            'trainer_id' => ['nullable', 'integer', Rule::exists('trainers', 'id')->whereNull('deleted_at')],
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:300'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'trainer_id' => 'المدرب',
            'vehicle_id' => 'المركبة',
            'scheduled_date' => 'تاريخ الحصة',
            'start_time' => 'وقت البداية',
            'duration_minutes' => 'مدة الحصة',
            'reason' => 'سبب التعديل',
        ];
    }
}
