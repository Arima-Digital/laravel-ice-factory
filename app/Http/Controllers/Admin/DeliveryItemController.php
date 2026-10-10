<?php

namespace App\Http\Controllers\Admin;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Freezer;
use App\Models\FreezerProductComposition;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Services\StopVisitService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DeliveryItemController extends Controller
{
    /**
     * Display all delivery items for a delivery
     */
    public function index($deliveryId)
    {
        $delivery = Delivery::find($deliveryId);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found',
            ], 404);
        }

        $items = DeliveryItem::where('delivery_id', $deliveryId)
            ->with(['store', 'freezer', 'product', 'delivery'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Delivery items retrieved successfully',
            'data' => $items,
            'count' => count($items),
        ], 200);
    }

    /**
     * Confirm freezer stock and deliver
     * Driver confirms stock (1-tap with IoT suggestion or manual input)
     *
     * One row is written per freezer per product, so delivering two products
     * to the same freezer during one visit is two calls. Sales are no longer
     * derived from the weight difference here: a freezer can hold more than
     * one product and a load cell cannot say which one was sold, so the driver
     * records sales through recordSale().
     */
    public function confirmFreezer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_id' => 'required|exists:deliveries,id',
            'store_id' => 'required|exists:stores,id',
            'freezer_id' => 'required|exists:freezers,id',
            'product_id' => 'required|exists:products,id',
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

        $freezer = Freezer::find($request->freezer_id);

        // The freezer has to belong to the store being visited, otherwise a
        // delivery could be booked against a freezer at a different store.
        if ($freezer->store_id !== (int) $request->store_id) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer does not belong to this store',
            ], 422);
        }

        // One row per freezer per product, so a second product for the same
        // freezer in the same delivery is allowed but a repeat of the same
        // product is not.
        $existing = DeliveryItem::where('delivery_id', $request->delivery_id)
            ->where('freezer_id', $request->freezer_id)
            ->where('product_id', $request->product_id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'This product already confirmed for this freezer in this delivery',
            ], 422);
        }

        // When the route was planned, only the planned stores can be driven to.
        // A delivery with no stops is a plan made before routes existed, or one
        // created directly in the database, and is left permissive rather than
        // blocking a visit that the warehouse never had a way to forbid.
        $stop = $delivery->stops()->where('store_id', $request->store_id)->first();

        if ($stop === null && $delivery->stops()->exists()) {
            $planned = $delivery->stops()->pluck('store_id')->all();

            return response()->json([
                'success' => false,
                'message' => 'This store is not on the delivery route',
                'store_id' => (int) $request->store_id,
                'planned_store_ids' => $planned,
            ], 422);
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
            'product_id' => $request->product_id,
            'confirmed_stock_before_ball' => $request->confirmed_stock_before_ball,
            'delivered_qty_ball' => $request->delivered_qty_ball,
            'photo_stock_before' => count($photoStockBeforePaths) > 0 ? $photoStockBeforePaths : null,
            'photo_delivered' => count($photoDeliveredPaths) > 0 ? $photoDeliveredPaths : null,
            'visited_at' => now(),
            // 'notes' => $request->notes ?? null,
        ]);

        // Sync freezer product composition (transaksi-based, bukan hasil bagi IoT)
        $comp = FreezerProductComposition::firstOrCreate(
            [
                'freezer_id' => $request->freezer_id,
                'product_id' => $request->product_id,
            ],
            [
                'qty_ball' => 0.00,
            ]
        );
        $comp->qty_ball = round((float) $comp->qty_ball + (float) $request->delivered_qty_ball, 2);
        $comp->delivery_item_id = $deliveryItem->id;
        $comp->save();

        // Confirming goods is proof the driver got there, so a stop that is
        // confirmed without an arrival still records one. It is the same moment
        // seen from the other side, and leaving it null would make the stop look
        // unvisited on a route screen.
        // Confirming goods at a store that was passed over would put the stock
        // somewhere the run has already written off, so it is refused rather
        // than quietly undoing the skip.
        if ($stop !== null && $stop->status === 'SKIPPED') {
            return response()->json([
                'success' => false,
                'message' => 'This stop was skipped and cannot receive goods',
                'skip_reason' => $stop->notes,
            ], 422);
        }

        if ($stop !== null) {
            app(StopVisitService::class)->arrive($delivery, $stop);
        }

        $stop?->update([
            'status' => 'VISITED',
            'visited_at' => $stop->visited_at ?? now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Freezer confirmed successfully',
            'data' => [
                'delivery_item' => $deliveryItem->load(['store', 'freezer', 'product', 'delivery']),
                'photos' => [
                    'photo_stock_before_urls' => array_map(
                        fn ($path) => Storage::url($path),
                        $deliveryItem->photo_stock_before ?? []
                    ),
                    'photo_delivered_urls' => array_map(
                        fn ($path) => Storage::url($path),
                        $deliveryItem->photo_delivered ?? []
                    ),
                ],
                'stock_check' => $this->crossCheckFreezer($freezer),
                'note' => 'Sales are recorded separately with POST /api/delivery-items/{deliveryItemId}/sales',
            ],
        ], 201);
    }

    /**
     * Record a sale against a delivery item.
     *
     * The driver states what the store bought. This is the source of the sales
     * figure: a load cell reports one weight and cannot say which of the
     * products in the freezer was sold, so the weight difference is no longer
     * used to derive it.
     *
     * The unit price is read from the product rather than taken from the
     * request, so the same product cannot be sold at a different price on two
     * different visits.
     */
    public function recordSale(Request $request, $id)
    {
        $deliveryItem = DeliveryItem::with(['freezer', 'product', 'store', 'delivery'])->find($id);

        if (! $deliveryItem) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery item not found',
            ], 404);
        }

        $delivery = $deliveryItem->delivery;

        if ($delivery->status !== 'IN_PROGRESS') {
            return response()->json([
                'success' => false,
                'message' => 'Only IN_PROGRESS deliveries can record sales',
                'current_status' => $delivery->status,
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
            'qty_ball' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // The product has to be one that was actually delivered to this
        // freezer, otherwise the sale would not match any delivery.
        $delivered = DeliveryItem::where('delivery_id', $deliveryItem->delivery_id)
            ->where('freezer_id', $deliveryItem->freezer_id)
            ->where('product_id', $request->product_id)
            ->exists();

        if (! $delivered) {
            return response()->json([
                'success' => false,
                'message' => 'Product was not delivered to this freezer in this delivery',
            ], 422);
        }

        $product = Product::find($request->product_id);
        $qtyBall = round((float) $request->qty_ball, 2);
        $totalAmount = round($qtyBall * (float) $product->selling_price, 2);

        $sale = Sale::create([
            'store_id' => $deliveryItem->store_id,
            'freezer_id' => $deliveryItem->freezer_id,
            'product_id' => $product->id,
            'delivery_item_id' => $deliveryItem->id,
            'qty_ball' => $qtyBall,
            'unit_price' => $product->selling_price,
            'total_amount' => $totalAmount,
            'status' => 'PENDING',
            'sold_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sale recorded, waiting for admin approval',
            'data' => [
                'sale' => $sale->load(['product', 'freezer', 'store']),
                'total_amount' => $totalAmount,
                'note' => 'Stored at full precision, rounded to the nearest 1.000 only for display',
            ],
        ], 201);
    }

    /**
     * Compare what the load cell reports with what the driver recorded.
     *
     * The sensor is a check, not the source of the numbers. When they agree the
     * figures are consistent; when they do not, something was mistyped or
     * something is missing, and the admin decides what to do about it. A
     * mismatch never blocks the confirmation.
     */
    private function crossCheckFreezer(Freezer $freezer): array
    {
        // The starting point is the most recent hand-confirmed figure, and every
        // delivery and sale counted below has to belong to that visit or a later
        // one. Summing the freezer's whole history here would re-add everything
        // the baseline already accounted for and report a drift every time.
        $baseline = DeliveryItem::where('freezer_id', $freezer->id)
            ->orderByDesc('id')
            ->first();

        if (! $baseline) {
            $deliveredBall = 0.0;
            $soldBall = 0.0;
            $baselineBall = null;
        } else {
            // confirmed_stock_before_ball belongs to one product, but the load
            // cell reports one number for the whole freezer, so the baseline has
            // to be the sum over every product recorded during that same visit.
            // Taking only the last row would compare one product's figure with
            // the total contents and report a drift on every multi-product stop.
            $visitItems = DeliveryItem::where('freezer_id', $freezer->id)
                ->where('delivery_id', $baseline->delivery_id);

            $baselineBall = round((float) (clone $visitItems)->sum('confirmed_stock_before_ball'), 2);
            $deliveredBall = round((float) $visitItems->sum('delivered_qty_ball'), 2);

            $soldBall = round((float) Sale::confirmed()
                ->where('freezer_id', $freezer->id)
                ->whereIn('delivery_item_id', (clone $visitItems)->select('delivery_items.id'))
                ->sum('qty_ball'), 2);
        }

        $sensorStock = $freezer->estimated_stock_ball;

        $driftBall = $baselineBall === null
            ? null
            : round($sensorStock - ($baselineBall + $deliveredBall - $soldBall), 2);

        return [
            'sensor_stock_ball' => $sensorStock,
            'confirmed_stock_before_ball' => $baselineBall,
            'delivered_ball' => $deliveredBall,
            'sold_ball' => $soldBall,
            'expected_stock_ball' => $baselineBall === null
                ? null
                : round($baselineBall + $deliveredBall - $soldBall, 2),
            'drift_ball' => $driftBall,
            'result' => $driftBall === null
                ? 'UNKNOWN'
                // The tolerance is one restock step, not a hundredth of a ball.
                // Both sides come from figures snapped to half-ball steps, so
                // rounding alone can put a full step between them: a freezer that
                // really measures 4.9 is reported as 4.5, which reads as half a
                // ball away from a driver who correctly wrote 5. Anything up to
                // one step is treated as agreement, and only a difference the
                // rounding cannot explain gets reported.
                : (abs($driftBall) <= Freezer::DRIFT_TOLERANCE_BALL ? 'MATCH' : 'DRIFT'),
            'iot_confidence' => $freezer->iot_confidence,
            'note' => 'The sensor reports a total for the whole freezer. It cannot say which product accounts for a difference, so drift is only a prompt to recount.',
        ];
    }

    /**
     * Get IoT suggestion for a freezer
     * Calculate suggested delivery based on IoT weight data
     */
    public function getSuggestion($freezerId)
    {
        $freezer = Freezer::with('products')->find($freezerId);

        if (! $freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer not found',
            ], 404);
        }

        // Reuse the model accessors so this endpoint and the freezer payload
        // can never disagree. They already clamp to the freezer capacity and
        // treat a missing sensor reading as zero, which stops an unmeasured
        // freezer from being suggested more than it can hold.
        $estimatedStock = $freezer->estimated_stock_ball;
        $suggestedDelivery = $freezer->suggested_delivery_ball;
        $netWeight = $freezer->last_weight_kg === null
            ? null
            : (float) $freezer->last_weight_kg - (float) $freezer->tare_weight_kg;
        $lastSeenMinutes = $freezer->last_seen_at === null
            ? null
            : now()->diffInMinutes($freezer->last_seen_at);

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
                    'estimated_stock_ball' => $estimatedStock,
                    'max_capacity_ball' => (float) $freezer->max_capacity_ball,
                    'suggested_delivery_ball' => $suggestedDelivery,
                    'ball_kg' => Freezer::BALL_KG,
                    'confidence' => $freezer->iot_confidence,
                    'note' => 'Driver should double-check physical stock',
                ],
                // A freezer can hold more than one product, so the suggestion is
                // a total in kilograms and cannot be split per product. The
                // products that have been delivered here are listed so the
                // driver knows what he may find inside, not what the weights
                // add up to.
                'products' => $freezer->products,
                'note' => 'The suggestion is the total content of the freezer. Which products make up that total is recorded during the visit, not by the sensor.',
            ],
        ], 200);
    }

    /**
     * Get delivery item details
     */
    public function show($id)
    {
        $item = DeliveryItem::with(['store', 'freezer', 'product', 'delivery', 'sales'])->find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery item not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery item retrieved successfully',
            'data' => [
                'delivery_item' => $item,
                // Re-read on demand, not only at confirmation time: the sensor
                // keeps reporting after the driver walked away, and an approval
                // or rejection changes the comparison.
                'stock_check' => $this->crossCheckFreezer($item->freezer),
            ],
        ], 200);
    }

    /**
     * Get delivery items by store
     */
    public function getByStore($storeId)
    {
        $store = Store::find($storeId);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $items = DeliveryItem::where('store_id', $storeId)
            ->with(['freezer', 'product', 'delivery', 'sales'])
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

        if (! $freezer) {
            return response()->json([
                'success' => false,
                'message' => 'Freezer not found',
            ], 404);
        }

        $items = DeliveryItem::where('freezer_id', $freezerId)
            ->with(['product', 'delivery', 'sales'])
            ->orderBy('visited_at', 'desc')
            ->get();

        // A freezer can hold more than one product, so the delivered total is
        // per product. Summing across products would still be a valid weight,
        // but it would not be attributable to anything.
        $totalDelivered = $items->sum('delivered_qty_ball');
        $totalSales = $items->flatMap->sales->where('status', 'CONFIRMED')->sum('total_amount');

        $deliveredByProduct = $items
            ->groupBy('product_id')
            ->map(function ($productItems, $productId) {
                return [
                    'product' => $productItems->first()->product,
                    'delivered_ball' => round((float) $productItems->sum('delivered_qty_ball'), 2),
                    'sold_ball' => round((float) $productItems->flatMap->sales->sum('qty_ball'), 2),
                    'sales_amount' => round((float) $productItems->flatMap->sales->where('status', 'CONFIRMED')->sum('total_amount'), 2),
                ];
            })
            ->values();

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
                    'by_product' => $deliveredByProduct,
                ],
            ],
        ], 200);
    }
}
