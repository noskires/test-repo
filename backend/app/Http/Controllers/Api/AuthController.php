<?php

namespace App\Http\Controllers\Api;

use App\Auth\CredentialChecker;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\CurrentUserResource;
use App\MasterData\MasterDataDirectory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\JWTGuard;

/**
 * JWT sign-in for everyone, employees and vendor staff (ADR 0004). The token carries only the user's id.
 */
class AuthController extends Controller
{
    /**
     * Check the credentials through the CredentialChecker port, create the PSS user on first sign-in, and issue a token.
     * Refused (401) when the credentials are wrong or the employee is unknown to the master data.
     */
    public function login(LoginRequest $request, CredentialChecker $credentials, MasterDataDirectory $directory): JsonResponse
    {
        $employeeId = $request->string('employee_id')->value();

        if (! $credentials->isValid($employeeId, $request->string('password')->value())) {
            return $this->invalidCredentials();
        }

        $employee = $directory->findEmployee($employeeId);
        if ($employee === null) {
            return $this->invalidCredentials();
        }

        $user = DB::transaction(fn (): User => User::query()->firstOrCreate(
            ['employee_id' => $employeeId],
            ['name' => $employee->name],
        ));

        return $this->tokenResponse($this->guard()->login($user));
    }

    /**
     * Swap a valid, or expired but still refreshable (14 days), token for a new one. The old token is blacklisted.
     */
    public function refresh(): JsonResponse
    {
        try {
            return $this->tokenResponse($this->guard()->refresh());
        } catch (JWTException) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * Blacklist the current token.
     */
    public function logout(): Response
    {
        $this->guard()->logout();

        return response()->noContent();
    }

    /**
     * Who is signed in: identity, granted roles and Vendor link, read fresh from the database.
     */
    public function me(Request $request): CurrentUserResource
    {
        return CurrentUserResource::make($request->user()->load('roles'));
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard */
        return auth('api');
    }

    private function invalidCredentials(): JsonResponse
    {
        return response()->json(['message' => 'Invalid employee ID or password.'], Response::HTTP_UNAUTHORIZED);
    }

    private function tokenResponse(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
        ]);
    }
}
