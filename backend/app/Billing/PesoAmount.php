<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * Converts between peso amounts (as entered or displayed) and integer centavos without float arithmetic.
 */
final class PesoAmount
{
    /**
     * A non-negative peso amount with at most two decimal places: no sign, no exponent, no bare decimal point.
     */
    public const string PATTERN = '/^(\d{1,15})(?:\.(\d{1,2}))?$/';

    /**
     * Parse a non-negative peso amount with at most two decimal places into centavos.
     */
    public static function toCentavos(string|int|float $pesos): int
    {
        $text = (string) $pesos;

        if (preg_match(self::PATTERN, $text, $matches) !== 1) {
            throw new InvalidArgumentException("[{$text}] is not a non-negative peso amount with at most two decimal places.");
        }

        return ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    /**
     * Format centavos as a peso amount with exactly two decimal places, e.g. "200000.00".
     */
    public static function fromCentavos(int $centavos): string
    {
        return intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format millicentavos (thousandths of a centavo) as an exact peso amount with five decimal places, e.g. "308.62500".
     */
    public static function fromMillicentavos(int $millicentavos): string
    {
        return intdiv($millicentavos, 100_000).'.'.str_pad((string) ($millicentavos % 100_000), 5, '0', STR_PAD_LEFT);
    }
}
