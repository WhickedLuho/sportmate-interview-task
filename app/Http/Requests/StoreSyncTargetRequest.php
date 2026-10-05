<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSyncTargetRequest extends FormRequest
{
    /**
     * GitHub logins: 1-39 characters, letters, digits and single hyphens,
     * neither starting nor ending with a hyphen.
     */
    private const LOGIN_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/';

    /**
     * Normalize the target name before format and uniqueness validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => strtolower(ltrim(trim((string) $this->input('name')), '@')),
        ]);
    }

    /**
     * Define target-name validation and uniqueness within the signed-in user.
     *
     * @return array<string, array<int, mixed>> Validation rules keyed by input field.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'regex:'.self::LOGIN_PATTERN,
                Rule::unique('sync_targets', 'name')->where('user_id', $this->user()->id),
            ],
        ];
    }

    /**
     * Provide readable messages for invalid or already tracked GitHub names.
     *
     * @return array<string, string> Custom validation messages keyed by rule.
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Enter a valid GitHub username or organization name (letters, numbers and single hyphens, up to 39 characters).',
            'name.unique' => 'You are already tracking this GitHub account.',
        ];
    }
}
