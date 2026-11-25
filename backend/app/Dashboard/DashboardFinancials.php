<?php

namespace App\Dashboard;

use App\Billing\PesoAmount;
use App\Enums\ApprovalAction;
use App\Enums\PmStatus;
use App\Enums\WorkOrderStatus;
use App\Models\PmTask;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's date-range figures (issue 42): PM Tasks Completed in the range and the Work Order money totals.
 * Each figure filters on its own event date, compared as Manila calendar dates (timestamps are stored in the app
 * timezone, Asia/Manila):
 * - estimated: estimated amount of Work Orders not Rejected or Cancelled, by the Work Order's created_at;
 * - approved: estimated amount of Work Orders by their latest LVL2 approve Approval Record, unless now Rejected;
 * - billing: Billing Amount of Closed Work Orders, by the Approval Record whose resulting status is Closed.
 *
 * Money is summed as the stored, already-rounded integer centavos (ADR 0002) and formatted only at the edge. Filters
 * reach a Work Order through its E-Call, like DashboardCounts; the caller passes them already through
 * DashboardCounts::visibleFilters, so vendor staff are limited to their own Vendor. Queries are fixed in number: one
 * COUNT for PM, one grouped SUM per money figure and one Vendor-name lookup.
 */
final class DashboardFinancials
{
    /**
     * @param  array{site_id?: string, equipment_category_id?: string, vendor_id?: int}  $filters
     */
    public static function completedPmTasks(array $filters, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $query = PmTask::query()
            ->where('status', PmStatus::Completed)
            ->where('work_done_date', '>=', $from->toDateString())
            ->where('work_done_date', '<', $to->addDay()->toDateString());
        foreach (['site_id', 'equipment_category_id', 'vendor_id'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where('pm_tasks.'.$column, $filters[$column]);
            }
        }

        return $query->count();
    }

    /**
     * @param  array{site_id?: string, equipment_category_id?: string, vendor_id?: int}  $filters
     * @return array<string, array{total: string, by_site: list<array{site_id: string, site_name: string, amount: string}>, by_vendor: list<array{vendor_id: int, vendor_name: string, amount: string}>}>
     */
    public static function for(array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = [$from->startOfDay()->toDateTimeString(), $to->addDay()->startOfDay()->toDateTimeString()];

        $groups = [
            'estimated' => self::grouped(
                self::workOrders($filters)
                    ->whereNotIn('work_orders.status', [WorkOrderStatus::Rejected->value, WorkOrderStatus::Cancelled->value])
                    ->where('work_orders.created_at', '>=', $start)
                    ->where('work_orders.created_at', '<', $end),
                'work_orders.estimated_amount_centavos',
            ),
            'approved' => self::grouped(
                self::workOrders($filters)
                    ->joinSub(self::latestEvent(fn (Builder $query) => $query
                        ->where('action', ApprovalAction::Approve->value)
                        ->where('previous_status', WorkOrderStatus::ForLvl2Endorsement->value)), 'lvl2', 'lvl2.subject_id', '=', 'work_orders.id')
                    ->where('work_orders.status', '!=', WorkOrderStatus::Rejected->value)
                    ->where('lvl2.event_at', '>=', $start)
                    ->where('lvl2.event_at', '<', $end),
                'work_orders.estimated_amount_centavos',
            ),
            'billing' => self::grouped(
                self::workOrders($filters)
                    ->joinSub(self::latestEvent(fn (Builder $query) => $query
                        ->where('resulting_status', WorkOrderStatus::Closed->value)), 'closing', 'closing.subject_id', '=', 'work_orders.id')
                    ->where('work_orders.status', WorkOrderStatus::Closed->value)
                    ->where('closing.event_at', '>=', $start)
                    ->where('closing.event_at', '<', $end),
                'work_orders.billing_amount_centavos',
            ),
        ];

        $vendorIds = array_unique(array_merge(...array_values(array_map(fn (array $rows): array => array_column($rows, 'vendor_id'), $groups))));
        $vendorNames = $vendorIds === [] ? [] : Vendor::query()->whereIn('id', $vendorIds)->pluck('name', 'id')->all();

        return array_map(fn (array $rows): array => self::summarise($rows, $vendorNames), $groups);
    }

