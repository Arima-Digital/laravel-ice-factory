# Ice Factory Distribution System — Business Requirements Document (BRD)
**Efficient | Accurate | Mobile-First**

**Date:** 25 Agustus 2026  
**Status:** Ready for Development  
**Audience:** Frontend & Backend Developers

---

## EXECUTIVE SUMMARY

Sistem manajemen distribusi es kristal dari production → warehouse → delivery → toko dengan fokus:
- **Efficiency:** Driver 1-2 taps per freezer, auto-calculate semua
- **Accuracy:** 100% data captured, settlement model untuk akurasi penjualan real
- **Flexibility:** Payment bisa instant, settlement, atau debt (sesuai kondisi toko)

**Core Principle:** Minimize manual input → Maximize data accuracy → Support real business model

---

## 1. OVERVIEW

### 1.1 System Scope
```
PRODUCTION (Warehouse)
    ↓
WAREHOUSE INVENTORY (Track stock ready to delivery)
    ↓
DELIVERY (Driver + Vehicle + Route)
    ↓
STORE FREEZER (IoT tracking stok real-time)
    ↓ 
SALES & SETTLEMENT (Accurate qty sold calculation)
    ↓
PAYMENT COLLECTION (Flexible: instant/settlement/debt)
    ↓
OUTSTANDING TRACKING (Auto-calculate & update)
    ↓
REPORTING & PROFIT (Dashboard + Monthly P&L)
```

### 1.2 Three Core Business Processes

**Process 1: Production → Warehouse Stock**
```
Production input: 100 ball
Reject: 5 ball
→ Good: 95 ball (auto-calc)
→ Available for delivery: 95 ball
```

**Process 2: Delivery & Confirmation (SETTLEMENT)**
```
Visit 1 (Day 1):
├─ Deliver 10 ball
├─ System: "First visit, stok awal assumed 0"
├─ Confirmed after deliver: 10 ball
└─ No sales recorded (first restock)

Visit 2 (Day 2):
├─ Driver arrive
├─ Confirm: "Stok sekarang 3 ball"
├─ System auto-calc: Sold = 10 - 3 = 7 ball ✓ (ACCURATE!)
├─ Amount: 7 × Rp 10,000 = Rp 70,000
└─ Driver collect payment for yesterday's penjualan
```

**Process 3: Payment Flexibility**
```
Model A (Settlement - Paling Akurat):
└─ Bayar esok hari saat visit, berdasarkan stok yang terjual

Model B (Instant):
└─ Bayar langsung saat delivery (estimate qty, adjust nanti)

Model C (Debt):
└─ Bayar minggu depan, fleksibel sesuai kemampuan toko
```

---

## 2. USER ROLES & COMPLETE CAPABILITIES

### 2.1 ADMIN / OWNER

**What They See:**
- Dashboard KPI (sales, collection, outstanding, stock)
- All reports (sales, P&L, collection, inventory turnover)
- User management (create/edit driver, warehouse staff)
- Expense tracking & approval
- Store & product management
- Real-time delivery tracking
- Freezer health alerts (IoT)

**What They Can Do:**

#### Daily Management
```
6:00 AM - Start Day
├─ [Open Dashboard]
│  ├─ Yesterday sales: Rp 5M
│  ├─ Yesterday collection: Rp 3.5M (70%)
│  ├─ Outstanding total: Rp 1.5M
│  ├─ 2 stores overdue > 3 days
│  ├─ Warehouse stock: 250 ball available
│  └─ 3 freezers alert: temp issue
│
└─ [VIEW DETAILS] untuk setiap KPI

8:00 AM - Monitor Operations
├─ [Check Deliveries Today]
│  ├─ Planned: 3 deliveries
│  ├─ Driver: Budi, Eka, Randi
│  ├─ Total load: 150 ball
│  └─ Target collection: Rp 1.5M
│
└─ [TRACK LIVE] untuk see progress

10:00 AM - Monitor Freezer Health 
├─ [Check IoT Alerts]
│  ├─ 🔴 FRZ-RSA-003-A: Temperature +2°C (WARNING!)
│  ├─ 🟡 FRZ-RSA-005-B: Offline 2 hours
│  └─ 🟢 50 freezers normal
│
└─ [SEND ALERT] untuk maintenance

12:00 PM - Review Collections
├─ [Check Outstanding]
│  ├─ RSA-001: Rp 50K (1 day overdue)
│  ├─ RSA-004: Rp 100K (3 days overdue)
│  └─ RSA-007: Rp 30K (pending)
│
└─ [SEND REMINDER] atau direct call

15:00 PM - Check Production
├─ [View Production Today]
│  ├─ Produced: 200 ball
│  ├─ Reject: 8 ball
│  ├─ Good: 192 ball
│  └─ Status: Posted (available for delivery)
│
└─ [APPROVE] jika needed

16:00 PM - End Day
├─ [View Daily Summary]
│  ├─ Today sales: Rp 480K
│  ├─ Today collection: Rp 350K (73%)
│  ├─ Deliveries completed: 3/3
│  ├─ Expenses today: Rp 150K
│  └─ Net: Rp 200K profit
│
└─ [PRINT REPORT] atau email dashboard
```

