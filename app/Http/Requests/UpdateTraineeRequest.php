<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpdateTraineeRequest extends StoreTraineeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('trainee'));
    }

    public function rules(): array
    {
        $trainee = $this->route('trainee');

        return array_merge(parent::rules(), [
            'national_id' => [
                'nullable', 'string', 'max:30', 'regex:/^[0-9]{6,20}$/',
                Rule::unique('trainees', 'national_id')->ignore($trainee->id)->whereNull('deleted_at'),
            ],
            'exam_date' => ['nullable', 'date'],
        ]);
    }
}
