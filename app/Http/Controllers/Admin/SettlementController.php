<?php

namespace App\Http\Controllers\Admin;

use App\Models\Store;
use App\Models\Sale;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Carbon\Carbon;

class SettlementController extends Controller
{
    /**
     * Get outstanding balance for a specific store
     * Outstanding = Total Sales - Total Confirmed Payments
     * Real-time calculation (not stored in DB)
     */
    public function getOutstanding($storeId)
    {
        $store = Store::find($storeId);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $totalSales = Sale::where('store_id', $storeId)->sum('total_amount');
        $totalPaidConfirmed = Payment::where('store_id', $storeId)
            ->where('status', 'CONFIRMED')
            ->sum('amount');

        $outstanding = $totalSales - $totalPaidConfirmed;
        $pendingPayments = Payment::where('store_id', $storeId)
            ->where('status', 'PENDING')
            ->sum('amount');

        // Calculate days outstanding (since first sale if outstanding > 0)
        $daysOutstanding = null;
        if ($outstanding > 0) {
            $firstSaleDate = Sale::where('store_id', $storeId)
                ->orderBy('sold_at', 'asc')
                ->first();
            
            if ($firstSaleDate) {
                $daysOutstanding = $firstSaleDate->sold_at->diffInDays(now());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Outstanding balance retrieved successfully',
            'data' => [
                'store' => $store,
                'total_sales' => $totalSales,
                'total_paid_confirmed' => $totalPaidConfirmed,
                'pending_payments' => $pendingPayments,
                'outstanding' => $outstanding,
                'status' => $outstanding > 0 ? 'DEBT' : 'SETTLED',
                'days_outstanding' => $daysOutstanding,
                'is_overdue' => $outstanding > 0 && $daysOutstanding >= 7, // 7+ days = overdue
            ],
        ], 200);
    }

    /**
     * Get outstanding summary for all stores
     * Shows only stores with outstanding > 0
     */
    public function getOutstandingSummary(Request $request)
    {
        $stores = Store::all();
        $summaryData = [];
        $totalOutstanding = 0;

        foreach ($stores as $store) {
            $totalSales = Sale::where('store_id', $store->id)->sum('total_amount');
            $totalPaidConfirmed = Payment::where('store_id', $store->id)
                ->where('status', 'CONFIRMED')
                ->sum('amount');

            $outstanding = $totalSales - $totalPaidConfirmed;

            if ($outstanding > 0 || $request->has('include_settled')) {
                // Calculate days outstanding
                $firstSaleDate = Sale::where('store_id', $store->id)
                    ->orderBy('sold_at', 'asc')
                    ->first();
                
                $daysOutstanding = $firstSaleDate 
                    ? $firstSaleDate->sold_at->diffInDays(now())
                    : 0;

                $isOverdue = $daysOutstanding >= 7;

                $summaryData[] = [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'store_owner' => $store->owner_name,
                    'store_phone' => $store->phone,
                    'total_sales' => $totalSales,
                    'total_paid' => $totalPaidConfirmed,
                    'outstanding' => $outstanding,
                    'days_outstanding' => $daysOutstanding,
                    'status' => $outstanding > 0 ? 'DEBT' : 'SETTLED',
                    'is_overdue' => $isOverdue,
                ];
            }
        }

        // Sort by outstanding amount (highest first)
        usort($summaryData, function ($a, $b) {
            return $b['outstanding'] <=> $a['outstanding'];
        });

        foreach ($summaryData as $data) {
            $totalOutstanding += $data['outstanding'];
        }

        return response()->json([
            'success' => true,
            'message' => 'Outstanding summary retrieved successfully',
            'data' => [
                'stores_with_outstanding' => count(array_filter($summaryData, fn($s) => $s['outstanding'] > 0)),
                'total_outstanding' => $totalOutstanding,
                'summary' => $summaryData,
            ],
        ], 200);
    }

