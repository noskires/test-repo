<?php

namespace App\Http\Requests;

use App\MasterData\Data\Employee;
use App\MasterData\MasterDataDirectory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSiteEicRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-site-settings');
    }

    /**
     * Get the validation rules that apply to the request. The EIC must be an employee the directory knows.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => [
                'bail',
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->directory()->findEmployee($value) === null) {
                        $fail('The selected employee is not known to the employee directory.');
                    }
                },
            ],
        ];
    }

    public function engineer(): Employee
    {
        return $this->directory()->findEmployee($this->validated('employee_id'));
    }

    private function directory(): MasterDataDirectory
    {
        return $this->container->make(MasterDataDirectory::class);
    }
}