#### Weekly Management
```
Every Monday:
├─ [Generate Weekly Report]
│  ├─ Sales: Rp 3.5M
│  ├─ Collection rate: 92%
│  ├─ Top performer: Budi (Rp 1.2M)
│  ├─ Outstanding breakdown
│  └─ Expense breakdown
│
└─ [REVIEW & ADJUST] strategy
```

#### Monthly Management
```
End of Month:
├─ [Generate P&L Report]
│  ├─ Revenue: Rp 10M
│  ├─ Production cost: Rp 4M
│  ├─ Operating expenses: Rp 3M
│  ├─ Net profit: Rp 3M
│  └─ Profit margin: 30%
│
├─ [Analyze Trends]
│  ├─ Best performing stores
│  ├─ Worst performing stores
│  ├─ Collection vs target
│  └─ Expense breakdown
│
└─ [Make Decision]
   ├─ Increase delivery frequency for high-velocity stores
   ├─ Review low performers (increase/decrease)
   └─ Plan next month
```

#### Management Functions
```
[Store Management]
├─ [Add New Store]
│  ├─ Name, owner, phone, address, location
│  └─ [SAVE]
│
├─ [Edit Store Info]
│  └─ Update details
│
└─ [View Store Profile]
   ├─ Sales history
   ├─ Payment history
   ├─ Current outstanding
   └─ Contact info

[User Management]
├─ [Create New Driver/Warehouse Staff]
│  ├─ Name, role, phone, username
│  └─ [SAVE] → Send login link
│
├─ [Edit User]
│  ├─ Change role
│  ├─ Suspend/activate
│  └─ Reset password
│
└─ [View User Activity]
   ├─ Login history
   ├─ Actions performed
   └─ Performance metrics

[Expense Management]
├─ [Approve Expenses]
│  ├─ DRAFT expenses waiting approval
│  ├─ [APPROVE] or [REJECT]
│  └─ Comments/notes
│
└─ [Expense Report]
   ├─ Category breakdown
   ├─ Monthly total
   └─ Trend vs budget

[Settings]
├─ [Configure Products]
│  ├─ Name, weight, selling price, cost
│  └─ [SAVE]
│
├─ [Configure Warehouse]
│  ├─ Location, capacity
│  └─ [SAVE]
│
├─ [Configure Vehicles]
│  ├─ Plate, capacity
│  └─ [SAVE]
│
└─ [System Settings]
   ├─ Business rules
   ├─ Alert thresholds
   └─ Report preferences
```

---

### 2.2 WAREHOUSE STAFF

**What They See:**
- Production records
- Warehouse stock level (real-time)
- Delivery plans & status
- IoT suggestions (which stores need delivery)
- Active deliveries progress

**What They Can Do:**

#### Daily Production
```
6:00 AM - Start Shift
├─ [Open Production Screen]
│  ├─ Today's plan: 200 ball
│  └─ Status: Not started
│
7:00 AM - Record Production
├─ [Input Production]
│  ├─ Product: Es Kristal 10 KG [SELECT]
│  ├─ Date: 2026-08-25 [AUTO]
│  ├─ Qty Produced: 100 [INPUT]
│  ├─ Qty Reject: 5 [INPUT]
│  ├─ Notes: "Normal prod, no issues" [OPTIONAL]
│  └─ [SAVE AS DRAFT]
│     └─ System auto-calc: Good = 95 ball
│
└─ Status: DRAFT (saved, not yet available)

10:00 AM - Post Production (Lock & Make Available)
├─ [Open DRAFT Production]
│  ├─ Verify: Produced 100, Reject 5, Good 95 ✓
│  └─ [POST] → Lock, available for delivery
│
└─ Warehouse stock updated: +95 ball

14:00 PM - Continue Production
├─ [Input Production Round 2]
│  ├─ Produced: 100
│  ├─ Reject: 3
│  ├─ [POST]
│  └─ Total good today: 192 ball

End of Day - Production Summary
├─ [View Day Summary]
│  ├─ Total produced: 200 ball ✓
│  ├─ Total reject: 8 ball
│  ├─ Total good: 192 ball ✓
│  ├─ Available: 192 ball
│  └─ Status: POSTED
```

#### Warehouse Stock Management
```
Anytime - Check Stock Level
├─ [Open Warehouse Dashboard]
│  ├─ Available stock: 250 ball
│  │  └─ Calculation: 
│  │     ├─ Total good production: 500 ball
│  │     ├─ Already delivered: 250 ball
│  │     └─ Available: 250 ball
│  │
│  ├─ Allocated for today's delivery: 50 ball
│  ├─ Remaining safe stock: 200 ball (3 days buffer)
│  └─ Status: Healthy ✓
│
└─ [VIEW BREAKDOWN]
   └─ Detailed production log per date
```

