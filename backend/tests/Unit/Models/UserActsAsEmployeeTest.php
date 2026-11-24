<?php

namespace Tests\Unit\Models;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one rule every approver check uses (issue 38): an internal employee (not vendor staff, not pending, issue 46)
 * with this employee ID.
 */
class UserActsAsEmployeeTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(array $attributes): User
    {
        return (new User)->forceFill($attributes);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function users(): array
    {
        return [
            'internal employee with the matching id' => [['employee_id' => 'P-1', 'vendor_id' => null, 'access' => 'internal'], true],
            'pending user with the matching id' => [['employee_id' => 'P-1', 'vendor_id' => null, 'access' => 'pending'], false],
            'vendor staff with the matching employee id' => [['employee_id' => 'P-1', 'vendor_id' => 7], false],
            'user with a null employee id' => [['employee_id' => null, 'vendor_id' => null], false],
            'internal employee with another id' => [['employee_id' => 'P-2', 'vendor_id' => null, 'access' => 'internal'], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('users')]
    public function test_acts_as_employee(array $attributes, bool $expected): void
    {
        $this->assertSame($expected, $this->user($attributes)->actsAsEmployee('P-1'));
    }

    public function test_is_internal_employee(): void
    {
        $this->assertTrue($this->user(['employee_id' => 'P-1', 'vendor_id' => null, 'access' => 'internal'])->isInternalEmployee());
        $this->assertFalse($this->user(['employee_id' => 'P-1', 'vendor_id' => null, 'access' => 'pending'])->isInternalEmployee());
        $this->assertFalse($this->user(['employee_id' => 'P-1', 'vendor_id' => 7])->isInternalEmployee());
        $this->assertFalse($this->user(['employee_id' => null, 'vendor_id' => null])->isInternalEmployee());
    }
}
