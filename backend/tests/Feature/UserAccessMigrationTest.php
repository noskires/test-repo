<?php

namespace Tests\Feature;

use App\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The issue 46 migrations: the access backfill (Q9) and one PSS user per employee_id (Q15).
 */
class UserAccessMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $file): Migration
    {
        return require database_path("migrations/{$file}");
    }

    public function test_existing_users_are_backfilled_from_their_vendor_link(): void
    {
        $vendor = Vendor::factory()->create();
        $migration = $this->migration('2026_11_24_150000_add_access_to_users_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'access'));

        DB::table('users')->insert([
            ['name' => 'Victor Vendor', 'employee_id' => 'EMP-V', 'vendor_id' => $vendor->id],
            ['name' => 'Ivy Internal', 'employee_id' => 'EMP-I', 'vendor_id' => null],
        ]);

        $migration->up();

        $this->assertSame('vendor', DB::table('users')->where('employee_id', 'EMP-V')->value('access'));
        $this->assertSame('internal', DB::table('users')->where('employee_id', 'EMP-I')->value('access'));
        $this->assertNull(DB::table('users')->where('employee_id', 'EMP-I')->value('access_set_at'));
    }

    public function test_a_second_user_with_the_same_employee_id_is_refused(): void
    {
        DB::table('users')->insert(['name' => 'First', 'employee_id' => 'EMP-1']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('users')->insert(['name' => 'Second', 'employee_id' => 'EMP-1']);
    }

    public function test_users_without_an_employee_id_are_not_affected_by_the_unique_constraint(): void
    {
        DB::table('users')->insert([['name' => 'First', 'employee_id' => null], ['name' => 'Second', 'employee_id' => null]]);

        $this->assertSame(2, DB::table('users')->whereNull('employee_id')->count());
    }
}
