<?php

namespace App\Http\Controllers\Api;

use App\Auth\CredentialChecker;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
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
     * Check the email and password through the CredentialChecker port first, then sign in the existing PSS user,
     * whatever their access state (ADR 0005). Wrong credentials are 401 whether or not the email belongs to a PSS user,
     * so sign-in never reveals who is registered (Q16). Correct credentials without a PSS user (possible only with a
     * checker other than the local one) are 403; sign-in never creates a user. The response says when the user must
     * change a temporary password first (issue 44).
     */
    public function login(LoginRequest $request, CredentialChecker $credentials): JsonResponse
    {
        $email = $request->string('email')->value();

        if (! $credentials->isValid($email, $request->string('password')->value())) {
            return $this->invalidCredentials();
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            return response()->json(
                ['message' => "Your account isn't set up in PSS yet. Ask an administrator."],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $this->tokenResponse($this->guard()->login($user), $user);
    }

    /**
     * Change the signed-in user's own password (issue 44). Clears the temporary-password flag. Tokens already issued
     * stay valid until they expire.
     */
    public function changePassword(ChangePasswordRequest $request): CurrentUserResource
    {
        $user = $request->user();
        $user->changePassword($request->validated('password'));

        return CurrentUserResource::make($user->load('roles'));
    }

    /**
     * Swap a valid, or expired but still refreshable (14 days), token for a new one. The old token is blacklisted.
     */
    public function refresh(): JsonResponse
    {
        try {
            $token = $this->guard()->refresh();

            return $this->tokenResponse($token, $this->guard()->setToken($token)->user());
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
        return response()->json(['message' => 'Invalid email or password.'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * The token, and whether its user must change a temporary password before using PSS (issue 44).
     */
    private function tokenResponse(string $token, ?User $user): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->guard()->factory()->getTTL() * 60,
            'must_change_password' => (bool) $user?->must_change_password,
        ]);
    }
}
