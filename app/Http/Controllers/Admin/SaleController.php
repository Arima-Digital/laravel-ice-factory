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
     *
     * Sales are recorded by the driver through
     * POST /admin/delivery-items/{id}/sales and start as PENDING until an
     * admin approves them. Optional filters: ?status=PENDING&store_id=1&product_id=1
     */
    public function index(Request $request)
    {
        $sales = Sale::with(['store', 'freezer', 'product', 'deliveryItem'])
            ->when($request->query('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->query('store_id'), function ($query, $storeId) {
                $query->where('store_id', $storeId);
            })
            ->when($request->query('product_id'), function ($query, $productId) {
                $query->where('product_id', $productId);
            })
            ->orderBy('sold_at', 'desc')
            ->get();

        // Split by status rather than summing everything: a rejected sale must
        // not be counted as revenue, and an unreviewed one must not be counted
        // as a debt either. Only CONFIRMED is money that has been agreed.
        $byStatus = $sales->groupBy('status')->map(function ($rows) {
            return [
                'count' => $rows->count(),
                'qty_ball' => round((float) $rows->sum('qty_ball'), 2),
                'amount' => round((float) $rows->sum('total_amount'), 2),
            ];
        });

        $summary = [
            'PENDING' => $byStatus->get('PENDING', ['count' => 0, 'qty_ball' => 0.0, 'amount' => 0.0]),
            'CONFIRMED' => $byStatus->get('CONFIRMED', ['count' => 0, 'qty_ball' => 0.0, 'amount' => 0.0]),
            'VOID' => $byStatus->get('VOID', ['count' => 0, 'qty_ball' => 0.0, 'amount' => 0.0]),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Sales retrieved successfully',
            'data' => $sales,
            'count' => count($sales),
            'summary' => $summary,
            'total_amount' => $summary['CONFIRMED']['amount'],
            'total_qty_ball' => $summary['CONFIRMED']['qty_ball'],
            'pending_amount' => $summary['PENDING']['amount'],
        ], 200);
    }

    /**
     * Approve a sale recorded by the driver.
     *
     * Only confirmed sales count towards what a store owes, so this is what
     * moves a figure from "reported" to "billed".
     */
    public function approve($id)
    {
        return $this->changeStatus($id, 'CONFIRMED', 'Sale approved');
    }

    /**
     * Reject a sale recorded by the driver, keeping the row for the audit
     * trail rather than deleting it.
     */
    public function reject($id)
    {
        return $this->changeStatus($id, 'VOID', 'Sale rejected');
    }

    private function changeStatus($id, string $status, string $message)
    {
        $sale = Sale::find($id);

        if (!$sale) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found',
            ], 404);
        }

        if ($sale->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Only PENDING sales can be reviewed',
                'current_status' => $sale->status,
            ], 422);
        }

        $sale->update(['status' => $status]);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $sale->load(['store', 'freezer', 'product', 'deliveryItem']),
        ], 200);
    }

    /**
     * Display a specific sale
     */
    public function show($id)
    {
        $sale = Sale::with(['store', 'freezer', 'product', 'deliveryItem'])->find($id);

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
            ->with(['freezer', 'product', 'deliveryItem'])
            ->orderBy('sold_at', 'desc')
            ->get();

        // This is the view of what a store has bought, so the total is the
        // money an admin has agreed. Pending and rejected sales are listed so
        // the store's history is complete, but they are not added to it.
        $confirmed = $sales->where('status', 'CONFIRMED');

        return response()->json([
            'success' => true,
            'message' => 'Sales for store retrieved successfully',
            'data' => [
                'store' => $store,
                'sales' => $sales,
                'count' => count($sales),
                'summary' => [
                    'total_qty_ball' => round((float) $confirmed->sum('qty_ball'), 2),
                    'total_amount' => round((float) $confirmed->sum('total_amount'), 2),
                    'pending_amount' => round((float) $sales->where('status', 'PENDING')->sum('total_amount'), 2),
                    'void_amount' => round((float) $sales->where('status', 'VOID')->sum('total_amount'), 2),
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
            ->with(['product', 'deliveryItem'])
            ->orderBy('sold_at', 'desc')
            ->get();

        $confirmed = $sales->where('status', 'CONFIRMED');
        $totalAmount = round((float) $confirmed->sum('total_amount'), 2);
        $totalQty = round((float) $confirmed->sum('qty_ball'), 2);
        $pendingAmount = round((float) $sales->where('status', 'PENDING')->sum('total_amount'), 2);

        // A freezer can hold more than one product at a time, so the money is
        // broken down per product rather than left as one freezer total. Only
        // confirmed sales appear here: a per-product figure that included sales
        // an admin may still reject would not be comparable between products.
        $byProduct = $confirmed->groupBy('product_id')->map(function ($productSales) {
            return [
                'product' => $productSales->first()->product,
                'qty_ball' => round((float) $productSales->sum('qty_ball'), 2),
                'total_amount' => round((float) $productSales->sum('total_amount'), 2),
            ];
        })->values();

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
                    'pending_amount' => $pendingAmount,
                    'avg_qty_per_sale' => $confirmed->count() > 0
                        ? round($totalQty / $confirmed->count(), 2)
                        : 0,
                    'by_product' => $byProduct,
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
            ->with(['store', 'freezer', 'product', 'deliveryItem'])
            ->orderBy('sold_at', 'desc')
            ->get();

        // A day's revenue is what an admin confirmed on that day. Sales still
        // waiting for review and sales that were rejected are listed so the day
        // can be reconciled, but neither is added to the total.
        $confirmed = $sales->where('status', 'CONFIRMED');

        return response()->json([
            'success' => true,
            'message' => 'Sales for date retrieved successfully',
            'data' => [
                'date' => $date,
                'sales' => $sales,
                'count' => count($sales),
                'summary' => [
                    'total_qty_ball' => round((float) $confirmed->sum('qty_ball'), 2),
                    'total_amount' => round((float) $confirmed->sum('total_amount'), 2),
                    'pending_amount' => round((float) $sales->where('status', 'PENDING')->sum('total_amount'), 2),
                    'void_amount' => round((float) $sales->where('status', 'VOID')->sum('total_amount'), 2),
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

        $sales = $query->with(['store', 'freezer', 'product'])->get();

        // When the caller filters by a single status the totals are simply that
        // status. Otherwise only confirmed sales count as revenue and the rest
        // is reported alongside, so a pending figure can never be mistaken for
        // money that has been agreed.
        $filteredByStatus = $request->has('status');
        $counted = $filteredByStatus ? $sales : $sales->where('status', 'CONFIRMED');

        $totalAmount = round((float) $counted->sum('total_amount'), 2);
        $totalQty = round((float) $counted->sum('qty_ball'), 2);
        $pendingAmount = round((float) $sales->where('status', 'PENDING')->sum('total_amount'), 2);

        // Group by store
        $byStore = $counted->groupBy('store_id')->map(function ($storeSales) {
            $store = $storeSales->first()->store;
            return [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'total_qty_ball' => round((float) $storeSales->sum('qty_ball'), 2),
                'total_amount' => round((float) $storeSales->sum('total_amount'), 2),
                'count' => count($storeSales),
            ];
        })->values();

        // Group by date
        $byDate = $counted->groupBy(function ($sale) {
            return $sale->sold_at->format('Y-m-d');
        })->map(function ($dateSales, $date) {
            return [
                'date' => $date,
                'total_qty_ball' => round((float) $dateSales->sum('qty_ball'), 2),
                'total_amount' => round((float) $dateSales->sum('total_amount'), 2),
                'count' => count($dateSales),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Sales summary retrieved successfully',
            'data' => [
                'total_sales' => $counted->count(),
                'listed_sales' => count($sales),
                'total_qty_ball' => $totalQty,
                'total_amount' => $totalAmount,
                'pending_amount' => $pendingAmount,
                'avg_amount_per_sale' => $counted->count() > 0
                    ? round($totalAmount / $counted->count(), 2)
                    : 0,
                'by_store' => $byStore,
                'by_date' => $byDate,
                // Every product has its own price, so revenue per product is
                // the number worth looking at rather than a single total.
                'by_product' => $counted->groupBy('product_id')->map(function ($productSales) {
                    return [
                        'product' => $productSales->first()->product,
                        'total_qty_ball' => round((float) $productSales->sum('qty_ball'), 2),
                        'total_amount' => round((float) $productSales->sum('total_amount'), 2),
                    ];
                })->values(),
            ],
        ], 200);
    }

    /**
     * Get sales status breakdown
     */
    public function getByStatus($status)
    {
        $sales = Sale::where('status', $status)
            ->with(['store', 'freezer', 'product', 'deliveryItem'])
            ->orderBy('sold_at', 'desc')
            ->get();

        // The status is fixed by the route here, so summing the rows is the
        // right answer: asking for PENDING means the caller wants the pending
        // total, not a confirmed one.
        $totalAmount = round((float) $sales->sum('total_amount'), 2);
        $totalQty = round((float) $sales->sum('qty_ball'), 2);

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
