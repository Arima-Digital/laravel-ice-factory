# WORKFLOW PHASE 2 — Cerita Real-World Scenario

## Judul: Sehari Kerja di Ice Factory Distribution 🧊

**Tanggal**: 25 Agustus 2026 (Hari Selasa)
**Lokasi**: Jakarta

---

## 🌅 PAGI HARI (06:00 - 10:00)

### Jam 06:00 — Pabrik Mulai Produksi

**Tempat**: Pabrik Es Kristal
**Tokoh**: Rudi (Warehouse Staff)

```
Rudi tiba di pabrik pada jam 06:00.

"Pagi ini kita produksi ES ICE-10 (10 KG per bungkus).
Mesin sudah siap, ayo mulai..."

Machine on → Production dimulai.
```

**Backend System Log**:
```
Status sebelum produksi:
├─ FREEZERS (IoT last seen):
│  ├─ FRZ-RSA-001-A: 85.5 kg (8 ball estimate)
│  ├─ FRZ-RSA-001-B: 15 kg (1 ball estimate)
│  └─ FRZ-RSA-001-C: 105 kg (10 ball estimate - FULL)
│
├─ Warehouse Stock (calculated):
│  └─ WH-001: SUM(POSTED productions) - SUM(delivered items) = 77 ball

└─ Sistem siap untuk catat produksi hari ini
```

---

### Jam 08:30 — Production Selesai

**Mesin stop. Output: 95 Ball Es**

**Rudi input ke sistem**:
```
Production Record:
├─ Product: ICE-10 (Es Kristal 10 KG)
├─ Production Date: 2026-08-25
├─ Qty Produced: 100 ball
├─ Qty Reject: 5 ball (penyusutan/cacat)
├─ Qty Good: 95 ball (auto-calculated: 100 - 5)
├─ Status: DRAFT (editing masih bisa)
└─ Created By: Rudi (warehouse_staff)

💾 PRODUCTIONS table:
├─ id: 3 (production baru)
├─ status: DRAFT
└─ qty_good_ball: 95
```

**Warehouse Stock Update** (real-time):
```
Previous: 77 ball
+ Production (DRAFT): 0 ball (DRAFT tidak masuk inventory)
= Current: 77 ball (tetap, karena DRAFT belum POSTED)
```

---

### Jam 09:00 — Admin Review & POSTING

**Tempat**: Kantor Admin
**Tokoh**: Andi (Admin)

```
Andi buka dashboard → lihat production hari ini DRAFT.

"Hmm, 100 - 5 reject = 95 ball. Baik baik saja.
Mari saya POSTING supaya masuk inventory..."

Click: "POSTED"
```

**Backend System**:
```
Production Status DRAFT → POSTED

💾 PRODUCTIONS table update:
└─ status: POSTED (final, tidak bisa diubah)
```

**Warehouse Stock Update** (real-time):
```
Previous: 77 ball
+ Production (POSTED): 95 ball (sekarang masuk!)
= Current: 172 ball

Andi lihat di dashboard:
┌────────────────────────────────────┐
│ WAREHOUSE INVENTORY TODAY          │
├────────────────────────────────────┤
│ Warehouse: WH-001 (Gudang Pusat)   │
│ Current Stock: 172 ball ✓          │
│                                    │
│ Breakdown:                         │
│ - POSTED Productions: 95 ball      │
│ - Previous Stock: 77 ball          │
│ - Delivered Today: 0 ball          │
└────────────────────────────────────┘
```

---

## ☀️ SIANG HARI (10:00 - 13:00)

### Jam 10:30 — Admin Buka Dashboard (Real-time Monitoring)

**Tempat**: Kantor Admin
**Tokoh**: Andi (Admin)

```
Dashboard menunjukkan status real-time dari freezer di lapangan.
Data ini datang dari IoT (Freezer Logs).

Andi analisis:
```

