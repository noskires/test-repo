<?php

namespace Database\Seeders;

use App\Enums\PssRole;
use App\Enums\UserAccess;
use App\MasterData\Data\Site;
use App\MasterData\MasterDataDirectory;
use App\MasterData\SampleMasterData;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo accounts (issues 04 and 44): one account per kind of user, each signing in with its email in EMAILS and
 * PASSWORD, with no temporary password to change. The Immediate Head chain (Supervisor up to Sector Head) needs no
 * PSS role: it comes from the master data. The EIC account is made the EIC of every sample Site, and each demo Vendor
 * is the Site Vendor of one sample Site, through the same SiteSetting methods the Site settings API uses.
 *
 * Safe to run again: accounts are matched by employee ID and their email, name and password are set again. Site
 * settings are set again too (a no-op when unchanged), so a re-seed undoes a hand change to them.
 */
class DemoAccountsSeeder extends Seeder
{
    public const string PASSWORD = 'password';

    public const string DEMO_VENDOR_NAME = 'Demo Facilities Services Inc.';

    public const string SECOND_DEMO_VENDOR_NAME = 'Second Demo Maintenance Corp.';

    /**
     * The sign-in email of each demo account, by employee ID.
     *
     * @var array<string, string>
     */
    public const array EMAILS = [
        SampleMasterData::SYSTEM_ADMINISTRATOR_EMPLOYEE_ID => 'admin@pss.test',
        SampleMasterData::MANAGEMENT_VIEWER_EMPLOYEE_ID => 'viewer@pss.test',
        SampleMasterData::REQUESTOR_EMPLOYEE_ID => 'requestor@pss.test',
        'EMP-2001' => 'supervisor@pss.test',
        'EMP-3001' => 'divhead@pss.test',
        'EMP-4001' => 'centerhead@pss.test',
        'EMP-5001' => 'sectorhead@pss.test',
        SampleMasterData::EIC_EMPLOYEE_ID => 'eic@pss.test',
        SampleMasterData::VENDOR_STAFF_EMPLOYEE_ID => 'vendor1@pss.test',
        SampleMasterData::SECOND_VENDOR_STAFF_EMPLOYEE_ID => 'vendor2@pss.test',
    ];

    /**
     * The sample Site each demo Vendor serves.
     *
     * @var array<string, string>
     */
    private const array VENDOR_SITES = [
        self::DEMO_VENDOR_NAME => 'SITE-MKT',
        self::SECOND_DEMO_VENDOR_NAME => 'SITE-CEB',
    ];

    /**
     * Seed the demo accounts. Names come from the master data, like a real first sign-in.
     */
    public function run(MasterDataDirectory $directory): void
    {
        $admin = $this->account($directory, SampleMasterData::SYSTEM_ADMINISTRATOR_EMPLOYEE_ID);
        $admin->grantRole(PssRole::SystemAdministrator);
        $this->account($directory, SampleMasterData::MANAGEMENT_VIEWER_EMPLOYEE_ID)->grantRole(PssRole::ManagementViewer);

        foreach ([SampleMasterData::REQUESTOR_EMPLOYEE_ID, 'EMP-2001', 'EMP-3001', 'EMP-4001', 'EMP-5001'] as $employeeId) {
            $this->account($directory, $employeeId);
        }

        $this->account($directory, SampleMasterData::EIC_EMPLOYEE_ID);
        $engineer = $directory->findEmployee(SampleMasterData::EIC_EMPLOYEE_ID);
        foreach (self::VENDOR_SITES as $siteId) {
            SiteSetting::assignEic($this->site($directory, $siteId), $engineer, $admin);
        }

        $vendorOne = Vendor::query()->firstOrCreate(['name' => self::DEMO_VENDOR_NAME], [
            'contact_person' => 'Victor Vendor Staff',
            'email' => 'ops@demo-facilities.test',
            'contact_number' => '+63 917 000 0000',
        ]);
        $vendorTwo = Vendor::query()->firstOrCreate(['name' => self::SECOND_DEMO_VENDOR_NAME], [
            'contact_person' => 'Wendy Second Vendor Staff',
            'email' => 'ops@second-demo-maintenance.test',
            'contact_number' => '+63 917 000 0001',
        ]);

        $this->account($directory, SampleMasterData::VENDOR_STAFF_EMPLOYEE_ID, $vendorOne)->linkToVendor($vendorOne);
        $this->account($directory, SampleMasterData::SECOND_VENDOR_STAFF_EMPLOYEE_ID, $vendorTwo)->linkToVendor($vendorTwo);

        foreach ([$vendorOne, $vendorTwo] as $vendor) {
            SiteSetting::assignVendor($this->site($directory, self::VENDOR_SITES[$vendor->name]), $vendor, $admin);
        }
    }

    /**
     * The demo user for the employee: email and PASSWORD, nothing to change on sign-in. Internal unless it is vendor
     * staff (the caller links the Vendor).
     */
    private function account(MasterDataDirectory $directory, string $employeeId, ?Vendor $vendor = null): User
    {
        $employee = $directory->findEmployee($employeeId)
            ?? throw new RuntimeException("Demo employee [{$employeeId}] is missing from the master data.");

        $user = User::query()->firstOrNew(['employee_id' => $employeeId]);
        $user->forceFill([
            'name' => $employee->name,
            'email' => self::EMAILS[$employeeId],
            'password' => self::PASSWORD,
            'must_change_password' => false,
        ])->save();

        if ($vendor === null && $user->access === UserAccess::Pending) {
            $user->markInternal();
        }

        return $user;
    }

    private function site(MasterDataDirectory $directory, string $siteId): Site
    {
        return $directory->findSite($siteId)
            ?? throw new RuntimeException("Demo site [{$siteId}] is missing from the master data.");
    }
}
