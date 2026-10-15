<?php

namespace App\Http\Controllers\Admin;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryLoadItem;
use App\Models\DeliveryStop;
use App\Models\Freezer;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliverySuggestionService;
use App\Services\RoutePlannerService;
use App\Services\StopVisitException;
use App\Services\StopVisitService;
use App\Services\WarehouseStockService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class DeliveryController extends Controller
{
    /**
     * Display a listing of deliveries.
     *
     * A driver is only shown their own runs. The route endpoint tells them
     * where they are going; the other drivers' loads and debt are not theirs
     * to see.
     */
    public function index(Request $request)
    {
        $query = Delivery::with(['driver', 'vehicle', 'stops.store'])->latest();

        if ($request->user()?->role === 'DRIVER') {
            $query->where('driver_id', $request->user()->id);
        }

        $deliveries = $query->latest('delivery_date')->get();

        $deliveries->each(function ($delivery) {
            $delivery->driver?->makeHidden(['created_at', 'updated_at']);
            $delivery->vehicle?->makeHidden(['created_at', 'updated_at']);
            $delivery->stops->each(fn ($stop) => $stop->store?->makeHidden(['created_at', 'updated_at']));
        });

        return response()->json([
            'success' => true,
            'message' => 'Data pengiriman berhasil diambil',
            'data' => $deliveries,
            'count' => count($deliveries),
        ], 200);
    }

    /**
     * Store a newly created delivery (Delivery Planning)
     *
     * Produces a DRAFT plan. A driver drafts their own and an admin approves it
     * via postDelivery; a warehouse does neither. The plan never becomes a run
     * from here, so whatever this creates is still a proposal.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'driver_id' => 'required|exists:users,id',
            'vehicle_id' => 'required|exists:vehicles,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'delivery_date' => 'nullable|date',
            'initial_qty_loaded_ball' => 'nullable|numeric|min:0.01',
            'notes' => 'nullable|string',
            'stores' => 'required|array|min:1',
            'stores.*' => 'integer|exists:stores,id',
            'loaded_items' => 'nullable|array|min:1',
            'loaded_items.*.product_id' => 'required|integer|exists:products,id',
            'loaded_items.*.qty_ball' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // The same store twice would break the unique constraint on
        // (delivery_id, store_id) and cannot be honoured by a single visit, so
        // it is rejected here rather than producing a 500 from the database.
        $storeIds = array_values(array_unique($request->input('stores', [])));
        if (count($storeIds) !== count($request->input('stores', []))) {
            return response()->json([
                'success' => false,
                'message' => 'Toko yang sama tercantum lebih dari satu kali',
                'errors' => ['stores' => ['Each store may appear only once in a delivery plan']],
            ], 422);
        }

        // The load can be told two ways: the caller may send the breakdown, the
        // headline total, or both. Whatever is missing is filled in, so a plan
        // can be drafted from the freezer settings alone without anyone working
        // the split out by hand. A product may still only appear once: the same
        // product listed twice is two partial loads of one thing, and the
        // remaining-per-product figure would count it twice.
        $providedItems = $request->input('loaded_items');
        $providedTotal = $request->input('initial_qty_loaded_ball');

        if ($providedItems !== null) {
            $loadedProductIds = array_map(fn ($row) => $row['product_id'], $providedItems);
            if (count($loadedProductIds) !== count(array_unique($loadedProductIds))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produk yang sama tercantum lebih dari satu kali',
                    'errors' => ['loaded_items' => ['Each product may appear only once in the load']],
                ], 422);
            }
        }

        if ($providedItems !== null && $providedTotal !== null) {
            // Both sides given: they have to agree. The load is one thing told
            // two ways, so a mismatch means one of them is wrong.
            $loadedTotal = round(array_sum(array_map(fn ($row) => (float) $row['qty_ball'], $providedItems)), 2);
            if (abs($loadedTotal - (float) $providedTotal) > 0.001) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total item yang dimuat tidak sesuai dengan initial_qty_loaded_ball',
                    'errors' => ['loaded_items' => ['The sum of loaded_items qty_ball must equal initial_qty_loaded_ball']],
                    'initial_qty_loaded_ball' => (float) $providedTotal,
                    'loaded_items_total' => $loadedTotal,
                ], 422);
            }

            $loadedItems = $providedItems;
            $initialLoadedBall = (float) $providedTotal;
        } elseif ($providedItems !== null) {
            $loadedItems = $providedItems;
            $initialLoadedBall = round(array_sum(array_map(fn ($row) => (float) $row['qty_ball'], $providedItems)), 2);
        } else {
            // No breakdown given, so it is taken from the freezers on the route:
            // a refill follows the shape the freezers are set to, falling back
            // to the default 30/70, scaled to however many balls are carried.
            $initialLoadedBall = $providedTotal !== null
                ? (float) $providedTotal
                : $this->suggestedLoadTotal($storeIds);

            $loadedItems = $this->splitLoadByRatio($storeIds, (float) $initialLoadedBall);
        }

        if ($initialLoadedBall <= 0 || empty($loadedItems)) {
            return response()->json([
                'success' => false,
                'message' => 'Muatan tidak dapat ditentukan',
                'errors' => ['loaded_items' => ['Provide loaded_items or initial_qty_loaded_ball, or give the route stores a freezer with a load config']],
            ], 422);
        }

        // A driver drafting a plan drafts it for themselves. Allowing them to
        // name another driver would let any driver put a route on someone
        // else's dashboard, and that driver would see a run they never agreed
        // to. The plan is still only a draft until an admin starts it.
        $caller = $request->user();
        if ($caller !== null && $caller->role === 'DRIVER'
            && (int) $request->driver_id !== (int) $caller->id) {
            return response()->json([
                'success' => false,
                'message' => 'Driver hanya dapat membuat rencana pengiriman untuk diri sendiri',
            ], 403);
        }

        // Validate driver is DRIVER role
        $driver = User::find($request->driver_id);
        if ($driver->role !== 'DRIVER') {
            return response()->json([
                'success' => false,
                'message' => 'Pengguna yang dipilih bukan driver',
            ], 422);
        }

        // Validate stock in the warehouse this delivery will actually load from
        $availableStock = $this->getAvailableStock((int) $request->warehouse_id);
        if ($initialLoadedBall > $availableStock) {
            return response()->json([
                'success' => false,
                'message' => 'Stok gudang tidak mencukupi',
                'warehouse_id' => $request->warehouse_id,
                'available' => $availableStock,
                'requested' => $initialLoadedBall,
            ], 422);
        }

        // Create delivery (status = DRAFT)
        $delivery = Delivery::create([
            'driver_id' => $request->driver_id,
            'vehicle_id' => $request->vehicle_id,
            'warehouse_id' => $request->warehouse_id,
            'delivery_date' => $request->delivery_date ?? now('Asia/Jakarta')->toDateString(),
            'initial_qty_loaded_ball' => $initialLoadedBall,
            'total_qty_delivered_ball' => 0,
            'total_qty_returned_ball' => 0,
            'status' => 'DRAFT',
            'notes' => $request->notes ?? null,
        ]);

        $this->syncStops($delivery, $storeIds);

        $delivery->loadedItems()->createMany(
            array_map(fn ($row) => [
                'product_id' => $row['product_id'],
                'qty_ball' => $row['qty_ball'],
            ], $loadedItems)
        );

        return response()->json([
            'success' => true,
            'message' => 'Rencana pengiriman berhasil dibuat',
            'data' => $delivery->load(['driver', 'vehicle', 'stops.store', 'loadedItems.product']),
        ], 201);
    }

    /**
     * How many balls a route needs, from the freezers at the stores it visits.
     *
     * Each freezer already works out the restock that refills it; adding those
     * up gives a starting total for the truck when the caller did not name one.
     */
    private function suggestedLoadTotal(array $storeIds): float
    {
        $total = 0.0;

        foreach (Freezer::whereIn('store_id', $storeIds)->get() as $freezer) {
            $total += (float) $freezer->suggested_delivery_ball;
        }

        return round($total, 2);
    }

    /**
     * Split a total load across products by the freezers' refill mix.
     *
     * The share each product gets is the sum of its percentage over every
     * freezer on the route, so a freezer set to 30/70 and one set to 100% of the
     * 10 kg pull the truck toward the 10 kg. The shares are then scaled to the
     * total and the rounding remainder is dropped on the biggest product, so the
     * parts always add back up to the whole.
     *
     * @return array<int, array{product_id: int, qty_ball: float}>
     */
    private function splitLoadByRatio(array $storeIds, float $total): array
    {
        $shares = [];

        foreach (Freezer::whereIn('store_id', $storeIds)->with('productSettings')->get() as $freezer) {
            foreach ($freezer->loadRatio() as $productId => $percentage) {
                $shares[$productId] = ($shares[$productId] ?? 0) + (float) $percentage;
            }
        }

        if (empty($shares)) {
            $shares = Product::defaultLoadRatio();
        }

        $shareSum = array_sum($shares);
        if ($shareSum <= 0) {
            return [];
        }

        // Biggest share last, so it is the one that soaks up the remainder.
        arsort($shares);

        $items = [];
        $allocated = 0.0;
        $lastProductId = array_key_last($shares);

        foreach ($shares as $productId => $percentage) {
            $qty = $productId === $lastProductId
                ? round($total - $allocated, 2)
                : round($total * ($percentage / $shareSum), 2);

            $allocated += $qty;

            if ($qty > 0) {
                $items[] = ['product_id' => (int) $productId, 'qty_ball' => $qty];
            }
        }

        return $items;
    }

    /**
     * Display a specific delivery
     */
    public function show(Request $request, $id)
    {
        $delivery = Delivery::with(['driver', 'vehicle', 'deliveryItems.product', 'loadedItems.product', 'stops.store'])->find($id);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman tidak ditemukan',
            ], 404);
        }

        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman ini ditugaskan untuk driver lain',
            ], 403);
        }

        // Live totals: the persisted total_qty_delivered_ball is only written
        // when the run completes, so during IN_PROGRESS it is still 0. The
        // driver's confirmed items are added up here so the detail screen can
        // show how much has been dropped off and how much is left on the van
        // while the run is still going.
        $totalDelivered = round((float) $delivery->deliveryItems->sum('delivered_qty_ball'), 2);
        $totalLoaded = (float) $delivery->initial_qty_loaded_ball;
        $delivery->setAttribute('total_qty_delivered_ball', $totalDelivered);

        $delivery->driver?->makeHidden(['created_at', 'updated_at']);
        $delivery->vehicle?->makeHidden(['created_at', 'updated_at']);
        $delivery->loadedItems->each(fn ($item) => $item->product?->makeHidden(['created_at', 'updated_at']));
        $delivery->deliveryItems->each(fn ($item) => $item->product?->makeHidden(['created_at', 'updated_at']));
        $delivery->stops->each(fn ($stop) => $stop->store?->makeHidden(['created_at', 'updated_at']));

        return response()->json([
            'success' => true,
            'message' => 'Data pengiriman berhasil diambil',
            'data' => $delivery,
            'totals' => [
                'initial_qty_loaded_ball' => $totalLoaded,
                'total_qty_delivered_ball' => $totalDelivered,
                'remaining_ball' => round($totalLoaded - $totalDelivered, 2),
                'items_count' => $delivery->deliveryItems->count(),
            ],
        ], 200);
    }

    /**
     * Update a delivery (only DRAFT deliveries)
     *
     * A driver can adjust their own draft, which is the same allowance the
     * create path gives them. Only DRAFT is editable, so a driver can never
     * reshape a run that is already under way.
     */
    public function update(Request $request, $id)
    {
        $delivery = Delivery::find($id);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman tidak ditemukan',
            ], 404);
        }

        // Editing someone else's plan is as much of a reach as reading it.
        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman ini ditugaskan untuk driver lain',
            ], 403);
        }

        // Only DRAFT deliveries can be edited
        if ($delivery->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pengiriman berstatus DRAFT yang dapat diedit',
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
            'stores' => 'sometimes|array|min:1',
            'stores.*' => 'integer|exists:stores,id',
            'loaded_items' => 'sometimes|array|min:1',
            'loaded_items.*.product_id' => 'required|integer|exists:products,id',
            'loaded_items.*.qty_ball' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        // If updating driver, validate role
        if ($request->has('driver_id')) {
            // A driver keeps their name on the plan. Reassigning it to a
            // colleague would hand that colleague a run, which is the admin's
            // call, not the current driver's.
            $caller = $request->user();
            if ($caller !== null && $caller->role === 'DRIVER'
                && (int) $request->driver_id !== (int) $caller->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver tidak dapat mengalihkan rencana pengiriman miliknya',
                ], 403);
            }

            $driver = User::find($request->driver_id);
            if ($driver->role !== 'DRIVER') {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengguna yang dipilih bukan driver',
                ], 422);
            }
        }

        // If the load changes, re-check stock in the warehouse that will be used:
        // the incoming one if the warehouse itself is being changed, otherwise the
        // one already on the delivery.
        if ($request->has('initial_qty_loaded_ball') || $request->has('warehouse_id')) {
            $warehouseId = (int) ($request->warehouse_id ?? $delivery->warehouse_id);
            $requested = (float) ($request->initial_qty_loaded_ball ?? $delivery->initial_qty_loaded_ball);

            $availableStock = $this->getAvailableStock($warehouseId);
            if ($requested > $availableStock) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stok gudang tidak mencukupi',
                    'warehouse_id' => $warehouseId,
                    'available' => $availableStock,
                    'requested' => $requested,
                ], 422);
            }
        }

        $validated = $validator->validated();

        $newStoreIds = null;

        if ($request->has('stores')) {
            $requested = $request->input('stores', []);
            $newStoreIds = array_values(array_unique($requested));

            if (count($newStoreIds) !== count($requested)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Toko yang sama tercantum lebih dari satu kali',
                    'errors' => ['stores' => ['Each store may appear only once in a delivery plan']],
                ], 422);
            }
        }

        // The load is one thing told two ways, so whichever side the caller
        // sends, the two have to agree once the update is applied. When only
        // the breakdown is sent it is checked against the existing headline
        // figure, and the other way round, so a half-update cannot leave the
        // two views of the load disagreeing.
        //
        // Only checked when the load is actually being touched. A plan drafted
        // before loads were split has a headline figure but no breakdown, and
        // an edit that leaves the load alone must not be blocked by a
        // disagreement the caller did not cause.
        $incomingLoad = $request->has('loaded_items') ? $request->input('loaded_items') : null;

        if ($incomingLoad !== null) {
            $incomingProductIds = array_map(fn ($row) => $row['product_id'], $incomingLoad);
            if (count($incomingProductIds) !== count(array_unique($incomingProductIds))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produk yang sama tercantum lebih dari satu kali',
                    'errors' => ['loaded_items' => ['Each product may appear only once in the load']],
                ], 422);
            }
        }

        if ($incomingLoad !== null || $request->has('initial_qty_loaded_ball')) {
            $headerBall = (float) ($request->has('initial_qty_loaded_ball')
                ? $request->initial_qty_loaded_ball
                : $delivery->initial_qty_loaded_ball);

            $breakdownBall = $incomingLoad !== null
                ? round(array_sum(array_map(fn ($row) => (float) $row['qty_ball'], $incomingLoad)), 2)
                : round((float) $delivery->loadedItems()->sum('qty_ball'), 2);

            if (abs($breakdownBall - $headerBall) > 0.001) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total item yang dimuat tidak sesuai dengan initial_qty_loaded_ball',
                    'errors' => ['loaded_items' => ['The sum of loaded_items qty_ball must equal initial_qty_loaded_ball']],
                    'initial_qty_loaded_ball' => $headerBall,
                    'loaded_items_total' => $breakdownBall,
                ], 422);
            }
        }

        unset($validated['loaded_items']);

        $delivery->update($validated);

        if ($incomingLoad !== null) {
            $delivery->loadedItems()->delete();
            $delivery->loadedItems()->createMany(
                array_map(fn ($row) => [
                    'product_id' => $row['product_id'],
                    'qty_ball' => $row['qty_ball'],
                ], $incomingLoad)
            );
        }

        // The route is ordered from the warehouse the delivery loads from, so
        // moving warehouse reorders it. A changed store list replaces the
        // route wholesale: the delivery is still DRAFT here, so no stop has
        // been driven and nothing is lost by reordering from scratch.
        if ($newStoreIds !== null || $request->has('warehouse_id')) {
            $this->syncStops(
                $delivery,
                $newStoreIds ?? $delivery->plannedStoreIds(),
                (int) ($validated['warehouse_id'] ?? $delivery->warehouse_id)
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengiriman berhasil diperbarui',
            'data' => $delivery->load(['driver', 'vehicle', 'stops.store', 'loadedItems.product']),
        ], 200);
    }

    /**
     * Delete a delivery (only DRAFT deliveries)
     *
     * Admin only, and the BRD has no delete or cancel screen for a delivery plan
     * anywhere, so this one is a team decision rather than a spec line. Warehouse
     * is off it, and so is the driver: a plan is the admin's record of what was
     * agreed, and a driver who does not want their draft can leave it as DRAFT
     * for the admin to remove.
     *
     * A POSTED plan is not deletable here at all. That is the whole point of
     * approving it separately: once goods are committed to a run, the record of
     * what was promised stays.
     */
    public function destroy(Request $request, $id)
    {
        $delivery = Delivery::find($id);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman tidak ditemukan',
            ], 404);
        }

        // Only DRAFT deliveries can be deleted
        if ($delivery->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pengiriman berstatus DRAFT yang dapat dihapus',
                'current_status' => $delivery->status,
            ], 422);
        }

        $delivery->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pengiriman berhasil dihapus',
        ], 200);
    }

    /**
     * Approve a delivery plan - Transition DRAFT → POSTED
     *
     * The step between "a plan exists" and "the plan runs". An admin agrees
     * with the route, the load and the driver; after that the plan is locked and
     * only the driver can take it on the road, via startDelivery.
     *
     * Stock is re-checked here, and not only at start, because posting is the
     * moment the warehouse commits the goods to this run. A plan approved on a
     * promise of stock that has since gone elsewhere should fail here, while
     * there is still a human deciding, rather than at 7 AM in the loading bay.
     */
    public function postDelivery($id)
    {
        $delivery = Delivery::find($id);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman tidak ditemukan',
            ], 404);
        }

        if ($delivery->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pengiriman berstatus DRAFT yang dapat disetujui',
                'current_status' => $delivery->status,
            ], 422);
        }

        $availableStock = $this->getAvailableStock((int) $delivery->warehouse_id);
        if ($delivery->initial_qty_loaded_ball > $availableStock) {
            return response()->json([
                'success' => false,
                'message' => 'Stok gudang tidak mencukupi untuk memposting pengiriman',
                'warehouse_id' => $delivery->warehouse_id,
                'available' => $availableStock,
                'required' => $delivery->initial_qty_loaded_ball,
            ], 422);
        }

        $delivery->update(['status' => 'POSTED']);

        return response()->json([
            'success' => true,
            'message' => 'Pengiriman berhasil disetujui dan dikunci',
            'data' => $delivery->load(['driver', 'vehicle', 'stops.store']),
            'info' => [
                'status' => 'POSTED',
                'immutable' => true,
                'next_step' => 'The assigned driver can start this delivery',
            ],
        ], 200);
    }

    /**
     * Start delivery - Transition POSTED → IN_PROGRESS
     * Driver confirms ready to start delivery
     *
     * Separate from posting, and deliberately the driver's own step. The plan is
     * already approved by this point, so starting it is not a second approval.
     */
    public function startDelivery(Request $request, $id)
    {
        $delivery = Delivery::find($id);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman tidak ditemukan',
            ], 404);
        }

        // A driver starts their own run, not somebody else's.
        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman ini ditugaskan untuk driver lain',
            ], 403);
        }

        // An unapproved draft cannot be started. Without this the driver could
        // skip the approval entirely and take their own draft straight onto the
        // road, which would make posting a step nobody has to take.
        if ($delivery->status !== 'POSTED') {
            return response()->json([
                'success' => false,
                'message' => $delivery->status === 'DRAFT'
                    ? 'Rencana pengiriman ini belum disetujui'
                    : 'Hanya pengiriman berstatus POSTED yang dapat dimulai',
                'current_status' => $delivery->status,
            ], 422);
        }

        // Validate warehouse stock still available
        $availableStock = $this->getAvailableStock((int) $delivery->warehouse_id);
        if ($delivery->initial_qty_loaded_ball > $availableStock) {
            return response()->json([
                'success' => false,
                'message' => 'Stok gudang tidak mencukupi untuk memulai pengiriman',
                'warehouse_id' => $delivery->warehouse_id,
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
            'message' => 'Pengiriman berhasil dimulai',
            'data' => $delivery->load(['driver', 'vehicle']),
        ], 200);
    }

    /**
     * Complete delivery - Transition IN_PROGRESS → COMPLETED
     * Verify balance: loaded = delivered + returned
     *
     * The driver's own closing step. BRD:366-374 has it at 12:00 PM with "Budi
     * submitted: Delivery complete" and the balance line "50 = 48 + 2 ✓", so
     * the person who ran the route is the one who reports it back, and the
     * warehouse is not on this endpoint.
     */
    public function completeDelivery(Request $request, $id)
    {
        $delivery = Delivery::find($id);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman tidak ditemukan',
            ], 404);
        }

        // A driver closes their own run, not somebody else's.
        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman ini ditugaskan untuk driver lain',
            ], 403);
        }

        // Only IN_PROGRESS can complete
        if ($delivery->status !== 'IN_PROGRESS') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pengiriman berstatus IN_PROGRESS yang dapat diselesaikan',
                'current_status' => $delivery->status,
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'total_qty_returned_ball' => 'required|numeric|min:0',
            'returned_items' => 'sometimes|array|min:1',
            'returned_items.*.product_id' => 'required|integer|exists:products,id',
            'returned_items.*.qty_returned_ball' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $totalDelivered = round((float) DeliveryItem::where('delivery_id', $id)->sum('delivered_qty_ball'), 2);
        $totalReturned = round((float) $request->total_qty_returned_ball, 2);
        $totalLoaded = round((float) $delivery->initial_qty_loaded_ball, 2);

        // A van carries a mix of products, and the headline balance (loaded =
        // delivered + returned) can be met while a single product is over- or
        // under-accounted for: load A=7 B=3, drop A=3 B=3 and report 4 back
        // balances at 10 = 6 + 4, yet B alone owes 0. The manifest says how much
        // of each product left the warehouse, so the balance is checked product
        // by product wherever the load was itemised.
        $loadItems = DeliveryLoadItem::where('delivery_id', $id)->get();

        $deliveredByProduct = DeliveryItem::where('delivery_id', $id)
            ->selectRaw('product_id, SUM(delivered_qty_ball) as delivered')
            ->groupBy('product_id')
            ->pluck('delivered', 'product_id');

        $returnsByProduct = [];

        if ($loadItems->isNotEmpty()) {
            $returnedItems = $request->input('returned_items');

            if ($returnedItems === null) {
                // With one product the headline total is that product's return,
                // so nothing is being guessed. With several it cannot be
                // attributed to a product, so it has to be given, not invented.
                if ($loadItems->count() === 1) {
                    $returnedItems = [[
                        'product_id' => (int) $loadItems->first()->product_id,
                        'qty_returned_ball' => $totalReturned,
                    ]];
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Rincian barang sisa per produk wajib diisi',
                        'errors' => ['returned_items' => ['Break the returned quantity down per product when more than one product was loaded']],
                        'loaded_products' => $loadItems->map(fn ($item) => [
                            'product_id' => (int) $item->product_id,
                            'qty_ball' => (float) $item->qty_ball,
                        ])->values(),
                    ], 422);
                }
            }

            $returnedIds = array_map(fn ($row) => (int) $row['product_id'], $returnedItems);

            if (count($returnedIds) !== count(array_unique($returnedIds))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produk yang sama tercantum lebih dari satu kali pada barang sisa',
                    'errors' => ['returned_items' => ['Each product may appear only once']],
                ], 422);
            }

            $returnedSum = round(array_sum(array_map(fn ($row) => (float) $row['qty_returned_ball'], $returnedItems)), 2);
            if (abs($returnedSum - $totalReturned) > 0.001) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total barang sisa tidak sesuai dengan rincian per produk',
                    'errors' => ['returned_items' => ['The returned_items qty_returned_ball must sum to total_qty_returned_ball']],
                    'total_qty_returned_ball' => $totalReturned,
                    'returned_items_total' => $returnedSum,
                ], 422);
            }

            foreach ($returnedItems as $row) {
                $returnsByProduct[(int) $row['product_id']] = round((float) $row['qty_returned_ball'], 2);
            }

            // The products in play are everything on the manifest plus anything
            // that was dropped off, so a product delivered but never loaded is
            // caught rather than hidden by a total that still adds up.
            $loadedByProduct = $loadItems->mapWithKeys(
                fn ($item) => [(int) $item->product_id => round((float) $item->qty_ball, 2)]
            );

            $productIds = array_values(array_unique(array_merge(
                $loadedByProduct->keys()->all(),
                array_map('intval', $deliveredByProduct->keys()->all()),
            )));
            sort($productIds);

            // A return can only be against a product that was carried or
            // dropped off. Anything else has no line to be accounted on.
            $unknown = array_values(array_diff($returnedIds, $productIds));
            if ($unknown !== []) {
                return response()->json([
                    'success' => false,
                    'message' => 'Barang sisa memuat produk yang tidak dimuat',
                    'errors' => ['returned_items' => ['Returned product is not part of the load']],
                    'unknown_product_ids' => $unknown,
                ], 422);
            }

            $breakdown = [];
            foreach ($productIds as $productId) {
                $loaded = $loadedByProduct[$productId] ?? 0.0;
                $delivered = round((float) ($deliveredByProduct[$productId] ?? 0), 2);
                $returned = $returnsByProduct[$productId] ?? 0.0;
                $total = round($delivered + $returned, 2);

                $breakdown[] = [
                    'product_id' => $productId,
                    'loaded' => $loaded,
                    'delivered' => $delivered,
                    'returned' => $returned,
                    'total' => $total,
                    'balanced' => abs($loaded - $total) < 0.001,
                ];
            }

            $unbalanced = array_values(array_filter($breakdown, fn ($row) => ! $row['balanced']));

            if ($unbalanced !== []) {
                return response()->json([
                    'success' => false,
                    'message' => 'Saldo pengiriman tidak sesuai per produk',
                    'balance' => [
                        'loaded' => $totalLoaded,
                        'delivered' => $totalDelivered,
                        'returned' => $totalReturned,
                        'total' => round($totalDelivered + $totalReturned, 2),
                    ],
                    'by_product' => $breakdown,
                    'error' => 'For each product, loaded must equal delivered + returned',
                ], 422);
            }
        } elseif ((float) $totalLoaded != ((float) $totalDelivered + (float) $totalReturned)) {
            // A plan made before the load was itemised carries no per-product
            // manifest, so only the headline can be checked.
            return response()->json([
                'success' => false,
                'message' => 'Saldo pengiriman tidak sesuai',
                'balance' => [
                    'loaded' => $totalLoaded,
                    'delivered' => $totalDelivered,
                    'returned' => $totalReturned,
                    'total' => $totalDelivered + $totalReturned,
                ],
                'error' => "Loaded ($totalLoaded) ≠ Delivered + Returned ($totalDelivered + $totalReturned)",
            ], 422);
        }

        // The manifest is the warehouse's record of what came back, so the
        // per-product returns are written onto it rather than left at the
        // single headline figure.
        foreach ($loadItems as $item) {
            $item->qty_returned_ball = $returnsByProduct[(int) $item->product_id] ?? 0.0;
            $item->save();
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
            'message' => 'Pengiriman berhasil diselesaikan',
            'summary' => [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
                'loaded' => $totalLoaded,
                'delivered' => $totalDelivered,
                'returned' => $totalReturned,
                'balance_verified' => (float) $totalLoaded == ((float) $totalDelivered + (float) $totalReturned),
                'by_product' => $loadItems->map(fn ($item) => [
                    'product_id' => (int) $item->product_id,
                    'loaded' => round((float) $item->qty_ball, 2),
                    'delivered' => round((float) ($deliveredByProduct[(int) $item->product_id] ?? 0), 2),
                    'returned' => round((float) $item->qty_returned_ball, 2),
                ])->values(),
            ],
            'data' => $delivery->load(['driver', 'vehicle', 'deliveryItems']),
        ], 200);
    }

    /**
     * Get real-time available stock for one warehouse.
     *
     * Takes the warehouse explicitly because the caller's chosen warehouse is
     * what decides whether the load is possible. The previous version summed
     * every production in the table, so a delivery could be cleared to load
     * from a warehouse that held none of that stock.
     */
    private function getAvailableStock(int $warehouseId): float
    {
        return app(WarehouseStockService::class)->available($warehouseId);
    }

    /**
     * Whether the caller is entitled to read this delivery.
     *
     * The listing already hides other drivers' runs, but the single-delivery
     * endpoints took any id at face value, so a driver who guessed or was told
     * one could read another driver's stops, load and collection progress. A
     * driver only ever sees their own.
     *
     * Admin and warehouse read every run. Warehouse keeps that because BRD:258-260
     * lists "Delivery plans & status" and "Active deliveries progress" under
     * What They See, and BRD:374 needs the completed total to move its own stock
     * ("Warehouse stock auto-update: 250 - 48 = 202 ball"). Reading is not
     * acting: that same section does not put warehouse on start, complete or the
     * stop steps, so those are closed to it in routes/api.php.
     */
    private function callerMayRead(Request $request, Delivery $delivery): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        if ($user->role === 'DRIVER') {
            return (int) $delivery->driver_id === (int) $user->id;
        }

        return true;
    }

    /**
     * Replace a delivery's planned route with the given stores, in visit order.
     *
     * The order comes from the planner rather than the caller, which is what
     * makes "nearest first" true no matter how the store list was typed. The
     * rows are only ever rebuilt on a DRAFT delivery, so no stop that has
     * already been driven is discarded.
     *
     * @param  array<int>  $storeIds
     */
    private function syncStops(Delivery $delivery, array $storeIds, ?int $warehouseId = null): void
    {
        $warehouse = Warehouse::find($warehouseId ?? $delivery->warehouse_id);
        $planner = app(RoutePlannerService::class);

        $ordered = $planner->order($warehouse, $storeIds);

        $delivery->stops()->delete();
        $delivery->stops()->createMany(
            collect($ordered)
                ->values()
                ->map(fn ($storeId, $index) => [
                    'store_id' => $storeId,
                    'sequence' => $index + 1,
                    'status' => 'PENDING',
                ])
                ->all()
        );
    }

    /**
     * The route for one delivery: where the driver goes, in what order, and how
     * far it is. This is the answer to "where is this driver supposed to be",
     * which the plan itself used to leave open.
     */
    public function getRoute(Request $request, $id)
    {
        $delivery = Delivery::with(['driver', 'warehouse', 'stops.store'])->find($id);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman tidak ditemukan',
            ], 404);
        }

        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman ini ditugaskan untuk driver lain',
            ], 403);
        }

        $planner = app(RoutePlannerService::class);
        $stops = $delivery->stops;
        $summary = $planner->summarise($delivery->warehouse, $stops->pluck('store_id')->all());

        // Legs are reported per stop so a client can show "4.2 km to the next
        // one" without redoing the arithmetic.
        $origin = $delivery->warehouse;
        $legs = [];
        $current = $origin;

        foreach ($stops as $stop) {
            $from = $current?->latitude !== null && $current?->longitude !== null ? $current : null;
            $to = $stop->store->latitude !== null && $stop->store->longitude !== null ? $stop->store : null;

            $legs[] = [
                'sequence' => $stop->sequence,
                'store' => $stop->store,
                'status' => $stop->status,
                'planned_qty_ball' => $stop->planned_qty_ball,
                'visited_at' => $stop->visited_at,
                'notes' => $stop->notes,
                'leg_km' => ($from && $to) ? round($planner->roadKm($from, $to), 1) : null,
                'arrived_at' => $stop->arrived_at,
                'departed_at' => $stop->departed_at,
            ];

            $current = $to;
        }

        return response()->json([
            'success' => true,
            'message' => 'Rute pengiriman berhasil diambil',
            'data' => [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
                'delivery_date' => $delivery->delivery_date,
                'driver' => $delivery->driver,
                'warehouse' => $delivery->warehouse,
                'initial_qty_loaded_ball' => $delivery->initial_qty_loaded_ball,
                'stops' => $legs,
                'stops_total' => $stops->count(),
                'stops_visited' => $stops->where('status', 'VISITED')->count(),
                // The store the driver is standing in, as opposed to the next
                // one to drive to. These are different during a visit.
                'at_store' => $stops->first(fn ($s) => $s->arrived_at !== null && $s->departed_at === null)?->store,
                'next_stop' => $stops->first(fn ($s) => $s->arrived_at === null && $s->status !== 'SKIPPED')?->store,
                'current_stop' => $stops->firstWhere('status', 'PENDING')?->store,
                'total_km' => $summary['total_km'],
                'total_minutes' => $summary['total_minutes'],
                'distance_note' => 'Straight-line distance scaled by '.RoutePlannerService::ROAD_WINDING_FACTOR.' to approximate road distance. Legs are null where a warehouse or store has no coordinates.',
            ],
        ], 200);
    }

    /**
     * The "Smart Delivery" screen: which stores look like they need a delivery.
     *
     * The BRD puts this before the plan is built, as three buckets the admin
     * works down. It is a suggestion, not a plan: nothing is written here, and
     * the driver still confirms every figure at the shop.
     *
     * No warehouse filter. What this reports is what is in each store's freezers,
     * so asking which warehouse to judge it by had no answer to give. Whether the
     * goods are there to send is a question for the plan, and POST /api/deliveries
     * already refuses a plan the warehouse cannot load.
     *
     * No tier filter either. The list is already sorted with the urgent ones first,
     * so a caller wanting only those reads them off the top without a second
     * request.
     *
     * No store filter. This screen exists to show the whole picture before a plan
     * exists, and a filter would hide the rows the warehouse is there to see. The
     * caller picks from what it returns and sends the chosen stores to
     * POST /api/deliveries; re-asking about a shortlist at this point is a second
     * source of stock figures for a problem that one list already answers.
     */
    public function suggestions()
    {
        $result = app(DeliverySuggestionService::class)->suggest();

        return response()->json([
            'success' => true,
            'message' => 'Saran pengiriman berhasil diambil',
            'data' => $result,
        ], 200);
    }

    /**
     * Record the driver arriving at one stop on the route.
     *
     * The BRD has this as its own step, before anything is unloaded. It is also
     * what a store whose freezers turn out to be empty needs, since without it
     * nothing but a confirmation could ever close the stop.
     */
    public function arriveAtStop(Request $request, $id, $storeId)
    {
        [$delivery, $stop] = $this->resolveStop($request, $id, $storeId);

        try {
            app(StopVisitService::class)->arrive($delivery, $stop);
        } catch (StopVisitException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ] + $e->context(), $e->status());
        }

        return response()->json([
            'success' => true,
            'message' => "Tiba di {$stop->store->name}",
            'data' => [
                'stop' => $stop->load('store'),
                'arrived_at' => $stop->arrived_at,
                'store' => $stop->store,
                'freezers' => $stop->store->freezers()->get(),
            ],
        ], 200);
    }

    /**
     * Record the driver leaving one stop.
     *
     * This is the step that closes a store nothing was sold at, which a
     * confirmation could never do.
     */
    public function departFromStop(Request $request, $id, $storeId)
    {
        [$delivery, $stop] = $this->resolveStop($request, $id, $storeId);

        try {
            app(StopVisitService::class)->depart($delivery, $stop);
        } catch (StopVisitException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ] + $e->context(), $e->status());
        }

        return response()->json([
            'success' => true,
            'message' => "Meninggalkan {$stop->store->name}",
            'data' => [
                'stop' => $stop->load('store'),
                'status' => $stop->status,
                'arrived_at' => $stop->arrived_at,
                'departed_at' => $stop->departed_at,
                'visited_at' => $stop->visited_at,
            ],
        ], 200);
    }

    /**
     * Pass over a stop without serving it, recording why.
     */
    public function skipStop(Request $request, $id, $storeId)
    {
        [$delivery, $stop] = $this->resolveStop($request, $id, $storeId);

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            app(StopVisitService::class)->skip($delivery, $stop, $request->input('reason'));
        } catch (StopVisitException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ] + $e->context(), $e->status());
        }

        return response()->json([
            'success' => true,
            'message' => "Melewati {$stop->store->name}",
            'data' => [
                'stop' => $stop->load('store'),
                'status' => $stop->status,
                'notes' => $stop->notes,
            ],
        ], 200);
    }

    /**
     * What is owed at one stop, shown before any payment is taken.
     */
    public function stopSettlementPreview(Request $request, $id, $storeId)
    {
        [$delivery, $stop] = $this->resolveStop($request, $id, $storeId);

        $preview = app(StopVisitService::class)->settlementPreview($delivery, $stop);
        $preview['store']?->makeHidden(['created_at', 'updated_at']);
        collect($preview['this_visit_sales']['lines'])->each(fn ($sale) => $sale->makeHidden(['created_at', 'updated_at']));

        return response()->json([
            'success' => true,
            'message' => 'Pratinjau penyelesaian stop berhasil diambil',
            'data' => $preview,
        ], 200);
    }

    /**
     * Find a stop on a delivery, checking the caller is allowed to see it.
     *
     * @return array{0: Delivery, 1: DeliveryStop}
     */
    private function resolveStop(Request $request, $id, $storeId)
    {
        $delivery = Delivery::with('stops.store')->find($id);

        if (! $delivery) {
            abort(response()->json([
                'success' => false,
                'message' => 'Pengiriman tidak ditemukan',
            ], 404));
        }

        if (! $this->callerMayRead($request, $delivery)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Pengiriman ini ditugaskan untuk driver lain',
            ], 403));
        }

        $stop = $delivery->stops->firstWhere('store_id', (int) $storeId);

        if (! $stop) {
            abort(response()->json([
                'success' => false,
                'message' => 'Toko ini tidak ada dalam rute pengiriman',
                'delivery_id' => $delivery->id,
                'planned_store_ids' => $delivery->plannedStoreIds(),
            ], 422));
        }

        return [$delivery, $stop];
    }

    /**
     * Get delivery summary including stock calculations
     */
    public function getSummary(Request $request, $id)
    {
        $delivery = Delivery::with(['driver', 'vehicle', 'deliveryItems', 'stops'])->find($id);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman tidak ditemukan',
            ], 404);
        }

        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'Pengiriman ini ditugaskan untuk driver lain',
            ], 403);
        }

        $totalDelivered = $delivery->deliveryItems->sum('delivered_qty_ball');
        $currentLoad = $delivery->initial_qty_loaded_ball - $totalDelivered;

        return response()->json([
            'success' => true,
            'message' => 'Ringkasan pengiriman berhasil diambil',
            'data' => [
                'delivery_id' => $delivery->id,
                'status' => $delivery->status,
                'driver' => $delivery->driver,
                'vehicle' => $delivery->vehicle,
                'delivery_date' => $delivery->delivery_date,
                'initial_load' => $delivery->initial_qty_loaded_ball,
                'total_delivered' => $totalDelivered,
                'current_load' => $currentLoad,
                'stops_completed' => $delivery->stops->where('status', 'VISITED')->count(),
                'stops_total' => $delivery->stops->count(),
                // A stop is done once the driver has left it, whether or not
                // anything was sold there. Counting only VISITED would report a
                // store they served with nothing as still outstanding work.
                'stops_departed' => $delivery->stops->whereNotNull('departed_at')->count(),
                'stops_skipped' => $delivery->stops->where('status', 'SKIPPED')->count(),
                'notes' => $delivery->notes,
            ],
        ], 200);
    }
}