**FREEZER STATUS REAL-TIME** (dari IoT):
```
┌─────────────────────────────────────────────────────┐
│ MONITORING DASHBOARD - 25 AUG 2026 10:30            │
├─────────────────────────────────────────────────────┤
│                                                     │
│ TOKO RSA-001 (Toko Rudi)                            │
│ Owner: Rudi Wijaya                                  │
│                                                     │
│ ├─ Freezer A [FRZ-RSA-001-A]:                       │
│ │  ├─ Last IoT Update: 10:25 (5 min ago) ✓         │
│ │  ├─ Current Weight: 85.5 kg                       │
│ │  ├─ Est. Stock: 8 ball                            │
│ │  ├─ Suggested Delivery: 2 ball                    │
│ │  └─ Status: HEALTHY                               │
│ │                                                   │
│ ├─ Freezer B [FRZ-RSA-001-B]:                       │
│ │  ├─ Last IoT Update: 10:20 (10 min ago) ✓        │
│ │  ├─ Current Weight: 15 kg                         │
│ │  ├─ Est. Stock: 1 ball                            │
│ │  ├─ Suggested Delivery: 9 ball                    │
│ │  └─ Status: ⚠️  LOW STOCK (urgent!)              │
│ │                                                   │
│ └─ Freezer C [FRZ-RSA-001-C]:                       │
│    ├─ Last IoT Update: 10:15 (15 min ago) ✓        │
│    ├─ Current Weight: 105 kg                        │
│    ├─ Est. Stock: 10 ball                           │
│    ├─ Suggested Delivery: 0 ball                    │
│    └─ Status: FULL (tidak perlu delivery)           │
│                                                     │
│ TOKO RSA-002 (Toko Budi)                            │
│ Owner: Budi Santoso                                 │
│                                                     │
│ ├─ Freezer A [FRZ-RSA-002-A]:                       │
│ │  ├─ Last IoT Update: 10:22 ✓                      │
│ │  ├─ Est. Stock: 7 ball                            │
│ │  └─ Suggested Delivery: 3 ball                    │
│ │                                                   │
│ └─ Freezer B [FRZ-RSA-002-B]:                       │
│    ├─ Last IoT Update: 10:18 ✓                      │
│    ├─ Est. Stock: 4 ball                            │
│    └─ Suggested Delivery: 6 ball                    │
│                                                     │
└─────────────────────────────────────────────────────┘
```

### Jam 11:00 — Admin Buat Delivery Plan

**Andi pikir**:
```
"Freezer B di RSA-001 URGENT! Hanya 1 ball, perlu restock ASAP.
RSA-002 juga butuh.

Hari ini Driver 1 (Rudi Junior) akan deliver ke dua toko ini.
Saya buat delivery plan...
```

**Andi click: "Create Delivery"**

**Form Input**:
```
Delivery Date: 2026-08-25
Driver: Rudi Junior (driver_1)
Vehicle: Mobil Isuzu (VEH-001)
Warehouse: WH-001 (Gudang Pusat)
Initial Qty Loaded: 50 ball (dari 172 ball yang tersedia)

Status: PLANNED
```

**Backend System**:
```
💾 DELIVERIES table (new):
├─ id: 1
├─ delivery_date: 2026-08-25
├─ driver_id: 3 (Rudi Junior)
├─ vehicle_id: 1 (Mobil Isuzu)
├─ warehouse_id: 1 (WH-001)
├─ initial_qty_loaded_ball: 50
├─ status: PLANNED
└─ created_at: 2026-08-25 11:00:00

Warehouse Inventory auto-update (on-the-fly):
├─ Total POSTED: 95 ball
├─ Loaded in vehicles: 50 ball (calculated)
└─ Available for future: 22 ball (172 - 50 - 100 reserved)
```

**Dashboard Andi sekarang show**:
```
Delivery Plan Created ✓
├─ Driver: Rudi Junior
├─ Vehicle: Mobil Isuzu (B-5000-XYZ)
├─ Qty Loaded: 50 ball
└─ Target: RSA-001 + RSA-002
```

---

### Jam 11:30 — Driver Rudi Junior Siap Berangkat

**Tempat**: Warehouse
**Tokoh**: Rudi Junior (Driver)

```
Rudi Junior nerima SMS dari sistem:

"Halo Rudi! Ada delivery plan untuk hari ini.
Mobil sudah dimuat 50 ball es.

Tujuan:
1️⃣ RSA-001 (Toko Rudi) - 3 freezer
2️⃣ RSA-002 (Toko Budi) - 2 freezer

Tap app untuk mulai delivery."

Rudi Junior: "Okee siap berangkat!"
```

