<?php

namespace App\Http\Requests;

use App\Billing\BoqQuantity;
use App\Models\ECall;
use App\Models\PriceListItem;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The Site Vendor's FSR and BOQ for an E-Call. Lines must use the Site Vendor's own active Price List items.
 * The E-Call status is checked under a lock when the revision is stored (ECallProposal::submit).
 */
class StoreECallProposalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('submitProposal', $this->eCall());
    }

    /**
     * Get the validation rules that apply to the request. The FSR follows the E-Call attachment rules and the
     * quantity precision comes from config/pss.php.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $extensions = implode(',', config('pss.e_call_attachments.extensions'));

        return [
            'fsr' => [
                'required',
                'file',
                'mimes:'.$extensions,
                'extensions:'.$extensions,
                'max:'.config('pss.e_call_attachments.max_kilobytes'),
            ],
            'assessment_notes' => ['required', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:'.config('pss.boq.max_lines')],
            'lines.*' => ['array:price_list_item_id,quantity'],
            'lines.*.price_list_item_id' => [
                'bail',
                'required',
                'integer',
                'distinct',
                Rule::exists('price_list_items', 'id')
                    ->where('vendor_id', $this->eCall()->vendor_id)
                    ->where('is_active', true),
            ],
            'lines.*.quantity' => [
                'bail',
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $decimalPlaces = $this->quantityDecimalPlaces();

                    if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                        $fail("The quantity must be a number with at most {$decimalPlaces} decimal places.");

                        return;
                    }

                    try {
                        $thousandths = BoqQuantity::toThousandths($value, $decimalPlaces);
                    } catch (InvalidArgumentException) {
                        $fail("The quantity must be a number with at most {$decimalPlaces} decimal places.");

                        return;
                    }

                    if ($thousandths === 0) {
                        $fail('The quantity must be greater than zero.');
                    }
                },
            ],
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
            'lines.*.price_list_item_id.exists' => "The selected item is not an active item on the Site Vendor's Price List.",
            'lines.*.price_list_item_id.distinct' => 'Each Price List item may appear on only one line.',
        ];
    }

    public function eCall(): ECall
    {
        /** @var ECall */
        return $this->route('eCall');
    }

    public function fsr(): UploadedFile
    {
        return $this->file('fsr');
    }

    /**
     * The validated lines with their Price List items and quantities in thousandths.
     *
     * @return list<array{item: PriceListItem, quantity_thousandths: int}>
     */
    public function boqLines(): array
    {
        $lines = $this->validated('lines');
        $items = PriceListItem::query()->findMany(array_column($lines, 'price_list_item_id'))->keyBy('id');

        return array_map(fn (array $line): array => [
            'item' => $items->get((int) $line['price_list_item_id']),
            'quantity_thousandths' => BoqQuantity::toThousandths($line['quantity'], $this->quantityDecimalPlaces()),
        ], array_values($lines));
    }

    private function quantityDecimalPlaces(): int
    {
        return (int) config('pss.boq.quantity_decimal_places');
    }
}
