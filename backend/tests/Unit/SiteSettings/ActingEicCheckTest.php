<?php

namespace Tests\Unit\SiteSettings;

use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one shared "may act as the Site's EIC" check (issue 37): the Site's current EIC, and never vendor staff.
 * PM Task validation, E-Call review and the Work Order EIC actions all use it. Needs the DB, so it extends
 * the Laravel TestCase.
 */
class ActingEicCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::factory()->create(['site_id' => 'S-1', 'eic_employee_id' => 'P-EIC']);
    }

    public function test_the_current_eic_passes(): void
    {
        $eic = User::factory()->create(['employee_id' => 'P-EIC']);

        $this->assertTrue(SiteSetting::isActingEic('S-1', $eic));
        $this->assertTrue(SiteSetting::isActingEic('S-1', $eic, lockForUpdate: true));
    }

    /**
     * @return array<string, array{\Closure(): User, string}>
     */
    public static function usersWhoMayNotAct(): array
    {
        return [
            'vendor staff with the EIC employee id' => [fn (): User => User::factory()->vendorStaff(Vendor::factory()->create())->create(['employee_id' => 'P-EIC']), 'S-1'],
            'a user with a null employee id' => [fn (): User => User::factory()->create(['employee_id' => null]), 'S-1'],
            'another employee' => [fn (): User => User::factory()->create(['employee_id' => 'P-OTHER']), 'S-1'],
            'the EIC at a site with no settings row' => [fn (): User => User::factory()->create(['employee_id' => 'P-EIC']), 'S-UNKNOWN'],
        ];
    }

    /**
     * @param  \Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoMayNotAct')]
    public function test_a_user_who_is_not_the_acting_eic_fails(\Closure $makeUser, string $siteId): void
    {
        $user = $makeUser();

        $this->assertFalse(SiteSetting::isActingEic($siteId, $user));
        $this->assertFalse(SiteSetting::isActingEic($siteId, $user, lockForUpdate: true));
    }

    public function test_a_site_with_no_eic_fails_even_for_a_user_with_no_employee_id(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => null]);

        $this->assertFalse(SiteSetting::isActingEic('S-1', User::factory()->create(['employee_id' => 'P-EIC'])));
        $this->assertFalse(SiteSetting::isActingEic('S-1', User::factory()->create(['employee_id' => null])));
    }

    public function test_a_replaced_eic_fails_and_the_new_one_passes(): void
    {
        $oldEic = User::factory()->create(['employee_id' => 'P-EIC']);
        $newEic = User::factory()->create(['employee_id' => 'P-NEW']);

        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-NEW']);

        $this->assertFalse(SiteSetting::isActingEic('S-1', $oldEic));
        $this->assertTrue(SiteSetting::isActingEic('S-1', $newEic));
    }
}
