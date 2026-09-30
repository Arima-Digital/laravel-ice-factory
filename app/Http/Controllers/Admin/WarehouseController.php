<?php

namespace App\Http\Controllers\Admin;

use App\Models\Warehouse;
use App\Services\WarehouseStockService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class WarehouseController extends Controller
{
    /**
     * Display a listing of all warehouses
     */
    public function index()
    {
        $warehouses = Warehouse::all();

        return response()->json([
            'success' => true,
            'message' => 'Warehouses retrieved successfully',
            'data' => $warehouses,
            'count' => count($warehouses),
        ], 200);
    }

    /**
     * Store a newly created warehouse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:warehouses,code',
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $warehouse = Warehouse::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Warehouse created successfully',
            'data' => $warehouse,
        ], 201);
    }

    /**
     * Display a specific warehouse
     */
    public function show($id)
    {
        $warehouse = Warehouse::find($id);

        if (!$warehouse) {
            return response()->json([
                'success' => false,
                'message' => 'Warehouse not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Warehouse retrieved successfully',
            'data' => $warehouse,
        ], 200);
    }

    /**
     * Update a warehouse
     */
    public function update(Request $request, $id)
    {
        $warehouse = Warehouse::find($id);

        if (!$warehouse) {
            return response()->json([
                'success' => false,
                'message' => 'Warehouse not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|unique:warehouses,code,' . $id,
            'name' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $warehouse->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Warehouse updated successfully',
            'data' => $warehouse,
        ], 200);
    }

    /**
     * Delete a warehouse
     */
    public function destroy($id)
    {
        $warehouse = Warehouse::find($id);

        if (!$warehouse) {
            return response()->json([
                'success' => false,
                'message' => 'Warehouse not found',
            ], 404);
        }

        $warehouse->delete();

        return response()->json([
            'success' => true,
            'message' => 'Warehouse deleted successfully',
        ], 200);
    }

    /**
     * Get real-time available stock for a warehouse
     *
     * Scoped to the warehouse in the URL. This used to ignore $id and sum the
     * whole productions table, which made every warehouse report the same
     * figure. Production batches that are not attributed to a warehouse are
     * reported separately instead of being folded into one of them.
     */
    public function getAvailableStock($id)
    {
        $warehouse = Warehouse::find($id);

        if (!$warehouse) {
            return response()->json([
                'success' => false,
                'message' => 'Warehouse not found',
            ], 404);
        }

        $stock = app(WarehouseStockService::class);

        $totalProduced = $stock->produced($warehouse->id);
        $totalDelivered = $stock->delivered($warehouse->id);
        $unassigned = $stock->unassigned();

        return response()->json([
            'success' => true,
            'message' => 'Available stock calculated successfully',
            'data' => [
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
                'total_produced_posted' => $totalProduced,
                'total_delivered' => $totalDelivered,
                'available_stock' => $totalProduced - $totalDelivered,
                'unassigned_production_qty' => $unassigned,
                'note' => 'Available = good production POSTED in this warehouse - delivered by this warehouse. '
                    . 'unassigned_production_qty is POSTED production with no warehouse and is excluded from available_stock.',
            ],
        ], 200);
    }
}