**Rudi buka Driver App**:
```
┌────────────────────────────────────┐
│ DELIVERY PLAN HARI INI             │
├────────────────────────────────────┤
│ Kendaraan: Mobil Isuzu (B-5000...)│
│ Qty Dimuat: 50 ball                │
│ Warehouse: WH-001 (Gudang Pusat)   │
│                                    │
│ TOKO YANG HARUS DIKUNJUNGI:        │
│ ✓ RSA-001 (Toko Rudi) - 3 freezer  │
│ ✓ RSA-002 (Toko Budi) - 2 freezer  │
│                                    │
│ [MULAI DELIVERY]                   │
└────────────────────────────────────┘
```

**Rudi click: "MULAI DELIVERY"**

**Backend System**:
```
💾 DELIVERIES table update:
├─ status: COMPLETED (or IN_PROGRESS)
├─ started_at: 2026-08-25 11:35:00
└─ (completed_at: null dulu, nanti update saat selesai)
```

---

## 🚗 SORE HARI (13:00 - 16:00)

### Jam 13:30 — Rudi Tiba di RSA-001

**Tempat**: Toko Rudi
**Tokoh**: Rudi Junior (Driver) + Rudi (Toko Owner)

```
Rudi Junior: "Assalamualaikum Pak Rudi! Tiba dengan es nih..."

Pak Rudi: "Wah, tepat waktu! Mari kita check freezer..."

Rudi Junior buka app → pilih "RSA-001"
```

**App menampilkan**:
```
┌──────────────────────────────────────┐
│ TOKO: RSA-001 (Toko Rudi)            │
│ KUNJUNGAN: 13:30                     │
├──────────────────────────────────────┤
│                                      │
│ FREEZER A [FRZ-RSA-001-A]:           │
│ Est. Stock (IoT): 8 ball             │
│ Suggested Delivery: 2 ball           │
│ [CONFIRM?] [EDIT]                    │
│                                      │
│ FREEZER B [FRZ-RSA-001-B]:           │
│ Est. Stock (IoT): 1 ball             │
│ Suggested Delivery: 9 ball           │
│ [CONFIRM?] [EDIT]                    │
│                                      │
│ FREEZER C [FRZ-RSA-001-C]:           │
│ Est. Stock (IoT): 10 ball (FULL)     │
│ Suggested Delivery: 0 ball           │
│ [CONFIRM?] [EDIT]                    │
│                                      │
│ Total Qty Loaded: 50 ball            │
│ Sisa di Mobil: 50 ball               │
└──────────────────────────────────────┘
```

### FREEZER A — Typical Case (95%)

**Rudi Junior cek fisik Freezer A**:
```
Rudi: "Yah, stok emang sekitar 8 ball. Kasih 2 ball aja deh."

Rudi Junior tap: [CONFIRM]
```

**Backend System**:
```
💾 DELIVERY_ITEMS (new):
├─ id: 1
├─ delivery_id: 1
├─ store_id: 1 (RSA-001)
├─ freezer_id: 1 (Freezer A)
├─ confirmed_stock_before_ball: 8 (driver confirm)
├─ delivered_qty_ball: 2 (dari suggestion)
├─ visited_at: 2026-08-25 13:32:15
└─ created_at: 2026-08-25 13:32:15

Auto-calculate stock_after:
├─ confirmed_before: 8 ball
├─ + delivered: 2 ball
└─ = stock_after: 10 ball

💾 SALES (new):
├─ id: 1
├─ store_id: 1 (RSA-001)
├─ freezer_id: 1 (Freezer A)
├─ delivery_item_id: 1 (link ke delivery_items)
├─ qty_ball: 2 (yang terjual sejak last delivery)
├─ unit_price: 10000 (dari PRODUCTS)
├─ total_amount: 20000 (2 × 10000)
├─ status: CONFIRMED
├─ sold_at: 2026-08-25 (hari ini)
└─ created_at: 2026-08-25 13:32:15

Vehicle Inventory Update:
├─ Qty in Vehicle: 50 - 2 = 48 ball
└─ Remaining Delivery: 2 toko lagi (RSA-002)
```

### FREEZER B — Edge Case (5%)

**Rudi Junior cek fisik Freezer B**:
```
Rudi: "Hmm, stok ini banyak lebih dari 1 ball... 
       Maybe 3 ball? Kasih 7 aja biar penuh."

Rudi Junior tap: [EDIT]
```

**App prompt**:
```
"Stok sebenarnya berapa ball?"
Rudi input: 3

App calculate: max (10) - confirmed (3) = 7 suggested

"Kasih berapa ball?"
App suggest: 7

Rudi Junior: "Oke 7 ball!"
```

