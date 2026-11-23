<?php

namespace Tests\Unit\Billing;

use App\Billing\BoqQuantity;
use App\Billing\PesoAmount;
use App\Billing\QuantityDiscrepancy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantityDiscrepancyTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int, array<string, mixed>}>
     */
    public static function lines(): array
    {
        return [
            'over' => [2_500, 2_750, 12_345, [
                'approved_quantity' => '2.500',
                'actual_quantity' => '2.750',
                'difference' => '0.250',
                'difference_amount_exact' => '30.86250',
                'discrepancy' => 'over',
            ]],
            'under' => [4_000, 1_500, 101, [
                'approved_quantity' => '4.000',
                'actual_quantity' => '1.500',
                'difference' => '-2.500',
                'difference_amount_exact' => '-2.52500',
                'discrepancy' => 'under',
            ]],
            'equal' => [1_000, 1_000, 50_000, [
                'approved_quantity' => '1.000',
                'actual_quantity' => '1.000',
                'difference' => '0.000',
                'difference_amount_exact' => '0.00000',
                'discrepancy' => 'equal',
            ]],
            'under by a fraction of a centavo' => [1_000, 999, 1, [
                'approved_quantity' => '1.000',
                'actual_quantity' => '0.999',
                'difference' => '-0.001',
                'difference_amount_exact' => '-0.00001',
                'discrepancy' => 'under',
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('lines')]
    public function test_it_describes_a_line(int $approved, int $actual, int $unitPrice, array $expected): void
    {
        $this->assertSame($expected, QuantityDiscrepancy::describe($approved, $actual, $unitPrice));
    }

    public function test_a_line_with_no_reported_quantity_has_no_discrepancy(): void
    {
        $this->assertSame([
            'approved_quantity' => '2.500',
            'actual_quantity' => null,
            'difference' => null,
            'difference_amount_exact' => null,
            'discrepancy' => null,
        ], QuantityDiscrepancy::describe(2_500, null, 12_345));
    }

    public function test_an_amount_too_large_to_store_exactly_is_null_but_the_marker_stays(): void
    {
        $described = QuantityDiscrepancy::describe(1_000, 9_999_999_999, 99_999_999_999_999);

        $this->assertNull($described['difference_amount_exact']);
        $this->assertSame('over', $described['discrepancy']);
    }

    public function test_signed_formatting(): void
    {
        $this->assertSame('-2.500', BoqQuantity::fromSignedThousandths(-2_500));
        $this->assertSame('0.250', BoqQuantity::fromSignedThousandths(250));
        $this->assertSame('-0.00001', PesoAmount::fromSignedMillicentavos(-1));
        $this->assertSame('308.62500', PesoAmount::fromSignedMillicentavos(30_862_500));
    }
}
