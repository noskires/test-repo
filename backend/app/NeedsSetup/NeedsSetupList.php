<?php

namespace App\NeedsSetup;

use App\Enums\WorkOrderStatus;
use App\MasterData\Data\Site;
use App\MasterData\ImmediateHeadChain;
use App\MasterData\MasterDataDirectory;
use App\Models\BillingThresholdSetting;
use App\Models\SiteSetting;
use App\Models\WorkOrder;
use App\Scheduling\PmStartDates;

/**
 * The admin Needs Setup list (GLOSSARY "Needs Setup list", issue 32): what stops PM Tasks from being generated and
 * which Work Orders are stuck on their Requestor's Immediate Head chain. Computed live from the directory and PSS's
 * own records on every call; nothing is stored. Sites first, then Equipment, then Work Orders, each by ID.
 *
 * Every rule is borrowed, never copied: Equipment uses PmStartDates (the PM generation job's rule), Work Orders use
 * WorkOrder::chainSetupProblems (the endorsement and billing actions' checks). One ImmediateHeadChain and one read of
 * the billing thresholds serve the whole request.
 */
final class NeedsSetupList
{
    public function __construct(private readonly MasterDataDirectory $directory) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        [$sites, $equipment] = $this->siteAndEquipmentEntries();

        return [...$sites, ...$equipment, ...$this->workOrderEntries()];
    }

    /**
     * Sites with a PM Frequency that miss their Site Vendor or EIC, and Equipment at any Site with a PM Frequency that
     * the PM generation job skips for lack of a start date. A Site without a PM Frequency hides its Equipment; a Site
     * missing only its Vendor or EIC does not. Sites with settings that are gone from the directory are skipped, as
     * the job skips them.
     *
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function siteAndEquipmentEntries(): array
    {
        $settings = SiteSetting::query()->whereNotNull('pm_frequency')->get()->keyBy('site_id');
        $sites = collect($this->directory->listSites())
            ->filter(fn (Site $site): bool => $settings->has($site->id))
            ->sortBy('id', SORT_STRING)
            ->values();

        $siteEntries = [];
        $equipmentEntries = [];
        $startDates = new PmStartDates;

        foreach ($sites as $site) {
            $setting = $settings->get($site->id);
            $reasons = array_values(array_filter([
                $setting->vendor_id === null ? 'no_site_vendor' : null,
                $setting->eic_employee_id === null ? 'no_eic' : null,
            ]));

            if ($reasons !== []) {
                $siteEntries[] = ['type' => 'site', 'id' => $site->id, 'name' => $site->name, 'reasons' => $reasons];
            }

            $equipmentAtSite = $this->directory->listEquipmentAtSite($site->id);
            $startDates->preload(array_map(fn ($equipment): string => $equipment->id, $equipmentAtSite));

            foreach ($equipmentAtSite as $equipment) {
                if ($startDates->needsStartDate($equipment)) {
                    $equipmentEntries[] = [
                        'type' => 'equipment',
                        'id' => $equipment->id,
                        'name' => $equipment->name,
                        'site' => ['id' => $site->id, 'name' => $site->name],
                        'reasons' => ['no_start_date'],
                    ];
                }
            }
        }

        usort($equipmentEntries, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return [$siteEntries, $equipmentEntries];
    }

    /**
     * Work Orders waiting on an endorsement or billing level whose chain doesn't resolve right now.
     *
     * @return list<array<string, mixed>>
     */
    private function workOrderEntries(): array
    {
        $statuses = array_values(array_filter(
            WorkOrderStatus::cases(),
            fn (WorkOrderStatus $status): bool => in_array($status, [WorkOrderStatus::Open, WorkOrderStatus::Implemented], true)
                || $status->endorsementLevel() !== null
                || $status->billingLevel() !== null,
        ));
        $chain = app(ImmediateHeadChain::class);
        $thresholds = BillingThresholdSetting::current();
        $entries = [];

        WorkOrder::query()
            ->whereIn('status', $statuses)
            ->with('eCall.requestor')
            ->lazyById(200)
            ->each(function (WorkOrder $workOrder) use ($chain, $thresholds, &$entries): void {
                $reasons = $workOrder->chainSetupProblems($chain, $thresholds);

                if ($reasons === []) {
                    return;
                }

                $eCall = $workOrder->eCall;
                $entries[] = [
                    'type' => 'work_order',
                    'id' => $workOrder->id,
                    'status' => $workOrder->status->value,
                    'e_call' => ['id' => $eCall->id, 'nature_of_issue' => $eCall->nature_of_issue],
                    'site' => ['id' => $eCall->site_id, 'name' => $eCall->site_name_snapshot],
                    'requestor' => ['employee_id' => $eCall->requestor_employee_id, 'name' => $eCall->requestor?->name],
                    'reasons' => $reasons,
                ];
            });

        return $entries;
    }
}
