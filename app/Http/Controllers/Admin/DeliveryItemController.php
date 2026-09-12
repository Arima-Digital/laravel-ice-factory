<?php

namespace App\Http\Controllers\Admin;

use App\Models\DeliveryItem;
use App\Models\Delivery;
use App\Models\Store;
use App\Models\Freezer;
use App\Models\Sale;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class DeliveryItemController extends Controller
{
    /**
     * Display all delivery items for a delivery
     */
    public function index($deliveryId)
    {
        $delivery = Delivery::find($deliveryId);

        if (!$delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found',
            ], 404);
        }

        $items = DeliveryItem::where('delivery_id', $deliveryId)
            ->with(['store', 'freezer', 'delivery'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Delivery items retrieved successfully',
            'data' => $items,
            'count' => count($items),
        ], 200);
    }

    /**
     * Confirm freezer stock & deliver
     * Driver confirms stock (1-tap with IoT suggestion or manual input)
     * Auto-calculate sales if previous delivery exists
     */
    public function confirmFreezer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_id' => 'required|exists:deliveries,id',
            'store_id' => 'required|exists:stores,id',
            'freezer_id' => 'required|exists:freezers,id',
            'confirmed_stock_before_ball' => 'required|numeric|min:0',
            'delivered_qty_ball' => 'required|numeric|min:0',
            'photo_stock_before' => 'nullable|array',
            'photo_stock_before.*' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'photo_delivered' => 'nullable|array',
            'photo_delivered.*' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            // 'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $delivery = Delivery::find($request->delivery_id);

        // Only IN_PROGRESS deliveries can confirm items
        if ($delivery->status !== 'IN_PROGRESS') {
            return response()->json([
                'success' => false,
                'message' => 'Only IN_PROGRESS deliveries can confirm items',
                'current_status' => $delivery->status,
            ], 422);
        }

        // Check if this freezer already confirmed for this delivery
        $existing = DeliveryItem::where('delivery_id', $request->delivery_id)
            ->where('freezer_id', $request->freezer_id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'This freezer already confirmed for this delivery',
            ], 422);
        }

        // Get previous delivery item for this freezer (for sales calculation)
        $previousItem = DeliveryItem::where('freezer_id', $request->freezer_id)
            ->where('delivery_id', '!=', $request->delivery_id)
            ->orderBy('visited_at', 'desc')
            ->first();

        $qtySold = 0;
        $salesAmount = 0;

        // Calculate sales if previous delivery exists
        if ($previousItem) {
            // Get previous confirmed stock AFTER delivery (before + delivered)
            $previousStockAfter = $previousItem->confirmed_stock_before_ball + $previousItem->delivered_qty_ball;
            
            // Current confirmed stock BEFORE delivery
            $currentStockBefore = $request->confirmed_stock_before_ball;
            
            // Qty Sold = Previous stock after - Current stock before
            $qtySold = $previousStockAfter - $currentStockBefore;

            // Get product price
            $freezer = Freezer::find($request->freezer_id);
            $product = Product::find($freezer->product_id);
            $salesAmount = $qtySold * $product->selling_price;
        }

        // Create delivery item
        $photoStockBeforePaths = [];
        $photoDeliveredPaths = [];

        // Handle multiple photo_stock_before uploads
        if ($request->hasFile('photo_stock_before')) {
            foreach ($request->file('photo_stock_before') as $photo) {
                if ($photo) {
                    $path = $photo->store('delivery-items/stock-before', 'public');
                    $photoStockBeforePaths[] = $path;
                }
            }
        }

        // Handle multiple photo_delivered uploads
        if ($request->hasFile('photo_delivered')) {
            foreach ($request->file('photo_delivered') as $photo) {
                if ($photo) {
                    $path = $photo->store('delivery-items/delivered', 'public');
                    $photoDeliveredPaths[] = $path;
                }
            }
        }

        $deliveryItem = DeliveryItem::create([
            'delivery_id' => $request->delivery_id,
            'store_id' => $request->store_id,
            'freezer_id' => $request->freezer_id,
            'confirmed_stock_before_ball' => $request->confirmed_stock_before_ball,
            'delivered_qty_ball' => $request->delivered_qty_ball,
            'photo_stock_before' => count($photoStockBeforePaths) > 0 ? $photoStockBeforePaths : null,
            'photo_delivered' => count($photoDeliveredPaths) > 0 ? $photoDeliveredPaths : null,
            'visited_at' => now(),
            // 'notes' => $request->notes ?? null,
        ]);

        // Auto-create sales record if qty sold > 0
        $sale = null;
        if ($qtySold > 0) {
            $freezer = Freezer::find($request->freezer_id);
            $product = Product::find($freezer->product_id);

            $sale = Sale::create([
                'store_id' => $request->store_id,
                'freezer_id' => $request->freezer_id,
                'delivery_item_id' => $deliveryItem->id,
                'qty_ball' => $qtySold,
                'unit_price' => $product->selling_price,
                'total_amount' => $salesAmount,
                'status' => 'CONFIRMED',
                'sold_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Freezer confirmed successfully',
            'data' => [
                'delivery_item' => $deliveryItem->load(['store', 'freezer', 'delivery']),
                'photos' => [
                    'photo_stock_before_urls' => array_map(
                        fn($path) => \Illuminate\Support\Facades\Storage::url($path),
                        $deliveryItem->photo_stock_before ?? []
                    ),
                    'photo_delivered_urls' => array_map(
                        fn($path) => \Illuminate\Support\Facades\Storage::url($path),
                        $deliveryItem->photo_delivered ?? []
                    ),
                ],
                'sales_calculated' => $qtySold > 0,
                'qty_sold' => $qtySold,
                'sales_amount' => $salesAmount,
                'sale_record' => $sale,
                'note' => $qtySold > 0 
                    ? "Sales auto-calculated from previous delivery"
                    : "First visit to this freezer, no sales calculated",
            ],
        ], 201);
    }

    /**
     * Get IoT suggestion for a freezer
     * Calculate suggested delivery based on IoT weight data
     */
    public function getSuggestion($freezerId)
    {
        $freezer = Freezer::with('product')->find($freezerId);

        if (!$freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer not found',
            ], 404);
        }

        // Calculate estimated stock from IoT weight
        $netWeight = $freezer->last_weight_kg - $freezer->tare_weight_kg;
        $estimatedStock = $netWeight / $freezer->product->weight_kg;

        // Suggested delivery = max capacity - estimated stock
        $suggestedDelivery = max(0, $freezer->max_capacity_ball - $estimatedStock);

        // Confidence level based on last seen time
        $lastSeenMinutes = now()->diffInMinutes($freezer->last_seen_at);
        $confidence = 'HIGH';
        if ($lastSeenMinutes > 60) {
            $confidence = 'MEDIUM';
        }
        if ($lastSeenMinutes > 120) {
            $confidence = 'LOW';
        }

        return response()->json([
            'success' => true,
            'message' => 'Freezer suggestion retrieved successfully',
            'data' => [
                'freezer_id' => $freezer->id,
                'freezer_code' => $freezer->code,
                'store' => $freezer->store,
                'iot_data' => [
                    'last_weight_kg' => $freezer->last_weight_kg,
                    'tare_weight_kg' => $freezer->tare_weight_kg,
                    'net_weight_kg' => $netWeight,
                    'last_temperature_c' => $freezer->last_temperature_c,
                    'last_door_status' => $freezer->last_door_status,
                    'last_seen_at' => $freezer->last_seen_at,
                    'last_seen_minutes_ago' => $lastSeenMinutes,
                ],
                'suggestion' => [
                    'estimated_stock_ball' => round($estimatedStock, 2),
                    'max_capacity_ball' => $freezer->max_capacity_ball,
                    'suggested_delivery_ball' => round($suggestedDelivery, 2),
                    'confidence' => $confidence,
                    'note' => 'Driver should double-check physical stock',
                ],
            ],
        ], 200);
    }

    /**
     * Get delivery item details
     */
    public function show($id)
    {
        $item = DeliveryItem::with(['store', 'freezer', 'delivery', 'sales'])->find($id);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery item not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery item retrieved successfully',
            'data' => $item,
        ], 200);
    }

    /**
     * Get delivery items by store
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

        $items = DeliveryItem::where('store_id', $storeId)
            ->with(['freezer', 'delivery', 'sales'])
            ->orderBy('visited_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Delivery items for store retrieved successfully',
            'data' => [
                'store' => $store,
                'delivery_items' => $items,
                'count' => count($items),
            ],
        ], 200);
    }

    /**
     * Get delivery items by freezer (history)
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

        $items = DeliveryItem::where('freezer_id', $freezerId)
            ->with(['delivery', 'sales'])
            ->orderBy('visited_at', 'desc')
            ->get();

        // Calculate freezer stats
        $totalDelivered = $items->sum('delivered_qty_ball');
        $totalSales = $items->flatMap->sales->sum('total_amount');

        return response()->json([
            'success' => true,
            'message' => 'Delivery items for freezer retrieved successfully',
            'data' => [
                'freezer' => $freezer,
                'delivery_items' => $items,
                'count' => count($items),
                'stats' => [
                    'total_delivered_ball' => $totalDelivered,
                    'total_sales_amount' => $totalSales,
                    'avg_delivery_ball' => count($items) > 0 ? $totalDelivered / count($items) : 0,
                ],
            ],
        ], 200);
    }
}
