DAY 1: PRODUCTION & DELIVERY
═══════════════════════════════════════════════════════════

STEP 1: Warehouse Create Production
POST /admin/productions
├─ Product: ICE-10 (10kg ball, Rp 10,000 per ball)
├─ Qty Produced: 50 ball
├─ Qty Rejected: 5 ball
├─ Qty Good: 45 ball (auto-calculated)
└─ Status: DRAFT

VERIFY:
✓ qty_good_ball = 50 - 5 = 45 ✓


STEP 2: Warehouse POST Production (Lock & Make Available)
POST /admin/productions/{id}/post
└─ Status: DRAFT → POSTED

VERIFY:
✓ Stock available = 45 ball ✓
✓ Can proceed to delivery ✓


STEP 3: Check Available Stock
GET /admin/warehouses/{id}/available-stock
├─ Total POSTED productions: 45 ball
├─ Minus already delivered: 0 ball
└─ Available: 45 ball ✓

VERIFY:
✓ Real-time calculation working ✓


STEP 4: Warehouse Plan Delivery
POST /admin/deliveries
├─ Driver: Supir A
├─ Vehicle: VH-001
├─ Warehouse: WH-001
├─ Total Load: 30 ball (dari 45 available)
└─ Status: DRAFT

VERIFY:
✓ Load 30 <= Available 45 ✓
✓ Delivery created DRAFT ✓

STEP 5: Driver Start Delivery
POST /admin/deliveries/{id}/start
├─ Re-check stock: still 45 available? ✓
└─ Status: DRAFT → IN_PROGRESS

VERIFY:
✓ Stock re-validated ✓
✓ Can proceed to freezers ✓

step 4-5
✅ BUKAN otomatis dari IoT notification!
✅ Ini MANUAL business decision oleh warehouse staff

Prosesnya:
─────────────────────────────────────────────

1️⃣ Warehouse staff check IoT suggestions (informational):
2️⃣ Warehouse staff DECIDE: "Hari ini kita delivery ke mana?"


STEP 6: Driver Visit Store A (Freezer 1)
═══════════════════════════════════════════════════════════

GET /admin/freezers/{freezerId}/suggestion
├─ Last IoT weight: 89.5 kg (tare 9.5 kg) 
├─ Net weight: 80 kg
├─ Product weight: 10 kg/ball
├─ Estimated stock: 80/10 = 8 ball
├─ Max capacity: 10 ball
├─ Suggested delivery: 10-8 = 2 ball
├─ Last seen: 30 min ago
└─ Confidence: HIGH ✓

VERIFY:
✓ IoT suggestion formula correct ✓


DRIVER CONFIRM: "Saya check fisik, ternyata 7 ball (bukan 8)"
POST /admin/delivery-items/confirm
├─ delivery_id: 1
├─ freezer_id: 1
├─ confirmed_stock_before: 7 ball (DRIVER OVERRIDE IoT!)
├─ delivered: 3 ball
├─ Status: DRAFT → in delivery_items
└─ Sales: NONE (first visit, no previous delivery)

VERIFY:
✓ Driver manual override accepted ✓
✓ No sales created (first visit) ✓
✓ Delivered qty: 3 ball ✓
✓ Stock after: 7 + 3 = 10 ball ✓


STEP 7: Driver Visit Store A (Freezer 2)
═══════════════════════════════════════════════════════════

GET /admin/freezers/{freezerId}/suggestion
├─ Last stock: 5 ball
├─ Max capacity: 10
├─ Suggested: 5 ball
└─ Confidence: MEDIUM

DRIVER CONFIRM:
POST /admin/delivery-items/confirm
├─ confirmed_stock_before: 5 ball
├─ delivered: 4 ball
└─ Status: confirmed

AUTO-SALES CALCULATION:
Previous delivery (from Freezer 1):
  ├─ previous_confirmed_before: 7 ball
  ├─ previous_delivered: 3 ball
  ├─ previous_stock_after: 7 + 3 = 10 ball
  
Current stock (Freezer 2):
  └─ current_confirmed_before: 5 ball
  
Sales qty: 10 - 5 = 5 ball ❌ ERROR!

