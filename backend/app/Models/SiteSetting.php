<?php

namespace App\Models;

use App\Enums\PmFrequency;
use App\Enums\SiteAssignment;
use App\Events\SitePmFrequencyChanged;
use App\MasterData\Data\Employee;
use App\MasterData\Data\Site;
use Database\Factories\SiteSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * PSS-owned settings for one external Site: its current Site Vendor, EIC and PM Frequency, plus a Snapshot of the Site name.
 * Every change is logged in SiteAssignmentChange so past jobs can be traced to the vendor or EIC of the time.
 */
#[Fillable(['site_id', 'site_name_snapshot', 'vendor_id', 'eic_employee_id', 'pm_frequency'])]
class SiteSetting extends Model
{
    /** @use HasFactory<SiteSettingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vendor_id' => 'integer',
            'pm_frequency' => PmFrequency::class,
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Whether the user is the Site's current EIC. False when the user has no employee ID or the Site has no EIC;
     * there is no fallback to an EIC captured on a task. With $lockForUpdate, the settings row is locked, so an
     * EIC replacement (assignEic also locks it) can't slip in before the caller's transaction commits.
     */
    public static function isCurrentEic(string $siteId, User $user, bool $lockForUpdate = false): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        $currentEicEmployeeId = static::query()
            ->where('site_id', $siteId)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->value('eic_employee_id');

        return $currentEicEmployeeId !== null && $user->employee_id === $currentEicEmployeeId;
    }

    /**
     * Make the Vendor the Site's one current Site Vendor, recording the change unless it is already assigned.
     */
    public static function assignVendor(Site $site, Vendor $vendor, User $changedBy): self
    {
        return static::changeAssignment($site, function (self $setting) use ($vendor, $changedBy): void {
            if ($setting->vendor_id === $vendor->id) {
                return;
            }

            SiteAssignmentChange::create([
                'site_id' => $setting->site_id,
                'assignment' => SiteAssignment::Vendor,
                'old_vendor_id' => $setting->vendor_id,
                'new_vendor_id' => $vendor->id,
                'changed_by_user_id' => $changedBy->id,
            ]);

            $setting->vendor_id = $vendor->id;
        });
    }

    /**
     * Make the employee the Site's EIC, recording the change unless they are already assigned.
     */
    public static function assignEic(Site $site, Employee $engineer, User $changedBy): self
    {
        return static::changeAssignment($site, function (self $setting) use ($engineer, $changedBy): void {
            if ($setting->eic_employee_id === $engineer->id) {
                return;
            }

            SiteAssignmentChange::create([
                'site_id' => $setting->site_id,
                'assignment' => SiteAssignment::Eic,
                'old_eic_employee_id' => $setting->eic_employee_id,
                'new_eic_employee_id' => $engineer->id,
                'changed_by_user_id' => $changedBy->id,
            ]);

            $setting->eic_employee_id = $engineer->id;
        });
    }

    /**
     * Set the Site's PM Frequency. A real change is recorded and announced with SitePmFrequencyChanged
     * (after commit). Setting the current value again does neither.
     */
    public static function assignPmFrequency(Site $site, PmFrequency $frequency, User $changedBy): self
    {
        return static::changeAssignment($site, function (self $setting) use ($frequency, $changedBy): void {
            $oldFrequency = $setting->pm_frequency;

            if ($oldFrequency === $frequency) {
                return;
            }

            SiteAssignmentChange::create([
                'site_id' => $setting->site_id,
                'assignment' => SiteAssignment::PmFrequency,
                'old_pm_frequency' => $oldFrequency,
                'new_pm_frequency' => $frequency,
                'changed_by_user_id' => $changedBy->id,
            ]);

            $setting->pm_frequency = $frequency;

            SitePmFrequencyChanged::dispatch($setting->site_id, $oldFrequency, $frequency);
        });
    }

    /**
     * Lock (or start) the Site's settings row, apply the change, and refresh the Site Snapshot.
     *
     * @param  callable(self): void  $applyChange
     */
    private static function changeAssignment(Site $site, callable $applyChange): self
    {
        return DB::transaction(function () use ($site, $applyChange): self {
            $setting = static::query()->lockForUpdate()->firstOrNew(['site_id' => $site->id]);

            $applyChange($setting);

            $setting->site_name_snapshot = $site->name;
            $setting->save();

            return $setting;
        });
    }
}
