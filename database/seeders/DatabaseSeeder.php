<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Production;
use App\Models\Expense;
use App\Models\Store;
use App\Models\Freezer;
use App\Models\Vehicle;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Sale;
use App\Models\Settlement;
use App\Models\Payment;
use App\Models\FreezerLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Truncate all tables to ensure clean state
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('users')->truncate();
        DB::table('products')->truncate();
        DB::table('warehouses')->truncate();
        DB::table('productions')->truncate();
        DB::table('expenses')->truncate();
        DB::table('stores')->truncate();
        DB::table('freezers')->truncate();
        DB::table('vehicles')->truncate();
        DB::table('deliveries')->truncate();
        DB::table('delivery_items')->truncate();
        DB::table('sales')->truncate();
        DB::table('settlements')->truncate();
        DB::table('payments')->truncate();
        DB::table('freezer_logs')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // ===== PHASE 1: USERS =====
        $admin = User::create([
            'username' => 'admin',
            'email' => 'admin@icefactory.local',
            'password_hash' => bcrypt('password123'),
            'role' => 'ADMIN',
        ]);

        $warehouse = User::create([
            'username' => 'warehouse_staff',
            'email' => 'warehouse@icefactory.local',
            'password_hash' => bcrypt('password123'),
            'role' => 'WAREHOUSE',
        ]);

        $driver = User::create([
            'username' => 'driver_1',
            'email' => 'driver1@icefactory.local',
            'password_hash' => bcrypt('password123'),
            'role' => 'DRIVER',
        ]);

        // ===== PHASE 1: PRODUCTS =====
        $product = Product::create([
            'code' => 'ICE-10',
            'name' => 'Es Kristal 10 KG',
            'weight_kg' => 10.00,
            'selling_price' => 10000,
        ]);

        // ===== PHASE 1: WAREHOUSE =====
        $warehouseLocation = Warehouse::create([
            'code' => 'WH-001',
            'name' => 'Gudang Pusat',
        ]);

        // ===== PHASE 1: PRODUCTIONS =====
        Production::create([
            'product_id' => $product->id,
            'production_date' => now()->toDateString(),
            'qty_produced_ball' => 100,
            'qty_reject_ball' => 5,
            'qty_good_ball' => 95,
            'created_by' => $warehouse->id,
            'status' => 'DRAFT',
        ]);

        Production::create([
            'product_id' => $product->id,
            'production_date' => now()->subDay()->toDateString(),
            'qty_produced_ball' => 80,
            'qty_reject_ball' => 3,
            'qty_good_ball' => 77,
            'created_by' => $warehouse->id,
            'status' => 'POSTED',
        ]);

        // ===== PHASE 1: EXPENSES =====
        Expense::create([
            'expense_date' => now()->toDateString(),
            'category' => 'FUEL',
            'amount' => 250000,
            'description' => 'Bensin kendaraan delivery',
            'created_by' => $admin->id,
            'status' => 'POSTED',
        ]);

        Expense::create([
            'expense_date' => now()->toDateString(),
            'category' => 'ELECTRICITY',
            'amount' => 500000,
            'description' => 'Tagihan listrik bulan Agustus',
            'created_by' => $admin->id,
            'status' => 'POSTED',
        ]);

        // ===== PHASE 2: STORES =====
        $store1 = Store::create([
            'code' => 'RSA-001',
            'name' => 'Toko Rudi',
            'owner_name' => 'Rudi Wijaya',
            'phone' => '08123456789',
            'address' => 'Jl. Merdeka No. 123, Jakarta',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
        ]);

        $store2 = Store::create([
            'code' => 'RSA-002',
            'name' => 'Toko Budi',
            'owner_name' => 'Budi Santoso',
            'phone' => '08129876543',
            'address' => 'Jl. Sudirman No. 456, Jakarta',
            'latitude' => -6.2167,
            'longitude' => 106.8294,
        ]);

        // ===== PHASE 2: FREEZERS =====
        $freezer1 = Freezer::create([
            'store_id' => $store1->id,
            'product_id' => $product->id,
            'code' => 'FRZ-RSA-001-A',
            'sim_number' => '6281234567890',
            'max_capacity_ball' => 10,
            'tare_weight_kg' => 5.00,
            'last_weight_kg' => 85.5,  // 80.5 net = 8 ball
            'last_temperature_c' => -18.5,
            'last_door_status' => 'CLOSED',
            'last_seen_at' => now(),
        ]);

        $freezer2 = Freezer::create([
            'store_id' => $store1->id,
            'product_id' => $product->id,
            'code' => 'FRZ-RSA-001-B',
            'sim_number' => '6281234567891',
            'max_capacity_ball' => 10,
            'tare_weight_kg' => 5.00,
            'last_weight_kg' => 15.0,   // 10 net = 1 ball
            'last_temperature_c' => -18.0,
            'last_door_status' => 'CLOSED',
            'last_seen_at' => now(),
        ]);

        $freezer3 = Freezer::create([
            'store_id' => $store1->id,
            'product_id' => $product->id,
            'code' => 'FRZ-RSA-001-C',
            'sim_number' => '6281234567892',
            'max_capacity_ball' => 10,
            'tare_weight_kg' => 5.00,
            'last_weight_kg' => 105.0,  // 100 net = 10 ball (FULL)
            'last_temperature_c' => -19.0,
            'last_door_status' => 'CLOSED',
            'last_seen_at' => now(),
        ]);

        $freezer4 = Freezer::create([
            'store_id' => $store2->id,
            'product_id' => $product->id,
            'code' => 'FRZ-RSA-002-A',
            'sim_number' => '6281234567893',
            'max_capacity_ball' => 10,
            'tare_weight_kg' => 5.00,
            'last_weight_kg' => 75.0,   // 70 net = 7 ball
            'last_temperature_c' => -18.0,
            'last_door_status' => 'CLOSED',
            'last_seen_at' => now(),
        ]);

        $freezer5 = Freezer::create([
            'store_id' => $store2->id,
            'product_id' => $product->id,
            'code' => 'FRZ-RSA-002-B',
            'sim_number' => '6281234567894',
            'max_capacity_ball' => 10,
            'tare_weight_kg' => 5.00,
            'last_weight_kg' => 45.0,   // 40 net = 4 ball
            'last_temperature_c' => -18.5,
            'last_door_status' => 'CLOSED',
            'last_seen_at' => now(),
        ]);

        // ===== PHASE 2: FREEZER_LOGS (IoT Data) =====
        FreezerLog::create([
            'freezer_id' => $freezer1->id,
            'weight_kg' => 85.5,
            'temperature_c' => -18.5,
            'door_status' => 'CLOSED',
            'logged_at' => now()->subMinutes(5),
        ]);

        FreezerLog::create([
            'freezer_id' => $freezer2->id,
            'weight_kg' => 15.0,
            'temperature_c' => -18.0,
            'door_status' => 'CLOSED',
            'logged_at' => now()->subMinutes(10),
        ]);

        // ===== PHASE 2: VEHICLES =====
        $vehicle = Vehicle::create([
            'code' => 'VEH-001',
            'plate_number' => 'B-5000-XYZ',
            'name' => 'Mobil Isuzu',
        ]);

        // ===== PHASE 2: DELIVERIES =====
        $delivery = Delivery::create([
            'delivery_date' => now()->toDateString(),
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'warehouse_id' => $warehouseLocation->id,
            'initial_qty_loaded_ball' => 50,
            'status' => 'COMPLETED',
            'started_at' => now()->subHours(2),
            'completed_at' => now(),
        ]);

        // ===== PHASE 2: DELIVERY_ITEMS =====
        $deliveryItem1 = DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'store_id' => $store1->id,
            'freezer_id' => $freezer1->id,
            'confirmed_stock_before_ball' => 8.0,
            'delivered_qty_ball' => 2.0,
            'visited_at' => now()->subHours(1, 45),
        ]);

        $deliveryItem2 = DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'store_id' => $store1->id,
            'freezer_id' => $freezer2->id,
            'confirmed_stock_before_ball' => 1.0,
            'delivered_qty_ball' => 9.0,
            'visited_at' => now()->subHours(1, 30),
        ]);

        $deliveryItem3 = DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'store_id' => $store2->id,
            'freezer_id' => $freezer4->id,
            'confirmed_stock_before_ball' => 7.0,
            'delivered_qty_ball' => 3.0,
            'visited_at' => now()->subHours(1),
        ]);

        // ===== PHASE 2: SALES (Auto-calculated from delivery) =====
        Sale::create([
            'store_id' => $store1->id,
            'freezer_id' => $freezer1->id,
            'delivery_item_id' => $deliveryItem1->id,
            'qty_ball' => 2.0,
            'unit_price' => 10000,
            'total_amount' => 20000,
            'status' => 'CONFIRMED',
            'sold_at' => now()->subHours(1, 45),
        ]);

        Sale::create([
            'store_id' => $store1->id,
            'freezer_id' => $freezer2->id,
            'delivery_item_id' => $deliveryItem2->id,
            'qty_ball' => 9.0,
            'unit_price' => 10000,
            'total_amount' => 90000,
            'status' => 'CONFIRMED',
            'sold_at' => now()->subHours(1, 30),
        ]);

        Sale::create([
            'store_id' => $store2->id,
            'freezer_id' => $freezer4->id,
            'delivery_item_id' => $deliveryItem3->id,
            'qty_ball' => 3.0,
            'unit_price' => 10000,
            'total_amount' => 30000,
            'status' => 'CONFIRMED',
            'sold_at' => now()->subHours(1),
        ]);

        // ===== PHASE 2: SETTLEMENTS =====
        $settlement1 = Settlement::create([
            'store_id' => $store1->id,
            'current_sales_amount' => 110000,  // 20000 + 90000
            'amount_paid' => 110000,
            'outstanding' => 0,
            'status' => 'COMPLETED',
        ]);

        $settlement2 = Settlement::create([
            'store_id' => $store2->id,
            'current_sales_amount' => 30000,
            'amount_paid' => 30000,
            'outstanding' => 0,
            'status' => 'COMPLETED',
        ]);

        // ===== PHASE 2: PAYMENTS =====
        Payment::create([
            'settlement_id' => $settlement1->id,
            'store_id' => $store1->id,
            'amount' => 110000,
            'method' => 'CASH',
            'reference' => 'Bayar langsung di tempat',
            'status' => 'CONFIRMED',
            'paid_at' => now()->subHours(1),
        ]);

        Payment::create([
            'settlement_id' => $settlement2->id,
            'store_id' => $store2->id,
            'amount' => 30000,
            'method' => 'CASH',
            'reference' => 'Bayar langsung di tempat',
            'status' => 'CONFIRMED',
            'paid_at' => now()->subHour(),
        ]);
    }
}

