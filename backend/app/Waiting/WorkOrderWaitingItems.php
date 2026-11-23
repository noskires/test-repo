<?php

namespace App\Waiting;

use App\Enums\WorkOrderStatus;
use App\Models\ApprovalRecord;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns Work Orders into "Waiting for me" items (issue 40). A Work Order has no Site or title of its own: both come
 * from its E-Call (the Site Snapshot and the nature of issue). Waiting since the latest of its creation and its latest
 * Approval Record. Timeliness and priority are null.
 */
final class WorkOrderWaitingItems
{
    public const TYPE = 'work_order';

    /**
     * Work Orders in the given statuses with their E-Call eager loaded and the latest Approval Record's time as a
     * subquery, so each provider stays two queries.
     *
     * @param  list<WorkOrderStatus>  $statuses
     * @return Builder<WorkOrder>
     */
    public static function query(array $statuses): Builder
    {
        return WorkOrder::query()
            ->whereIn('status', $statuses)
            ->with('eCall')
            ->select('work_orders.*')
            ->addSelect([
                'latest_approval_at' => ApprovalRecord::query()
                    ->select('created_at')
                    ->where('subject_type', (new WorkOrder)->getMorphClass())
                    ->whereColumn('subject_id', 'work_orders.id')
                    ->orderByDesc('id')
                    ->limit(1),
            ])
            ->withCasts(['latest_approval_at' => 'immutable_datetime']);
    }

    /**
     * @param  iterable<WorkOrder>  $workOrders  Loaded through query().
     * @return Collection<int, WaitingItem>
     */
    public static function from(iterable $workOrders): Collection
    {
        return collect($workOrders)->map(fn (WorkOrder $workOrder): WaitingItem => self::make($workOrder))->values();
    }

    private static function make(WorkOrder $workOrder): WaitingItem
    {
        $waitingSince = collect([
            $workOrder->created_at?->toImmutable(),
            $workOrder->latest_approval_at,
        ])->filter()->max() ?? $workOrder->updated_at->toImmutable();

        return new WaitingItem(
            type: self::TYPE,
            id: $workOrder->id,
            title: $workOrder->eCall->nature_of_issue,
            siteId: $workOrder->eCall->site_id,
            siteName: $workOrder->eCall->site_name_snapshot,
            status: $workOrder->status->value,
            timeliness: null,
            waitingSince: CarbonImmutable::instance($waitingSince),
        );
    }
}
