<?php

namespace App\Http\Requests;

use App\Enums\PssRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GrantUserRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-roles');
    }

    /**
     * Get the validation rules that apply to the request. Only the stored PSS Roles can be granted.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(PssRole::class)],
        ];
    }

    /**
     * A pending user can't be granted any role until they are classified (issue 46, Q7), and a vendor-linked user can't
     * be a System Administrator (issue 45, Q2).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->route('user');

                if ($user instanceof User && $user->isPending()) {
                    $validator->errors()->add('role', 'Classify this user first (internal or a Vendor) before granting roles.');
                }

                if ($user instanceof User && $user->vendor_id !== null
                    && $this->input('role') === PssRole::SystemAdministrator->value) {
                    $validator->errors()->add('role', 'A user linked to a Vendor cannot be a System Administrator. Unlink the Vendor first.');
                }
            },
        ];
    }
}