**Backend System**:
```
💾 DELIVERY_ITEMS (new):
├─ id: 2
├─ delivery_id: 1
├─ store_id: 1 (RSA-001)
├─ freezer_id: 2 (Freezer B)
├─ confirmed_stock_before_ball: 3 (manual input driver)
├─ delivered_qty_ball: 7 (driver confirm)
├─ visited_at: 2026-08-25 13:40:30

Auto-calculate stock_after: 3 + 7 = 10 ball

💾 SALES (new):
├─ qty_ball: 7 (stock terjual sejak last delivery)
├─ total_amount: 70000 (7 × 10000)
└─ sold_at: 2026-08-25

Vehicle Inventory:
├─ Qty in Vehicle: 48 - 7 = 41 ball
└─ RSA-001 Done! ✓
```

### FREEZER C — Skip (Full)

**App show**:
```
FREEZER C: 10 ball (FULL)
Suggested Delivery: 0 ball

Rudi Junior: "Ini sudah full, skip aja."
Click: [SKIP]
```

**Backend System**:
```
❌ DELIVERY_ITEMS NOT CREATED (tidak ada delivery untuk freezer ini)
```

---

### Jam 14:00 — Settlement & Payment di RSA-001

**Setelah selesai deliver ke 3 freezer, sistem otomatis buat SETTLEMENT**:

**Backend System**:
```
💾 SETTLEMENTS (new):
├─ id: 1
├─ store_id: 1 (RSA-001)
├─ current_sales_amount: 90000 (20000 + 70000)
├─ amount_paid: 0 (belum bayar)
├─ outstanding: 90000
├─ status: DRAFT
└─ created_at: 2026-08-25 14:00:00
```

**App show untuk Rudi Junior**:
```
┌────────────────────────────────────┐
│ SETTLEMENT RSA-001                 │
├────────────────────────────────────┤
│ Total Penjualan: Rp 90.000         │
│ (Freezer A: Rp 20.000)             │
│ (Freezer B: Rp 70.000)             │
│                                    │
│ Sudah Dibayar: Rp 0                │
│ Outstanding: Rp 90.000             │
│                                    │
│ [COLLECT PAYMENT]                  │
└────────────────────────────────────┘
```

**Rudi tap: [COLLECT PAYMENT]**

**Pak Rudi bayar cash: Rp 90.000**

**Backend System**:
```
💾 PAYMENTS (new):
├─ id: 1
├─ settlement_id: 1 (RSA-001)
├─ store_id: 1
├─ amount: 90000 (CASH from Rudi)
├─ method: CASH
├─ reference: "Bayar langsung di tempat"
├─ status: CONFIRMED
├─ paid_at: 2026-08-25 14:05:00

💾 SETTLEMENTS (update):
├─ amount_paid: 90000 (dari 0)
├─ outstanding: 0 (90000 - 90000)
├─ status: COMPLETED
```

**App show**:
```
┌────────────────────────────────────┐
│ SETTLEMENT RSA-001 ✓ LUNAS        │
├────────────────────────────────────┤
│ Total Penjualan: Rp 90.000         │
│ Sudah Dibayar: Rp 90.000 ✓         │
│ Outstanding: Rp 0                  │
│ Status: COMPLETED                  │
│                                    │
│ 🎉 Terima kasih Pak Rudi!         │
│ [NEXT TOKO]                        │
└────────────────────────────────────┘
```

---

### Jam 14:20 — Rudi Tiba di RSA-002

**Proses sama seperti RSA-001**:

```
Freezer RSA-002-A: confirm 7 ball → deliver 3 ball
  💾 SALES: qty=3, total=30000

Freezer RSA-002-B: confirm 4 ball → deliver 6 ball
  💾 SALES: qty=6, total=60000

Auto-create SETTLEMENT RSA-002:
  current_sales_amount: 90000 (30000 + 60000)

Pak Budi bayar CASH: Rp 90.000
  
Auto-create PAYMENTS + update SETTLEMENTS:
  SETTLEMENTS: outstanding = 0 ✓
```

