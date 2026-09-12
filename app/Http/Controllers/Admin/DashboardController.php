<?php

namespace App\Http\Controllers\Admin;

use App\Models\Sale;
use App\Models\Payment;
use App\Models\Delivery;
use App\Models\Store;
use App\Models\Production;
use App\Models\DeliveryItem;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard summary (KPI Cards for today)
     * Admin dashboard - shows all key metrics for today
     */
    public function getSummary(Request $request)
    {
        $today = $request->get('date') ? Carbon::parse($request->date) : Carbon::today();

        // 1. Total Revenue Today (Sales)
        $totalRevenueToday = Sale::whereDate('sold_at', $today)->sum('total_amount');
        
        // 2. Total Sales Qty Today
        $totalQtyToday = Sale::whereDate('sold_at', $today)->sum('qty_ball');

        // 3. Warehouse Stock Available (Real-time)
        $totalProduced = Production::where('status', 'POSTED')->sum('qty_good_ball');
        $totalDelivered = DeliveryItem::sum('delivered_qty_ball');
        $warehouseStock = $totalProduced - $totalDelivered;

        // 4. Outstanding Amount (All stores)
        $totalSales = Sale::sum('total_amount');
        $totalPaidConfirmed = Payment::where('status', 'CONFIRMED')->sum('amount');
        $outstandingAmount = $totalSales - $totalPaidConfirmed;

        // 5. Overdue Amount (7+ days)
        $overdueStores = Store::all();
        $overdueAmount = 0;
        
        foreach ($overdueStores as $store) {
            $storeSales = Sale::where('store_id', $store->id)->sum('total_amount');
            $storePaid = Payment::where('store_id', $store->id)
                ->where('status', 'CONFIRMED')
                ->sum('amount');
            $storeOutstanding = $storeSales - $storePaid;

            if ($storeOutstanding > 0) {
                $firstSale = Sale::where('store_id', $store->id)
                    ->orderBy('sold_at', 'asc')
                    ->first();
                
                if ($firstSale && $firstSale->sold_at->diffInDays(now()) >= 7) {
                    $overdueAmount += $storeOutstanding;
                }
            }
        }

        // 6. Active Deliveries In Progress
        $activeDeliveries = Delivery::where('status', 'IN_PROGRESS')->count();

        // 7. Pending Payments (TRANSFER/QRIS awaiting confirmation)
        $pendingPayments = Payment::where('status', 'PENDING')
            ->whereIn('method', ['TRANSFER', 'QRIS'])
            ->count();
        $pendingPaymentsAmount = Payment::where('status', 'PENDING')
            ->whereIn('method', ['TRANSFER', 'QRIS'])
            ->sum('amount');

        // 8. Today's Collection (Payments confirmed today)
        $todayCollection = Payment::whereDate('paid_at', $today)
            ->where('status', 'CONFIRMED')
            ->sum('amount');

        // 9. Collection Rate Today
        $collectionRate = $totalRevenueToday > 0 
            ? ($todayCollection / $totalRevenueToday) * 100 
            : 0;

        // 10. Completed Deliveries Today
        $completedDeliveries = Delivery::whereDate('completed_at', $today)
            ->where('status', 'COMPLETED')
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Dashboard summary retrieved successfully',
            'data' => [
                'date' => $today->toDateString(),
                'kpi_cards' => [
                    'total_revenue_today' => $totalRevenueToday,
                    'total_sales_qty_today' => $totalQtyToday,
                    'warehouse_stock_available' => $warehouseStock,
                    'outstanding_amount' => $outstandingAmount,
                    'overdue_amount_7plus_days' => $overdueAmount,
                    'active_deliveries_in_progress' => $activeDeliveries,
                    'pending_payments_count' => $pendingPayments,
                    'pending_payments_amount' => $pendingPaymentsAmount,
                ],
                'additional_metrics' => [
                    'today_collection' => $todayCollection,
                    'collection_rate_percent' => round($collectionRate, 2),
                    'completed_deliveries_today' => $completedDeliveries,
                ],
            ],
        ], 200);
    }

    /**
     * Get daily breakdown (Store-by-store detail for specific date)
     */
    public function getDailyBreakdown(Request $request)
    {
        $date = $request->get('date') ? Carbon::parse($request->date) : Carbon::today();

        $stores = Store::all();
        $breakdown = [];

        foreach ($stores as $store) {
            // Sales for this date
            $sales = Sale::where('store_id', $store->id)
                ->whereDate('sold_at', $date)
                ->get();

            if ($sales->count() === 0) {
                continue; // Skip stores with no activity today
            }

            $salesAmount = $sales->sum('total_amount');
            $salesQty = $sales->sum('qty_ball');

            // Deliveries for this date
            $deliveryItems = DeliveryItem::where('store_id', $store->id)
                ->whereDate('visited_at', $date)
                ->get();
            $deliveredQty = $deliveryItems->sum('delivered_qty_ball');

            // Outstanding
            $totalStoreSales = Sale::where('store_id', $store->id)->sum('total_amount');
            $totalStorePaid = Payment::where('store_id', $store->id)
                ->where('status', 'CONFIRMED')
                ->sum('amount');
            $outstanding = $totalStoreSales - $totalStorePaid;

            // Days outstanding
            $firstSale = Sale::where('store_id', $store->id)
                ->orderBy('sold_at', 'asc')
                ->first();
            $daysOutstanding = $firstSale && $outstanding > 0
                ? $firstSale->sold_at->diffInDays(now())
                : 0;

            $isOverdue = $daysOutstanding >= 7;

            $breakdown[] = [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'store_owner' => $store->owner_name,
                'sales_amount_today' => $salesAmount,
                'sales_qty_today' => $salesQty,
                'delivered_qty_today' => $deliveredQty,
                'outstanding_balance' => $outstanding,
                'days_outstanding' => $daysOutstanding,
                'is_overdue' => $isOverdue,
            ];
        }

        // Sort by sales amount (highest first)
        usort($breakdown, function ($a, $b) {
            return $b['sales_amount_today'] <=> $a['sales_amount_today'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Daily breakdown retrieved successfully',
            'data' => [
                'date' => $date->toDateString(),
                'stores' => $breakdown,
                'summary' => [
                    'total_stores_active' => count($breakdown),
                    'total_sales' => array_sum(array_column($breakdown, 'sales_amount_today')),
                    'total_qty' => array_sum(array_column($breakdown, 'sales_qty_today')),
                    'total_delivered' => array_sum(array_column($breakdown, 'delivered_qty_today')),
                ],
            ],
        ], 200);
    }

    /**
     * Get weekly summary (7 days aggregated)
     */
    public function getWeeklySummary(Request $request)
    {
        // Week format: 2026-W35 or specific start date
        $startDate = $request->get('start_date') 
            ? Carbon::parse($request->start_date) 
            : Carbon::now()->startOfWeek();
        
        $endDate = $startDate->copy()->endOfWeek();

        // Daily breakdown for 7 days
        $dailyData = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $dayRevenue = Sale::whereDate('sold_at', $currentDate)->sum('total_amount');
            $dayQty = Sale::whereDate('sold_at', $currentDate)->sum('qty_ball');
            $dayCollection = Payment::whereDate('paid_at', $currentDate)
                ->where('status', 'CONFIRMED')
                ->sum('amount');
            $dayDelivered = DeliveryItem::whereDate('visited_at', $currentDate)->sum('delivered_qty_ball');

            $dailyData[] = [
                'date' => $currentDate->toDateString(),
                'day_name' => $currentDate->format('l'),
                'revenue' => $dayRevenue,
                'qty_sold' => $dayQty,
                'delivered_qty' => $dayDelivered,
                'collection' => $dayCollection,
            ];

            $currentDate->addDay();
        }

        // Top performing stores this week
        $stores = Store::all();
        $storePerformance = [];

        foreach ($stores as $store) {
            $weekSales = Sale::where('store_id', $store->id)
                ->whereBetween('sold_at', [$startDate, $endDate])
                ->sum('total_amount');

            if ($weekSales > 0) {
                $storePerformance[] = [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'week_sales' => $weekSales,
                ];
            }
        }

        // Sort by sales (highest first)
        usort($storePerformance, function ($a, $b) {
            return $b['week_sales'] <=> $a['week_sales'];
        });

        // Take top 10
        $topStores = array_slice($storePerformance, 0, 10);

        return response()->json([
            'success' => true,
            'message' => 'Weekly summary retrieved successfully',
            'data' => [
                'week_start' => $startDate->toDateString(),
                'week_end' => $endDate->toDateString(),
                'daily_breakdown' => $dailyData,
                'top_performing_stores' => $topStores,
                'summary' => [
                    'total_revenue' => array_sum(array_column($dailyData, 'revenue')),
                    'total_qty_sold' => array_sum(array_column($dailyData, 'qty_sold')),
                    'total_delivered' => array_sum(array_column($dailyData, 'delivered_qty')),
                    'total_collection' => array_sum(array_column($dailyData, 'collection')),
                ],
            ],
        ], 200);
    }

    /**
     * Get monthly P&L (Profit & Loss)
     */
    public function getMonthlyPL(Request $request)
    {
        $month = $request->get('month') 
            ? Carbon::parse($request->month)->startOfMonth() 
            : Carbon::now()->startOfMonth();
        
        $endDate = $month->copy()->endOfMonth();

        // REVENUE
        $totalSales = Sale::whereBetween('sold_at', [$month, $endDate])->sum('total_amount');
        $totalCollected = Payment::whereBetween('paid_at', [$month, $endDate])
            ->where('status', 'CONFIRMED')
            ->sum('amount');

        // Outstanding NOT counted in P&L (belum terima uang)
        $revenue = $totalCollected; // Only confirmed payments counted

        // EXPENSES
        $expenses = Expense::whereBetween('expense_date', [$month, $endDate])
            ->where('status', 'APPROVED')
            ->get();

        $totalExpenses = $expenses->sum('amount');

        // Group expenses by category/keyword
        $expenseBreakdown = $expenses->groupBy('category')->map(function ($group, $category) {
            return [
                'category' => $category ?? 'Uncategorized',
                'total' => $group->sum('amount'),
                'count' => $group->count(),
            ];
        })->values();

        // PROFIT
        $profit = $revenue - $totalExpenses;
        $profitMargin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

        return response()->json([
            'success' => true,
            'message' => 'Monthly P&L retrieved successfully',
            'data' => [
                'month' => $month->format('Y-m'),
                'month_name' => $month->format('F Y'),
                'revenue' => [
                    'total_sales_amount' => $totalSales,
                    'total_collected' => $totalCollected,
                    'outstanding_not_counted' => $totalSales - $totalCollected,
                    'revenue_for_pl' => $revenue,
                ],
                'expenses' => [
                    'total_expenses' => $totalExpenses,
                    'breakdown' => $expenseBreakdown,
                ],
                'profit_loss' => [
                    'net_profit' => $profit,
                    'profit_margin_percent' => round($profitMargin, 2),
                    'status' => $profit >= 0 ? 'PROFIT' : 'LOSS',
                ],
            ],
        ], 200);
    }
}
