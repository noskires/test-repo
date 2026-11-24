<?php

namespace Tests\Feature;

use App\Models\ECall;
use App\Models\PmTask;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Who sees what (issue 04, Q5): every internal employee sees every Site's records; vendor staff see only their own
 * Vendor's PM Tasks, E-Calls and Work Orders.
 */
class RecordVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
    }

    private function workOrderFor(ECall $eCall): WorkOrder
    {
        return (new WorkOrder)->setRelation('eCall', $eCall);
    }

    public function test_an_internal_employee_who_is_not_the_eic_views_every_record_type(): void
    {
        $employee = User::factory()->create(['employee_id' => 'EMP-ANYONE']);
        $eCall = ECall::factory()->create(['vendor_id' => $this->vendor->id]);

        $this->assertTrue(Gate::forUser($employee)->allows('view', PmTask::factory()->create(['vendor_id' => $this->vendor->id])));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $eCall));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $this->workOrderFor($eCall)));
    }

    public function test_vendor_staff_view_their_own_vendors_records(): void
    {
        $staff = User::factory()->vendorStaff($this->vendor)->create(['employee_id' => 'EMP-V']);
        $eCall = ECall::factory()->create(['vendor_id' => $this->vendor->id]);

        $this->assertTrue(Gate::forUser($staff)->allows('view', PmTask::factory()->create(['vendor_id' => $this->vendor->id])));
        $this->assertTrue(Gate::forUser($staff)->allows('view', $eCall));
        $this->assertTrue(Gate::forUser($staff)->allows('view', $this->workOrderFor($eCall)));
    }

    public function test_vendor_staff_get_403_on_another_vendors_pm_task(): void
    {
        $this->actingAs(User::factory()->vendorStaff()->create(['employee_id' => 'EMP-V']));
        $pmTask = PmTask::factory()->create(['vendor_id' => $this->vendor->id]);

        $this->getJson("/api/pm-tasks/{$pmTask->id}")->assertForbidden();
    }

    public function test_vendor_staff_get_403_on_another_vendors_e_call(): void
    {
        $this->actingAs(User::factory()->vendorStaff()->create(['employee_id' => 'EMP-V']));
        $eCall = ECall::factory()->create(['vendor_id' => $this->vendor->id]);

        $this->getJson("/api/e-calls/{$eCall->id}")->assertForbidden();
    }

    public function test_vendor_staff_are_denied_another_vendors_work_order(): void
    {
        $staff = User::factory()->vendorStaff()->create(['employee_id' => 'EMP-V']);
        $eCall = ECall::factory()->create(['vendor_id' => $this->vendor->id]);

        $this->assertTrue(Gate::forUser($staff)->denies('view', $this->workOrderFor($eCall)));
    }
}
