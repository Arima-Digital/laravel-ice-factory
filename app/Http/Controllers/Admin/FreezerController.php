<?php

namespace App\Http\Controllers\Admin;

use App\Models\Freezer;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class FreezerController extends Controller
{
    /**
     * Display a listing of all freezers
     * GET /api/freezers
     *
     * Optional filters: ?store_id=1&product_id=1
     *
     * A freezer can hold more than one product, so product_id filters on what
     * has actually been delivered to the freezer rather than on a single
     * product stored on the freezer.
     */
    public function index(Request $request)
    {
        $freezers = Freezer::with(['store', 'products'])
            ->when($request->query('store_id'), function ($query, $storeId) {
                $query->where('store_id', $storeId);
            })
            ->when($request->query('product_id'), function ($query, $productId) {
                $query->whereHas('deliveryItems', function ($deliveryItem) use ($productId) {
                    $deliveryItem->where('product_id', $productId);
                });
            })
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Freezers retrieved successfully',
            'data' => $freezers,
            'count' => $freezers->count(),
        ], 200);
    }

    /**
     * Store a newly created freezer
     * POST /api/freezers
     *
     * IoT fields (last_weight_kg, last_temperature_c, last_door_status,
     * last_seen_at) are owned by the sensor webhook and are never accepted
     * from this endpoint.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|integer|exists:stores,id',
            'code' => 'required|string|max:50|unique:freezers,code',
            'sim_number' => 'nullable|string|max:50',
            'max_capacity_ball' => 'required|numeric|min:0.1',
            'tare_weight_kg' => 'required|numeric|min:0.1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $freezer = Freezer::create($validator->validated());
        $freezer->load(['store', 'products']);

        return response()->json([
            'success' => true,
            'message' => 'Freezer created successfully',
            'data' => $freezer,
        ], 201);
    }

    /**
     * Display a specific freezer
     * GET /api/freezers/{id}
     */
    public function show($id)
    {
        $freezer = Freezer::with(['store', 'products'])->find($id);

        if (!$freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Freezer retrieved successfully',
            'data' => $freezer,
        ], 200);
    }

    /**
     * Update a freezer
     * PUT /api/freezers/{id}
     */
    public function update(Request $request, $id)
    {
        $freezer = Freezer::find($id);

        if (!$freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'store_id' => 'sometimes|integer|exists:stores,id',
            'code' => 'sometimes|string|max:50|unique:freezers,code,' . $id,
            'sim_number' => 'nullable|string|max:50',
            'max_capacity_ball' => 'sometimes|numeric|min:0.1',
            'tare_weight_kg' => 'sometimes|numeric|min:0.1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $freezer->update($validator->validated());
        $freezer->load(['store', 'products']);

        return response()->json([
            'success' => true,
            'message' => 'Freezer updated successfully',
            'data' => $freezer,
        ], 200);
    }

    /**
     * Delete a freezer
     * DELETE /api/freezers/{id}
     *
     * A freezer referenced by delivery items or sales is kept, so the
     * transaction history stays intact.
     */
    public function destroy($id)
    {
        $freezer = Freezer::find($id);

        if (!$freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer not found',
            ], 404);
        }

        $deliveryItemCount = $freezer->deliveryItems()->count();
        $saleCount = $freezer->sales()->count();

        if ($deliveryItemCount > 0 || $saleCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer cannot be deleted because it has transaction history',
                'details' => [
                    'delivery_items' => $deliveryItemCount,
                    'sales' => $saleCount,
                ],
            ], 422);
        }

        $freezer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Freezer deleted successfully',
        ], 200);
    }

    /**
     * Display all freezers belonging to a store
     * GET /api/stores/{storeId}/freezers
     *
     * Returns every freezer in one call so the frontend does not have to
     * request the suggestion of each freezer separately during a visit.
     */
    public function getByStore($storeId)
    {
        $store = Store::find($storeId);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $freezers = Freezer::with(['products'])
            ->where('store_id', $store->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Freezers retrieved successfully',
            'data' => [
                'store' => $store,
                'freezers' => $freezers,
            ],
            'count' => $freezers->count(),
        ], 200);
    }
}
