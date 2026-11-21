<?php

namespace Tests\Unit\Billing;

use App\Billing\BillingLevels;
use App\Billing\BillingThresholds;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BillingLevelsTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function amountsAgainstDefaultThresholds(): array
    {
        return [
            'zero is below the lower threshold' => [0, 2],
            '199,999.99 is below the lower threshold' => [19_999_999, 2],
            '200,000.00 is exactly the lower threshold' => [20_000_000, 3],
            '500,000.00 is between the thresholds' => [50_000_000, 3],
            '1,000,000.00 is exactly the upper threshold' => [100_000_000, 3],
            '1,000,000.01 is above the upper threshold' => [100_000_001, 4],
        ];
    }

    #[DataProvider('amountsAgainstDefaultThresholds')]
    public function test_it_returns_the_required_levels_for_a_billing_amount(int $amountInCentavos, int $expectedLevels): void
    {
        $thresholds = new BillingThresholds(lowerInCentavos: 20_000_000, upperInCentavos: 100_000_000);

        $this->assertSame($expectedLevels, BillingLevels::requiredBillingLevels($amountInCentavos, $thresholds));
    }

    public function test_it_uses_the_thresholds_it_is_given(): void
    {
        $thresholds = new BillingThresholds(lowerInCentavos: 100, upperInCentavos: 200);

        $this->assertSame(2, BillingLevels::requiredBillingLevels(99, $thresholds));
        $this->assertSame(3, BillingLevels::requiredBillingLevels(200, $thresholds));
        $this->assertSame(4, BillingLevels::requiredBillingLevels(201, $thresholds));
    }

    public function test_it_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BillingLevels::requiredBillingLevels(-1, new BillingThresholds(20_000_000, 100_000_000));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function invalidThresholdPairs(): array
    {
        return [
            'lower equals upper' => [100, 100],
            'lower above upper' => [200, 100],
            'zero lower' => [0, 100],
            'negative lower' => [-1, 100],
        ];
    }

    #[DataProvider('invalidThresholdPairs')]
    public function test_thresholds_must_be_positive_and_ordered(int $lowerInCentavos, int $upperInCentavos): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BillingThresholds($lowerInCentavos, $upperInCentavos);
    }
}
