<?php

namespace App\Jobs;

use App\Enums\PmStatus;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use App\MasterData\MasterDataDirectory;
use App\Models\ChecklistTemplate;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Scheduling\PmStartDates;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Daily: make sure every eligible Equipment has exactly one open PM Task (PRD §6.4), and flag open tasks
 * whose Equipment is gone from the directory. Safe to run twice or concurrently: the unique open key on
 * pm_tasks rejects a second open task, and a losing insert is skipped.
 *
 * Eligible: the Site has a PM Frequency, a Site Vendor and an EIC, and the Equipment has a start date
 * (PmStartDates: the latest Completed task's Work Done Date, else its installation date). Equipment skipped for
 * lack of a start date shows on the Needs Setup list (issue 32), which uses the same PmStartDates rule.
 * Inactive Equipment (gone from the source database, issue 03) gets no new task, and its open task is flagged.
 */
class GeneratePmTasks implements ShouldQueue
{
    use Queueable;

    /** @var array<string, ?int> checklist version ID per category, for this run */
    private array $checklistVersionIds = [];

    /** @var array<string, string> category name per category ID, for this run */
    private array $categoryNames = [];

    /** The shared open-task and start-date rule (issue 32), for this run */
    private PmStartDates $startDates;

    public function handle(MasterDataDirectory $directory): void
    {
        $this->startDates = new PmStartDates;

        foreach ($directory->listEquipmentCategories() as $category) {
            $this->categoryNames[$category->id] = $category->name;
        }

        $settings = SiteSetting::query()
            ->whereNotNull('pm_frequency')
            ->whereNotNull('vendor_id')
            ->whereNotNull('eic_employee_id')
            ->get()
            ->keyBy('site_id');

        foreach ($directory->listSites() as $site) {
            $setting = $settings->get($site->id);

            if ($setting === null) {
                continue;
            }

            foreach ($directory->listEquipmentAtSite($site->id) as $equipment) {
                $this->ensureOpenTask($site, $equipment, $setting);
            }
        }

        $this->flagRemovedEquipment($directory);
    }

    private function ensureOpenTask(Site $site, Equipment $equipment, SiteSetting $setting): void
    {
        if (! $equipment->isActive || $this->startDates->hasOpenTask($equipment->id)) {
            return;
        }

        $dueFrom = $this->startDates->dueFromDate($equipment);

        if ($dueFrom === null) {
            return;
        }

        $checklistVersionId = $this->checklistVersionId($equipment->categoryId);

        try {
            DB::transaction(fn (): PmTask => PmTask::create([
                'site_id' => $site->id,
                'site_name_snapshot' => $site->name,
                'equipment_id' => $equipment->id,
                'equipment_name_snapshot' => $equipment->name,
                'equipment_category_id' => $equipment->categoryId,
                'equipment_category_snapshot' => $this->categoryNames[$equipment->categoryId] ?? $equipment->categoryId,
                'equipment_location_snapshot' => $equipment->location,
                'vendor_id' => $setting->vendor_id,
                'eic_employee_id' => $setting->eic_employee_id,
                'checklist_template_version_id' => $checklistVersionId,
                'no_checklist' => $checklistVersionId === null,
                'status' => PmStatus::Scheduled,
                'due_from_date' => $dueFrom,
                'due_date' => $setting->pm_frequency->nextDueDate($dueFrom),
            ]));
        } catch (UniqueConstraintViolationException) {
            // A concurrent run created this Equipment's open task first.
        }
    }

    /**
     * The current version of the category's active PM Checklist, or null when there is none.
     */
    private function checklistVersionId(string $categoryId): ?int
    {
        if (! array_key_exists($categoryId, $this->checklistVersionIds)) {
            $template = ChecklistTemplate::query()
                ->where('equipment_category_id', $categoryId)
                ->where('is_active', true)
                ->with('currentVersion')
                ->first();

            $this->checklistVersionIds[$categoryId] = $template?->currentVersion?->id;
        }

        return $this->checklistVersionIds[$categoryId];
    }

    /**
     * Keep open tasks whose Equipment left the directory or is inactive (issue 03), flagged for the EIC (issue 25).
     * Never cancel them.
     */
    private function flagRemovedEquipment(MasterDataDirectory $directory): void
    {
        PmTask::query()->open()->chunkById(200, function ($tasks) use ($directory): void {
            foreach ($tasks as $task) {
                $task->equipment_removed = ! ($directory->findEquipment($task->equipment_id)?->isActive ?? false);

                if ($task->isDirty('equipment_removed')) {
                    $task->save();
                }
            }
        });
    }
}
