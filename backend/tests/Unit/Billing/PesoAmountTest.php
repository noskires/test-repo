<?php

namespace Tests\Unit\Billing;

use App\Billing\PesoAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PesoAmountTest extends TestCase
{
    /**
     * @return array<string, array{string|int|float, int}>
     */
    public static function parsableAmounts(): array
    {
        return [
            'whole pesos as a string' => ['200000', 20_000_000],
            'two decimal places' => ['199999.99', 19_999_999],
            'one decimal place' => ['250000.5', 25_000_050],
            'one centavo' => ['0.01', 1],
            'an integer' => [1_000_000, 100_000_000],
            'a JSON float is parsed without float arithmetic' => [1000000.01, 100_000_001],
        ];
    }

    #[DataProvider('parsableAmounts')]
    public function test_it_converts_pesos_to_centavos(string|int|float $pesos, int $expectedCentavos): void
    {
        $this->assertSame($expectedCentavos, PesoAmount::toCentavos($pesos));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unparsableAmounts(): array
    {
        return [
            'three decimal places' => ['1.234'],
            'negative' => ['-1.00'],
            'not a number' => ['abc'],
            'empty' => [''],
            'leading plus sign' => ['+5'],
            'trailing decimal point' => ['5.'],
            'missing whole pesos' => ['.5'],
            'exponent notation' => ['2e5'],
        ];
    }

    #[DataProvider('unparsableAmounts')]
    public function test_it_rejects_amounts_that_are_not_whole_centavos(string $pesos): void
    {
        $this->expectException(InvalidArgumentException::class);

        PesoAmount::toCentavos($pesos);
    }

    public function test_it_formats_centavos_as_a_two_decimal_peso_string(): void
    {
        $this->assertSame('200000.00', PesoAmount::fromCentavos(20_000_000));
        $this->assertSame('1000000.01', PesoAmount::fromCentavos(100_000_001));
        $this->assertSame('0.05', PesoAmount::fromCentavos(5));
    }
}
