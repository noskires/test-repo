<?php

namespace App\Dashboard;

use App\Enums\ECallStatus;
use App\Enums\PmStatus;
use App\Enums\WorkOrderStatus;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard's current-state counts (issue 27): open PM Tasks by PM Status, E-Calls by E-Call Status plus an open
 * total, and Work Orders by status plus the workflow groups. Each section is one grouped COUNT query; no rows are
 * loaded into PHP. Filters (site, equipment category, vendor) AND together; Work Orders filter through their E-Call.
 * Vendor staff are always limited to their own Vendor, whatever vendor_id they pass. Every status key is present,
 * zero included, so issues 28 and 42 can add keys beside these without renaming.
 */
final class DashboardCounts
{
    /**
     * @param  array{site_id?: string, equipment_category_id?: string, vendor_id?: int}  $filters
     * @return array<string, mixed>
     */
    public static function for(User $user, array $filters): array
    {
        if ($user->vendor_id !== null) {
            $filters['vendor_id'] = $user->vendor_id;
        }

        $pm = self::countByStatus(
            PmTask::query()->whereNotIn('status', [PmStatus::Completed, PmStatus::Cancelled]),
            'pm_tasks',
            $filters,
        );
        $eCalls = self::countByStatus(ECall::query(), 'e_calls', $filters);
        $workOrders = self::countByStatus(
            WorkOrder::query()->join('e_calls', 'e_calls.id', '=', 'work_orders.e_call_id'),
            'e_calls',
            $filters,
            'work_orders.status',
        );

        $openPm = array_filter(PmStatus::cases(), fn (PmStatus $status): bool => $status->isOpen());
        $closedECall = [ECallStatus::Closed, ECallStatus::Rejected, ECallStatus::Cancelled];
        $eCallsByStatus = self::keyed(ECallStatus::cases(), $eCalls);
        $woByStatus = self::keyed(WorkOrderStatus::cases(), $workOrders);
        $sum = fn (array $statuses): int => array_sum(array_map(fn (WorkOrderStatus $s): int => $woByStatus[$s->value], $statuses));

        return [
            'pm' => [
                'by_status' => self::keyed(array_values($openPm), $pm),
            ],
            'e_calls' => [
                'by_status' => $eCallsByStatus,
                'open' => array_sum(array_map(
                    fn (ECallStatus $s): int => in_array($s, $closedECall, true) ? 0 : $eCallsByStatus[$s->value],
                    ECallStatus::cases(),
                )),
            ],
            'work_orders' => [
                'by_status' => $woByStatus,
                'groups' => [
                    'awaiting_endorsement' => $sum([WorkOrderStatus::ForLvl1Endorsement, WorkOrderStatus::ForLvl2Endorsement]),
                    'under_implementation' => $sum([WorkOrderStatus::ForImplementation]),
                    'awaiting_eic_validation' => $sum([WorkOrderStatus::Implemented]),
                    'awaiting_billing_endorsement' => $sum([
                        WorkOrderStatus::ForBillingEndorsementLvl1, WorkOrderStatus::ForBillingEndorsementLvl2,
                        WorkOrderStatus::ForBillingEndorsementLvl3, WorkOrderStatus::ForBillingEndorsementLvl4,
                    ]),
                    'closed' => $sum([WorkOrderStatus::Closed]),
                ],
            ],
        ];
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array{site_id?: string, equipment_category_id?: string, vendor_id?: int}  $filters
     * @return array<string, int>
     */
    private static function countByStatus(Builder $query, string $filterTable, array $filters, string $statusColumn = 'status'): array
    {
        foreach (['site_id', 'equipment_category_id', 'vendor_id'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($filterTable.'.'.$column, $filters[$column]);
            }
        }

        return $query->toBase()
            ->selectRaw($statusColumn.' as status, COUNT(*) as aggregate')
            ->groupBy($statusColumn)
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @param  list<\BackedEnum>  $statuses
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private static function keyed(array $statuses, array $counts): array
    {
        $keyed = [];
        foreach ($statuses as $status) {
            $keyed[$status->value] = $counts[$status->value] ?? 0;
        }

        return $keyed;
    }
}
