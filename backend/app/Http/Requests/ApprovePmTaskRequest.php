<?php

namespace App\Http\Requests;

use App\Models\PmTask;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The EIC approves a PM Report. Remarks are optional. The Work Done Date is not editable here.
 */
class ApprovePmTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('validate', $this->pmTask());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function pmTask(): PmTask
    {
        /** @var PmTask */
        return $this->route('pmTask');
    }

    public function remarks(): ?string
    {
        $remarks = $this->validated('remarks');

        return is_string($remarks) && trim($remarks) !== '' ? $remarks : null;
    }
}
