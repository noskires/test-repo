<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
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
     * The Vendor this user works for. Set only for vendor staff. Issue 04 fills it at login.
     *
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
