<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductionController extends Controller
{
    /**
     * Display a listing of all productions
     * GET /admin/productions
     * 
     * Returns: All production records dengan status (DRAFT/POSTED/CANCELLED)
     */
    public function index()
    {
        try {
            $productions = Production::with(['product', 'creator'])
                ->orderBy('production_date', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Productions retrieved successfully',
                'data' => $productions,
                'count' => count($productions),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve productions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created production in DRAFT status
     * POST /admin/productions
     * 
     * Input (JSON):
     * {
     *   "product_id": 1,
     *   "production_date": "2026-08-25",
     *   "qty_produced_ball": 100,
     *   "qty_reject_ball": 5
     * }
     * 
     * Auto-calculated:
     * - qty_good = qty_produced - qty_reject = 95
     * - status = "DRAFT" (locked & unavailable for delivery)
     * - created_by = current authenticated user
     * - production_date = today
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'production_date' => 'nullable|date',
                'qty_produced_ball' => 'required|numeric|min:0',
                'qty_reject_ball' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            // Auto-calculate qty_good_ball
            $validated['qty_good_ball'] = $validated['qty_produced_ball'] - $validated['qty_reject_ball'];

            // Set status DRAFT
            $validated['status'] = 'DRAFT';

            // Track siapa yang membuat production
            $validated['created_by'] = auth()->id() ?? 1;

            // Set production date (default: hari ini)
            if (!isset($validated['production_date'])) {
                $validated['production_date'] = now('Asia/Jakarta')->toDateString();
            }

            // Create production record
            $production = Production::create($validated);

            // Load relationships untuk response
            $production->load(['product', 'creator']);

            return response()->json([
                'success' => true,
                'message' => 'Production created successfully in DRAFT status',
                'data' => $production,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create production',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified production
     * GET /admin/productions/{id}
     */
    public function show($id)
    {
        try {
            $production = Production::with(['product', 'creator'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Production retrieved successfully',
                'data' => $production,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Production not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve production',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified production
     * PUT /admin/productions/{id}
     * 
     * PENTING: Hanya bisa update DRAFT productions\!
     * Setelah status POSTED, production immutable (tidak bisa diubah)
     * Ini untuk maintain audit trail & data integrity
     * 
     * Input (JSON):
     * {
     *   "qty_produced": 110,
     *   "qty_reject": 6,
     *   "notes": "Updated notes"
     * }
     * 
     * Auto-calculated ulang:
     * - qty_good = qty_produced - qty_reject
     */
    public function update(Request $request, $id)
    {
        try {
            $production = Production::findOrFail($id);

            // CHECK: Hanya DRAFT yang boleh diupdate
            if ($production->status !== 'DRAFT') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only update DRAFT productions. Current status: ' . $production->status,
                    'data' => [
                        'id' => $production->id,
                        'current_status' => $production->status,
                        'message' => 'Cannot edit after POSTED',
                    ],
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'product_id' => 'nullable|exists:products,id',
                'production_date' => 'nullable|date',
                'qty_produced_ball' => 'nullable|numeric|min:0',
                'qty_reject_ball' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            // Re-calculate qty_good_ball jika ada perubahan
            if (isset($validated['qty_produced_ball']) || isset($validated['qty_reject_ball'])) {
                $qtyProduced = $validated['qty_produced_ball'] ?? $production->qty_produced_ball;
                $qtyReject = $validated['qty_reject_ball'] ?? $production->qty_reject_ball;
                $validated['qty_good_ball'] = $qtyProduced - $qtyReject;
            }

            // Update production
            $production->update($validated);

            // Load relationships
            $production->load(['product', 'creator']);

            return response()->json([
                'success' => true,
                'message' => 'Production updated successfully (still in DRAFT status)',
                'data' => $production,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Production not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update production',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete the specified production
     * DELETE /admin/productions/{id}
     * 
     * PENTING: Hanya DRAFT yang boleh dihapus\!
     * POSTED productions tidak bisa dihapus (immutable record)
     */
    public function destroy($id)
    {
        try {
            $production = Production::findOrFail($id);

            // CHECK: Hanya DRAFT yang boleh dihapus
            if ($production->status !== 'DRAFT') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only delete DRAFT productions. Current status: ' . $production->status,
                ], 422);
            }

            $productionId = $production->id;
            $production->delete();

            return response()->json([
                'success' => true,
                'message' => 'Production deleted successfully',
                'data' => [
                    'deleted_id' => $productionId,
                ],
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Production not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete production',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST Production (Lock & Make Available)
     * POST /admin/productions/{id}/post
     * 
     * WORKFLOW:
     * DRAFT (editing allowed) → POST (lock & make available for delivery)
     * 
     * Setelah POST:
     * ✓ Production immutable (tidak bisa diubah atau dihapus)
     * ✓ qty_good_ball ditambah ke warehouse stock (real-time calculation)
     * ✓ Siap untuk delivery
     * ✓ Permanent audit trail
     * 
     * Response: Production dengan status POSTED
     */
    public function post($id)
    {
        try {
            $production = Production::findOrFail($id);

            // CHECK 1: Hanya DRAFT yang boleh di-POST
            if ($production->status !== 'DRAFT') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only post DRAFT productions. Current status: ' . $production->status,
                ], 422);
            }

            // CHECK 2: Pastikan qty_good >= 0 (validasi logical)
            if ($production->qty_good_ball < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot post production with negative qty_good',
                    'data' => [
                        'qty_produced' => $production->qty_produced,
                        'qty_reject' => $production->qty_reject,
                        'qty_good' => $production->qty_good_ball,
                    ],
                ], 422);
            }

            // UPDATE: Status → POSTED (locked)
            $production->update(['status' => 'POSTED']);

            // Load relationships
            $production->load(['product', 'creator']);

            return response()->json([
                'success' => true,
                'message' => 'Production posted successfully and locked',
                'data' => $production,
                'info' => [
                    'status' => 'POSTED',
                    'qty_good_ball' => $production->qty_good_ball,
                    'now_available_for_delivery' => true,
                    'immutable' => true,
                ],
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Production not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to post production',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get production by date range
     * GET /admin/productions/date/{date}
     * 
     * Contoh: /admin/productions/date/2026-08-25
     */
    public function getByDate($date)
    {
        try {
            $productions = Production::whereDate('production_date', $date)
                ->with(['product', 'creator'])
                ->get();

            $totalProduced = $productions->sum('qty_produced');
            $totalReject = $productions->sum('qty_reject');
            $totalGood = $productions->sum('qty_good_ball');

            return response()->json([
                'success' => true,
                'message' => "Productions for date {$date}",
                'data' => $productions,
                'summary' => [
                    'date' => $date,
                    'total_produced' => $totalProduced,
                    'total_reject' => $totalReject,
                    'total_good' => $totalGood,
                    'count' => count($productions),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve productions by date',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get production by status
     * GET /admin/productions/status/{status}
     * 
     * Contoh: /admin/productions/status/DRAFT
     * Possible status: DRAFT, POSTED, CANCELLED
     */
    public function getByStatus($status)
    {
        try {
            $validStatuses = ['DRAFT', 'POSTED', 'CANCELLED'];

            if (!in_array($status, $validStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status',
                    'valid_statuses' => $validStatuses,
                ], 400);
            }

            $productions = Production::where('status', $status)
                ->with(['product', 'creator'])
                ->orderBy('production_date', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => "Productions with status {$status}",
                'data' => $productions,
                'count' => count($productions),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve productions by status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
 