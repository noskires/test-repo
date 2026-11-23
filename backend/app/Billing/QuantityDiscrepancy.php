<?php

namespace App\Billing;

use OverflowException;

/**
 * Marks how a reported (actual) quantity compares with a Work Order line's approved quantity (issue 21): the
 * difference (actual - approved), its exact amount at the line's unit price (millicentavos, ADR 0002, never rounded)
 * and a marker: over, under or equal. Quantities above the approved one are allowed; this only makes them visible.
 */
final class QuantityDiscrepancy
{
    public const string OVER = 'over';

    public const string UNDER = 'under';

    public const string EQUAL = 'equal';

    /**
     * @return array{approved_quantity: string, actual_quantity: ?string, difference: ?string, difference_amount_exact: ?string, discrepancy: ?string}
     */
    public static function describe(int $approvedThousandths, ?int $actualThousandths, int $unitPriceCentavos): array
    {
        if ($actualThousandths === null) {
            return [
                'approved_quantity' => BoqQuantity::fromThousandths($approvedThousandths),
                'actual_quantity' => null,
                'difference' => null,
                'difference_amount_exact' => null,
                'discrepancy' => null,
            ];
        }

        $difference = $actualThousandths - $approvedThousandths;

        try {
            $amount = BoqQuantity::lineTotalMillicentavos(abs($difference), $unitPriceCentavos);
            $differenceAmount = PesoAmount::fromSignedMillicentavos($difference < 0 ? -$amount : $amount);
        } catch (OverflowException) {
            $differenceAmount = null;
        }

        return [
            'approved_quantity' => BoqQuantity::fromThousandths($approvedThousandths),
            'actual_quantity' => BoqQuantity::fromThousandths($actualThousandths),
            'difference' => BoqQuantity::fromSignedThousandths($difference),
            'difference_amount_exact' => $differenceAmount,
            'discrepancy' => self::marker($difference),
        ];
    }

    public static function marker(int $differenceThousandths): string
    {
        return match (true) {
            $differenceThousandths > 0 => self::OVER,
            $differenceThousandths < 0 => self::UNDER,
            default => self::EQUAL,
        };
    }
}
