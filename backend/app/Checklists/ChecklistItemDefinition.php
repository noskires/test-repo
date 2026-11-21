<?php

namespace App\Checklists;

use App\Enums\ChecklistResponseType;

/**
 * One Checklist Item as described in a checklist definition. Numeric criteria are normalised decimal strings.
 */
final readonly class ChecklistItemDefinition
{
    public function __construct(
        public string $label,
        public ChecklistResponseType $responseType,
        public bool $mandatory,
        public bool $remarksRequiredOnFail,
        public bool $photoRequired,
        public ?string $acceptableMin,
        public ?string $acceptableMax,
        public ?string $acceptableCriteria,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $checklistKey, int $position, array $data): self
    {
        $label = $data['label'] ?? null;
        if (! is_string($label) || trim($label) === '') {
            throw InvalidChecklistDefinition::because($checklistKey, "item {$position} needs a label.");
        }

        $responseType = ChecklistResponseType::tryFrom((string) ($data['response_type'] ?? ''));
        if ($responseType === null) {
            throw InvalidChecklistDefinition::because($checklistKey, "item [{$label}] has an unknown response type.");
        }

        $acceptableMin = self::decimalOrNull($checklistKey, $label, 'acceptable_min', $data['acceptable_min'] ?? null);
        $acceptableMax = self::decimalOrNull($checklistKey, $label, 'acceptable_max', $data['acceptable_max'] ?? null);

        if (($acceptableMin !== null || $acceptableMax !== null) && $responseType !== ChecklistResponseType::Numeric) {
            throw InvalidChecklistDefinition::because($checklistKey, "item [{$label}] has a numeric min or max but is not a numeric item.");
        }

        if ($acceptableMin !== null && $acceptableMax !== null && (float) $acceptableMin > (float) $acceptableMax) {
            throw InvalidChecklistDefinition::because($checklistKey, "item [{$label}] has an acceptable min above its max.");
        }

        $acceptableCriteria = $data['acceptable_criteria'] ?? null;
        if ($acceptableCriteria !== null && ! is_string($acceptableCriteria)) {
            throw InvalidChecklistDefinition::because($checklistKey, "item [{$label}] has acceptable criteria that are not text.");
        }

        return new self(
            label: trim($label),
            responseType: $responseType,
            mandatory: self::flag($checklistKey, $label, 'mandatory', $data),
            remarksRequiredOnFail: self::flag($checklistKey, $label, 'remarks_required_on_fail', $data),
            photoRequired: self::flag($checklistKey, $label, 'photo_required', $data),
            acceptableMin: $acceptableMin,
            acceptableMax: $acceptableMax,
            acceptableCriteria: $acceptableCriteria,
        );
    }

    /**
     * @return array{label: string, response_type: string, mandatory: bool, remarks_required_on_fail: bool, photo_required: bool, acceptable_min: ?string, acceptable_max: ?string, acceptable_criteria: ?string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'response_type' => $this->responseType->value,
            'mandatory' => $this->mandatory,
            'remarks_required_on_fail' => $this->remarksRequiredOnFail,
            'photo_required' => $this->photoRequired,
            'acceptable_min' => $this->acceptableMin,
            'acceptable_max' => $this->acceptableMax,
            'acceptable_criteria' => $this->acceptableCriteria,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function flag(string $checklistKey, string $label, string $name, array $data): bool
    {
        $value = $data[$name] ?? false;

        if (! is_bool($value)) {
            throw InvalidChecklistDefinition::because($checklistKey, "item [{$label}] flag [{$name}] must be true or false.");
        }

        return $value;
    }

    /**
     * Normalise a numeric criterion to a plain decimal string ("12.50" and 12.5 both become "12.5").
     */
    private static function decimalOrNull(string $checklistKey, string $label, string $name, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_int($value) || is_float($value) || is_string($value) ? (string) $value : '';

        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/', $text, $matches) !== 1) {
            throw InvalidChecklistDefinition::because($checklistKey, "item [{$label}] [{$name}] must be a number.");
        }

        $fraction = rtrim($matches[2] ?? '', '0');

        return $fraction === '' ? $matches[1] : "{$matches[1]}.{$fraction}";
    }
}
