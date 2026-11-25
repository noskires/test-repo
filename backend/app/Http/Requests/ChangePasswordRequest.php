<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in user changes their own password (issue 44). Any signed-in user may, including a pending one.
 */
class ChangePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request. The new password follows Laravel's default Password rule,
     * must be confirmed and must differ from the current one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:api'],
            'password' => [
                'required',
                'string',
                'max:255',
                'confirmed',
                Password::default(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $current = $this->user()?->getAuthPassword();
                    if (is_string($value) && is_string($current) && Hash::check($value, $current)) {
                        $fail('The new password must be different from your current password.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The current password is incorrect.',
        ];
    }
}
