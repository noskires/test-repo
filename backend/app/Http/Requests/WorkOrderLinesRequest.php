<?php

namespace App\Http\Requests;

use App\Billing\BoqQuantity;
use App\Models\ECallProposalLine;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Shared validation for creating and editing a Work Order: at least one line, each picking a line of the approved
 * BOQ revision at most once, with 0 < quantity <= that BOQ line's quantity (precision from pss.boq), plus optional
 * attachments under the E-Call upload rules.
 */
abstract class WorkOrderLinesRequest extends FormRequest
{
    /** @var Collection<int, ECallProposalLine>|null */
    private ?Collection $boqLines = null;

    /**
     * The approved FSR + BOQ revision whose lines may be used.
     */
    abstract protected function approvedProposalId(): ?int;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $extensions = implode(',', config('pss.e_call_attachments.extensions'));

        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.config('pss.boq.max_lines')],
            'lines.*' => ['array:e_call_proposal_line_id,quantity'],
            'lines.*.e_call_proposal_line_id' => [
                'bail',
                'required',
                'integer',
                'distinct',
                Rule::exists('e_call_proposal_lines', 'id')->where('e_call_proposal_id', $this->approvedProposalId() ?? 0),
            ],
            'lines.*.quantity' => ['bail', 'required', $this->quantityRule()],
            'attachments' => ['sometimes', 'array', 'max:'.config('pss.e_call_attachments.max_files')],
            'attachments.*' => ['file', 'mimes:'.$extensions, 'extensions:'.$extensions, 'max:'.config('pss.e_call_attachments.max_kilobytes')],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.e_call_proposal_line_id.exists' => 'The selected line is not on the approved BOQ revision.',
            'lines.*.e_call_proposal_line_id.distinct' => 'Each BOQ line may appear only once on a Work Order.',
        ];
    }

    /**
     * The validated lines with their BOQ lines and quantities in thousandths.
     *
     * @return list<array{line: ECallProposalLine, quantity_thousandths: int}>
     */
    public function workOrderLines(): array
    {
        return array_map(fn (array $line): array => [
            'line' => $this->boqLines()->get((int) $line['e_call_proposal_line_id']),
            'quantity_thousandths' => BoqQuantity::toThousandths($line['quantity'], $this->quantityDecimalPlaces()),
        ], array_values($this->validated('lines')));
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return array_values($this->file('attachments', []));
    }

    private function quantityRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $decimalPlaces = $this->quantityDecimalPlaces();

            try {
                $thousandths = is_string($value) || is_int($value) || is_float($value)
                    ? BoqQuantity::toThousandths($value, $decimalPlaces)
                    : null;
            } catch (InvalidArgumentException) {
                $thousandths = null;
            }

            if ($thousandths === null) {
                $fail("The quantity must be a number with at most {$decimalPlaces} decimal places.");

                return;
            }

            if ($thousandths === 0) {
                $fail('The quantity must be greater than zero.');

                return;
            }

            $boqLineId = $this->input(str_replace('.quantity', '.e_call_proposal_line_id', $attribute));
            $boqLine = is_numeric($boqLineId) ? $this->boqLines()->get((int) $boqLineId) : null;

            if ($boqLine !== null && $thousandths > $boqLine->quantity_thousandths) {
                $fail('The quantity cannot be more than the approved BOQ quantity ('.BoqQuantity::fromThousandths($boqLine->quantity_thousandths).').');
            }
        };
    }

    /**
     * The approved revision's BOQ lines, keyed by ID.
     *
     * @return Collection<int, ECallProposalLine>
     */
    private function boqLines(): Collection
    {
        return $this->boqLines ??= ECallProposalLine::query()
            ->where('e_call_proposal_id', $this->approvedProposalId() ?? 0)
            ->get()
            ->keyBy('id');
    }

    private function quantityDecimalPlaces(): int
    {
        return (int) config('pss.boq.quantity_decimal_places');
    }
}
