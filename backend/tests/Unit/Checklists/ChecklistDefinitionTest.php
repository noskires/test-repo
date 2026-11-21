<?php

namespace Tests\Unit\Checklists;

use App\Checklists\ChecklistDefinition;
use App\Checklists\InvalidChecklistDefinition;
use App\Enums\ChecklistResponseType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChecklistDefinitionTest extends TestCase
{
    /**
     * A valid fixture definition. Shared with the loader and API feature tests.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function validDefinition(array $overrides = []): array
    {
        return array_merge([
            'key' => 'genset',
            'name' => 'Genset PM Checklist',
            'equipment_category_id' => 'CAT-GENSET',
            'items' => [
                ['label' => 'Engine oil inspection', 'response_type' => 'good_defective', 'mandatory' => true, 'remarks_required_on_fail' => true],
                ['label' => 'Battery voltage', 'response_type' => 'numeric', 'acceptable_min' => 12, 'acceptable_max' => '13.5'],
                ['label' => 'Photo of control panel', 'response_type' => 'photo', 'photo_required' => true],
            ],
        ], $overrides);
    }

    public function test_it_parses_a_valid_definition_keeping_item_order(): void
    {
        $definition = ChecklistDefinition::fromArray(self::validDefinition());

        $this->assertSame('genset', $definition->key);
        $this->assertSame('Genset PM Checklist', $definition->name);
        $this->assertSame('CAT-GENSET', $definition->equipmentCategoryId);
        $this->assertSame(
            ['Engine oil inspection', 'Battery voltage', 'Photo of control panel'],
            array_map(fn ($item): string => $item->label, $definition->items),
        );

        [$oil, $battery, $photo] = $definition->items;
        $this->assertSame(ChecklistResponseType::GoodDefective, $oil->responseType);
        $this->assertTrue($oil->mandatory);
        $this->assertTrue($oil->remarksRequiredOnFail);
        $this->assertFalse($oil->photoRequired);
        $this->assertSame('12', $battery->acceptableMin);
        $this->assertSame('13.5', $battery->acceptableMax);
        $this->assertTrue($photo->photoRequired);
    }

    public function test_flags_default_to_false_and_criteria_to_null(): void
    {
        $item = ChecklistDefinition::fromArray(self::validDefinition([
            'items' => [['label' => 'Door seal', 'response_type' => 'yes_no']],
        ]))->items[0];

        $this->assertFalse($item->mandatory);
        $this->assertFalse($item->remarksRequiredOnFail);
        $this->assertFalse($item->photoRequired);
        $this->assertNull($item->acceptableMin);
        $this->assertNull($item->acceptableMax);
        $this->assertNull($item->acceptableCriteria);
    }

    public function test_every_response_type_is_accepted(): void
    {
        $items = array_map(
            fn (string $type): array => ['label' => "Check {$type}", 'response_type' => $type],
            ['pass_fail', 'good_defective', 'yes_no', 'numeric', 'text', 'photo'],
        );

        $this->assertCount(6, ChecklistDefinition::fromArray(self::validDefinition(['items' => $items]))->items);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidDefinitions(): array
    {
        $item = ['label' => 'Check', 'response_type' => 'pass_fail'];

        return [
            'missing key' => [self::validDefinition(['key' => ''])],
            'missing name' => [self::validDefinition(['name' => null])],
            'missing category' => [self::validDefinition(['equipment_category_id' => ''])],
            'no items' => [self::validDefinition(['items' => []])],
            'item without a label' => [self::validDefinition(['items' => [['response_type' => 'pass_fail']]])],
            'unknown response type' => [self::validDefinition(['items' => [['label' => 'Check', 'response_type' => 'thumbs_up']]])],
            'numeric min above max' => [self::validDefinition(['items' => [['label' => 'Volts', 'response_type' => 'numeric', 'acceptable_min' => 14, 'acceptable_max' => 12]]])],
            'non-numeric min' => [self::validDefinition(['items' => [['label' => 'Volts', 'response_type' => 'numeric', 'acceptable_min' => 'low']]])],
            'min on a non-numeric item' => [self::validDefinition(['items' => [['label' => 'Check', 'response_type' => 'pass_fail', 'acceptable_min' => 1]]])],
            'flag that is not a boolean' => [self::validDefinition(['items' => [[...$item, 'mandatory' => 'yes']]])],
            'duplicate labels' => [self::validDefinition(['items' => [$item, $item]])],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidDefinitions')]
    public function test_it_rejects_invalid_definitions(array $data): void
    {
        $this->expectException(InvalidChecklistDefinition::class);

        ChecklistDefinition::fromArray($data);
    }

    public function test_the_fingerprint_is_stable_for_identical_content_and_ignores_the_name(): void
    {
        $original = ChecklistDefinition::fromArray(self::validDefinition());

        $this->assertSame($original->fingerprint(), ChecklistDefinition::fromArray(self::validDefinition())->fingerprint());
        $this->assertSame($original->fingerprint(), ChecklistDefinition::fromArray(self::validDefinition(['name' => 'Renamed']))->fingerprint());
        $this->assertSame(
            $original->fingerprint(),
            ChecklistDefinition::fromArray(self::validDefinition(['items' => [
                ['label' => 'Engine oil inspection', 'response_type' => 'good_defective', 'mandatory' => true, 'remarks_required_on_fail' => true],
                ['label' => 'Battery voltage', 'response_type' => 'numeric', 'acceptable_min' => '12.0', 'acceptable_max' => 13.5],
                ['label' => 'Photo of control panel', 'response_type' => 'photo', 'photo_required' => true],
            ]]))->fingerprint(),
            'Equivalent numeric criteria must not create a new version.',
        );
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function contentChanges(): array
    {
        return [
            'item added' => [function (array $data): array {
                $data['items'][] = ['label' => 'Coolant level', 'response_type' => 'pass_fail'];

                return $data;
            }],
            'item removed' => [function (array $data): array {
                array_pop($data['items']);

                return $data;
            }],
            'item edited' => [function (array $data): array {
                $data['items'][0]['mandatory'] = false;

                return $data;
            }],
            'items reordered' => [function (array $data): array {
                $data['items'] = array_reverse($data['items']);

                return $data;
            }],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    #[DataProvider('contentChanges')]
    public function test_the_fingerprint_changes_when_the_items_change(callable $change): void
    {
        $original = ChecklistDefinition::fromArray(self::validDefinition());
        $changed = ChecklistDefinition::fromArray($change(self::validDefinition()));

        $this->assertNotSame($original->fingerprint(), $changed->fingerprint());
    }
}
