<?php

namespace App\Http\Controllers\Api;

use App\Auth\CredentialChecker;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\JWTGuard;

/**
 * JWT sign-in for everyone, employees and vendor staff (ADR 0004). The token carries only the user's id.
 */
class AuthController extends Controller
{
    /**
     * Check the credentials through the CredentialChecker port first, then sign in the existing PSS user, whatever
     * their access state (ADR 0005). Wrong credentials are 401 whether or not the employee is a PSS user, so sign-in
     * never reveals who is registered (Q16). Correct credentials without a PSS user are 403; sign-in never creates one.
     */
    public function login(LoginRequest $request, CredentialChecker $credentials): JsonResponse
    {
        $employeeId = $request->string('employee_id')->value();

        if (! $credentials->isValid($employeeId, $request->string('password')->value())) {
            return $this->invalidCredentials();
        }

        $user = User::query()->where('employee_id', $employeeId)->first();
        if ($user === null) {
            return response()->json(
                ['message' => "Your account isn't set up in PSS yet. Ask an administrator."],
                Response::HTTP_FORBIDDEN,
            );
        }

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
     * Who is signed in: identity, granted roles, access and Vendor link, read fresh from the database.
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