#### Delivery Planning (IoT-Smart)
```
8:00 AM - Check IoT Suggestions
├─ [Open Smart Delivery]
│  ├─ 🔴 HIGH PRIORITY (Est stock 0):
│  │  ├─ RSA-001 (Toko Rapi): Suggest 10 ball
│  │  ├─ RSA-002 (Toko Sejahtera): Suggest 8 ball
│  │  └─ Last update: 2 hours ago
│  │
│  ├─ 🟡 MEDIUM (Est stock 3-5):
│  │  ├─ RSA-003: Suggest 5 ball
│  │  └─ Last update: 1 hour ago
│  │
│  └─ 🟢 LOW (Est stock > 5):
│     └─ RSA-007: Suggest 2 ball
│
└─ Confidence: 95% (sensor online 24h)

9:00 AM - Create Delivery
├─ [CREATE DELIVERY PLAN]
│  ├─ Driver: [Budi Santoso] [SELECT]
│  ├─ Vehicle: [B2345XYZ - Avanza] [SELECT]
│  ├─ Stores: [RSA-001, RSA-002, RSA-003, RSA-005] [MULTI-SELECT]
│  │  └─ Auto-sort by optimal route (nearest first)
│  │     └─ Show: "Route: 28 km, ~2 hours"
│  │
│  ├─ Initial Load: 50 ball [INPUT] (or auto-calc)
│  ├─ Notes: "High priority for RSA-001" [OPTIONAL]
│  └─ [CREATE & SEND TO DRIVER]
│
└─ Status: Delivery #001 created, sent to Budi's app

10:00 AM - Monitor Delivery Progress
├─ [TRACK DELIVERY]
│  ├─ Status: IN_PROGRESS
│  ├─ Current stop: RSA-002 (2/4)
│  ├─ Delivered so far: 18 ball
│  ├─ Collection progress: Rp 180K / Rp 300K target
│  └─ ETA complete: 12:00 PM
│
└─ [LIVE TRACKING MAP] (optional)

12:00 PM - Delivery Completed Notification
├─ Budi submitted: Delivery complete
│  ├─ Delivered total: 48 ball
│  ├─ Returned: 2 ball
│  ├─ Balance verified: 50 = 48 + 2 ✓
│  └─ Total sales: Rp 350K
│  └─ Total collection: Rp 280K
│
└─ Warehouse stock auto-update: 250 - 48 = 202 ball
```

#### Inventory Optimization
```
Daily - Stock Monitoring
├─ [Check Inventory Turnover]
│  ├─ RSA-001: 0.5 days (VERY FAST)
│  │  └─ Recommendation: Daily delivery
│  │
│  ├─ RSA-003: 2.5 days (MEDIUM)
│  │  └─ Recommendation: Every 2-3 days
│  │
│  └─ RSA-007: 5 days (SLOW)
│     └─ Recommendation: 2x per week
│
└─ Adjust delivery schedule accordingly
```

---

### 2.3 DRIVER

**What They See:**
- Today's delivery route
- Store list with IoT suggestions (est stock, suggested delivery)
- Map & navigation
- Payment options
- Receipt & summary

**What They Can Do:**

#### Morning - Prepare Delivery
```
7:00 AM - Check Today's Delivery
├─ [OPEN APP - LOGIN]
│
└─ [DASHBOARD]
   ├─ Status: "Delivery scheduled for today"
   ├─ Vehicle: B2345XYZ (Avanza Putih)
   ├─ Initial Load: 50 ball
   ├─ Stops: 5 toko
   ├─ Collection Target: Rp 500K
   └─ [VIEW ROUTE] → [START DELIVERY]

8:00 AM - Load at Warehouse
├─ [LOAD CONFIRMATION]
│  ├─ Warehouse staff: Verify & load 50 ball
│  ├─ Driver: [CONFIRM LOADED]
│  │  └─ System: "Loaded: 50 ball ✓"
│  │
│  └─ [PROCEED TO FIRST STOP]

└─ Navigation: Route optimized, 5 stops planned
   ├─ Stop 1: RSA-001 (5 km)
   ├─ Stop 2: RSA-002 (8 km)
   ├─ Stop 3: RSA-003 (6 km)
   ├─ Stop 4: RSA-005 (4 km)
   └─ Stop 5: RSA-007 (3 km)
```

#### At Store - SETTLEMENT WORKFLOW (Main Process)
```
10:00 AM - ARRIVE AT STOP #1 (RSA-001 - Toko Rapi)
├─ [ARRIVE NOW]
│  ├─ App: "You have arrived at RSA-001"
│  ├─ Store info: Owner (Joko), Phone, Address
│  └─ Freezers list
│
└─ FREEZER A (FRZ-RSA-001-A):
   │
   ├─ Current Status (from IoT):
   │  ├─ Estimated stock: 2 ball (from last weight)
   │  ├─ Max capacity: 10 ball
   │  ├─ Suggested delivery: 8 ball (10 - 2)
   │  ├─ Confidence: 95%
   │  └─ Last IoT update: 15 min ago
   │
   ├─ CONFIRM STOCK:
   │  ├─ App ask: "Stok terukur 2 ball, cocok? [YES] [NO]"
   │  │
   │  ├─ If YES (typical, 95% case):
   │  │  ├─ [TAP YES] ← 1 tap!
   │  │  ├─ Auto-fill: confirmed_stock = 2 ball
   │  │  ├─ Auto-fill: delivered_qty = 8 ball
   │  │  └─ Freezer ready ✓
   │  │
   │  └─ If NO (edge case, 5%):
   │     ├─ [TAP NO - MANUAL INPUT]
   │     ├─ Input: "Stok sebenarnya berapa?"
   │     ├─ Driver: Type "3"
   │     ├─ System auto-calc: Deliver = 10 - 3 = 7
   │     ├─ Display: "Deliver 7 ball, ok? [YES]"
   │     └─ [TAP YES]
   │
   └─ Freezer A: CONFIRMED ✓
      ├─ confirmed_stock: 3 ball
      ├─ delivered_qty: 7 ball
      └─ sales_calculation_ready: Previous stock - 3 = sold

SETTLEMENT CALCULATION (Auto):
├─ This is 2nd visit to RSA-001
├─ Previous confirmed stock (from yesterday): 5 ball
├─ Current confirmed stock: 3 ball
├─ Qty Sold = 5 - 3 = 2 ball ✓ (ACCURATE!)
├─ Unit price: Rp 10,000/ball
├─ Sales amount: 2 × 10,000 = Rp 20,000
└─ Status: CONFIRMED & LOCKED

SETTLEMENT PREVIEW (After all freezers confirmed):
├─ Total delivered: 7 ball
├─ Total sales: Rp 20,000 (from sold qty calc)
├─ Previous outstanding: Rp 0
├─ Total due today: Rp 20,000
│
├─ Payment options:
│  ├─ [PAY TODAY] Rp 20,000 (instant)
│  ├─ [PAY PARTIAL] (e.g., Rp 15,000)
│  ├─ [SKIP] (no payment, outstanding Rp 20,000)
│  └─ [PAY OLD DEBT] (if ada hutang sebelumnya)
│
└─ [PROCEED TO PAYMENT]
```

