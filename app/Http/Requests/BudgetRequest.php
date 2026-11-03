<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class BudgetRequest extends ApiRequest
{
    public function rules(): array
    {
        $budget = $this->route('budget');

        return [
            'category' => [$this->req(), 'string', 'max:100',
                Rule::unique('budgets')
                    ->where('period', $this->input('period', $budget?->period))
                    ->ignore($budget)],
            'allocated' => [$this->req(), 'numeric', 'gte:0', 'max:999999999999'],
            'period' => [$this->req(), 'date_format:Y-m'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return ['category.unique' => 'A budget for this category already exists in that period.'];
    }
}
