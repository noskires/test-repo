<?php

namespace Tests\Unit\Scheduling;

use App\Enums\PmFrequency;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PmFrequencyTest extends TestCase
{
    /**
     * @return array<string, array{PmFrequency, string, string}>
     */
    public static function nextDueDates(): array
    {
        return [
            'monthly mid-month' => [PmFrequency::Monthly, '2026-01-15', '2026-02-15'],
            'monthly clamps to end of February' => [PmFrequency::Monthly, '2026-01-31', '2026-02-28'],
            'monthly clamps to leap day' => [PmFrequency::Monthly, '2028-01-31', '2028-02-29'],
            'twice a month from month end' => [PmFrequency::TwiceAMonth, '2026-01-31', '2026-02-15'],
            'twice a month across February' => [PmFrequency::TwiceAMonth, '2026-02-20', '2026-03-07'],
            'bi-monthly across the year end' => [PmFrequency::BiMonthly, '2026-12-31', '2027-02-28'],
            'quarterly clamps' => [PmFrequency::Quarterly, '2026-11-30', '2027-02-28'],
            'semi-annual clamps' => [PmFrequency::SemiAnnual, '2026-08-31', '2027-02-28'],
            'annual from a leap day' => [PmFrequency::Annual, '2028-02-29', '2029-02-28'],
        ];
    }

    #[DataProvider('nextDueDates')]
    public function test_next_due_date(PmFrequency $frequency, string $from, string $expected): void
    {
        $this->assertSame($expected, $frequency->nextDueDate(CarbonImmutable::parse($from))->toDateString());
    }

    public function test_next_due_date_does_not_change_the_given_date(): void
    {
        $from = CarbonImmutable::parse('2026-01-31');

        PmFrequency::Monthly->nextDueDate($from);

        $this->assertSame('2026-01-31', $from->toDateString());
    }

    public function test_there_are_exactly_the_six_mvp_frequencies(): void
    {
        $this->assertSame(
            ['monthly', 'twice_a_month', 'bi_monthly', 'quarterly', 'semi_annual', 'annual'],
            array_column(PmFrequency::cases(), 'value'),
        );
    }
}
