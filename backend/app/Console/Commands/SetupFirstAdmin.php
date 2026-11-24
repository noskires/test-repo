<?php

namespace App\Console\Commands;

use App\Enums\PssRole;
use App\MasterData\MasterDataDirectory;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('pss:setup-first-admin')]
#[Description('Grant System Administrator to the employee in PSS_FIRST_ADMIN_EMPLOYEE_ID, creating the PSS user if missing')]
class SetupFirstAdmin extends Command
{
    /**
     * Execute the console command. Safe to run again: an existing admin is left as it is.
     */
    public function handle(MasterDataDirectory $directory): int
    {
        $employeeId = trim((string) config('pss.first_admin_employee_id'));

        if ($employeeId === '') {
            $this->error('PSS_FIRST_ADMIN_EMPLOYEE_ID is not set. Set it in .env to the employee ID of the first System Administrator.');

            return self::FAILURE;
        }

        $employee = $directory->findEmployee($employeeId);
        if ($employee === null) {
            $this->error("Employee [{$employeeId}] was not found in the master data. Check PSS_FIRST_ADMIN_EMPLOYEE_ID.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($employeeId, $employee): void {
            User::query()
                ->firstOrCreate(['employee_id' => $employeeId], ['name' => $employee->name])
                ->grantRole(PssRole::SystemAdministrator);
        });

        $this->info("{$employee->name} [{$employeeId}] is a System Administrator.");

        return self::SUCCESS;
    }
}
