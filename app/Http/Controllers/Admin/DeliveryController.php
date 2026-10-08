<?php

namespace App\Http\Controllers\Admin;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryStop;
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
        $query = Delivery::with(['driver', 'vehicle', 'stops.store']);

        if ($request->user()?->role === 'DRIVER') {
            $query->where('driver_id', $request->user()->id);
        }

        $deliveries = $query->latest('delivery_date')->get();

        return response()->json([
            'success' => true,
            'message' => 'Deliveries retrieved successfully',
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
            'initial_qty_loaded_ball' => 'required|numeric|min:1',
            'notes' => 'nullable|string',
            'stores' => 'required|array|min:1',
            'stores.*' => 'integer|exists:stores,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
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
                'message' => 'The same store was listed more than once',
                'errors' => ['stores' => ['Each store may appear only once in a delivery plan']],
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
                'message' => 'A driver can only create a delivery plan for themselves',
            ], 403);
        }

        // Validate driver is DRIVER role
        $driver = User::find($request->driver_id);
        if ($driver->role !== 'DRIVER') {
            return response()->json([
                'success' => false,
                'message' => 'Selected user is not a driver',
            ], 422);
        }

        // Validate stock in the warehouse this delivery will actually load from
        $availableStock = $this->getAvailableStock((int) $request->warehouse_id);
        if ($request->initial_qty_loaded_ball > $availableStock) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient warehouse stock',
                'warehouse_id' => $request->warehouse_id,
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

        $this->syncStops($delivery, $storeIds);

        return response()->json([
            'success' => true,
            'message' => 'Delivery plan created successfully',
            'data' => $delivery->load(['driver', 'vehicle', 'stops.store']),
        ], 201);
    }

    /**
     * Display a specific delivery
     */
    public function show(Request $request, $id)
    {
        $delivery = Delivery::with(['driver', 'vehicle', 'deliveryItems', 'stops.store'])->find($id);

        if (! $delivery) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery not found',
            ], 404);
        }

        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'This delivery is assigned to another driver',
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

        return response()->json([
            'success' => true,
            'message' => 'Delivery retrieved successfully',
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
                'message' => 'Delivery not found',
            ], 404);
        }

        // Editing someone else's plan is as much of a reach as reading it.
        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'This delivery is assigned to another driver',
            ], 403);
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
            'stores' => 'sometimes|array|min:1',
            'stores.*' => 'integer|exists:stores,id',
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
            // A driver keeps their name on the plan. Reassigning it to a
            // colleague would hand that colleague a run, which is the admin's
            // call, not the current driver's.
            $caller = $request->user();
            if ($caller !== null && $caller->role === 'DRIVER'
                && (int) $request->driver_id !== (int) $caller->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'A driver cannot reassign their own delivery plan',
                ], 403);
            }

            $driver = User::find($request->driver_id);
            if ($driver->role !== 'DRIVER') {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected user is not a driver',
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
                    'message' => 'Insufficient warehouse stock',
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
                    'message' => 'The same store was listed more than once',
                    'errors' => ['stores' => ['Each store may appear only once in a delivery plan']],
                ], 422);
            }
        }

        $delivery->update($validated);

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
            'message' => 'Delivery updated successfully',
            'data' => $delivery->load(['driver', 'vehicle', 'stops.store']),
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
                'message' => 'Delivery not found',
            ], 404);
        }

        if ($delivery->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'Only DRAFT deliveries can be posted',
                'current_status' => $delivery->status,
            ], 422);
        }

        $availableStock = $this->getAvailableStock((int) $delivery->warehouse_id);
        if ($delivery->initial_qty_loaded_ball > $availableStock) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient warehouse stock to post delivery',
                'warehouse_id' => $delivery->warehouse_id,
                'available' => $availableStock,
                'required' => $delivery->initial_qty_loaded_ball,
            ], 422);
        }

        $delivery->update(['status' => 'POSTED']);

        return response()->json([
            'success' => true,
            'message' => 'Delivery posted successfully and locked',
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
                'message' => 'Delivery not found',
            ], 404);
        }

        // A driver starts their own run, not somebody else's.
        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'This delivery is assigned to another driver',
            ], 403);
        }

        // An unapproved draft cannot be started. Without this the driver could
        // skip the approval entirely and take their own draft straight onto the
        // road, which would make posting a step nobody has to take.
        if ($delivery->status !== 'POSTED') {
            return response()->json([
                'success' => false,
                'message' => $delivery->status === 'DRAFT'
                    ? 'This delivery plan has not been approved yet'
                    : 'Only POSTED deliveries can be started',
                'current_status' => $delivery->status,
            ], 422);
        }

        // Validate warehouse stock still available
        $availableStock = $this->getAvailableStock((int) $delivery->warehouse_id);
        if ($delivery->initial_qty_loaded_ball > $availableStock) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient warehouse stock to start delivery',
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
            'message' => 'Delivery started successfully',
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
                'message' => 'Delivery not found',
            ], 404);
        }

        // A driver closes their own run, not somebody else's.
        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'This delivery is assigned to another driver',
            ], 403);
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
        if ((float) $totalLoaded != ((float) $totalDelivered + (float) $totalReturned)) {
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
                'balance_verified' => (float) $totalLoaded == ((float) $totalDelivered + (float) $totalReturned),
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
                'message' => 'Delivery not found',
            ], 404);
        }

        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'This delivery is assigned to another driver',
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
            'message' => 'Delivery route retrieved successfully',
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
            'message' => 'Delivery suggestions retrieved successfully',
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
            'message' => "Arrived at {$stop->store->name}",
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
            'message' => "Departed {$stop->store->name}",
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
                'message' => 'Validation failed',
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
            'message' => "Skipped {$stop->store->name}",
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

        return response()->json([
            'success' => true,
            'message' => 'Stop settlement preview retrieved successfully',
            'data' => app(StopVisitService::class)->settlementPreview($delivery, $stop),
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
                'message' => 'Delivery not found',
            ], 404));
        }

        if (! $this->callerMayRead($request, $delivery)) {
            abort(response()->json([
                'success' => false,
                'message' => 'This delivery is assigned to another driver',
            ], 403));
        }

        $stop = $delivery->stops->firstWhere('store_id', (int) $storeId);

        if (! $stop) {
            abort(response()->json([
                'success' => false,
                'message' => 'This store is not on the delivery route',
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
                'message' => 'Delivery not found',
            ], 404);
        }

        if (! $this->callerMayRead($request, $delivery)) {
            return response()->json([
                'success' => false,
                'message' => 'This delivery is assigned to another driver',
            ], 403);
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
