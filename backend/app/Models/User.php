<?php

namespace App\Models;

use App\Enums\PssRole;
use App\Enums\UserAccess;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * A PSS user, keyed by the unique external employee_id and added by a System Administrator (ADR 0004, ADR 0005). The
 * token carries only the primary key, so roles, access and the Vendor link are read from the database on every request.
 *
 * Every user has an access state (UserAccess). This model is the one place that keeps "vendor exactly when vendor_id is
 * set": see the saving hook in booted().
 */
#[Fillable(['name', 'email', 'password', 'employee_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * A user nobody has classified yet is pending (fail closed).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'access' => 'pending',
    ];

    /**
     * Keep the access invariant on every save: a vendor_id means `vendor`, and a `vendor` user who loses their vendor_id
     * becomes `pending` (Q8), never silently internal.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->vendor_id !== null) {
                $user->access = UserAccess::Vendor;
            } elseif ($user->access === UserAccess::Vendor) {
                $user->access = UserAccess::Pending;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'vendor_id' => 'integer',
            'access' => UserAccess::class,
            'access_set_at' => 'datetime',
        ];
    }

    /**
     * An internal employee of the organisation: has an employee ID and is not vendor staff. Only internal employees
     * can hold approver rights (issue 38).
     */
    public function isInternalEmployee(): bool
    {
        return $this->access === UserAccess::Internal && $this->vendor_id === null && $this->employee_id !== null;
    }

    public function isPending(): bool
    {
        return $this->access === UserAccess::Pending;
    }

    /**
     * Classify this user as an internal employee (Q6), recording who ($by) and when. A no-op for an internal user.
     * Refused for a vendor-linked user, who must be unlinked first, so `vendor` and vendor_id never disagree.
     *
     * @throws ValidationException when the user is linked to a Vendor
     */
    public function markInternal(?User $by = null): void
    {
        if ($this->access === UserAccess::Internal) {
            return;
        }

        if ($this->vendor_id !== null) {
            throw ValidationException::withMessages([
                'access' => 'This user is linked to a Vendor: unlink the Vendor first, then set them internal.',
            ]);
        }

        $this->recordAccessChange(UserAccess::Internal, $by);
        $this->save();
    }

    /**
     * Set the access state with who changed it ($by: an admin, or null for the system) and when. Not saved here.
     */
    private function recordAccessChange(UserAccess $access, ?User $by): void
    {
        $this->forceFill([
            'access' => $access,
            'access_set_by_user_id' => $by?->id,
            'access_set_at' => now(),
        ]);
    }

    /**
     * THE rule every approver check uses (issue 38): this user is the internal employee with this employee ID.
     * Used for the Site EIC (SiteSetting::isActingEic), Work Order endorsement, and billing endorsement (issue 23).
     * No other code compares vendor_id or employee_id to grant approver rights.
     */
    public function actsAsEmployee(string $employeeId): bool
    {
        return $this->isInternalEmployee() && $this->employee_id === $employeeId;
    }

    /**
     * The Vendor this user works for. Set only for vendor staff, and only by a System Administrator (linkToVendor):
     * sign-in never sets it.
     *
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function hasRole(PssRole $role): bool
    {
        return $this->roles->contains(fn (UserRole $userRole): bool => $userRole->role === $role);
    }

    /**
     * The granted role keys, sorted.
     *
     * @return list<string>
     */
    public function roleKeys(): array
    {
        return $this->roles->map(fn (UserRole $userRole): string => $userRole->role->value)->sort()->values()->all();
    }

    /**
     * Grant a PSS Role. Granting a role the user already has changes nothing.
     */
    public function grantRole(PssRole $role, ?User $grantedBy = null): void
    {
        $this->roles()->firstOrCreate(['role' => $role], ['granted_by_user_id' => $grantedBy?->id]);
        $this->unsetRelation('roles');
    }

    /**
     * Revoke a PSS Role. Revoking a role the user doesn't have changes nothing. The last System Administrator keeps
     * the role, including when they remove it from themselves (Q13).
     *
     * @throws ValidationException when this user is the last System Administrator
     */
    public function revokeRole(PssRole $role): void
    {
        DB::transaction(function () use ($role): void {
            if ($role === PssRole::SystemAdministrator) {
                $adminIds = UserRole::query()->where('role', $role)->lockForUpdate()->pluck('user_id');

                if ($adminIds->count() === 1 && $adminIds->contains($this->id)) {
                    throw ValidationException::withMessages([
                        'role' => 'The last System Administrator cannot lose the role. Grant it to someone else first.',
                    ]);
                }
            }

            $this->roles()->where('role', $role)->delete();
        });

        $this->unsetRelation('roles');
    }

    /**
     * Link this user to a Vendor (they become `vendor`), or unlink them with null (they become `pending`, Q8). The only
     * way a user gets a vendor_id (Q3). A current Site EIC can't be linked: vendor staff never act as EIC (issue 37).
     * Unlinking a user who has no Vendor changes nothing. Each change records who made it ($by) and when.
     *
     * @throws ValidationException when linking a current Site EIC or a System Administrator (issue 45)
     */
    public function linkToVendor(?Vendor $vendor, ?User $by = null): void
    {
        if ($vendor === null && $this->vendor_id === null) {
            return;
        }

        DB::transaction(function () use ($vendor, $by): void {
            if ($vendor !== null && $this->roles()->where('role', PssRole::SystemAdministrator->value)->exists()) {
                throw ValidationException::withMessages([
                    'vendor_id' => 'Revoke the System Administrator role first.',
                ]);
            }

            if ($vendor !== null && $this->employee_id !== null) {
                $eicSiteIds = SiteSetting::query()
                    ->where('eic_employee_id', $this->employee_id)
                    ->lockForUpdate()
                    ->pluck('site_id');

                if ($eicSiteIds->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'vendor_id' => 'This user is the current EIC of '.$eicSiteIds->implode(', ')
                            .': replace them as EIC first, then link them to a Vendor.',
                    ]);
                }
            }

            $this->recordAccessChange($vendor === null ? UserAccess::Pending : UserAccess::Vendor, $by);
            $this->forceFill(['vendor_id' => $vendor?->id])->save();
        });

        $this->unsetRelation('vendor');
    }

    /**
     * The JWT subject: the primary key only. Roles and the Vendor link are never put in the token.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
