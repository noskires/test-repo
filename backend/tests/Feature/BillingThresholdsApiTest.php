<?php

namespace Tests\Feature;

use App\Billing\BillingThresholds;
use App\Models\BillingThresholdChange;
use App\Models\BillingThresholdSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingThresholdsApiTest extends TestCase
{
    use RefreshDatabase;

    private const string URI = '/api/settings/billing-thresholds';

    private function actingAsThresholdManager(bool $isAllowed = true): User
    {
        Gate::define('manage-billing-thresholds', fn (User $user): bool => $isAllowed);

        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function assertThresholdsUnchanged(): void
    {
        $this->assertDatabaseCount('billing_threshold_settings', 1);
        $this->assertDatabaseHas('billing_threshold_settings', [
            'lower_threshold_centavos' => 20_000_000,
            'upper_threshold_centavos' => 100_000_000,
        ]);
        $this->assertDatabaseCount('billing_threshold_changes', 0);
    }

    public function test_defaults_are_seeded_at_200_000_and_1_000_000(): void
    {
        $this->assertThresholdsUnchanged();

        $thresholds = BillingThresholdSetting::current();
        $this->assertEquals(new BillingThresholds(20_000_000, 100_000_000), $thresholds);
    }

    public function test_any_authenticated_user_can_read_the_thresholds(): void
    {
        $this->actingAsThresholdManager(isAllowed: false);

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.lower_threshold', '200000.00')
            ->assertJsonPath('data.upper_threshold', '1000000.00');
    }

    public function test_updating_the_thresholds_returns_200_persists_and_records_the_change(): void
    {
        $user = $this->actingAsThresholdManager();

        $this->putJson(self::URI, ['lower_threshold' => '250000.50', 'upper_threshold' => 1500000])
            ->assertOk()
            ->assertJsonPath('data.lower_threshold', '250000.50')
            ->assertJsonPath('data.upper_threshold', '1500000.00');

        $this->assertEquals(new BillingThresholds(25_000_050, 150_000_000), BillingThresholdSetting::current());
        $this->assertDatabaseCount('billing_threshold_settings', 1);

        $change = BillingThresholdChange::sole();
        $this->assertSame($user->id, $change->changed_by_user_id);
        $this->assertSame(20_000_000, $change->old_lower_threshold_centavos);
        $this->assertSame(100_000_000, $change->old_upper_threshold_centavos);
        $this->assertSame(25_000_050, $change->new_lower_threshold_centavos);
        $this->assertSame(150_000_000, $change->new_upper_threshold_centavos);
        $this->assertNotNull($change->created_at);
    }

    public function test_every_change_is_recorded_against_the_previous_values(): void
    {
        $this->actingAsThresholdManager();

        $this->putJson(self::URI, ['lower_threshold' => 300000, 'upper_threshold' => 1000000])->assertOk();
        $this->putJson(self::URI, ['lower_threshold' => 300000, 'upper_threshold' => 2000000])->assertOk();

        $changes = BillingThresholdChange::query()->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertSame(30_000_000, $changes[1]->old_lower_threshold_centavos);
        $this->assertSame(100_000_000, $changes[1]->old_upper_threshold_centavos);
        $this->assertSame(200_000_000, $changes[1]->new_upper_threshold_centavos);
    }

    public function test_saving_identical_values_records_no_change(): void
    {
        $this->actingAsThresholdManager();

        $this->putJson(self::URI, ['lower_threshold' => '200000.00', 'upper_threshold' => '1000000.00'])->assertOk();

        $this->assertThresholdsUnchanged();
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'lower equals upper' => [['lower_threshold' => 500000, 'upper_threshold' => 500000], ['lower_threshold']],
            'lower above upper' => [['lower_threshold' => 2000000, 'upper_threshold' => 1000000], ['lower_threshold']],
            'zero lower' => [['lower_threshold' => 0, 'upper_threshold' => 1000000], ['lower_threshold']],
            'negative lower' => [['lower_threshold' => -1, 'upper_threshold' => 1000000], ['lower_threshold']],
            'zero upper' => [['lower_threshold' => 200000, 'upper_threshold' => 0], ['upper_threshold']],
            'negative upper' => [['lower_threshold' => 200000, 'upper_threshold' => -5], ['upper_threshold']],
            'missing both' => [[], ['lower_threshold', 'upper_threshold']],
            'not numeric' => [['lower_threshold' => 'abc', 'upper_threshold' => 1000000], ['lower_threshold']],
            'more than two decimals' => [['lower_threshold' => '200000.001', 'upper_threshold' => 1000000], ['lower_threshold']],
            'leading plus sign' => [['lower_threshold' => '+5', 'upper_threshold' => '10'], ['lower_threshold']],
            'trailing decimal point' => [['lower_threshold' => '5.', 'upper_threshold' => '10'], ['lower_threshold']],
            'missing whole pesos' => [['lower_threshold' => '.5', 'upper_threshold' => '10'], ['lower_threshold']],
            'exponent notation' => [['lower_threshold' => '2e5', 'upper_threshold' => '1000000'], ['lower_threshold']],
            'leading plus sign on upper' => [['lower_threshold' => '5', 'upper_threshold' => '+10'], ['upper_threshold']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $invalidFields
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_thresholds_return_422(array $payload, array $invalidFields): void
    {
        $this->actingAsThresholdManager();

        $this->putJson(self::URI, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($invalidFields);

        $this->assertThresholdsUnchanged();
    }

    public function test_update_returns_403_when_the_gate_denies(): void
    {
        $this->actingAsThresholdManager(isAllowed: false);

        $this->putJson(self::URI, ['lower_threshold' => 300000, 'upper_threshold' => 2000000])->assertForbidden();

        $this->assertThresholdsUnchanged();
    }

    public function test_both_endpoints_return_401_when_unauthenticated(): void
    {
        $this->getJson(self::URI)->assertUnauthorized();
        $this->putJson(self::URI, ['lower_threshold' => 300000, 'upper_threshold' => 2000000])->assertUnauthorized();

        $this->assertThresholdsUnchanged();
    }
}
