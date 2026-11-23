<?php

namespace App\Scheduling;

use App\Enums\PmStatus;
use App\MasterData\Data\Equipment;
use App\Models\PmTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE rule for when an Equipment can get a PM Task, shared by the PM generation job (GeneratePmTasks) and the Needs
 * Setup list (issue 32) so the two can never disagree. An Equipment with an open PM Task is already covered. Without
 * one, its next task is due from its start date: the latest Completed task's Work Done Date, else its installation
 * date from the directory. No open task and no start date means Needs Setup (`no_start_date`).
 *
 * Looks each Equipment up lazily, one query per question. preload() answers a whole batch in two queries instead;
 * use it for lists. Use one instance per run or request: it caches what it read.
 */
final class PmStartDates
{
    /** @var array<string, bool> */
    private array $hasOpenTask = [];

    /** @var array<string, ?string> latest Completed Work Done Date per Equipment ID */
    private array $lastWorkDoneDate = [];

    /**
     * Read the open tasks and the latest Work Done Dates of these Equipment in two queries.
     *
     * @param  list<string>  $equipmentIds
     */
    public function preload(array $equipmentIds): void
    {
        if ($equipmentIds === []) {
            return;
        }

        $withOpenTask = PmTask::query()->open()->whereIn('equipment_id', $equipmentIds)->distinct()->pluck('equipment_id')->flip();
        $lastWorkDone = $this->completedWithWorkDoneDate()
            ->whereIn('equipment_id', $equipmentIds)
            ->groupBy('equipment_id')
            ->selectRaw('equipment_id, max(work_done_date) as last_work_done_date')
            ->pluck('last_work_done_date', 'equipment_id');

        foreach ($equipmentIds as $equipmentId) {
            $this->hasOpenTask[$equipmentId] = $withOpenTask->has($equipmentId);
            $this->lastWorkDoneDate[$equipmentId] = $lastWorkDone->get($equipmentId);
        }
    }

    public function hasOpenTask(string $equipmentId): bool
    {
        return $this->hasOpenTask[$equipmentId] ??= PmTask::query()->open()->where('equipment_id', $equipmentId)->exists();
    }

    /**
     * The latest Completed task's Work Done Date, else the installation date, else null (Needs Setup, issue 32).
     */
    public function dueFromDate(Equipment $equipment): ?CarbonImmutable
    {
        if (! array_key_exists($equipment->id, $this->lastWorkDoneDate)) {
            $this->lastWorkDoneDate[$equipment->id] = $this->completedWithWorkDoneDate()
                ->where('equipment_id', $equipment->id)
                ->max('work_done_date');
        }

        $lastWorkDoneDate = $this->lastWorkDoneDate[$equipment->id];

        if ($lastWorkDoneDate !== null) {
            return CarbonImmutable::parse($lastWorkDoneDate)->startOfDay();
        }

        return $equipment->installedOn === null ? null : CarbonImmutable::instance($equipment->installedOn)->startOfDay();
    }

    /**
     * Whether the PM generation job skips this Equipment for lack of a start date: no open task and no start date.
     */
    public function needsStartDate(Equipment $equipment): bool
    {
        return ! $this->hasOpenTask($equipment->id) && $this->dueFromDate($equipment) === null;
    }

    /**
     * @return Builder<PmTask>
     */
    private function completedWithWorkDoneDate(): Builder
    {
        return PmTask::query()->where('status', PmStatus::Completed)->whereNotNull('work_done_date');
    }
}
