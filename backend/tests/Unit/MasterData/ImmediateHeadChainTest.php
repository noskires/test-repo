<?php

namespace Tests\Unit\MasterData;

use App\MasterData\Data\Employee;
use App\MasterData\ImmediateHeadChain;
use App\MasterData\InMemoryMasterDataDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImmediateHeadChainTest extends TestCase
{
    /**
     * Requestor -> Team Head -> Division Head -> Center Head -> Sector Head (top of the chain).
     */
    private function chain(): ImmediateHeadChain
    {
        $directory = (new InMemoryMasterDataDirectory)
            ->addEmployee(new Employee(id: 'REQ', name: 'Requestor', immediateHeadId: 'TEAM'))
            ->addEmployee(new Employee(id: 'TEAM', name: 'Team Head', immediateHeadId: 'DIV'))
            ->addEmployee(new Employee(id: 'DIV', name: 'Division Head', immediateHeadId: 'CENTER'))
            ->addEmployee(new Employee(id: 'CENTER', name: 'Center Head', immediateHeadId: 'SECTOR'))
            ->addEmployee(new Employee(id: 'SECTOR', name: 'Sector Head'))
            ->addEmployee(new Employee(id: 'ORPHAN', name: 'Orphan', immediateHeadId: 'GONE'));

        return new ImmediateHeadChain($directory);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function levels(): array
    {
        return [
            'level 1' => [1, 'TEAM'],
            'level 2' => [2, 'DIV'],
            'level 3' => [3, 'CENTER'],
            'level 4' => [4, 'SECTOR'],
        ];
    }

    #[DataProvider('levels')]
    public function test_it_returns_the_head_at_each_level(int $level, string $expectedEmployeeId): void
    {
        $this->assertSame($expectedEmployeeId, $this->chain()->headAtLevel('REQ', $level)?->id);
    }

    public function test_it_returns_null_when_the_chain_runs_out(): void
    {
        $chain = $this->chain();

        $this->assertNull($chain->headAtLevel('REQ', 5));
        $this->assertNull($chain->headAtLevel('DIV', 3));
        $this->assertNull($chain->headAtLevel('SECTOR', 1));
    }

    public function test_it_returns_null_when_a_head_is_missing_from_the_directory(): void
    {
        $this->assertNull($this->chain()->headAtLevel('ORPHAN', 1));
    }

    public function test_it_returns_null_for_an_unknown_employee(): void
    {
        $this->assertNull($this->chain()->headAtLevel('NOBODY', 1));
    }

    public function test_it_rejects_a_level_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->chain()->headAtLevel('REQ', 0);
    }
}
