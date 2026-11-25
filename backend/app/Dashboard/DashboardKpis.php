<?php

namespace App\Dashboard;

use App\Enums\ECallPriority;
use App\Enums\PmStatus;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use App\MasterData\MasterDataDirectory;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard KPIs (issue 28): PM Compliance, operational status and Equipment Requiring Attention. Gathers the
 * facts and hands them to the pure rules (PmCompliance, OperatingState, EquipmentAttention).
 *
 * Filters follow issue 27 (DashboardCounts::visibleFilters). PM Compliance filters on pm_tasks columns. The Equipment
 * figures cover the Equipment the directory lists at every Site (or the filtered Site), narrowed by category and, for
 * vendor_id, to Sites whose site_settings.vendor_id is that Vendor; a Site without settings has no Site Vendor.
 *
 * Queries are fixed in number whatever the number of Equipment: the vendor's Sites (only with vendor_id), PM
 * Compliance's tasks, open PM Tasks, Completed PM Tasks, latest-revision failed reports and open Emergency E-Calls.
 * The directory is called once per covered Site.
 */
final class DashboardKpis
{
    /**
     * @param  array{site_id?: string, equipment_category_id?: string, vendor_id?: int}  $filters
     * @return array{pm: array{compliance: array<string, mixed>}, equipment: array<string, mixed>}
     */
    public static function for(User $user, array $filters, MasterDataDirectory $directory, CarbonImmutable $today): array
    {
        $filters = DashboardCounts::visibleFilters($user, $filters);
        [$from, $to] = self::defaultPeriod($today);
        $equipment = self::coveredEquipment($filters, $directory);

        return [
            'pm' => [
                'compliance' => [
                    'period_from' => $from->toDateString(),
                    'period_to' => $to->toDateString(),
                    ...self::pmCompliance($filters, $from, $to, $today),
                ],
            ],
            'equipment' => [
                'operating' => self::operating($equipment),
                'requiring_attention' => self::requiringAttention($equipment, $filters['vendor_id'] ?? null, $today),
            ],
        ];
    }

