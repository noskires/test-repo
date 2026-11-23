<?php

namespace App\Waiting;

use App\Enums\ECallStatus;
use App\Models\ApprovalRecord;
use App\Models\ECall;
use App\Models\ECallProposal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns E-Calls into "Waiting for me" items (issue 39): titled by the nature of issue, with the Site Snapshot,
 * status and priority. Waiting since the latest of: filing, the latest FSR + BOQ revision's submission, and the
 * latest Approval Record.
 */
final class ECallWaitingItems
{
    public const TYPE = 'e_call';

    /**
     * E-Calls in the given statuses, with the two timestamps waiting_since needs loaded as subqueries, so each
     * provider stays one query.
     *
     * @param  list<ECallStatus>  $statuses
     * @return Builder<ECall>
     */
    public static function query(array $statuses): Builder
    {
        return ECall::query()
            ->whereIn('status', $statuses)
            ->select('e_calls.*')
            ->addSelect([
                'latest_proposal_submitted_at' => ECallProposal::query()
                    ->select('submitted_at')
                    ->whereColumn('e_call_id', 'e_calls.id')
                    ->orderByDesc('revision')
                    ->limit(1),
                'latest_approval_at' => ApprovalRecord::query()
                    ->select('created_at')
                    ->where('subject_type', (new ECall)->getMorphClass())
                    ->whereColumn('subject_id', 'e_calls.id')
                    ->orderByDesc('id')
                    ->limit(1),
            ])
            ->withCasts([
                'latest_proposal_submitted_at' => 'immutable_datetime',
                'latest_approval_at' => 'immutable_datetime',
            ]);
    }

    /**
     * @param  Builder<ECall>  $query  A query built by query().
     * @return Collection<int, WaitingItem>
     */
    public static function from(Builder $query): Collection
    {
        return $query->get()->map(fn (ECall $eCall): WaitingItem => self::make($eCall));
    }

    private static function make(ECall $eCall): WaitingItem
    {
        $waitingSince = collect([
            $eCall->created_at?->toImmutable(),
            $eCall->latest_proposal_submitted_at,
            $eCall->latest_approval_at,
        ])->filter()->max() ?? $eCall->updated_at->toImmutable();

        return new WaitingItem(
            type: self::TYPE,
            id: $eCall->id,
            title: $eCall->nature_of_issue,
            siteId: $eCall->site_id,
            siteName: $eCall->site_name_snapshot,
            status: $eCall->status->value,
            timeliness: null,
            waitingSince: CarbonImmutable::instance($waitingSince),
            priority: $eCall->priority->value,
        );
    }
}
