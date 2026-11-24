<?php

namespace App\Http\Controllers\Api;

use App\Enums\PssRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\GrantUserRoleRequest;
use App\Http\Requests\LinkUserVendorRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The admin API behind `manage-roles` (issue 04): PSS users, their PSS Roles and their Vendor link.
 */
class AdminUserController extends Controller
{
    /**
     * List PSS users, by name.
     */
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->with('roles')->orderBy('name')->orderBy('id')->get());
    }

    /**
     * Grant a role. Granting a role the user already has is a no-op.
     */
    public function grantRole(GrantUserRoleRequest $request, User $user): UserResource
    {
        $user->grantRole(PssRole::from($request->validated('role')), $request->user());

        return UserResource::make($user->load('roles'));
    }

    /**
     * Revoke a role. Refused for the last System Administrator.
     */
    public function revokeRole(User $user, string $role): UserResource
    {
        $pssRole = PssRole::tryFrom($role);
        if ($pssRole === null) {
            throw ValidationException::withMessages(['role' => 'The selected role is invalid.']);
        }

        $user->revokeRole($pssRole);

        return UserResource::make($user->load('roles'));
    }

    /**
     * Link the user to a Vendor, or unlink them with `vendor_id: null`. Refused for a current Site EIC.
     */
    public function linkVendor(LinkUserVendorRequest $request, User $user): UserResource
    {
        $vendorId = $request->validated('vendor_id');

        $user->linkToVendor($vendorId === null ? null : Vendor::query()->findOrFail($vendorId));

        return UserResource::make($user->load('roles'));
    }
}