**Vehicle Inventory Update**:
```
Qty in Vehicle:
├─ Start: 50 ball
├─ Delivered RSA-001: -(2+7) = -9 ball
├─ Delivered RSA-002: -(3+6) = -9 ball
└─ Sisa di kendaraan: 50 - 9 - 9 = 32 ball

Wait! Ini ada 32 ball sisa???

Check:
├─ Initial Loaded: 50 ball
├─ RSA-001 Freezer A: -2 ball (remaining 48)
├─ RSA-001 Freezer B: -7 ball (remaining 41)
├─ RSA-002 Freezer A: -3 ball (remaining 38)
├─ RSA-002 Freezer B: -6 ball (remaining 32)
└─ ✓ Balanced correctly!
```

---

## 🌙 MALAM HARI (16:00 - 18:00)

### Jam 16:30 — Rudi Junior Selesai Delivery

**Rudi Junior tap di app: "SELESAI DELIVERY"**

**Backend System**:
```
💾 DELIVERIES (update):
├─ status: COMPLETED
├─ started_at: 2026-08-25 11:35:00
├─ completed_at: 2026-08-25 16:30:00
├─ total_delivered: 9 + 9 = 18 ball
├─ remaining_in_vehicle: 32 ball
└─ Duration: ~5 hours

Warehouse Inventory (final):
├─ POSTED Productions: 95 ball
├─ Delivered Items: 18 ball
└─ Available Stock: 77 ball (95 - 18)
```

**App show**:
```
┌────────────────────────────────────┐
│ DELIVERY SELESAI ✓                 │
├────────────────────────────────────┤
│ Total Delivered: 18 ball           │
│ Remaining in Vehicle: 32 ball      │
│ Total Revenue Hari Ini: Rp 180.000 │
│ (RSA-001: Rp 90.000)               │
│ (RSA-002: Rp 90.000)               │
│                                    │
│ Total Collected: Rp 180.000 ✓      │
│ [RETURN TO WAREHOUSE]              │
└────────────────────────────────────┘
```

---

### Jam 17:00 — Rudi Junior Kembali ke Warehouse

**Rudi Junior kembali dengan 32 ball sisa**

**Warehouse Staff (Rudi) confirm return**:
```
"Baik, 32 ball masuk kembali ke warehouse.
Inventory terverifikasi."
```

**Final Summary untuk Hari Ini**:

```
PRODUCTION:
├─ Produced: 100 ball
├─ Rejected: 5 ball
├─ Good: 95 ball ✓
└─ Status: POSTED

WAREHOUSE:
├─ Stock Awal: 77 ball
├─ + Production: 95 ball
├─ - Delivered: 18 ball
└─ Final Stock: 172 - 18 = 154 ball ✓

DELIVERY:
├─ Qty Loaded: 50 ball
├─ Delivered: 18 ball
├─ Returned: 32 ball
└─ Status: COMPLETED ✓

SALES:
├─ RSA-001: Rp 90.000 (Freezer A: 20K + B: 70K)
├─ RSA-002: Rp 90.000 (Freezer A: 30K + B: 60K)
└─ Total Revenue: Rp 180.000 ✓

SETTLEMENT:
├─ RSA-001: COMPLETED, Outstanding: Rp 0 ✓
└─ RSA-002: COMPLETED, Outstanding: Rp 0 ✓

PAYMENT:
├─ RSA-001: Rp 90.000 CASH ✓
├─ RSA-002: Rp 90.000 CASH ✓
└─ Total Collected: Rp 180.000 ✓
```

---

### Jam 17:30 — Admin Dashboard Report

**Tempat**: Kantor Admin
**Tokoh**: Andi (Admin)

```
Andi lihat dashboard final hari ini:
```