    /**
     * Provisional (until issue 42 adds a date range): the current calendar month in the app timezone (Asia/Manila).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function defaultPeriod(CarbonImmutable $today): array
    {
        return [$today->startOfMonth()->startOfDay(), $today->endOfMonth()->startOfDay()];
    }

    /**
     * @param  array{site_id?: string, equipment_category_id?: string, vendor_id?: int}  $filters
     * @return array{due: int, on_time: int, percent: float|null}
     */
    private static function pmCompliance(array $filters, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
    {
        $query = PmTask::query()
            ->whereNotIn('status', PmCompliance::EXCLUDED_STATUSES)
            ->whereBetween('due_date', [$from->toDateString(), PmCompliance::dueWindowEnd($to, $today)]);
        foreach (['site_id', 'equipment_category_id', 'vendor_id'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where('pm_tasks.'.$column, $filters[$column]);
            }
        }

        $tasks = $query->get(['status', 'due_date', 'work_done_date'])
            ->map(fn (PmTask $task): PmTaskDates => new PmTaskDates($task->status, $task->due_date, $task->work_done_date));

        return PmCompliance::calculate($tasks, $from, $to, $today);
    }

    /**
     * @param  array{site_id?: string, equipment_category_id?: string, vendor_id?: int}  $filters
     * @return list<Equipment>
     */
    private static function coveredEquipment(array $filters, MasterDataDirectory $directory): array
    {
        $siteIds = array_key_exists('site_id', $filters)
            ? [$filters['site_id']]
            : array_map(fn (Site $site): string => $site->id, $directory->listSites());

        if (array_key_exists('vendor_id', $filters)) {
            $vendorSiteIds = SiteSetting::query()->where('vendor_id', $filters['vendor_id'])->pluck('site_id')->all();
            $siteIds = array_values(array_intersect($siteIds, $vendorSiteIds));
        }

        $equipment = [];
        foreach (array_unique($siteIds) as $siteId) {
            foreach ($directory->listEquipmentAtSite($siteId) as $item) {
                if (! array_key_exists('equipment_category_id', $filters) || $item->categoryId === $filters['equipment_category_id']) {
                    $equipment[] = $item;
                }
            }
        }

        return $equipment;
    }

    /**
     * @param  list<Equipment>  $equipment
     * @return array<string, int>
     */
    private static function operating(array $equipment): array
    {
        $counts = array_fill_keys(array_map(fn (OperatingState $state): string => $state->value, OperatingState::cases()), 0);
        foreach ($equipment as $item) {
            $counts[OperatingState::classify($item->operatingStatus)->value]++;
        }

        return $counts;
    }

    /**
     * With an effective Vendor (vendor staff are forced to their own; an internal user may pass vendor_id), the
     * overdue_pm, failed_items and emergency_e_call reasons count only that Vendor's PM Tasks and E-Calls, so a Site's
     * former Vendor's records never show (issue 49). not_operating comes from master data and is not narrowed.
     *
     * @param  list<Equipment>  $equipment
     * @return array{count: int, items: list<array{equipment_id: string, equipment_name: string, site_id: string, reasons: list<string>}>}
     */
    private static function requiringAttention(array $equipment, ?int $vendorId, CarbonImmutable $today): array
    {
        if ($equipment === []) {
            return ['count' => 0, 'items' => []];
        }

        $equipmentIds = array_values(array_unique(array_map(fn (Equipment $item): string => $item->id, $equipment)));

        $openTasks = PmTask::query()->open()->whereIn('equipment_id', $equipmentIds)
            ->when($vendorId !== null, fn ($query) => $query->where('vendor_id', $vendorId))
            ->get(['equipment_id', 'status', 'due_date', 'work_done_date'])
            ->groupBy('equipment_id');

        $withFailedItems = self::equipmentWithFailedLatestReport($equipmentIds, $vendorId);

        $withEmergency = array_flip(ECall::query()
            ->whereIn('equipment_id', $equipmentIds)
            ->where('priority', ECallPriority::Emergency)
            ->whereNotIn('status', DashboardCounts::CLOSED_E_CALL_STATUSES)
            ->when($vendorId !== null, fn ($query) => $query->where('vendor_id', $vendorId))
            ->distinct()
            ->pluck('equipment_id')
            ->all());

        $items = [];
        foreach ($equipment as $item) {
            $reasons = EquipmentAttention::reasons(
                ($openTasks[$item->id] ?? collect())->map(
                    fn (PmTask $task): PmTaskDates => new PmTaskDates($task->status, $task->due_date, $task->work_done_date),
                ),
                isset($withFailedItems[$item->id]),
                isset($withEmergency[$item->id]),
                OperatingState::classify($item->operatingStatus),
                $today,
            );
            if ($reasons !== []) {
                $items[] = [
                    'equipment_id' => $item->id,
                    'equipment_name' => $item->name,
                    'site_id' => $item->siteId,
                    'reasons' => array_map(fn (AttentionReason $reason): string => $reason->value, $reasons),
                ];
            }
        }

        usort($items, fn (array $a, array $b): int => [$a['site_id'], $a['equipment_id']] <=> [$b['site_id'], $b['equipment_id']]);

        return ['count' => count($items), 'items' => $items];
    }

    /**
     * The Equipment whose most recent Completed PM Task (latest due date, then highest id) has a failed response on
     * the highest revision of its PM Report. With a Vendor, only that Vendor's Completed PM Tasks are considered, so
     * "most recent" means that Vendor's most recent. Two queries.
     *
     * @param  list<string>  $equipmentIds
     * @return array<string, int> Equipment IDs as keys.
     */
    private static function equipmentWithFailedLatestReport(array $equipmentIds, ?int $vendorId): array
    {
        $latestTaskIdByEquipment = PmTask::query()
            ->where('status', PmStatus::Completed)
            ->whereIn('equipment_id', $equipmentIds)
            ->when($vendorId !== null, fn ($query) => $query->where('vendor_id', $vendorId))
            ->orderBy('due_date')
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'equipment_id'])
            ->pluck('id', 'equipment_id')
            ->all();

        $failedTaskIds = DB::table('pm_reports')
            ->whereIn('pm_task_id', array_values($latestTaskIdByEquipment))
            ->whereRaw('revision = (select max(r2.revision) from pm_reports r2 where r2.pm_task_id = pm_reports.pm_task_id)')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('pm_report_responses')
                ->whereColumn('pm_report_responses.pm_report_id', 'pm_reports.id')
                ->where('pm_report_responses.is_failed', true))
            ->pluck('pm_task_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $failed = array_flip($failedTaskIds);

        return array_flip(array_keys(array_filter(
            $latestTaskIdByEquipment,
            fn ($taskId): bool => isset($failed[(int) $taskId]),
        )));
    }
}
