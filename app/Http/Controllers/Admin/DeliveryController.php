<?php

namespace App\Http\Controllers\Admin;

use App\Models\Delivery;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Production;
use App\Models\DeliveryItem;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    /**
     * Display a listing of all deliveries
     */
    public function index()
    {
        $deliveries = Delivery::with(['driver', 'vehicle'])->get();

        return response()->json([
            'success' => true,
            'message' => 'Deliveries retrieved successfully',
            'data' => $deliveries,
            'count' => count($deliveries),
        ], 200);
    }

    /**
     * Store a newly created delivery (Delivery Planning)
     * Warehouse staff creates delivery plan
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'driver_id' => 'required|exists:users,id',
            'vehicle_id' => 'required|exists:vehicles,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'delivery_date' => 'nullable|date',
            'initial_qty_loaded_ball' => 'required|numeric|min:1',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Validate driver is DRIVER role
        $driver = User::find($request->driver_id);
        if ($driver->role !== 'DRIVER') {
            return response()->json([
                'success' => false,
                'message' => 'Selected user is not a driver',
            ], 422);
        }

        // Validate warehouse stock available
        $availableStock = $this->getAvailableStock();
        if ($request->initial_qty_loaded_ball > $availableStock) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient warehouse stock',
                'available' => $availableStock,
                'requested' => $request->initial_qty_loaded_ball,
            ], 422);
        }

        // Create delivery (status = DRAFT)
        $delivery = Delivery::create([
            'driver_id' => $request->driver_id,
            'vehicle_id' => $request->vehicle_id,
            'warehouse_id' => $request->warehouse_id,
            'delivery_date' => $request->delivery_date ?? now('Asia/Jakarta')->toDateString(),
            'initial_qty_loaded_ball' => $request->initial_qty_loaded_ball,
            'total_qty_delivered_ball' => 0,
            'total_qty_returned_ball' => 0,
            'status' => 'DRAFT',
            'notes' => $request->notes ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Delivery plan created successfully',
            'data' => $delivery->load(['driver', 'vehicle']),
        ], 201);
    }

    /**
     * Display a specific delivery
     */
    public function show($id)
    {
        $delivery = Delivery::with(['driver', 'vehicle', 'deliveryItems'])->find($id);

        if (!$delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery retrieved successfully',
            'data' => $delivery,
        ], 200);
    }

    /**
     * Update a delivery (only DRAFT deliveries)
     */
    public function update(Request $request, $id)
    {
        $delivery = Delivery::find($id);

        if (!$delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found',
            ], 404);
        }

        // Only DRAFT deliveries can be edited
        if ($delivery->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'Only DRAFT deliveries can be edited',
                'current_status' => $delivery->status,
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'driver_id' => 'sometimes|exists:users,id',
            'vehicle_id' => 'sometimes|exists:vehicles,id',
            'warehouse_id' => 'sometimes|exists:warehouses,id',
            'delivery_date' => 'sometimes|date',
            'initial_qty_loaded_ball' => 'sometimes|numeric|min:1',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If updating driver, validate role
        if ($request->has('driver_id')) {
            $driver = User::find($request->driver_id);
            if ($driver->role !== 'DRIVER') {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected user is not a driver',
                ], 422);
            }
        }

        // If updating load qty, validate stock
        if ($request->has('initial_qty_loaded_ball')) {
            $availableStock = $this->getAvailableStock();
            if ($request->initial_qty_loaded_ball > $availableStock) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient warehouse stock',
                    'available' => $availableStock,
                    'requested' => $request->initial_qty_loaded_ball,
                ], 422);
            }
        }

        $delivery->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Delivery updated successfully',
            'data' => $delivery->load(['driver', 'vehicle']),
        ], 200);
    }

    /**
     * Delete a delivery (only DRAFT deliveries)
     */
    public function destroy($id)
    {
        $delivery = Delivery::find($id);

        if (!$delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found',
            ], 404);
        }

        // Only DRAFT deliveries can be deleted
        if ($delivery->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'Only DRAFT deliveries can be deleted',
                'current_status' => $delivery->status,
            ], 422);
        }

        $delivery->delete();

        return response()->json([
            'success' => true,
            'message' => 'Delivery deleted successfully',
        ], 200);
    }

    /**
     * Start delivery - Transition DRAFT → IN_PROGRESS
     * Driver confirms ready to start delivery
     */
    public function startDelivery($id)
    {
        $delivery = Delivery::find($id);

        if (!$delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found',
            ], 404);
        }

        // Only DRAFT can start
        if ($delivery->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'Only DRAFT deliveries can be started',
                'current_status' => $delivery->status,
            ], 422);
        }

        // Validate warehouse stock still available
        $availableStock = $this->getAvailableStock();
        if ($delivery->initial_qty_loaded_ball > $availableStock) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient warehouse stock to start delivery',
                'available' => $availableStock,
                'required' => $delivery->initial_qty_loaded_ball,
            ], 422);
        }

        $delivery->update([
            'status' => 'IN_PROGRESS',
            'started_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Delivery started successfully',
            'data' => $delivery->load(['driver', 'vehicle']),
        ], 200);
    }

    /**
     * Complete delivery - Transition IN_PROGRESS → COMPLETED
     * Verify balance: loaded = delivered + returned
     */
    public function completeDelivery(Request $request, $id)
    {
        $delivery = Delivery::find($id);

        if (!$delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found',
            ], 404);
        }

        // Only IN_PROGRESS can complete
        if ($delivery->status !== 'IN_PROGRESS') {
            return response()->json([
                'success' => false,
                'message' => 'Only IN_PROGRESS deliveries can be completed',
                'current_status' => $delivery->status,
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'total_qty_returned_ball' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $totalDelivered = DeliveryItem::where('delivery_id', $id)->sum('delivered_qty_ball');
        $totalReturned = $request->total_qty_returned_ball;
        $totalLoaded = $delivery->initial_qty_loaded_ball;

        // Balance verification: loaded = delivered + returned (using == for loose comparison to handle decimal types)
        if ((float)$totalLoaded != ((float)$totalDelivered + (float)$totalReturned)) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery balance does not match',
                'balance' => [
                    'loaded' => $totalLoaded,
                    'delivered' => $totalDelivered,
                    'returned' => $totalReturned,
                    'total' => $totalDelivered + $totalReturned,
                ],
                'error' => "Loaded ($totalLoaded) ≠ Delivered + Returned ($totalDelivered + $totalReturned)",
            ], 422);
        }

        // Update delivery
        $delivery->update([
            'status' => 'COMPLETED',
            'total_qty_delivered_ball' => $totalDelivered,
            'total_qty_returned_ball' => $totalReturned,
            'completed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Delivery completed successfully',
            'summary' => [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
                'loaded' => $totalLoaded,
                'delivered' => $totalDelivered,
                'returned' => $totalReturned,
                'balance_verified' => (float)$totalLoaded == ((float)$totalDelivered + (float)$totalReturned),
            ],
            'data' => $delivery->load(['driver', 'vehicle', 'deliveryItems']),
        ], 200);
    }

    /**
     * Get real-time available warehouse stock
     * Formula: SUM(good productions POSTED) - SUM(delivered to stores)
     */
    private function getAvailableStock()
    {
        $totalProduced = Production::where('status', 'POSTED')
            ->sum('qty_good_ball');

        $totalDelivered = DeliveryItem::sum('delivered_qty_ball');

        return $totalProduced - $totalDelivered;
    }

    /**
     * Get delivery summary including stock calculations
     */
    public function getSummary($id)
    {
        $delivery = Delivery::with(['driver', 'vehicle', 'deliveryItems'])->find($id);

        if (!$delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found',
            ], 404);
        }

        $totalDelivered = $delivery->deliveryItems->sum('delivered_qty_ball');
        $currentLoad = $delivery->initial_qty_loaded_ball - $totalDelivered;

        return response()->json([
            'success' => true,
            'message' => 'Delivery summary retrieved successfully',
            'data' => [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
                'driver' => $delivery->driver,
                'vehicle' => $delivery->vehicle,
                'delivery_date' => $delivery->delivery_date,
                'initial_load' => $delivery->initial_qty_loaded_ball,
                'total_delivered' => $totalDelivered,
                'current_load' => $currentLoad,
                'stops_completed' => $delivery->deliveryItems->count(),
                'notes' => $delivery->notes,
            ],
        ], 200);
    }
}
