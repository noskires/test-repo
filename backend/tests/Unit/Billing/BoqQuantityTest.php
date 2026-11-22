<?php

namespace Tests\Unit\Billing;

use App\Billing\BoqQuantity;
use App\Billing\PesoAmount;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BoqQuantityTest extends TestCase
{
    /**
     * @return array<string, array{string|int|float, int}>
     */
    public static function parsableQuantities(): array
    {
        return [
            'whole number' => ['4', 4_000],
            'one decimal place' => ['2.5', 2_500],
            'three decimal places' => ['0.333', 333],
            'an integer' => [12, 12_000],
            'a JSON float is parsed without float arithmetic' => [1.005, 1_005],
            'largest quantity' => ['9999999.999', 9_999_999_999],
        ];
    }

    #[DataProvider('parsableQuantities')]
    public function test_it_converts_a_quantity_to_thousandths(string|int|float $quantity, int $expectedThousandths): void
    {
        $this->assertSame($expectedThousandths, BoqQuantity::toThousandths($quantity, 3));
    }

    /**
     * @return array<string, array{string|int|float, int}>
     */
    public static function unparsableQuantities(): array
    {
        return [
            'more decimals than allowed' => ['1.2345', 3],
            'more decimals than configured' => ['1.25', 1],
            'decimals when none are allowed' => ['1.5', 0],
            'negative' => ['-1', 3],
            'exponent' => ['1e3', 3],
            'bare decimal point' => ['1.', 3],
            'too many integer digits' => ['10000000', 3],
            'text' => ['abc', 3],
        ];
    }

    #[DataProvider('unparsableQuantities')]
    public function test_it_rejects_a_malformed_quantity(string|int|float $quantity, int $decimalPlaces): void
    {
        $this->expectException(InvalidArgumentException::class);

        BoqQuantity::toThousandths($quantity, $decimalPlaces);
    }

    public function test_the_decimal_places_cannot_exceed_the_storage_scale(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BoqQuantity::toThousandths('1.0001', 4);
    }

    public function test_it_formats_thousandths_with_three_decimal_places(): void
    {
        $this->assertSame('2.500', BoqQuantity::fromThousandths(2_500));
        $this->assertSame('0.007', BoqQuantity::fromThousandths(7));
        $this->assertSame('12.000', BoqQuantity::fromThousandths(12_000));
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function lineTotals(): array
    {
        return [
            '2.5 x 123.45' => [2_500, 12_345, 30_862_500],
            '0.333 x 10.01' => [333, 1_001, 333_333],
            '0.001 x 0.01' => [1, 1, 1],
            'zero price' => [5_000, 0, 0],
        ];
    }

    #[DataProvider('lineTotals')]
    public function test_the_line_total_in_millicentavos_is_exact(int $quantityThousandths, int $unitPriceCentavos, int $expected): void
    {
        $this->assertSame($expected, BoqQuantity::lineTotalMillicentavos($quantityThousandths, $unitPriceCentavos));
    }

    public function test_a_line_total_too_large_to_store_throws(): void
    {
        $this->expectException(OverflowException::class);

        BoqQuantity::lineTotalMillicentavos(9_999_999_999, 99_999_999_999_999);
    }

    public function test_an_exact_sum_too_large_to_store_throws(): void
    {
        $this->expectException(OverflowException::class);

        BoqQuantity::sumMillicentavos([PHP_INT_MAX, 1]);
    }

    public function test_the_sum_is_exact(): void
    {
        $this->assertSame(31_195_833, BoqQuantity::sumMillicentavos([30_862_500, 333_333]));
        $this->assertSame(0, BoqQuantity::sumMillicentavos([]));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function roundedTotals(): array
    {
        return [
            'exactly half a centavo rounds up' => [500, 1],
            'just below half a centavo rounds down' => [499, 0],
            'just above half a centavo rounds up' => [501, 1],
            'whole centavos stay unchanged' => [12_000, 12],
            'zero' => [0, 0],
            'half up on a larger amount' => [31_195_833, 31_196],
            'half exactly on a larger amount' => [30_862_500, 30_863],
            'just below half on a larger amount' => [30_862_499, 30_862],
            'largest storable total does not overflow' => [PHP_INT_MAX, intdiv(PHP_INT_MAX, 1_000) + 1],
        ];
    }

    #[DataProvider('roundedTotals')]
    public function test_the_total_rounds_half_up_to_the_centavo(int $millicentavos, int $expectedCentavos): void
    {
        $this->assertSame($expectedCentavos, BoqQuantity::roundHalfUpToCentavos($millicentavos));
    }

    public function test_rounding_a_negative_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BoqQuantity::roundHalfUpToCentavos(-1);
    }

    public function test_millicentavos_format_as_pesos_with_five_decimal_places(): void
    {
        $this->assertSame('308.62500', PesoAmount::fromMillicentavos(30_862_500));
        $this->assertSame('3.33333', PesoAmount::fromMillicentavos(333_333));
        $this->assertSame('0.00001', PesoAmount::fromMillicentavos(1));
        $this->assertSame('0.00000', PesoAmount::fromMillicentavos(0));
    }
}