#### Payment Collection (3 Models Support)
```
OPTION 1: PAY TODAY (Instant Settlement)
├─ Driver: [Select PAY TODAY]
├─ Amount due: Rp 20,000 ✓
├─ Select payment method:
│  ├─ [CASH]
│  ├─ [TRANSFER] → Show bank account
│  ├─ [QRIS] → Show QR code
│  └─ [CHECK]
│
├─ If CASH:
│  ├─ Input: Amount received: Rp 20,000
│  ├─ Receipt auto-generate
│  ├─ System: Payment CONFIRMED
│  └─ Outstanding: Rp 0
│
├─ If TRANSFER:
│  ├─ Show: Bank info for transfer
│  ├─ Owner transfer via phone banking
│  ├─ Driver: [TAKE RECEIPT PHOTO]
│  │  └─ Upload proof
│  │
│  ├─ Driver: [CONFIRM TRANSFER]
│  ├─ System: Payment status PENDING (awaiting confirm from backend)
│  └─ After backend verify: CONFIRMED
│
└─ Receipt ready to print/share

OPTION 2: SKIP - Outstanding (Debt Model)
├─ Driver: [Select SKIP]
├─ System: "Outstanding Rp 20,000 not paid today"
├─ Outstanding auto-tracked: Rp 20,000
├─ Next visit:
│  ├─ Driver show: "You still owe Rp 20,000 from before"
│  ├─ Driver can: Collect today + old debt
│  └─ Payment settled
│
└─ Toko receive receipt: "Outstanding: Rp 20,000, please pay next visit"

OPTION 3: PAY MULTIPLE (Mix Yesterday + Today + Old Debt)
├─ Toko have: Today sales (Rp 20K) + Yesterday sales (Rp 50K) + Old debt (Rp 100K)
├─ Driver [PAY MULTIPLE]:
│  ├─ Today: Rp 20,000 [INPUT]
│  ├─ Yesterday: Rp 50,000 [INPUT]
│  ├─ Old debt: Rp 100,000 [INPUT]
│  ├─ Total: Rp 170,000
│  └─ [CONFIRM]
│
├─ 3 Payment records created:
│  ├─ Payment 1: Rp 20K (TODAY type)
│  ├─ Payment 2: Rp 50K (PAST_DAYS type)
│  ├─ Payment 3: Rp 100K (DEBT type)
│  └─ All: CONFIRMED
│
└─ Outstanding: Rp 0 ✓ (All settled!)

PARTIAL PAYMENT:
├─ Due: Rp 20,000
├─ Toko: "Saya hanya bisa bayar Rp 15,000 hari ini"
├─ Driver: [PAY PARTIAL] → Input Rp 15,000
├─ System:
│  ├─ Payment recorded: Rp 15,000 CONFIRMED
│  ├─ Outstanding remaining: Rp 5,000
│  ├─ Next visit show: "Still owe Rp 5,000"
│  └─ Reconciliation automatic
```

#### End of Delivery
```
15:00 PM - COMPLETE ALL STOPS
├─ 5 stops completed ✓
├─ Deliveries: 48 ball
├─ Returns: 2 ball
├─ Total sales: Rp 480,000
├─ Total collection: Rp 350,000 (73%)
├─ Outstanding: Rp 130,000
│
└─ [COMPLETE DELIVERY]

15:05 PM - RETURN TO WAREHOUSE
├─ [UNLOAD]
│  ├─ Return 2 ball to warehouse
│  ├─ Warehouse staff verify: 2 ball ✓
│  └─ [CONFIRM RETURN]
│
└─ System: Delivery #001 COMPLETED ✅

DELIVERY SUMMARY:
├─ Loaded: 50 ball
├─ Delivered: 48 ball
├─ Returned: 2 ball
├─ Balance: 50 = 48 + 2 ✓ VERIFIED
├─ Total sales: Rp 480,000
├─ Total collection: Rp 350,000
├─ Collection rate: 73%
└─ Outstanding: Rp 130,000 (tracked auto)

16:00 PM - END OF DAY
├─ Driver: Job done ✓
├─ App show: "Great job! Completed 5 stops, collected Rp 350K"
└─ Home 🏡
```

---

## 3. CORE FEATURES & WORKFLOWS

