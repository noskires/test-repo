<?php

namespace App\Checklists;

use App\Enums\ChecklistResponseType;

/**
 * Decides whether a PM Report response to a Checklist Item counts as failed. Numeric readings are compared
 * with the acceptable range as exact decimals (bcmath), never as floats.
 */
final class ChecklistResponseEvaluator
{
    /**
     * A plain signed decimal reading: up to 12 integer digits and 6 decimals, no exponent.
     */
    public const string NUMERIC_READING_PATTERN = '/^-?\d{1,12}(?:\.\d{1,6})?$/';

    /**
     * The answers each choice type accepts, with the failing answer last.
     *
     * @var array<string, array{string, string}>
     */
    public const array CHOICES = [
        'pass_fail' => ['pass', 'fail'],
        'good_defective' => ['good', 'defective'],
        'yes_no' => ['yes', 'no'],
    ];

    /**
     * fail / defective / no, or a numeric reading below the min or above the max (when set).
     * Text and photo responses, and missing responses, never count as failed.
     */
    public static function isFailed(ChecklistResponseType $type, ?string $value, ?string $acceptableMin, ?string $acceptableMax): bool
    {
        if ($value === null) {
            return false;
        }

        if (array_key_exists($type->value, self::CHOICES)) {
            return $value === self::CHOICES[$type->value][1];
        }

        if ($type !== ChecklistResponseType::Numeric || ! self::isNumericReading($value)) {
            return false;
        }

        $scale = 20;

        return ($acceptableMin !== null && bccomp($value, $acceptableMin, $scale) === -1)
            || ($acceptableMax !== null && bccomp($value, $acceptableMax, $scale) === 1);
    }

    public static function isNumericReading(string $value): bool
    {
        return preg_match(self::NUMERIC_READING_PATTERN, $value) === 1;
    }
}
