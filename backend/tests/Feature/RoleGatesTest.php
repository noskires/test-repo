<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin gates resolve from stored PSS Roles (issue 04): only a System Administrator holds them.
 */
class RoleGatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function adminGates(): array
    {
        return [
            'manage-vendors' => ['manage-vendors'],
            'manage-site-settings' => ['manage-site-settings'],
            'manage-checklists' => ['manage-checklists'],
            'manage-price-lists' => ['manage-price-lists'],
            'manage-billing-thresholds' => ['manage-billing-thresholds'],
            'manage-roles' => ['manage-roles'],
        ];
    }

    #[DataProvider('adminGates')]
    public function test_a_system_administrator_is_allowed(string $gate): void
    {
        $admin = User::factory()->create(['employee_id' => 'EMP-ADMIN']);
        $admin->grantRole(PssRole::SystemAdministrator);

        $this->assertTrue(Gate::forUser($admin)->allows($gate));
    }

    /**
     * @return array<string, array{string, Closure(): User}>
     */
    public static function deniedUsersPerGate(): array
    {
        $deniedUsers = [
            'a management viewer' => function (): User {
                $user = User::factory()->create(['employee_id' => 'EMP-VIEW']);
                $user->grantRole(PssRole::ManagementViewer);

                return $user;
            },
            'an ordinary employee' => fn (): User => User::factory()->create(['employee_id' => 'EMP-1']),
            'vendor staff' => fn (): User => User::factory()->vendorStaff()->create(['employee_id' => 'EMP-V']),
        ];

        $cases = [];
        foreach (array_keys(self::adminGates()) as $gate) {
            foreach ($deniedUsers as $label => $makeUser) {
                $cases["{$gate} denies {$label}"] = [$gate, $makeUser];
            }
        }

        return $cases;
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('deniedUsersPerGate')]
    public function test_everyone_else_is_denied(string $gate, Closure $makeUser): void
    {
        $this->assertTrue(Gate::forUser($makeUser())->denies($gate));
    }

    public function test_a_revoked_role_stops_allowing_on_the_next_check(): void
    {
        $admin = User::factory()->create(['employee_id' => 'EMP-ADMIN']);
        $admin->grantRole(PssRole::SystemAdministrator);
        $other = User::factory()->create(['employee_id' => 'EMP-OTHER']);
        $other->grantRole(PssRole::SystemAdministrator);

        $admin->revokeRole(PssRole::SystemAdministrator);

        $this->assertTrue(Gate::forUser($admin->refresh())->denies('manage-roles'));
    }

    public function test_the_admin_endpoints_use_the_real_gate(): void
    {
        $admin = User::factory()->create(['employee_id' => 'EMP-ADMIN']);
        $admin->grantRole(PssRole::SystemAdministrator);

        $this->actingAs($admin)->postJson('/api/vendors', [
            'name' => 'Acme Facilities Inc.',
            'contact_person' => 'Juan dela Cruz',
            'email' => 'ops@acme.test',
            'contact_number' => '+63 917 000 0000',
        ])->assertCreated();

        $this->actingAs(User::factory()->create(['employee_id' => 'EMP-1']))->postJson('/api/vendors', [
            'name' => 'Other Inc.',
        ])->assertForbidden();
    }
}
