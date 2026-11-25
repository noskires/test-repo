<?php

namespace App\Reports;

use App\Dashboard\DashboardCounts;
use App\Dashboard\DashboardKpis;
use App\Dashboard\PmCompliance;
use App\Dashboard\PmTaskDates;
use App\Models\PmTask;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The Check-up compliance report (issue 29): the dashboard's PM Compliance for a period plus one row per PM Task
 * counted as due. Rows come from DashboardKpis::pmComplianceDueTasks(), the same query the dashboard counts, and the
 * summary from PmCompliance::calculate() over those rows, so the row count always equals summary.due. Vendor staff
 * are forced to their own Vendor (DashboardCounts::visibleFilters). Two queries whatever the number of rows.
 *
 * Row columns are provisional (product-owner, 2026-11-25).
 */
final class PmComplianceReport
{
    /** @var array<string, string> Row key => export header, in column order. */
    public const COLUMNS = [
        'site_name' => 'Site',
        'equipment_name' => 'Equipment',
        'equipment_category' => 'Equipment category',
        'vendor_name' => 'Vendor',
        'due_date' => 'Due date',
        'work_done_date' => 'Work done date',
        'status' => 'Status',
        'on_time' => 'On time',
    ];

    /**
     * @param  array{site_id?: string, equipment_category_id?: string, vendor_id?: int}  $filters
     * @return array{period: array{from: string, to: string}, summary: array{due: int, on_time: int, compliance: float|null}, rows: list<array<string, mixed>>}
     */
    public static function for(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
    {
        $tasks = DashboardKpis::pmComplianceDueTasks(DashboardCounts::visibleFilters($user, $filters), $from, $to, $today)
            ->with('vendor:id,name')
            ->orderBy('due_date')
            ->orderBy('site_name_snapshot')
            ->orderBy('equipment_name_snapshot')
            ->orderBy('id')
            ->get();

        $dates = $tasks->map(fn (PmTask $task): PmTaskDates => new PmTaskDates($task->status, $task->due_date, $task->work_done_date));
        $summary = PmCompliance::calculate($dates, $from, $to, $today);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => ['due' => $summary['due'], 'on_time' => $summary['on_time'], 'compliance' => $summary['percent']],
            'rows' => $tasks->map(fn (PmTask $task, int $index): array => [
                'site_name' => $task->site_name_snapshot,
                'equipment_name' => $task->equipment_name_snapshot,
                'equipment_category' => $task->equipment_category_snapshot,
                'vendor_name' => $task->vendor?->name,
                'due_date' => $task->due_date->toDateString(),
                'work_done_date' => $task->work_done_date?->toDateString(),
                'status' => $task->status->value,
                'on_time' => PmCompliance::isOnTime($dates[$index]),
            ])->all(),
        ];
    }

    /**
     * The export filename, e.g. pm-compliance-2026-11-01-to-2026-11-30.xlsx.
     */
    public static function filename(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return "pm-compliance-{$from->toDateString()}-to-{$to->toDateString()}.xlsx";
    }

    /**
     * The report rows as export cells: the same columns in the same order, on_time as Yes / No.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<string|null>>
     */
    public static function exportRows(array $rows): array
    {
        return array_map(fn (array $row): array => array_map(
            fn (string $key): ?string => $key === 'on_time' ? ($row[$key] ? 'Yes' : 'No') : $row[$key],
            array_keys(self::COLUMNS),
        ), $rows);
    }
}
