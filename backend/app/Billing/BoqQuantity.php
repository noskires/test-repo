<?php

namespace App\Billing;

use InvalidArgumentException;
use OverflowException;

/**
 * Exact BOQ arithmetic without floats. Quantities are stored as integer thousandths, unit prices as integer
 * centavos, so a line total (quantity x unit price) is an exact integer number of millicentavos
 * (thousandths of a centavo). Line totals are never rounded. Only the BOQ total also gets a rounded value,
 * half-up to whole centavos.
 */
final class BoqQuantity
{
    /**
     * Decimal places of the stored quantity. The configured precision may be lower, never higher.
     */
    public const int STORAGE_SCALE = 3;

    /**
     * Largest number of digits before the decimal point.
     */
    public const int MAX_INTEGER_DIGITS = 7;

    /**
     * Parse a non-negative quantity with at most $decimalPlaces decimals into thousandths.
     */
    public static function toThousandths(string|int|float $quantity, int $decimalPlaces): int
    {
        if ($decimalPlaces < 0 || $decimalPlaces > self::STORAGE_SCALE) {
            throw new InvalidArgumentException('Quantities support between 0 and '.self::STORAGE_SCALE.' decimal places.');
        }

        $text = (string) $quantity;

        if (preg_match(self::pattern($decimalPlaces), $text, $matches) !== 1) {
            throw new InvalidArgumentException("[{$text}] is not a non-negative quantity with at most {$decimalPlaces} decimal places.");
        }

        return ((int) $matches[1]) * 1_000 + (int) str_pad($matches[2] ?? '', self::STORAGE_SCALE, '0');
    }

    /**
     * The validation pattern for a quantity with at most $decimalPlaces decimals.
     */
    public static function pattern(int $decimalPlaces): string
    {
        $fraction = $decimalPlaces === 0 ? '' : '(?:\.(\d{1,'.$decimalPlaces.'}))?';

        return '/^(\d{1,'.self::MAX_INTEGER_DIGITS.'})'.$fraction.'$/';
    }

    /**
     * Format thousandths as a quantity with exactly three decimal places, e.g. "2.500".
     */
    public static function fromThousandths(int $thousandths): string
    {
        return intdiv($thousandths, 1_000).'.'.str_pad((string) ($thousandths % 1_000), self::STORAGE_SCALE, '0', STR_PAD_LEFT);
    }

    /**
     * The exact line total in millicentavos. Throws when it does not fit in a 64-bit integer.
     */
    public static function lineTotalMillicentavos(int $quantityThousandths, int $unitPriceCentavos): int
    {
        return self::toStorableInteger(bcmul((string) $quantityThousandths, (string) $unitPriceCentavos, 0));
    }

    /**
     * The exact sum of line totals in millicentavos. Throws when it does not fit in a 64-bit integer.
     *
     * @param  list<int>  $millicentavos
     */
    public static function sumMillicentavos(array $millicentavos): int
    {
        $total = '0';

        foreach ($millicentavos as $amount) {
            $total = bcadd($total, (string) $amount, 0);
        }

        return self::toStorableInteger($total);
    }

    /**
     * Round an exact, non-negative amount in millicentavos to whole centavos, half-up (0.5 centavo rounds up).
     * Only the BOQ total is rounded. Line totals stay exact.
     */
    public static function roundHalfUpToCentavos(int $millicentavos): int
    {
        if ($millicentavos < 0) {
            throw new InvalidArgumentException('Only non-negative amounts can be rounded.');
        }

        return intdiv($millicentavos, 1_000) + ($millicentavos % 1_000 >= 500 ? 1 : 0);
    }

    private static function toStorableInteger(string $exact): int
    {
        if (bccomp($exact, (string) PHP_INT_MAX, 0) === 1) {
            throw new OverflowException("[{$exact}] millicentavos is too large to store exactly.");
        }

        return (int) $exact;
    }
}
