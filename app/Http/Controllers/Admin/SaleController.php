<?php

namespace App\Http\Controllers\Admin;

use App\Models\Sale;
use App\Models\Store;
use App\Models\Freezer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    /**
     * Display all sales (read-only)
     * Sales are auto-generated from DeliveryItemController
     */
    public function index()
    {
        $sales = Sale::with(['store', 'freezer', 'deliveryItem'])
            ->orderBy('sold_at', 'desc')
            ->get();

        $totalAmount = $sales->sum('total_amount');
        $totalQty = $sales->sum('qty_ball');

        return response()->json([
            'success' => true,
            'message' => 'Sales retrieved successfully',
            'data' => $sales,
            'count' => count($sales),
            'summary' => [
                'total_qty_ball' => $totalQty,
                'total_amount' => $totalAmount,
            ],
        ], 200);
    }

    /**
     * Display a specific sale
     */
    public function show($id)
    {
        $sale = Sale::with(['store', 'freezer', 'deliveryItem'])->find($id);

        if (!$sale) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sale retrieved successfully',
            'data' => $sale,
        ], 200);
    }

    /**
     * Get sales by store
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

        $sales = Sale::where('store_id', $storeId)
            ->with(['freezer', 'deliveryItem'])
            ->orderBy('sold_at', 'desc')
            ->get();

        $totalAmount = $sales->sum('total_amount');
        $totalQty = $sales->sum('qty_ball');

        return response()->json([
            'success' => true,
            'message' => 'Sales for store retrieved successfully',
            'data' => [
                'store' => $store,
                'sales' => $sales,
                'count' => count($sales),
                'summary' => [
                    'total_qty_ball' => $totalQty,
                    'total_amount' => $totalAmount,
                ],
            ],
        ], 200);
    }

    /**
     * Get sales by freezer
     */
    public function getByFreezer($freezerId)
    {
        $freezer = Freezer::with('store')->find($freezerId);

        if (!$freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer not found',
            ], 404);
        }

        $sales = Sale::where('freezer_id', $freezerId)
            ->with('deliveryItem')
            ->orderBy('sold_at', 'desc')
            ->get();

        $totalAmount = $sales->sum('total_amount');
        $totalQty = $sales->sum('qty_ball');

        return response()->json([
            'success' => true,
            'message' => 'Sales for freezer retrieved successfully',
            'data' => [
                'freezer' => $freezer,
                'sales' => $sales,
                'count' => count($sales),
                'summary' => [
                    'total_qty_ball' => $totalQty,
                    'total_amount' => $totalAmount,
                    'avg_qty_per_sale' => count($sales) > 0 ? $totalQty / count($sales) : 0,
                ],
            ],
        ], 200);
    }

    /**
     * Get sales by date
     */
    public function getByDate($date)
    {
        $sales = Sale::whereDate('sold_at', $date)
            ->with(['store', 'freezer', 'deliveryItem'])
            ->orderBy('sold_at', 'desc')
            ->get();

        $totalAmount = $sales->sum('total_amount');
        $totalQty = $sales->sum('qty_ball');

        return response()->json([
            'success' => true,
            'message' => 'Sales for date retrieved successfully',
            'data' => [
                'date' => $date,
                'sales' => $sales,
                'count' => count($sales),
                'summary' => [
                    'total_qty_ball' => $totalQty,
                    'total_amount' => $totalAmount,
                ],
            ],
        ], 200);
    }

    /**
     * Get sales summary with advanced filtering
     */
    public function getSummary(Request $request)
    {
        $query = Sale::query();

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('sold_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('sold_at', '<=', $request->end_date);
        }

        // Filter by store
        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $sales = $query->with(['store', 'freezer'])->get();

        $totalAmount = $sales->sum('total_amount');
        $totalQty = $sales->sum('qty_ball');

        // Group by store
        $byStore = $sales->groupBy('store_id')->map(function ($storeSales) {
            $store = $storeSales->first()->store;
            return [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'total_qty_ball' => $storeSales->sum('qty_ball'),
                'total_amount' => $storeSales->sum('total_amount'),
                'count' => count($storeSales),
            ];
        })->values();

        // Group by date
        $byDate = $sales->groupBy(function ($sale) {
            return $sale->sold_at->format('Y-m-d');
        })->map(function ($dateSales, $date) {
            return [
                'date' => $date,
                'total_qty_ball' => $dateSales->sum('qty_ball'),
                'total_amount' => $dateSales->sum('total_amount'),
                'count' => count($dateSales),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Sales summary retrieved successfully',
            'data' => [
                'total_sales' => count($sales),
                'total_qty_ball' => $totalQty,
                'total_amount' => $totalAmount,
                'avg_amount_per_sale' => count($sales) > 0 ? $totalAmount / count($sales) : 0,
                'by_store' => $byStore,
                'by_date' => $byDate,
            ],
        ], 200);
    }

    /**
     * Get sales status breakdown
     */
    public function getByStatus($status)
    {
        $sales = Sale::where('status', $status)
            ->with(['store', 'freezer', 'deliveryItem'])
            ->orderBy('sold_at', 'desc')
            ->get();

        $totalAmount = $sales->sum('total_amount');
        $totalQty = $sales->sum('qty_ball');

        return response()->json([
            'success' => true,
            'message' => 'Sales by status retrieved successfully',
            'data' => [
                'status' => $status,
                'sales' => $sales,
                'count' => count($sales),
                'summary' => [
                    'total_qty_ball' => $totalQty,
                    'total_amount' => $totalAmount,
                ],
            ],
        ], 200);
    }
}
