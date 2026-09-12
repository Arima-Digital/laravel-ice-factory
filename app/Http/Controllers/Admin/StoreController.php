<?php

namespace App\Http\Controllers\Admin;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class StoreController extends Controller
{
    /**
     * Display a listing of all stores
     */
    public function index()
    {
        $stores = Store::all();

        return response()->json([
            'success' => true,
            'message' => 'Stores retrieved successfully',
            'data' => $stores,
            'count' => count($stores),
        ], 200);
    }

    /**
     * Store a newly created store
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:stores,code',
            'name' => 'required|string',
            'owner_name' => 'required|string',
            'phone' => 'required|string',
            'address' => 'required|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $store = Store::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Store created successfully',
            'data' => $store,
        ], 201);
    }

    /**
     * Display a specific store
     */
    public function show($id)
    {
        $store = Store::find($id);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Store retrieved successfully',
            'data' => $store,
        ], 200);
    }

    /**
     * Update a store
     */
    public function update(Request $request, $id)
    {
        $store = Store::find($id);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|unique:stores,code,' . $id,
            'name' => 'sometimes|string',
            'owner_name' => 'sometimes|string',
            'phone' => 'sometimes|string',
            'address' => 'sometimes|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $store->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Store updated successfully',
            'data' => $store,
        ], 200);
    }

    /**
     * Delete a store
     */
    public function destroy($id)
    {
        $store = Store::find($id);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $store->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store deleted successfully',
        ], 200);
    }
}
