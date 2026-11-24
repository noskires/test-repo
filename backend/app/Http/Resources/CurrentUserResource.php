<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user for `GET /api/auth/me`, returned without a `data` wrapper.
 *
 * @mixin User
 */
class CurrentUserResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array{employee_id: string|null, name: string, roles: list<string>, vendor_id: int|null, access: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->employee_id,
            'name' => $this->name,
            'roles' => $this->roleKeys(),
            'vendor_id' => $this->vendor_id,
            'access' => $this->access->value,
        ];
    }
}
