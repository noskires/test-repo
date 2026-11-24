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
     * Execute the console command. Safe to run again: an existing admin is left as it is. A new user is created
     * internal; an existing user keeps their access state (issue 46, Q17), with a warning when they are pending.
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

        $user = DB::transaction(function () use ($employeeId, $employee): User {
            $user = User::query()->firstOrNew(['employee_id' => $employeeId], ['name' => $employee->name]);
            if (! $user->exists) {
                $user->markInternal();
            }
            $user->grantRole(PssRole::SystemAdministrator);

            return $user;
        });

        $this->info("{$employee->name} [{$employeeId}] is a System Administrator.");

        if ($user->isPending()) {
            $this->warn('This user is pending: they can sign in but can\'t use PSS until they are classified (internal or a Vendor) in the database or by another System Administrator.');
        }

        return self::SUCCESS;
    }
}
