<?php

namespace Tests\Unit\Checklists;

use App\Checklists\ChecklistResponseEvaluator;
use App\Enums\ChecklistResponseType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChecklistResponseEvaluatorTest extends TestCase
{
    /**
     * @return array<string, array{ChecklistResponseType, ?string, ?string, ?string, bool}>
     */
    public static function responses(): array
    {
        return [
            'pass' => [ChecklistResponseType::PassFail, 'pass', null, null, false],
            'fail' => [ChecklistResponseType::PassFail, 'fail', null, null, true],
            'good' => [ChecklistResponseType::GoodDefective, 'good', null, null, false],
            'defective' => [ChecklistResponseType::GoodDefective, 'defective', null, null, true],
            'yes' => [ChecklistResponseType::YesNo, 'yes', null, null, false],
            'no' => [ChecklistResponseType::YesNo, 'no', null, null, true],
            'numeric inside the range' => [ChecklistResponseType::Numeric, '15', '10', '20.5', false],
            'numeric equal to the min' => [ChecklistResponseType::Numeric, '10.000', '10', '20.5', false],
            'numeric equal to the max' => [ChecklistResponseType::Numeric, '20.5', '10', '20.5', false],
            'numeric just above the max' => [ChecklistResponseType::Numeric, '20.500001', '10', '20.5', true],
            'numeric just below the min' => [ChecklistResponseType::Numeric, '9.999999', '10', '20.5', true],
            'numeric far below a negative min' => [ChecklistResponseType::Numeric, '-12', '-10', null, true],
            'numeric with only a max' => [ChecklistResponseType::Numeric, '-1000', null, '5', false],
            'numeric with only a min' => [ChecklistResponseType::Numeric, '1000000', '5', null, false],
            'numeric with no range' => [ChecklistResponseType::Numeric, '-3.5', null, null, false],
            'float-unsafe comparison stays exact' => [ChecklistResponseType::Numeric, '0.3', '0.1', '0.3', false],
            'text never fails' => [ChecklistResponseType::Text, 'fail', null, null, false],
            'photo never fails' => [ChecklistResponseType::Photo, null, null, null, false],
            'no response is not a failure' => [ChecklistResponseType::PassFail, null, null, null, false],
            'no numeric reading is not a failure' => [ChecklistResponseType::Numeric, null, '10', '20', false],
        ];
    }

    #[DataProvider('responses')]
    public function test_it_decides_whether_a_response_failed(ChecklistResponseType $type, ?string $value, ?string $min, ?string $max, bool $expected): void
    {
        $this->assertSame($expected, ChecklistResponseEvaluator::isFailed($type, $value, $min, $max));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function numericReadings(): array
    {
        return [
            'integer' => ['12', true],
            'decimal' => ['12.5', true],
            'negative' => ['-0.25', true],
            'six decimals' => ['1.123456', true],
            'seven decimals' => ['1.1234567', false],
            'exponent' => ['1e3', false],
            'bare point' => ['1.', false],
            'text' => ['abc', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('numericReadings')]
    public function test_it_recognises_a_numeric_reading(string $value, bool $expected): void
    {
        $this->assertSame($expected, ChecklistResponseEvaluator::isNumericReading($value));
    }
}
