<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name' => [$this->req(), 'string', 'max:255'],
            'email' => [$this->req(), 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$this->req(), Password::min(8)],
            'isActive' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }
}
