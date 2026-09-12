<?php

namespace App\Http\Controllers\Admin;

use App\Models\Payment;
use App\Models\Store;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class PaymentController extends Controller
{
    /**
     * Collect payment from store
     * Supports CASH, TRANSFER, QRIS payment methods
     * Payment types: TODAY (today's sales), PAST_DAYS (older sales), DEBT (outstanding)
     */
    public function collectPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:CASH,TRANSFER,QRIS',
            'payment_type' => 'required|in:TODAY,PAST_DAYS,DEBT',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $store = Store::find($request->store_id);

        // Determine status based on method
        // CASH = CONFIRMED, TRANSFER/QRIS = PENDING (awaiting receipt confirmation)
        $status = $request->method === 'CASH' ? 'CONFIRMED' : 'PENDING';

        $payment = Payment::create([
            'store_id' => $request->store_id,
            'amount' => $request->amount,
            'method' => $request->method,
            'payment_type' => $request->payment_type,
            'status' => $status,
            'paid_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded successfully',
            'data' => [
                'payment' => $payment,
                'store' => $store,
                'status_note' => $status === 'PENDING' 
                    ? 'Receipt photo required for confirmation'
                    : 'Payment confirmed',
            ],
        ], 201);
    }

    /**
     * Upload receipt photo for TRANSFER/QRIS payments
     * Only PENDING payments can be uploaded
     */
    public function uploadReceipt(Request $request, $paymentId)
    {
        $validator = Validator::make($request->all(), [
            'receipt_photo' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payment = Payment::find($paymentId);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found',
            ], 404);
        }

        if ($payment->method === 'CASH') {
            return response()->json([
                'success' => false,
                'message' => 'Receipt upload only for TRANSFER/QRIS payments',
            ], 400);
        }

        if ($payment->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Only PENDING payments can upload receipt',
            ], 400);
        }

        // Store receipt photo
        $path = $request->file('receipt_photo')->store('receipts', 'public');
        $payment->update([
            'receipt_photo_path' => $path,
            'receipt_uploaded_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Receipt uploaded successfully',
            'data' => [
                'payment' => $payment,
                'receipt_url' => Storage::url($path),
                'status_note' => 'Awaiting admin confirmation',
            ],
        ], 200);
    }

    /**
     * Admin confirm TRANSFER/QRIS payment
     * Transitions PENDING → CONFIRMED
     */
    public function confirmPayment(Request $request, $paymentId)
    {
        $validator = Validator::make($request->all(), [
            'confirmed' => 'required|boolean',
            'admin_notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payment = Payment::find($paymentId);

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found',
            ], 404);
        }

        if ($payment->status !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Only PENDING payments can be confirmed',
            ], 400);
        }

        if ($request->confirmed) {
            $payment->update([
                'status' => 'CONFIRMED',
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
                'admin_notes' => $request->admin_notes,
            ]);

            $message = 'Payment confirmed successfully';
        } else {
            $payment->update([
                'status' => 'REJECTED',
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
                'admin_notes' => $request->admin_notes,
            ]);

            $message = 'Payment rejected';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $payment,
        ], 200);
    }

    /**
     * Get payment history for a store
     */
    public function getByStore($storeId, Request $request)
    {
        $store = Store::find($storeId);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $query = Payment::where('store_id', $storeId);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment type
        if ($request->has('payment_type')) {
            $query->where('payment_type', $request->payment_type);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('paid_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('paid_at', '<=', $request->end_date);
        }

        $payments = $query->with('createdByUser')
            ->orderBy('paid_at', 'desc')
            ->get();

        $totalAmount = $payments->sum('amount');

        // Breakdown by status
        $breakdown = [
            'CONFIRMED' => $payments->where('status', 'CONFIRMED')->sum('amount'),
            'PENDING' => $payments->where('status', 'PENDING')->sum('amount'),
            'REJECTED' => $payments->where('status', 'REJECTED')->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Payment history retrieved successfully',
            'data' => [
                'store' => $store,
                'payments' => $payments,
                'count' => count($payments),
                'summary' => [
                    'total_amount' => $totalAmount,
                    'breakdown_by_status' => $breakdown,
                ],
            ],
        ], 200);
    }

    /**
     * Get payment summary across all stores
     */
    public function getSummary(Request $request)
    {
        $query = Payment::query();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('paid_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('paid_at', '<=', $request->end_date);
        }

        $payments = $query->with(['store', 'createdByUser'])->get();

        $totalAmount = $payments->sum('amount');

        // Breakdown by status
        $byStatus = [
            'CONFIRMED' => $payments->where('status', 'CONFIRMED')->sum('amount'),
            'PENDING' => $payments->where('status', 'PENDING')->sum('amount'),
            'REJECTED' => $payments->where('status', 'REJECTED')->sum('amount'),
        ];

        // Breakdown by method
        $byMethod = [
            'CASH' => $payments->where('method', 'CASH')->sum('amount'),
            'TRANSFER' => $payments->where('method', 'TRANSFER')->sum('amount'),
            'QRIS' => $payments->where('method', 'QRIS')->sum('amount'),
        ];

        // Breakdown by payment type
        $byType = [
            'TODAY' => $payments->where('payment_type', 'TODAY')->sum('amount'),
            'PAST_DAYS' => $payments->where('payment_type', 'PAST_DAYS')->sum('amount'),
            'DEBT' => $payments->where('payment_type', 'DEBT')->sum('amount'),
        ];

        // Group by store
        $byStore = $payments->groupBy('store_id')->map(function ($storePayments) {
            $store = $storePayments->first()->store;
            return [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'total_amount' => $storePayments->sum('amount'),
                'count' => count($storePayments),
                'confirmed' => $storePayments->where('status', 'CONFIRMED')->sum('amount'),
                'pending' => $storePayments->where('status', 'PENDING')->sum('amount'),
            ];
        })->values();

        // Group by date
        $byDate = $payments->groupBy(function ($payment) {
            return $payment->paid_at->format('Y-m-d');
        })->map(function ($datePayments, $date) {
            return [
                'date' => $date,
                'total_amount' => $datePayments->sum('amount'),
                'count' => count($datePayments),
                'confirmed' => $datePayments->where('status', 'CONFIRMED')->sum('amount'),
                'pending' => $datePayments->where('status', 'PENDING')->sum('amount'),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Payment summary retrieved successfully',
            'data' => [
                'total_payments' => count($payments),
                'total_amount' => $totalAmount,
                'breakdown_by_status' => $byStatus,
                'breakdown_by_method' => $byMethod,
                'breakdown_by_type' => $byType,
                'by_store' => $byStore,
                'by_date' => $byDate,
            ],
        ], 200);
    }

    /**
     * Get pending TRANSFER/QRIS payments awaiting confirmation
     */
    public function getPending()
    {
        $payments = Payment::where('status', 'PENDING')
            ->where('method', '!=', 'CASH')
            ->with(['store', 'createdByUser'])
            ->orderBy('receipt_uploaded_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Pending payments retrieved successfully',
            'data' => [
                'payments' => $payments,
                'count' => count($payments),
            ],
        ], 200);
    }
}
