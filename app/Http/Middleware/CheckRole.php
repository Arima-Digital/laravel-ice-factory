<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Role permission messages
     */
    private const ROLE_DESCRIPTIONS = [
        'ADMIN' => 'Full access to dashboard, reporting, user management, and all operations',
        'WAREHOUSE' => 'Production management and delivery planning',
        'DRIVER' => 'Freezer confirmation and payment collection',
    ];

    /**
     * Handle an incoming request.
     *
     * Middleware untuk mengecek role user.
     * Gunakan: ->middleware('role:ADMIN,WAREHOUSE')
     * Atau: ->middleware('role:DRIVER')
     * 
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // Jika user belum authenticate, block
        if (!$request->user()) {
            return response()->json([
                'message' => 'Unauthorized',
                'error' => 'User not authenticated. Please login first.',
            ], 401);
        }

        // Ambil role user dari database
        $userRole = $request->user()->role;

        // Cek apakah role user ada di list role yang diizinkan
        if (!in_array($userRole, $roles)) {
            $allowedRoles = implode(', ', $roles);
            $userDescription = self::ROLE_DESCRIPTIONS[$userRole] ?? 'Unknown role';
            $allowedDescriptions = array_map(
                fn($role) => self::ROLE_DESCRIPTIONS[$role] ?? $role,
                $roles
            );

            return response()->json([
                'message' => 'Forbidden',
                'error' => 'Access denied',
                'details' => [
                    'your_role' => $userRole,
                    'your_permissions' => $userDescription,
                    'required_roles' => $allowedRoles,
                    'allowed_permissions' => $allowedDescriptions,
                ],
            ], 403);
        }

        return $next($request);
    }
}
