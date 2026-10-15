<?php

namespace App\Http\Controllers\Admin;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class VehicleController extends Controller
{
    /**
     * Display a listing of all vehicles
     */
    public function index()
    {
        $vehicles = Vehicle::all();

        return response()->json([
            'success' => true,
            'message' => 'Data kendaraan berhasil diambil',
            'data' => $vehicles,
            'count' => count($vehicles),
        ], 200);
    }

    /**
     * Store a newly created vehicle
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:vehicles,code',
            'plate_number' => 'required|string|unique:vehicles,plate_number',
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $vehicle = Vehicle::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Kendaraan berhasil dibuat',
            'data' => $vehicle,
        ], 201);
    }

    /**
     * Display a specific vehicle
     */
    public function show($id)
    {
        $vehicle = Vehicle::find($id);

        if (!$vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Kendaraan tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Kendaraan berhasil diambil',
            'data' => $vehicle,
        ], 200);
    }

    /**
     * Update a vehicle
     */
    public function update(Request $request, $id)
    {
        $vehicle = Vehicle::find($id);

        if (!$vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Kendaraan tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|unique:vehicles,code,' . $id,
            'plate_number' => 'sometimes|string|unique:vehicles,plate_number,' . $id,
            'name' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $vehicle->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Kendaraan berhasil diperbarui',
            'data' => $vehicle,
        ], 200);
    }

    /**
     * Delete a vehicle
     */
    public function destroy($id)
    {
        $vehicle = Vehicle::find($id);

        if (!$vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Kendaraan tidak ditemukan',
            ], 404);
        }

        $vehicle->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kendaraan berhasil dihapus',
        ], 200);
    }
}