WAIT - This doesn't make sense...
Let me reconsider: Freezer 2 is different freezer!
Previous delivery for Freezer 2 should be from previous delivery round.

SCENARIO CORRECTION:
Freezer 2 had previous delivery:
├─ previous_confirmed_before: 8 ball
├─ previous_delivered: 2 ball
├─ previous_stock_after: 8 + 2 = 10 ball

Current: 5 ball
Sales: 10 - 5 = 5 ball ✓

Auto-create Sale:
  ├─ qty_ball: 5
  ├─ total_amount: 5 × 10,000 = Rp 50,000
  ├─ Store: A
  ├─ Freezer: 2
  ├─ status: UNPAID
  └─ created_at: now()

VERIFY:
✓ Sales auto-created ✓
✓ Amount calculated: 5 ball × Rp 10,000 = Rp 50,000 ✓
✓ Status: UNPAID ✓


STEP 8: Driver Visit Store B (Freezer 3)
═══════════════════════════════════════════════════════════

Previous delivery:
├─ confirmed_before: 6 ball
├─ delivered: 3 ball  
├─ stock_after: 9 ball

Current confirmed: 4 ball
Sales: 9 - 4 = 5 ball
Amount: 5 × 10,000 = Rp 50,000 ✓

Auto-create Sale:
└─ Store B, Freezer 3, Rp 50,000


STEP 9: Driver Complete Delivery
═══════════════════════════════════════════════════════════

Total loaded: 30 ball
Total delivered: 3 + 4 + 3 = 10 ball
Total returned: 30 - 10 = 20 ball

POST /admin/deliveries/{id}/complete
├─ returned_qty: 20 ball
└─ Balance check: 30 = 10 + 20? ✓ YES!

Status: IN_PROGRESS → COMPLETED ✓

VERIFY:
✓ Balance verification passed ✓
✓ Delivery completed ✓


CHECK STOCK AFTER DELIVERY:
═══════════════════════════════════════════════════════════

GET /admin/warehouses/{id}/available-stock
├─ Total POSTED: 45 ball
├─ Minus delivered: 10 ball
└─ Available: 35 ball ✓

VERIFY:
✓ Stock decreased correctly ✓
✓ Real-time calculation updated ✓


DAY 2: PAYMENT & SETTLEMENT
═══════════════════════════════════════════════════════════

CHECK SALES:
GET /admin/stores/1/sales
├─ Freezer 2: Rp 50,000 (5 ball) - Status: UNPAID
├─ Freezer 3: Rp 50,000 (5 ball) - Status: UNPAID
└─ Total: Rp 100,000

VERIFY:
✓ Sales listed correctly ✓
✓ Totals calculated ✓


CHECK OUTSTANDING BEFORE PAYMENT:
GET /admin/stores/1/settlement
├─ Total Sales: Rp 100,000
├─ Total Paid (CONFIRMED): Rp 0
├─ Outstanding: Rp 100,000
├─ Status: DEBT
└─ Days Outstanding: 0

VERIFY:
✓ Outstanding calculated correctly ✓


STORE COLLECT PAYMENT (TODAY - Rp 100,000):
POST /admin/payments/collect
├─ store_id: 1
├─ amount: 100,000
├─ method: CASH
├─ payment_type: TODAY
└─ status: CONFIRMED (auto, karna CASH)

VERIFY:
✓ Payment created immediately CONFIRMED ✓
✓ No receipt needed for CASH ✓


CHECK OUTSTANDING AFTER PAYMENT:
GET /admin/stores/1/settlement
├─ Total Sales: Rp 100,000
├─ Total Paid (CONFIRMED): Rp 100,000
├─ Outstanding: Rp 0
├─ Status: SETTLED ✓

VERIFY:
✓ Outstanding reset to 0 ✓
✓ Full settlement ✓


SCENARIO SUMMARY:
═══════════════════════════════════════════════════════════
✓ Production: 45 ball created
✓ Stock: Real-time reduced (45 → 35 available)
✓ Sales: Auto-created Rp 100,000 (2 sales × Rp 50,000)
✓ Payments: Rp 100,000 CONFIRMED via CASH
✓ Outstanding: Settled (0 balance)
✓ Full workflow complete! 🎉