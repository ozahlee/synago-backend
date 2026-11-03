<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Base request: clients send camelCase (matching API responses);
 * payload() converts validated input to snake_case model attributes.
 * Authorization is handled by route middleware, so authorize() is always true.
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Validated input with snake_case keys, minus any keys listed in $except. */
    public function payload(array $except = []): array
    {
        return collect($this->validated())
            ->except($except)
            ->mapWithKeys(fn ($v, $k) => [Str::snake($k) => $v])
            ->all();
    }

    protected function isUpdate(): bool
    {
        return $this->isMethod('PUT') || $this->isMethod('PATCH');
    }

    /** "required" on create, "sometimes" on update (PATCH semantics). */
    protected function req(): string
    {
        return $this->isUpdate() ? 'sometimes' : 'required';
    }
}
