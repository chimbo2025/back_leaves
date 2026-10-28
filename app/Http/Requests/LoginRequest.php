<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'nullable', 'string'],
            'username' => ['sometimes', 'nullable', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('email') && ! $this->filled('username')) {
                $validator->errors()->add('email', 'Either an email address or username is required.');
            }
        });
    }
}