    /**
     * Work Orders joined to their E-Call, narrowed by the dashboard filters on the E-Call.
     *
     * @param  array{site_id?: string, equipment_category_id?: string, vendor_id?: int}  $filters
     */
    private static function workOrders(array $filters): Builder
    {
        $query = DB::table('work_orders')->join('e_calls', 'e_calls.id', '=', 'work_orders.e_call_id');
        foreach (['site_id', 'equipment_category_id', 'vendor_id'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where('e_calls.'.$column, $filters[$column]);
            }
        }

        return $query;
    }

    /**
     * The latest matching Work Order Approval Record per Work Order: subject_id and event_at.
     *
     * @param  callable(Builder): mixed  $matching
     */
    private static function latestEvent(callable $matching): Builder
    {
        $query = DB::table('approval_records')
            ->where('subject_type', 'work_order')
            ->selectRaw('subject_id, MAX(created_at) as event_at')
            ->groupBy('subject_id');
        $matching($query);

        return $query;
    }

    /**
     * One grouped SUM per (Site, Vendor) pair; the caller folds the pairs into totals.
     *
     * @return list<array{site_id: string, site_name: string, vendor_id: int, centavos: int}>
     */
    private static function grouped(Builder $query, string $amountColumn): array
    {
        return $query
            ->selectRaw('e_calls.site_id, e_calls.vendor_id, MAX(e_calls.site_name_snapshot) as site_name, SUM('.$amountColumn.') as centavos')
            ->groupBy('e_calls.site_id', 'e_calls.vendor_id')
            ->get()
            ->map(fn (object $row): array => [
                'site_id' => (string) $row->site_id,
                'site_name' => (string) $row->site_name,
                'vendor_id' => (int) $row->vendor_id,
                'centavos' => (int) $row->centavos,
            ])
            ->filter(fn (array $row): bool => $row['centavos'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  list<array{site_id: string, site_name: string, vendor_id: int, centavos: int}>  $rows
     * @param  array<int, string>  $vendorNames
     * @return array{total: string, by_site: list<array{site_id: string, site_name: string, amount: string}>, by_vendor: list<array{vendor_id: int, vendor_name: string, amount: string}>}
     */
    private static function summarise(array $rows, array $vendorNames): array
    {
        $sites = [];
        $vendors = [];
        foreach ($rows as $row) {
            $site = $sites[$row['site_id']] ?? ['site_id' => $row['site_id'], 'site_name' => $row['site_name'], 'centavos' => 0];
            $site['site_name'] = max($site['site_name'], $row['site_name']);
            $site['centavos'] += $row['centavos'];
            $sites[$row['site_id']] = $site;

            $vendor = $vendors[$row['vendor_id']] ?? ['vendor_id' => $row['vendor_id'], 'vendor_name' => $vendorNames[$row['vendor_id']] ?? '', 'centavos' => 0];
            $vendor['centavos'] += $row['centavos'];
            $vendors[$row['vendor_id']] = $vendor;
        }

        $bySite = array_values($sites);
        usort($bySite, fn (array $a, array $b): int => [$a['site_name'], $a['site_id']] <=> [$b['site_name'], $b['site_id']]);
        $byVendor = array_values($vendors);
        usort($byVendor, fn (array $a, array $b): int => [$a['vendor_name'], $a['vendor_id']] <=> [$b['vendor_name'], $b['vendor_id']]);

        return [
            'total' => PesoAmount::fromCentavos(array_sum(array_column($rows, 'centavos'))),
            'by_site' => array_map(fn (array $site): array => [
                'site_id' => $site['site_id'],
                'site_name' => $site['site_name'],
                'amount' => PesoAmount::fromCentavos($site['centavos']),
            ], $bySite),
            'by_vendor' => array_map(fn (array $vendor): array => [
                'vendor_id' => $vendor['vendor_id'],
                'vendor_name' => $vendor['vendor_name'],
                'amount' => PesoAmount::fromCentavos($vendor['centavos']),
            ], $byVendor),
        ];
    }
}
