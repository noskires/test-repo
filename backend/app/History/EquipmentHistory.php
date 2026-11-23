<?php

namespace App\History;

use App\Billing\PesoAmount;
use App\Models\ECall;
use App\Models\PmReport;
use App\Models\PmTask;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The maintenance history of one Equipment (issue 26): its PM Tasks, E-Calls and Work Orders in one read-only list,
 * newest first by entry date. Built only from PSS's own records and their stored Snapshots, so Equipment removed
 * upstream keeps its history; the MasterDataDirectory is never called.
 *
 * Entry date: PM Task = due date; E-Call and Work Order = the Manila date of created_at (stored timestamps are Manila
 * wall-clock time, ADR 0003). Ties: newer created_at first, then higher ID. A Work Order belongs to its E-Call's
 * Equipment, Vendor and Snapshots. Vendor staff only see their own Vendor's records.
 */
final class EquipmentHistory
{
    public const string PM_TASK = 'pm_task';

    public const string E_CALL = 'e_call';

    public const string WORK_ORDER = 'work_order';

    /**
     * @return list<array<string, mixed>>
     */
    public static function for(string $equipmentId, User $user, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $entries = self::pmTasks($equipmentId, $user, $from, $to)
            ->concat(self::eCalls($equipmentId, $user, $from, $to))
            ->concat(self::workOrders($equipmentId, $user, $from, $to));

        return $entries
            ->sort(fn (array $a, array $b): int => [$b['entry_date'], $b['_created_at'], $b['id'], $b['type']]
                <=> [$a['entry_date'], $a['_created_at'], $a['id'], $a['type']])
            ->map(function (array $entry): array {
                unset($entry['_created_at']);

                return $entry;
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function pmTasks(string $equipmentId, User $user, ?CarbonImmutable $from, ?CarbonImmutable $to): Collection
    {
        return PmTask::query()
            ->select('pm_tasks.*')
            ->selectSub(
                PmReport::query()->select('findings')->whereColumn('pm_reports.pm_task_id', 'pm_tasks.id')->orderByDesc('revision')->limit(1),
                'latest_findings',
            )
            ->where('equipment_id', $equipmentId)
            ->when($user->vendor_id !== null, fn (Builder $query) => $query->where('vendor_id', $user->vendor_id))
            ->when($from !== null, fn (Builder $query) => $query->whereDate('due_date', '>=', $from->toDateString()))
            ->when($to !== null, fn (Builder $query) => $query->whereDate('due_date', '<=', $to->toDateString()))
            ->get()
            ->map(fn (PmTask $task): array => [
                'type' => self::PM_TASK,
                'id' => $task->id,
                'entry_date' => $task->due_date->toDateString(),
                'status' => $task->status->value,
                ...self::snapshots($task),
                'work_done_date' => $task->work_done_date?->toDateString(),
                'findings' => $task->getAttribute('latest_findings'),
                'created_at' => $task->created_at->toJSON(),
                '_created_at' => $task->getRawOriginal('created_at'),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function eCalls(string $equipmentId, User $user, ?CarbonImmutable $from, ?CarbonImmutable $to): Collection
    {
        return self::filedBetween(ECall::query()->visibleTo($user)->where('equipment_id', $equipmentId), 'created_at', $from, $to)
            ->get()
            ->map(fn (ECall $eCall): array => [
                'type' => self::E_CALL,
                'id' => $eCall->id,
                'entry_date' => $eCall->created_at->toDateString(),
                'status' => $eCall->status->value,
                ...self::snapshots($eCall),
                'priority' => $eCall->priority->value,
                'pm_task_id' => $eCall->pm_task_id,
                'created_at' => $eCall->created_at->toJSON(),
                '_created_at' => $eCall->getRawOriginal('created_at'),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function workOrders(string $equipmentId, User $user, ?CarbonImmutable $from, ?CarbonImmutable $to): Collection
    {
        $query = WorkOrder::query()
            ->whereHas('eCall', fn (Builder $eCall) => $eCall->visibleTo($user)->where('equipment_id', $equipmentId))
            ->with('eCall');

        return self::filedBetween($query, 'work_orders.created_at', $from, $to)
            ->get()
            ->map(fn (WorkOrder $workOrder): array => [
                'type' => self::WORK_ORDER,
                'id' => $workOrder->id,
                'entry_date' => $workOrder->created_at->toDateString(),
                'status' => $workOrder->status->value,
                ...self::snapshots($workOrder->eCall),
                'e_call_id' => $workOrder->e_call_id,
                'estimated_amount' => PesoAmount::fromCentavos($workOrder->estimated_amount_centavos),
                'estimated_amount_exact' => PesoAmount::fromMillicentavos($workOrder->estimated_amount_millicentavos),
                'billing_amount' => $workOrder->billing_amount_centavos === null ? null : PesoAmount::fromCentavos($workOrder->billing_amount_centavos),
                'billing_amount_exact' => $workOrder->billing_amount_millicentavos === null ? null : PesoAmount::fromMillicentavos($workOrder->billing_amount_millicentavos),
                'created_at' => $workOrder->created_at->toJSON(),
                '_created_at' => $workOrder->getRawOriginal('created_at'),
            ]);
    }

    /**
     * Narrow by the Manila date of a timestamp column, inclusive, as a range so the column's index stays usable.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function filedBetween(Builder $query, string $column, ?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $query) => $query->where($column, '>=', $from->startOfDay()->toDateTimeString()))
            ->when($to !== null, fn (Builder $query) => $query->where($column, '<', $to->addDay()->startOfDay()->toDateTimeString()));
    }

    /**
     * @return array{site_id: string, site_name_snapshot: string|null, equipment_id: string|null, equipment_name_snapshot: string|null, equipment_category_snapshot: string|null}
     */
    private static function snapshots(Model $record): array
    {
        return [
            'site_id' => $record->site_id,
            'site_name_snapshot' => $record->site_name_snapshot,
            'equipment_id' => $record->equipment_id,
            'equipment_name_snapshot' => $record->equipment_name_snapshot,
            'equipment_category_snapshot' => $record->equipment_category_snapshot,
        ];
    }
}
