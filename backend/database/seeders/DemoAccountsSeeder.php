<?php

namespace Database\Seeders;

use App\Enums\PssRole;
use App\Enums\UserAccess;
use App\MasterData\MasterDataDirectory;
use App\MasterData\SampleMasterData;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo accounts for the local credential checker (issue 04): a System Administrator, a Management / Viewer, an
 * ordinary employee and a vendor staff user linked to a demo Vendor. All share PASSWORD. Safe to run again.
 */
class DemoAccountsSeeder extends Seeder
{
    public const string PASSWORD = 'password';

    public const string DEMO_VENDOR_NAME = 'Demo Facilities Services Inc.';

    /**
     * Seed the demo accounts. Names come from the master data, like a real first sign-in.
     */
    public function run(MasterDataDirectory $directory): void
    {
        $this->account($directory, SampleMasterData::SYSTEM_ADMINISTRATOR_EMPLOYEE_ID)->grantRole(PssRole::SystemAdministrator);
        $this->account($directory, SampleMasterData::MANAGEMENT_VIEWER_EMPLOYEE_ID)->grantRole(PssRole::ManagementViewer);
        $this->account($directory, SampleMasterData::REQUESTOR_EMPLOYEE_ID);

        $vendor = Vendor::query()->firstOrCreate(['name' => self::DEMO_VENDOR_NAME], [
            'contact_person' => 'Victor Vendor Staff',
            'email' => 'ops@demo-facilities.test',
            'contact_number' => '+63 917 000 0000',
        ]);
        $this->account($directory, SampleMasterData::VENDOR_STAFF_EMPLOYEE_ID)->linkToVendor($vendor);
    }

    private function account(MasterDataDirectory $directory, string $employeeId): User
    {
        $employee = $directory->findEmployee($employeeId)
            ?? throw new RuntimeException("Demo employee [{$employeeId}] is missing from the master data.");

        $user = User::query()->updateOrCreate(
            ['employee_id' => $employeeId],
            ['name' => $employee->name, 'password' => self::PASSWORD],
        );

        if ($user->access === UserAccess::Pending) {
            $user->markInternal();
        }

        return $user;
    }
}
