<?php

namespace App\Models;

use App\Enums\PssRole;
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
 * A PSS user, keyed by the external employee_id and created on first sign-in (ADR 0004). The token carries only the
 * primary key, so roles and the Vendor link are read from the database on every request.
 */
#[Fillable(['name', 'email', 'password', 'employee_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }

    /**
     * An internal employee of the organisation: has an employee ID and is not vendor staff. Only internal employees
     * can hold approver rights (issue 38).
     */
    public function isInternalEmployee(): bool
    {
        return $this->vendor_id === null && $this->employee_id !== null;
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
     * Link this user to a Vendor (they become vendor staff), or unlink them with null (an ordinary employee again).
     * The only way a user gets a vendor_id (Q3). A current Site EIC can't be linked: vendor staff never act as EIC
     * (issue 37).
     *
     * @throws ValidationException when linking a current Site EIC
     */
    public function linkToVendor(?Vendor $vendor): void
    {
        DB::transaction(function () use ($vendor): void {
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
