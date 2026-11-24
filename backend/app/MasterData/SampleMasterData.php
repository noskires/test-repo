<?php

namespace App\MasterData;

use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;
use DateTimeImmutable;

/**
 * Sample master data. MasterDataSampleSeeder writes it to the master data tables for local dev and demos (issue 03),
 * and tests load it into the in-memory fake. Category IDs are the stable codes (SourceSchema). No Building (issue 03).
 *
 * The Requestor has a full Immediate Head chain: Team Head -> Division Head -> Center Head -> Sector Head.
 */
final class SampleMasterData
{
    public const string REQUESTOR_EMPLOYEE_ID = 'EMP-1001';

    /** Demo accounts (DemoAccountsSeeder, issue 04). */
    public const string SYSTEM_ADMINISTRATOR_EMPLOYEE_ID = 'EMP-9001';

    public const string MANAGEMENT_VIEWER_EMPLOYEE_ID = 'EMP-9002';

    public const string VENDOR_STAFF_EMPLOYEE_ID = 'EMP-8001';

    /** Every sample employee, for MasterDataSampleSeeder (the port has no employee list). */
    public const array EMPLOYEE_IDS = [
        self::REQUESTOR_EMPLOYEE_ID, 'EMP-2001', 'EMP-3001', 'EMP-4001', 'EMP-5001', 'EMP-6001',
        self::SYSTEM_ADMINISTRATOR_EMPLOYEE_ID, self::MANAGEMENT_VIEWER_EMPLOYEE_ID, self::VENDOR_STAFF_EMPLOYEE_ID,
    ];

    public static function load(InMemoryMasterDataDirectory $directory): InMemoryMasterDataDirectory
    {
        return $directory
            ->addEquipmentCategory(new EquipmentCategory(id: 'GENSET', name: 'Genset'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'ACU', name: 'ACU'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'ATS', name: 'ATS'))
            ->addSite(new Site(id: 'SITE-MKT', name: 'Makati Office'))
            ->addSite(new Site(id: 'SITE-CEB', name: 'Cebu Site'))
            ->addEquipment(new Equipment(
                id: 'EQ-MKT-GENSET-1',
                siteId: 'SITE-MKT',
                name: 'Genset 1',
                categoryId: 'GENSET',
                location: 'Basement, Genset Room',
                installedOn: new DateTimeImmutable('2022-03-15'),
                operatingStatus: 'operational',
            ))
            ->addEquipment(new Equipment(
                id: 'EQ-MKT-ACU-3F',
                siteId: 'SITE-MKT',
                name: 'ACU 3F East Wing',
                categoryId: 'ACU',
                location: '3rd Floor, East Wing',
                installedOn: new DateTimeImmutable('2023-07-01'),
                operatingStatus: 'operational',
            ))
            ->addEquipment(new Equipment(
                id: 'EQ-CEB-GENSET-1',
                siteId: 'SITE-CEB',
                name: 'Cebu Genset',
                categoryId: 'GENSET',
                location: 'Generator Shed',
                installedOn: new DateTimeImmutable('2021-11-20'),
                operatingStatus: 'operational',
            ))
            ->addEquipment(new Equipment(
                id: 'EQ-CEB-ATS-1',
                siteId: 'SITE-CEB',
                name: 'Cebu ATS',
                categoryId: 'ATS',
                location: 'Electrical Room',
                installedOn: new DateTimeImmutable('2021-11-20'),
                operatingStatus: 'non-operational',
            ))
            ->addEmployee(new Employee(id: self::REQUESTOR_EMPLOYEE_ID, name: 'Rina Requestor', immediateHeadId: 'EMP-2001', positionTitle: 'Facilities Coordinator'))
            ->addEmployee(new Employee(id: 'EMP-2001', name: 'Tomas Team Head', immediateHeadId: 'EMP-3001', positionTitle: 'Team Head'))
            ->addEmployee(new Employee(id: 'EMP-3001', name: 'Diana Division Head', immediateHeadId: 'EMP-4001', positionTitle: 'Division Head'))
            ->addEmployee(new Employee(id: 'EMP-4001', name: 'Carlo Center Head', immediateHeadId: 'EMP-5001', positionTitle: 'Center Head'))
            ->addEmployee(new Employee(id: 'EMP-5001', name: 'Sofia Sector Head', positionTitle: 'Sector Head'))
            ->addEmployee(new Employee(id: 'EMP-6001', name: 'Erwin Engineer-in-Charge', immediateHeadId: 'EMP-3001'))
            ->addEmployee(new Employee(id: self::SYSTEM_ADMINISTRATOR_EMPLOYEE_ID, name: 'Ada Administrator'))
            ->addEmployee(new Employee(id: self::MANAGEMENT_VIEWER_EMPLOYEE_ID, name: 'Mario Management Viewer'))
            ->addEmployee(new Employee(id: self::VENDOR_STAFF_EMPLOYEE_ID, name: 'Victor Vendor Staff'));
    }
}
