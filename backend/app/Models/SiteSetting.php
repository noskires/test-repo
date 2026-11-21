<?php

namespace App\Models;

use App\Enums\SiteAssignment;
use App\MasterData\Data\Employee;
use App\MasterData\Data\Site;
use Database\Factories\SiteSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * PSS-owned settings for one external Site: its current Site Vendor and EIC, plus a Snapshot of the Site name.
 * Every change is logged in SiteAssignmentChange so past jobs can be traced to the vendor or EIC of the time.
 */
#[Fillable(['site_id', 'site_name_snapshot', 'vendor_id', 'eic_employee_id'])]
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
