<?php

namespace Tests;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Unique per test process: the PID plus a random suffix, so a reused PID never shares a folder.
     */
    private static ?string $processToken = null;

    /**
     * storage/framework/testing/process-{token}, created on demand and removed when the process exits.
     */
    private static ?string $processStoragePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateStoragePerProcess();
    }

    /**
     * The one PSS user with this employee ID (users.employee_id is unique, issue 46): the existing one, or a new
     * internal employee. Calling it twice for the same ID returns the same person.
     */
    protected static function employeeUser(string $employeeId): User
    {
        return User::query()->firstWhere('employee_id', $employeeId)
            ?? User::factory()->create(['employee_id' => $employeeId]);
    }

    /**
     * The person with this employee ID, linked to the Vendor as vendor staff. Replaces fixtures that used to create a
     * second account sharing an employee's ID (issue 38): the same person, now vendor-linked, still can't act as that
     * employee.
     */
    protected static function vendorStaffWithEmployeeId(string $employeeId, ?Vendor $vendor = null): User
    {
        $user = self::employeeUser($employeeId);
        $user->forceFill(['vendor_id' => ($vendor ?? Vendor::factory()->create())->id])->save();

        return $user;
    }

    public static function processToken(): string
    {
        return self::$processToken ??= getmypid().'-'.bin2hex(random_bytes(4));
    }

    /**
     * Point storage_path() at a per-process folder (issue 36). Storage::fake() roots fake disks at
     * storage_path('framework/testing/disks/{disk}') and empties that folder first, so this keeps two
     * concurrent `php artisan test` runs from wiping each other's files, without touching any test file.
     * Config values derived from storage_path() (log, cache, session, view paths) were resolved at boot
     * and are unaffected; nothing in the app calls storage_path() at runtime.
     */
    private function isolateStoragePerProcess(): void
    {
        if (self::$processStoragePath === null) {
            $processStoragePath = $this->app->storagePath('framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'process-'.self::processToken());
            self::$processStoragePath = $processStoragePath;

            register_shutdown_function(static function () use ($processStoragePath): void {
                (new Filesystem)->deleteDirectory($processStoragePath);
            });
        }

        $this->app->useStoragePath(self::$processStoragePath);
    }
}
