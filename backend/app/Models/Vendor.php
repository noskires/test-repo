<?php

namespace App\Models;

use App\Enums\VendorStatus;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An outside company that does PM and corrective work. PSS owns this record;
 * Vendors are deactivated, never deleted, so history keeps resolving.
 */
#[Fillable(['name', 'contact_person', 'email', 'contact_number', 'status'])]
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VendorStatus::class,
        ];
    }

    /**
     * Scope the query to vendors with the given status.
     *
     * @param  Builder<Vendor>  $query
     */
    public function scopeWithStatus(Builder $query, VendorStatus $status): void
    {
        $query->where('status', $status);
    }
}