    /**
     * Get overdue stores (outstanding > 7 days)
     */
    public function getOverdue(Request $request)
    {
        $daysThreshold = $request->get('days', 7);
        $stores = Store::all();
        $overdueData = [];
        $totalOverdueAmount = 0;

        foreach ($stores as $store) {
            $totalSales = Sale::where('store_id', $store->id)->sum('total_amount');
            $totalPaidConfirmed = Payment::where('store_id', $store->id)
                ->where('status', 'CONFIRMED')
                ->sum('amount');

            $outstanding = $totalSales - $totalPaidConfirmed;

            if ($outstanding > 0) {
                $firstSaleDate = Sale::where('store_id', $store->id)
                    ->orderBy('sold_at', 'asc')
                    ->first();

                if ($firstSaleDate) {
                    $daysOutstanding = $firstSaleDate->sold_at->diffInDays(now());

                    if ($daysOutstanding >= $daysThreshold) {
                        $overdueData[] = [
                            'store_id' => $store->id,
                            'store_name' => $store->name,
                            'store_owner' => $store->owner_name,
                            'store_phone' => $store->phone,
                            'store_address' => $store->address,
                            'outstanding' => $outstanding,
                            'days_overdue' => $daysOutstanding - $daysThreshold,
                            'days_since_first_sale' => $daysOutstanding,
                            'first_sale_date' => $firstSaleDate->sold_at->toDateString(),
                            'priority' => $daysOutstanding >= 30 ? 'HIGH' : ($daysOutstanding >= 14 ? 'MEDIUM' : 'LOW'),
                        ];

                        $totalOverdueAmount += $outstanding;
                    }
                }
            }
        }

        // Sort by days overdue (highest first)
        usort($overdueData, function ($a, $b) {
            return $b['days_overdue'] <=> $a['days_overdue'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Overdue stores retrieved successfully',
            'data' => [
                'threshold_days' => $daysThreshold,
                'overdue_stores_count' => count($overdueData),
                'total_overdue_amount' => $totalOverdueAmount,
                'stores' => $overdueData,
            ],
        ], 200);
    }

    /**
     * Get settlement history for a store
     * Shows all sales and payments timeline
     */
    public function getSettlementHistory($storeId, Request $request)
    {
        $store = Store::find($storeId);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        // Get all sales
        $sales = Sale::where('store_id', $storeId)
            ->with('freezer')
            ->orderBy('sold_at', 'desc')
            ->get();

        // Get all payments
        $payments = Payment::where('store_id', $storeId)
            ->with('createdByUser')
            ->orderBy('paid_at', 'desc')
            ->get();

        // Combine and sort by date
        $history = [];
        
        foreach ($sales as $sale) {
            $history[] = [
                'type' => 'SALE',
                'date' => $sale->sold_at,
                'amount' => $sale->total_amount,
                'qty' => $sale->qty_ball,
                'freezer_id' => $sale->freezer_id,
                'freezer_name' => $sale->freezer->name ?? 'Unknown',
                'description' => "Sale at {$sale->freezer->name}",
            ];
        }

        foreach ($payments as $payment) {
            $history[] = [
                'type' => 'PAYMENT',
                'date' => $payment->paid_at,
                'amount' => -$payment->amount,
                'method' => $payment->method,
                'payment_type' => $payment->payment_type,
                'status' => $payment->status,
                'description' => "Payment {$payment->method} ({$payment->payment_type})",
            ];
        }

        // Sort by date (newest first)
        usort($history, function ($a, $b) {
            return $b['date']->timestamp <=> $a['date']->timestamp;
        });

        // Calculate running balance
        $runningBalance = 0;
        $historyWithBalance = array_map(function ($item) use (&$runningBalance) {
            $runningBalance += $item['amount'];
            return array_merge($item, ['running_balance' => $runningBalance]);
        }, $history);

        // Current outstanding
        $totalSales = $sales->sum('total_amount');
        $totalPaidConfirmed = Payment::where('store_id', $storeId)
            ->where('status', 'CONFIRMED')
            ->sum('amount');
        $outstanding = $totalSales - $totalPaidConfirmed;

        return response()->json([
            'success' => true,
            'message' => 'Settlement history retrieved successfully',
            'data' => [
                'store' => $store,
                'total_sales' => $totalSales,
                'total_paid_confirmed' => $totalPaidConfirmed,
                'outstanding' => $outstanding,
                'sales_count' => count($sales),
                'payments_count' => count($payments),
                'history' => $historyWithBalance,
            ],
        ], 200);
    }

    /**
     * Get settlement statistics
     */
    public function getStatistics(Request $request)
    {
        $startDate = $request->get('start_date') 
            ? Carbon::parse($request->start_date)->startOfDay() 
            : Carbon::now()->startOfMonth();
        
        $endDate = $request->get('end_date') 
            ? Carbon::parse($request->end_date)->endOfDay() 
            : Carbon::now()->endOfDay();

        // Total sales in period
        $totalSales = Sale::whereBetween('sold_at', [$startDate, $endDate])
            ->sum('total_amount');

        // Total confirmed payments in period
        $totalPaidConfirmed = Payment::whereBetween('paid_at', [$startDate, $endDate])
            ->where('status', 'CONFIRMED')
            ->sum('amount');

        // Payment methods breakdown
        $byMethod = [
            'CASH' => Payment::whereBetween('paid_at', [$startDate, $endDate])
                ->where('status', 'CONFIRMED')
                ->where('method', 'CASH')
                ->sum('amount'),
            'TRANSFER' => Payment::whereBetween('paid_at', [$startDate, $endDate])
                ->where('status', 'CONFIRMED')
                ->where('method', 'TRANSFER')
                ->sum('amount'),
            'QRIS' => Payment::whereBetween('paid_at', [$startDate, $endDate])
                ->where('status', 'CONFIRMED')
                ->where('method', 'QRIS')
                ->sum('amount'),
        ];

        // Payment types breakdown
        $byType = [
            'TODAY' => Payment::whereBetween('paid_at', [$startDate, $endDate])
                ->where('status', 'CONFIRMED')
                ->where('payment_type', 'TODAY')
                ->sum('amount'),
            'PAST_DAYS' => Payment::whereBetween('paid_at', [$startDate, $endDate])
                ->where('status', 'CONFIRMED')
                ->where('payment_type', 'PAST_DAYS')
                ->sum('amount'),
            'DEBT' => Payment::whereBetween('paid_at', [$startDate, $endDate])
                ->where('status', 'CONFIRMED')
                ->where('payment_type', 'DEBT')
                ->sum('amount'),
        ];

        // Overall outstanding across all stores
        $allSales = Sale::sum('total_amount');
        $allPaidConfirmed = Payment::where('status', 'CONFIRMED')->sum('amount');
        $overallOutstanding = $allSales - $allPaidConfirmed;

        return response()->json([
            'success' => true,
            'message' => 'Settlement statistics retrieved successfully',
            'data' => [
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ],
                'sales' => [
                    'total_in_period' => $totalSales,
                    'total_all_time' => $allSales,
                ],
                'payments' => [
                    'total_in_period' => $totalPaidConfirmed,
                    'total_all_time' => $allPaidConfirmed,
                ],
                'outstanding' => [
                    'total_outstanding' => $overallOutstanding,
                    'payment_rate' => $allSales > 0 ? ($allPaidConfirmed / $allSales * 100) : 0,
                ],
                'breakdown_by_method' => $byMethod,
                'breakdown_by_type' => $byType,
            ],
        ], 200);
    }
}
