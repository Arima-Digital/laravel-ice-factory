<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register endpoint
     * POST /auth/register
     * 
     * Body:
     * - username (string, required, unique)
     * - email (string, required, unique)
     * - password (string, required, min 6)
     * - role (string, required: ADMIN|WAREHOUSE|DRIVER)
     * 
     * Returns:
     * - user (object): New user details with role
     * - token (string): API token for Sanctum authentication
     * - expires_in (int): Token expiration in seconds
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|string|in:ADMIN,WAREHOUSE,DRIVER',
        ]);

        // Create new user with hashed password
        $user = User::create([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        // Auto-login: create token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'token' => $token,
            'expires_in' => 2592000, // 30 days in seconds
        ], 201);
    }

    /**
     * Login endpoint
     * POST /auth/login
     * 
     * Body:
     * - email (string, required)
     * - password (string, required)
     * 
     * Returns:
     * - user (object): User details with role
     * - token (string): API token for Sanctum authentication
     * - expires_in (int): Token expiration in seconds
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Create API token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'token' => $token,
            'expires_in' => 2592000, // 30 days in seconds
        ], 200);
    }

    /**
     * Get current authenticated user
     * GET /auth/me
     * 
     * Headers:
     * - Authorization: Bearer {token}
     * 
     * Returns:
     * - user (object): Current user details with role
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ], 200);
    }

    /**
     * Refresh token
     * POST /auth/refresh
     * 
     * Headers:
     * - Authorization: Bearer {token}
     * 
     * Returns:
     * - token (string): New API token
     * - expires_in (int): Token expiration in seconds
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        
        // Revoke all existing tokens
        $user->tokens()->delete();
        
        // Create new token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Token refreshed successfully',
            'token' => $token,
            'expires_in' => 2592000, // 30 days in seconds
        ], 200);
    }

    /**
     * Logout endpoint
     * POST /auth/logout
     * 
     * Headers:
     * - Authorization: Bearer {token}
     * 
     * Returns:
     * - message (string): Logout confirmation
     */
    public function logout(Request $request)
    {
        // Revoke the current token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout successful',
        ], 200);
    }

    /**
     * Logout from all devices
     * POST /auth/logout-all
     * 
     * Headers:
     * - Authorization: Bearer {token}
     * 
     * Returns:
     * - message (string): Logout confirmation
     */
    public function logoutAll(Request $request)
    {
        // Revoke all tokens
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out from all devices',
        ], 200);
    }
}