### 3.1 FEATURE: Production Management
**Capability:**
- Warehouse input daily production (produced, reject)
- System auto-calculate good qty
- Post production (lock & make available)
- Track all production records

**Acceptance Criteria:**
- ✅ Input 2 fields only: produced, reject
- ✅ Good qty auto-calculate (produced - reject)
- ✅ Status: DRAFT → POST (lock)
- ✅ Posted production available for delivery
- ✅ Production history viewable
- ✅ Daily & monthly summary

**Formula:**
```
Qty Good = Qty Produced - Qty Reject

Example:
├─ Produced: 100 ball
├─ Reject: 5 ball
└─ Good: 95 ball ✓
```

---

### 3.2 FEATURE: Warehouse Stock Tracking
**Capability:**
- Real-time warehouse stock level
- Track: production added - deliveries deducted
- View available stock for next delivery
- Inventory safety check

**Acceptance Criteria:**
- ✅ Show total available stock
- ✅ Show breakdown (what's available vs allocated)
- ✅ Auto-update after each delivery
- ✅ Alert if stock low (< 3 days buffer)
- ✅ History log of stock movement

**Formulas:**
```
Available Stock = SUM(Good Production) - SUM(Delivered to Stores)

Example:
├─ Total good produced: 500 ball
├─ Already delivered: 250 ball
├─ Available now: 250 ball ✓

Warehouse Safety Check:
├─ Available: 250 ball
├─ Today's plan delivery: 50 ball
├─ Remaining: 200 ball
├─ Average daily sales: 50 ball
├─ Days buffer: 200 / 50 = 4 days ✓ (Safe!)
```

---

### 3.3 FEATURE: IoT Stock Estimation
**Capability:**
- Freezer sensor track weight, temperature, door
- System estimate stock based on weight
- Provide confidence score
- Show suggestions to driver/warehouse

**Acceptance Criteria:**
- ✅ Show estimated stock (e.g., 2 ball)
- ✅ Show suggested delivery (e.g., 8 ball)
- ✅ Show confidence level (e.g., 95%)
- ✅ Show last update time
- ✅ Graceful fallback if sensor offline
- ✅ Alert if temperature abnormal

**Formulas:**
```
Estimated Stock = (Last Weight - Tare Weight) / Product Weight

Example:
├─ Last weight from sensor: 52.5 kg
├─ Tare weight (empty freezer): 50 kg
├─ Product weight per ball: 10 kg
├─ Net weight: 52.5 - 50 = 2.5 kg
├─ Estimated stock: 2.5 / 10 = 0.25 ≈ 0.25 ball
└─ Rounded: 0 ball (or show 0.25 ball if decimal)

Suggested Delivery = Max Capacity - Estimated Stock

Example:
├─ Max capacity: 10 ball
├─ Estimated stock: 0 ball
└─ Suggested: 10 - 0 = 10 ball ✓
```

---

### 3.4 FEATURE: Delivery Confirmation (Minimal Input)
**Capability:**
- Driver arrive at store
- System show IoT estimates (stock, suggested delivery)
- Driver confirm stock with 1 tap (typical) or manual input (edge case)
- Auto-fill delivery qty based on max capacity
- Minimal data entry, maximum accuracy

**Acceptance Criteria:**
- ✅ Pre-fill with IoT estimate
- ✅ 1-tap confirm for typical case (95%)
- ✅ Manual override for edge case (5%)
- ✅ Auto-calculate delivery qty (max - confirmed)
- ✅ Driver notes optional
- ✅ Freezer status tracked
- ✅ Zero manual calculation needed

**Workflow Example:**
```
Typical Case (95%):
├─ System: "Est stok 2 ball, suggest deliver 8. OK? [YES] [NO]"
├─ Driver: [TAP YES]
├─ Auto-fill: confirmed_stock = 2, delivered_qty = 8
└─ Status: ✓ CONFIRMED (1 tap!)

Edge Case (5%):
├─ Driver: "Stok lebih dari estimate, sebenarnya 3"
├─ Driver: [TAP NO]
├─ Input: Stock = 3
├─ System auto-calc: Deliver = 10 - 3 = 7
├─ Confirm: [YES]
└─ Status: ✓ CONFIRMED (3 inputs)
```

---

### 3.5 FEATURE: Settlement & Sales Calculation (ACCURATE)
**Capability:**
- Auto-calculate quantity sold (from stock confirmed difference)
- Create sales record automatically
- Track per freezer, per store, per visit
- Zero manual input for qty sold

**Acceptance Criteria:**
- ✅ Qty sold = previous_confirmed - current_confirmed
- ✅ Qty sold auto-calculated (zero manual)
- ✅ Sales amount = qty_sold × unit_price
- ✅ Sales record locked & immutable
- ✅ Handle first visit (no previous stock)
- ✅ Audit trail complete

**Formulas:**
```
Qty Sold = Previous Confirmed Stock - Current Confirmed Stock

Timeline Example:
├─ Day 1 (First visit):
│  ├─ Deliver: 10 ball
│  ├─ Confirmed after deliver: 10 ball
│  └─ Sales: 0 (first restock, no previous record)
│
├─ Day 2 (Second visit):
│  ├─ Confirm: Stok now 3 ball
│  ├─ Previous confirmed (from Day 1): 10 ball
│  ├─ Qty Sold = 10 - 3 = 7 ball ✓ (ACCURATE!)
│  ├─ Unit price: Rp 10,000
│  └─ Sales amount: 7 × 10,000 = Rp 70,000
│
└─ Day 3 (Third visit):
   ├─ Confirm: Stok now 1 ball
   ├─ Previous confirmed (from Day 2): 3 ball
   ├─ Qty Sold = 3 - 1 = 2 ball ✓
   ├─ Sales amount: 2 × 10,000 = Rp 20,000
   └─ Deliver: 9 ball

Total Penjualan Freezer (2 hari):
├─ Day 2: Rp 70,000
├─ Day 3: Rp 20,000
└─ Total: Rp 90,000 ✓
```

---

### 3.6 FEATURE: Flexible Payment Collection
**Capability:**
- Support 3 payment models: Settlement, Instant, Debt
- Driver collect payment with 1-3 inputs
- Payment type tracked (TODAY, PAST_DAYS, DEBT)
- Support partial payment
- Multiple payment methods (CASH, TRANSFER, QRIS)
- Receipt generation

**Acceptance Criteria:**
- ✅ 3 payment models supported
- ✅ Payment type tracked (for accounting)
- ✅ Partial payment support
- ✅ Multi-method support
- ✅ Receipt photo for TRANSFER/QRIS
- ✅ Outstanding auto-update
- ✅ Transaction auditable

**Formulas:**
```
Model A: SETTLEMENT (Paling akurat & realistic)
├─ Day 1: Deliver 10 ball (Rp 100K)
│  └─ No payment (Toko: "Bayar besok")
│
└─ Day 2: 
   ├─ Confirm: 3 ball terjual (8 ball habis)
   ├─ Sales (accurate): 8 × Rp 10K = Rp 80,000
   ├─ Driver collect: Rp 80,000 (settlement)
   └─ Outstanding: Rp 0 ✓

Model B: INSTANT (Simple, ada adjustment nanti)
├─ Day 1: Deliver 10 ball
│  └─ Estimate all sold, bayar Rp 100,000
│
└─ Day 2:
   ├─ Confirm: 3 ball terjual (7 ball sold, bukan 10)
   ├─ Difference: Rp 30,000 credit (untuk next visit)
   └─ Adjusted payment: Rp 70,000 (ngatur dengan barang next visit)

Model C: DEBT (Fleksibel, flexible payment terms)
├─ Day 1: Deliver 10 ball
│  └─ Toko: "Bayar minggu depan" (cash short)
│
├─ Day 2: No delivery (rest day)
│  └─ Outstanding: Rp 100,000 (tracked)
│
├─ Day 3: Still outstanding, no delivery
│  └─ Outstanding: Rp 100,000 (reminder sent)
│
└─ Day 8 (Minggu depan): Driver visit
   ├─ Collect: Rp 100,000 (previous debt)
   ├─ Deliver: New 10 ball
   ├─ System: Payment type = DEBT (untuk accounting)
   └─ Outstanding cleared ✓

Multi-Type Payment (Complex but supported):
├─ Day 1: Deliver 10 ball (Rp 100K) → No payment
├─ Day 2: Deliver 8 ball (Rp 80K) → No payment
│  └─ Total sales now: Rp 180K
│
├─ Day 3: Driver visit
│  ├─ Confirm: 2 ball sold from yesterday
│  │  └─ Accurate sales: Rp 70K (7 ball × 10K)
│  │
│  ├─ Total due: Rp 70K (accurate from Day 2 settlement)
│  │          + Rp 80K (new sales from Day 3)
│  │          + Rp 100K (old debt from Day 1)
│  │          = Rp 250K TOTAL
│  │
│  ├─ Driver offer:
│  │  ├─ PAY DAY 2 SALES: Rp 70K (payment_type=PAST_DAYS)
│  │  ├─ PAY DAY 3 SALES: Rp 80K (payment_type=TODAY)
│  │  ├─ PAY OLD DEBT: Rp 100K (payment_type=DEBT)
│  │  └─ Total input: Rp 250K
│  │
│  └─ System:
│     ├─ 3 payment records created (audit trail)
│     ├─ All marked CONFIRMED
│     └─ Outstanding: Rp 0 ✓ (All settled!)
```

---

### 3.7 FEATURE: Outstanding Balance Tracking
**Capability:**
- Auto-calculate outstanding per store
- Real-time update
- Show breakdown (which sales unpaid)
- Alert for overdue

**Acceptance Criteria:**
- ✅ Auto-calculate real-time
- ✅ Show total outstanding per store
- ✅ Show breakdown by date
- ✅ Alert if overdue > N days
- ✅ Dashboard visible for admin/driver
- ✅ History of outstanding changes

**Formulas:**
```
Outstanding Balance = Total Sales (all time) - Total Paid (all time)

Timeline Example:
├─ 2026-08-22: Sales Rp 100K, Paid: 0 → Outstanding: Rp 100K
├─ 2026-08-23: Sales Rp 80K, Paid: 0 → Outstanding: Rp 180K (accumulated)
├─ 2026-08-24: Sales Rp 50K, Paid: Rp 150K → Outstanding: Rp 80K
└─ 2026-08-25: Sales Rp 70K, Paid: Rp 80K → Outstanding: Rp 70K

Current Outstanding Breakdown:
├─ 2026-08-22: Rp 0 (paid 2026-08-24)
├─ 2026-08-23: Rp 0 (paid 2026-08-24)
├─ 2026-08-24: Rp 30K (partial, remaining)
└─ 2026-08-25: Rp 70K (today, not yet paid)
   └─ Total: Rp 100K ✓
```

---

### 3.8 FEATURE: Delivery Balance Verification
**Capability:**
- Auto-verify: loaded qty = delivered qty + returned qty
- Flag error if balance doesn't match
- Prevent delivery closure if not balanced
- Zero missing data

**Acceptance Criteria:**
- ✅ Auto-verify after driver complete delivery
- ✅ Show clear balance summary
- ✅ Flag if not balanced (error)
- ✅ Don't allow closure if error
- ✅ Driver fix before submitting
- ✅ Admin alerted if repeated errors

**Formulas:**
```
Balance Verification = Loaded = Delivered + Returned

Example:
├─ Loaded: 50 ball
├─ Stop 1 deliver: 10 ball
├─ Stop 2 deliver: 12 ball
├─ Stop 3 deliver: 15 ball
├─ Stop 4 deliver: 8 ball
├─ Stop 5 deliver: 3 ball
├─ Total delivered: 48 ball
├─ Returned: 2 ball
│
├─ Verification: 50 = 48 + 2 ✓ BALANCED!
└─ Status: Delivery can be closed ✓

If Not Balanced:
├─ Example: 50 ≠ 48 + 1 (missing 1 ball)
├─ System: Error! Cannot close delivery
├─ Driver: Check again, find missing ball
└─ After fix: 50 = 48 + 2 ✓ Now OK
```

---

## 4. CALCULATION FORMULAS (COMPLETE)

### 4.1 Stock & Inventory Calculations

**Formula 1: Warehouse Available Stock**
```
Available = SUM(Good Production Posted) - SUM(Delivered to Stores)
```

**Formula 2: Estimated Stock from IoT**
```
Estimated Stock (ball) = (Last Weight - Tare Weight) / Product Weight
```

**Formula 3: Suggested Delivery**
```
Suggested Delivery = Max Capacity - Estimated Stock
```

**Formula 4: Vehicle Current Load**
```
Current Load = Initial Load - SUM(Delivered so far)
```

**Formula 5: Inventory Turnover Days**
```
Turnover Days = Max Capacity / Average Daily Sales
```

### 4.2 Sales & Revenue Calculations

**Formula 6: Quantity Sold (SETTLEMENT)**
```
Qty Sold = Previous Confirmed Stock - Current Confirmed Stock
```

**Formula 7: Sales Amount**
```
Sales Amount = Qty Sold × Unit Price
```

**Formula 8: Total Sales per Store**
```
Total Sales = SUM(Sales Amount for all freezers in store)
```

**Formula 9: Collection Rate**
```
Collection Rate = Total Paid / Total Sales × 100%
```

### 4.3 Financial Calculations

**Formula 10: Outstanding Balance**
```
Outstanding = Total Sales (all time) - Total Paid (all time)
```

**Formula 11: Net Profit**
```
Net Profit = Revenue - Production Cost - Operating Expenses

Where:
- Revenue = SUM(Confirmed Sales)
- Production Cost = SUM(Good Ball Produced) × Cost per Unit
- Operating Expenses = SUM(All Expenses)
```

---

## 5. SAMPLE ENDPOINTS & RESPONSES

### Endpoint 1: Confirm Store Delivery Stop
```
POST /api/delivery/confirm-stop

Request:
{
  "delivery_id": 1,
  "store_id": 1,
  "freezer_confirmations": [
    {
      "freezer_id": 1,
      "confirmed_stock_ball": 2,
      "delivered_qty_ball": 8,
      "notes": "OK, condition good"
    }
  ]
}

Response:
{
  "success": true,
  "settlement": {
    "store_id": 1,
    "total_sales_amount": 70000,
    "amount_to_collect": 70000,
    "payment_options": {
      "can_pay_today": true,
      "can_pay_partial": true,
      "previous_outstanding": 0
    }
  }
}
```

### Endpoint 2: Collect Payment
```
POST /api/payment/collect

Request:
{
  "store_id": 1,
  "amount": 70000,
  "method": "CASH",
  "payment_type": "TODAY",
  "reference": ""
}

Response:
{
  "success": true,
  "payment": {
    "id": 1,
    "amount": 70000,
    "status": "CONFIRMED",
    "receipt_number": "PAY-20260825-001"
  },
  "outstanding_balance": 0
}
```

### Endpoint 3: Complete Delivery
```
POST /api/delivery/complete

Request:
{
  "delivery_id": 1,
  "final_qty_returned_ball": 2
}

Response:
{
  "success": true,
  "summary": {
    "total_delivered": 48,
    "total_sales": 480000,
    "total_collected": 350000,
    "collection_rate": 0.73,
    "status": "COMPLETED"
  }
}
```

### Endpoint 4: Get Dashboard Summary
```
GET /api/dashboard/summary

Response:
{
  "today": {
    "sales": 480000,
    "collection": 350000,
    "outstanding": 130000,
    "warehouse_stock": 250
  },
  "active_deliveries": 2,
  "freezer_alerts": 1,
  "collection_target": 500000,
  "collection_progress": 0.70
}
```

### Endpoint 5: Get Outstanding Tracking
```
GET /api/collection/outstanding-summary

Response:
{
  "total_outstanding": 1500000,
  "by_store": [
    {
      "store_id": 1,
      "store_name": "Toko Rapi",
      "outstanding": 100000,
      "overdue_days": 3,
      "breakdown": [
        {
          "date": "2026-08-23",
          "sales": 50000,
          "paid": 0
        }
      ]
    }
  ]
}
```

---

## 6. FEATURE ACCEPTANCE CRITERIA

### Production Feature
- ✅ Input 2 fields (produced, reject)
- ✅ Auto-calculate good qty
- ✅ Status workflow (DRAFT → POST)
- ✅ Production history & summary
- ✅ Daily output tracking

### Warehouse Feature
- ✅ Real-time stock level
- ✅ Breakdown view (allocated vs available)
- ✅ Safety check (days buffer)
- ✅ Auto-update after delivery
- ✅ IoT suggestions prioritized
- ✅ Delivery creation easy (1-2 clicks)

### Delivery Feature
- ✅ Driver 1-tap confirm per freezer (typical)
- ✅ Manual override for edge case
- ✅ IoT estimate visible with confidence
- ✅ Route optimized & navigable
- ✅ Live progress tracking
- ✅ Balance verification before close

### Sales Feature
- ✅ Auto-calculate qty sold (zero manual)
- ✅ Sales amount auto-calculated
- ✅ Sales record immutable (locked)
- ✅ Handle first visit (no previous stock)
- ✅ Per freezer, per store tracking
- ✅ Complete audit trail

### Payment Feature
- ✅ 3 models supported (Settlement, Instant, Debt)
- ✅ Payment type tracked (TODAY, PAST_DAYS, DEBT)
- ✅ Partial payment support
- ✅ Multiple methods (CASH, TRANSFER, QRIS)
- ✅ Receipt generation
- ✅ Outstanding auto-update
- ✅ No balancing errors

### Outstanding Feature
- ✅ Auto-calculate real-time
- ✅ Per store tracking
- ✅ Breakdown by date
- ✅ Overdue alert (> N days)
- ✅ Dashboard visible
- ✅ History tracking

### Dashboard Feature
- ✅ KPI cards (sales, collection, outstanding, stock)
- ✅ Real-time updates
- ✅ Active delivery tracking
- ✅ Freezer health alerts
- ✅ Collection vs target
- ✅ Clear, actionable insights

---

## 7. EFFICIENCY & ACCURACY METRICS

### Efficiency Targets
- **Driver per freezer:** < 30 seconds (1-2 taps)
- **Driver per store:** < 5 minutes (all freezers)
- **5 stores in:** < 2 hours (including payment)
- **Data entry errors:** 0 (auto-calculate)
- **Offline support:** Full capability
- **App load time:** < 3 seconds

### Accuracy Targets
- **Sales calculation:** 100% accurate (from physical stock confirm)
- **Outstanding tracking:** Real-time, 0 discrepancies
- **Delivery balance:** 0 missing data (50 = 48 + 2)
- **Payment reconciliation:** Auto, instant
- **Audit trail:** Complete, immutable
- **Inventory turnover:** Accurate, data-driven

---

## 8. APPENDIX: FORMULAS QUICK REFERENCE

```
WAREHOUSE STOCK
Available = SUM(Good Production) - SUM(Delivered)

IoT ESTIMATION
Est Stock = (Last Weight - Tare) / Product Weight
Suggested = Max Capacity - Est Stock

SALES (SETTLEMENT)
Qty Sold = Previous Confirmed - Current Confirmed
Amount = Qty Sold × Unit Price

OUTSTANDING
Outstanding = Total Sales - Total Paid

PAYMENT TYPES
- TODAY: Penjualan hari ini
- PAST_DAYS: Penjualan kemarin/sebelumnya
- DEBT: Hutang lama outstanding

DELIVERY BALANCE
Loaded = Delivered + Returned
(50 = 48 + 2 ✓)

PROFIT
Net = Revenue - Production Cost - Operating Expenses

COLLECTION RATE
Rate = Total Paid / Total Sales × 100%

INVENTORY TURNOVER
Days = Max Capacity / Avg Daily Sales
```

---

## 9. IMPLEMENTATION ROADMAP

### Phase 1: Core Delivery (Week 1-2)
- ✅ Driver app: Confirm stok, payment collection
- ✅ Warehouse: Production record, basic stock tracking
- ✅ Admin: Basic dashboard
- ✅ Settlement model (core business logic)
- ✅ Payment CASH (instant)

### Phase 2: Smart Features (Week 3-4)
- ✅ IoT integration & stock estimation
- ✅ Route optimization
- ✅ Smart delivery suggestions
- ✅ Real-time progress tracking
- ✅ Outstanding tracking

### Phase 3: Advanced Payment (Week 5)
- ✅ Multi-payment methods (TRANSFER, QRIS)
- ✅ Flexible payment (PAST_DAYS, DEBT)
- ✅ Partial payment
- ✅ Payment reconciliation

### Phase 4: Analytics & Reporting (Week 6)
- ✅ P&L report
- ✅ Collection tracking & alerts
- ✅ Inventory turnover analysis
- ✅ Sales trends & forecasting
- ✅ Export reports

---

**Version:** 1.0  
**Status:** Ready for Development  
**Last Updated:** 25 Agustus 2026
