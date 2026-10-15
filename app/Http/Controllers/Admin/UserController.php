<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Display a listing of all users
     * GET /api/users
     */
    public function index()
    {
        try {
            $users = User::all();

            return response()->json([
                'success' => true,
                'message' => 'Data pengguna berhasil diambil',
                'data' => $users,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pengguna',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified user
     * GET /api/users/{user}
     */
    public function show($id)
    {
        try {
            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengguna tidak ditemukan',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil diambil',
                'data' => $user,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pengguna',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created user
     * POST /api/users
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'username' => 'required|string|unique:users,username|max:255',
                'email' => 'nullable|email|unique:users,email|max:255',
                'password' => 'required|string|min:6',
                'role' => 'required|in:ADMIN,WAREHOUSE,DRIVER',
            ]);

            // Hash the password
            $validated['password_hash'] = bcrypt($validated['password']);
            unset($validated['password']);

            $user = User::create($validated);

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil dibuat',
                'data' => $user,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat pengguna',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified user
     * PUT /api/users/{user}
     */
    public function update(Request $request, $id)
    {
        try {
            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengguna tidak ditemukan',
                ], 404);
            }

            $validated = $request->validate([
                'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->ignore($id)],
                'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
                'password' => 'nullable|string|min:6',
                'role' => 'nullable|in:ADMIN,WAREHOUSE,DRIVER',
            ]);

            // Hash password if provided
            if ($request->filled('password')) {
                $validated['password_hash'] = bcrypt($validated['password']);
                unset($validated['password']);
            }

            $user->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil diperbarui',
                'data' => $user,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pengguna',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified user
     * DELETE /api/users/{user}
     */
    public function destroy($id)
    {
        try {
            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengguna tidak ditemukan',
                ], 404);
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil dihapus',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus pengguna',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get users by role
     * GET /api/users/role/{role}
     */
    public function getByRole($role)
    {
        try {
            $validRoles = ['ADMIN', 'WAREHOUSE', 'DRIVER'];

            if (! in_array($role, $validRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role tidak valid. Nilai yang diperbolehkan: ADMIN, WAREHOUSE, DRIVER',
                ], 400);
            }

            $users = User::where('role', $role)->get();

            return response()->json([
                'success' => true,
                'message' => "Data pengguna dengan role {$role}",
                'data' => $users,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pengguna',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of all drivers
     * GET /api/drivers
     *
     * Used to fill the driver dropdown when building a delivery plan, so it
     * only returns the fields needed for selection. The users table has no
     * name column, so username is the only label available.
     */
    public function getDrivers()
    {
        try {
            $drivers = User::where('role', 'DRIVER')
                ->orderBy('id')
                ->get(['id', 'username', 'role']);

            return response()->json([
                'success' => true,
                'message' => 'Data driver berhasil diambil',
                'data' => $drivers,
                'count' => $drivers->count(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data driver',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
