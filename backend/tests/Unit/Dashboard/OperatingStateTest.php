<?php

namespace Tests\Unit\Dashboard;

use App\Dashboard\OperatingState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperatingStateTest extends TestCase
{
    /**
     * @return array<string, array{string|null, OperatingState}>
     */
    public static function statuses(): array
    {
        return [
            'operational' => ['operational', OperatingState::Operational],
            'case-insensitive and trimmed' => ['  Operational ', OperatingState::Operational],
            'null' => [null, OperatingState::Unknown],
            'empty' => ['', OperatingState::Unknown],
            'blank' => ['   ', OperatingState::Unknown],
            'down' => ['down', OperatingState::NonOperational],
            'non-operational' => ['Non-Operational', OperatingState::NonOperational],
            'under repair' => ['under repair', OperatingState::NonOperational],
        ];
    }

    #[DataProvider('statuses')]
    public function test_classifies_the_directory_operating_status(?string $status, OperatingState $expected): void
    {
        $this->assertSame($expected, OperatingState::classify($status));
    }
}
