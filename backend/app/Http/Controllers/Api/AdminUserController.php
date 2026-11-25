<?php

namespace App\Http\Controllers\Api;

use App\Enums\PssRole;
use App\Enums\UserAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\GrantUserRoleRequest;
use App\Http\Requests\LinkUserVendorRequest;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserAccessRequest;
use App\Http\Resources\UserResource;
use App\MasterData\MasterDataDirectory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The admin API behind `manage-roles` (issues 04 and 46): PSS users, their access, PSS Roles and Vendor link.
 */
class AdminUserController extends Controller
{
    /**
     * List PSS users, by name. `?access=pending` (or `internal`, `vendor`) keeps only users in that state (Q10).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['access' => ['sometimes', 'string', Rule::enum(UserAccess::class)]]);

        return UserResource::collection(User::query()
            ->with('roles')
            ->when($request->query('access'), fn ($query, string $access) => $query->where('access', $access))
            ->orderBy('name')
            ->orderBy('id')
            ->get());
    }

    /**
     * Add a PSS user (Q14): an employee from the master data, classified as internal or a Vendor's staff, with no
     * roles. Linking to a Vendor goes through the same rules as PUT .../vendor (a current Site EIC is refused), and
     * nothing is stored when it is refused. The user gets the email and a temporary password to change on first
     * sign-in (issue 44).
     */
    public function store(StoreUserRequest $request, MasterDataDirectory $directory): JsonResponse
    {
        $employeeId = $request->validated('employee_id');
        $vendorId = $request->validated('vendor_id');
        $email = $request->validated('email');
        $temporaryPassword = $request->validated('temporary_password');
        $admin = $request->user();

        $user = DB::transaction(function () use ($employeeId, $vendorId, $email, $temporaryPassword, $directory, $admin): User {
            $user = new User(['employee_id' => $employeeId, 'name' => $directory->findEmployee($employeeId)->name]);
            $user->email = $email;
            $user->setTemporaryPassword($temporaryPassword);

            if ($vendorId === null) {
                $user->markInternal($admin);
            } else {
                $user->save();
                $user->linkToVendor(Vendor::query()->findOrFail($vendorId), $admin);
            }

            return $user;
        });

        return UserResource::make($user->load('roles'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Reset a forgotten password (issue 44): the user gets a new temporary password to change on their next sign-in.
     * Tokens already issued stay valid until they expire, but the temporary-password check blocks them.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): UserResource
    {
        $user->setTemporaryPassword($request->validated('temporary_password'));

        return UserResource::make($user->load('roles'));
    }

    /**
     * Classify the user as internal (Q6). Refused for a vendor-linked user; a no-op for an internal one.
     */
    public function updateAccess(UpdateUserAccessRequest $request, User $user): UserResource
    {
        $user->markInternal($request->user());

        return UserResource::make($user->load('roles'));
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
     * Link the user to a Vendor, or unlink them with `vendor_id: null` (they become pending). Refused for a current
     * Site EIC.
     */
    public function linkVendor(LinkUserVendorRequest $request, User $user): UserResource
    {
        $vendorId = $request->validated('vendor_id');

        $user->linkToVendor($vendorId === null ? null : Vendor::query()->findOrFail($vendorId), $request->user());

        return UserResource::make($user->load('roles'));
    }
}