**ADMIN DASHBOARD - END OF DAY REPORT**:
```
┌─────────────────────────────────────────────┐
│ LAPORAN HARIAN - 25 AGUSTUS 2026            │
├─────────────────────────────────────────────┤
│                                             │
│ PRODUCTION:                                 │
│ ✓ Total Produced: 95 ball                   │
│ ✓ Status: POSTED                            │
│ ✓ Warehouse Stock Now: 154 ball             │
│                                             │
│ DELIVERIES:                                 │
│ ✓ Qty Delivered: 18 ball                    │
│ ✓ Qty Returned: 32 ball                     │
│ ✓ Status: COMPLETED                         │
│                                             │
│ SALES & REVENUE:                            │
│ ├─ RSA-001 (Toko Rudi): Rp 90.000           │
│ │  ├─ Freezer A: Rp 20.000 (2 ball)         │
│ │  └─ Freezer B: Rp 70.000 (7 ball)         │
│ │                                           │
│ ├─ RSA-002 (Toko Budi): Rp 90.000           │
│ │  ├─ Freezer A: Rp 30.000 (3 ball)         │
│ │  └─ Freezer B: Rp 60.000 (6 ball)         │
│ │                                           │
│ └─ TOTAL REVENUE: Rp 180.000 ✓              │
│                                             │
│ SETTLEMENTS:                                │
│ ✓ RSA-001: COMPLETED (Outstanding: 0)       │
│ ✓ RSA-002: COMPLETED (Outstanding: 0)       │
│                                             │
│ PAYMENTS COLLECTED:                         │
│ ✓ Cash Received: Rp 180.000                 │
│ ✓ All Settlements: PAID ✓                   │
│                                             │
│ EXPENSES (Other):                           │
│ ├─ Fuel: Rp 250.000 (recorded)              │
│ └─ Electricity: Rp 500.000 (recorded)       │
│                                             │
│ PROFIT CALCULATION (Today):                 │
│ ├─ Revenue: Rp 180.000                      │
│ ├─ Expenses: Rp 750.000                     │
│ ├─ Net: Rp 180.000 - 750.000 = -570.000 ❌  │
│ │ (Negative karena expenses bulanan)        │
│ │                                           │
│ └─ Note: Lihat monthly profit untuk view    │
│         yang akurat                         │
│                                             │
└─────────────────────────────────────────────┘
```

**Andi summary**:
```
"Hari ini berjalan lancar!

✓ Production: 95 ball sesuai rencana
✓ Delivery: 18 ball ke 2 toko, selesai tepat waktu
✓ Revenue: Rp 180.000 masuk semua
✓ Settlement: Kedua toko sudah bayar lunas
✓ Inventory: Balanced, semua tercatat dengan baik

Besok lanjut lagi..."
```

---

## 📊 DATA INTEGRITY CHECK

**Verify semua data tersimpan dengan benar**:

```
WAREHOUSE STOCK AUDIT:
├─ Formula: SUM(POSTED productions) - SUM(delivery_items)
├─ Calculation: 95 - 18 = 77 ball
└─ Database Stock: 77 ball ✓ MATCH!

VEHICLE INVENTORY AUDIT:
├─ Initial Loaded: 50 ball
├─ Delivered: 18 ball
├─ Remaining: 32 ball
└─ Calculation: 50 - 18 = 32 ball ✓ MATCH!

REVENUE AUDIT:
├─ Sales RSA-001: (2 × 10000) + (7 × 10000) = 90.000
├─ Sales RSA-002: (3 × 10000) + (6 × 10000) = 90.000
├─ Total: 180.000
└─ Database Revenue: 180.000 ✓ MATCH!

COLLECTION AUDIT:
├─ Settlement RSA-001: 90.000
├─ Settlement RSA-002: 90.000
├─ Total: 180.000
└─ Payments Collected: 180.000 ✓ MATCH!
```

---

## 🎯 KEY LEARNINGS FROM THIS STORY

1. **Data Flow is Sequential**:
   - Production → Warehouse → Delivery → Sales → Settlement → Payment
   - Setiap step punya data record terpisah di table berbeda
   - Tidak ada data yang hilang atau tercatat 2x

2. **IoT Data for Planning, Human Confirmation for Accuracy**:
   - IoT suggest: 1 ball di Freezer B
   - Driver confirm fisik: 3 ball sebenarnya
   - System record driver confirmation, bukan IoT (100% accurate)

3. **Auto-Calculations Prevent Errors**:
   - stock_after = confirmed_before + delivered_qty (otomatis)
   - total_amount = qty_ball × unit_price (otomatis)
   - warehouse_stock = SUM(produced) - SUM(delivered) (otomatis)
   - Tidak ada manual calculation → zero error

4. **Immutable Audit Trail**:
   - Setiap transaksi punya timestamp
   - POSTED production tidak bisa diubah
   - CONFIRMED payment tidak bisa dibatalkan
   - Jika ada masalah, buat VOID record baru (bukan edit)

5. **Flexible but Clear**:
   - 95% case: driver confirm IoT suggestion (1 click)
   - 5% case: driver manual input (few clicks)
   - Model A payment: instant (like today's story)
   - Model B, C: future option (flexible)
