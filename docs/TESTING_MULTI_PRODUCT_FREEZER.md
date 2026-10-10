# Testing Flow - Multi Product Freezer

Dokumen ini dibuat berdasarkan BRD (patokan utama alur) sebagai panduan testing terstruktur.
Tujuannya: bisa diuji step-by-step dari Production → Warehouse → Delivery → Sales, termasuk case multi-produk.

> Catatan:
> - 1 ball = 10 kg, 0.5 ball = 5 kg
> - Production boleh pecahan (decimal). Suggestion/Estimated pakai kelipatan 0.5 ball
> - Freezer pakai **komposisi per produk (transaksi-based)**, bukan stok per produk style gudang. Total tetap dari IoT.
> - `delivery_items` = kwitansi tiap transaksi (barang apa yang dibawa, berapa). `freezer_product_compositions` = agregat isi kulkas saat ini.

---

## Contoh Data (dipakai di seluruh dokumen)

Asumsi ID setelah seeding:

| Entitas | ID | Keterangan |
|---|---|---|
| Warehouse | 1 | Gudang Utama (WH-01) |
| Product A | 1 | Es Kristal 10 KG, harga Rp 10.000/ball |
| Product B | 2 | Es Kristal 15 KG, harga Rp 15.000/ball |
| Store | 1 | Toko Rapi |
| Freezer | 1 | FRZ-RSA-001-A (max 10 ball), milik Store 1 |
| Driver | 2 | driver_1 |
| Vehicle | 1 | B2345XYZ |

Saldo awal: Warehouse 1 punya 194,5 ball (dari production), semua freezer kosong.

---

## 1. Prerequisites

- [ ] Login tiap role → simpan token: `ADMIN_TOKEN`, `WAREHOUSE_TOKEN`, `DRIVER_TOKEN`
- [ ] Warehouse, Driver, Vehicle, 2 Product, Store + Freezer tersedia

```text
POST /api/auth/login
Content-Type: application/json

{ "username": "admin", "password": "password123" }
```

Response:
```json
{
  "success": true,
  "data": { "token": "1|xxxxxxxx", "user": { "id": 1, "role": "ADMIN" } }
}
```

---

## 2. Production → Warehouse Stock

### 2.1 Create Production (DRAFT)
```text
POST /api/productions
Authorization: Bearer <WAREHOUSE_TOKEN>
Content-Type: application/json
```
Request body:
```json
{
  "product_id": 1,
  "warehouse_id": 1,
  "production_date": "2026-10-08",
  "qty_produced_ball": 200,
  "qty_reject_ball": 5.5
}
```
Response (201):
```json
{
  "success": true,
  "message": "Production created successfully",
  "data": {
    "id": 1,
    "product_id": 1,
    "warehouse_id": 1,
    "qty_produced_ball": "200.00",
    "qty_reject_ball": "5.50",
    "qty_good_ball": "194.50",
    "status": "DRAFT"
  }
}
```

> `warehouse_id` wajib. Tanpa itu masuk `unassigned_production_qty` dan tidak muncul di `available_stock`. `qty_good_ball` dihitung otomatis (200 − 5,5 = 194,5), pecahan sah.

### 2.2 Post Production (LOCK & Available)
```text
POST /api/productions/1/post
Authorization: Bearer <WAREHOUSE_TOKEN>
```
Response (200): `"status": "POSTED"`.

### 2.3 Cek Warehouse Stock
```text
GET /api/warehouses/1/available-stock
Authorization: Bearer <WAREHOUSE_TOKEN>
```
Response (200):
```json
{
  "success": true,
  "data": {
    "warehouse_id": 1,
    "warehouse_code": "WH-01",
    "warehouse_name": "Gudang Utama",
    "total_produced_posted": 194.5,
    "total_delivered": 0,
    "available_stock": 194.5,
    "unassigned_production_qty": 0
  }
}
```

> Patokan BRD: `Available Stock = SUM(Good Production POSTED) - SUM(Delivered)`.

---

## 3. Smart Delivery Suggestions (IoT-based)

```text
GET /api/deliveries/suggestions
Authorization: Bearer <WAREHOUSE_TOKEN>
```
Response (200):
```json
{
  "success": true,
  "data": [
    {
      "store_id": 1,
      "store_name": "Toko Rapi",
      "freezer_code": "FRZ-RSA-001-A",
      "suggest_ball": 10,
      "tier": "HIGH"
    }
  ]
}
```

> Saran selalu TOTAL ball (kelipatan 0,5), bukan per produk. Sumber kebenaran stok per produk tetap gudang (production), bukan sensor.

---

## 4. Create Delivery Plan (DRAFT)

### 4.1 Create Delivery
```text
POST /api/deliveries
Authorization: Bearer <ADMIN_TOKEN>
Content-Type: application/json
```
Request body:
```json
{
  "driver_id": 2,
  "vehicle_id": 1,
  "warehouse_id": 1,
  "delivery_date": "2026-10-08",
  "initial_qty_loaded_ball": 50,
  "stores": [1]
}
```
Response (201):
```json
{
  "success": true,
  "message": "Delivery plan created successfully",
  "data": {
    "id": 16,
    "status": "DRAFT",
    "initial_qty_loaded_ball": "50.00",
    "total_qty_delivered_ball": "0.00",
    "stops": [
      { "store_id": 1, "sequence": 1, "status": "PENDING" }
    ]
  }
}
```

### 4.2 Get Delivery Detail (cek totals)
```text
GET /api/deliveries/16
Authorization: Bearer <ADMIN_TOKEN>
```
Response (200):
```json
{
  "success": true,
  "data": {
    "id": 16,
    "status": "DRAFT",
    "initial_qty_loaded_ball": "50.00",
    "total_qty_delivered_ball": "0.00",
    "deliveryItems": []
  },
  "totals": {
    "initial_qty_loaded_ball": 50,
    "total_qty_delivered_ball": 0,
    "remaining_ball": 50,
    "items_count": 0
  }
}
```

> `data.total_qty_delivered_ball` dihitung **live** dari item yang sudah dikonfirmasi. `totals` merangkum berapa turun & sisa di mobil.
>
> Endpoint ini punya 2 kegunaan: **sebelum berangkat** (lihat barang yang dibawa + rute, delivered masih 0) **dan selama rute** (setelah driver confirm, angka otomatis naik). Tidak perlu endpoint lain untuk melihat sisa load.

### Siapa approve apa (penting, jangan dicampur)

| Aksi | Siapa | Endpoint | Butuh approve? | Efek |
|---|---|---|---|---|
| Barang turun | Driver | `POST /api/delivery-items/confirm` | **Tidak** | load − , komposisi + , stok gudang − |
| Catat jual | Driver | `POST /api/delivery-items/{deliveryItemId}/sales` | Tidak (PENDING) | belum ada efek |
| Setujui jual | Admin | `POST /api/sales/{saleId}/approve` | **Ya** | piutang + , komposisi − |
| Tolak jual | Admin | `POST /api/sales/{saleId}/reject` | **Ya** | tak ada efek |

> Penurunan barang (load) **tidak menunggu approval** — begitu driver `confirm` sukses, `delivery_items` tercatat dan sisa load langsung ter-update. Approval admin hanya untuk **penjualan**, dan approval itu **tidak** mengubah load mobil (hanya komposisi freezer + piutang).

### 4.3 Post Delivery (DRAFT → POSTED)
```text
POST /api/deliveries/16/post
Authorization: Bearer <ADMIN_TOKEN>
```
Response (200): `"status": "POSTED"`, immutable.

---

## 5. Start Delivery (Driver)

```text
POST /api/deliveries/16/start
Authorization: Bearer <DRIVER_TOKEN>
```
Response (200): `"status": "IN_PROGRESS"`, `started_at` terisi.

---

## 6. Arrive & Confirm Freezer (Multi-Product)

### 6.1 Arrive at Store (opsional)
```text
POST /api/deliveries/16/stores/1/arrive
Authorization: Bearer <DRIVER_TOKEN>
```

### 6.2 Confirm Freezer - Produk 1 (LOAD-IN)
```text
POST /api/delivery-items/confirm
Authorization: Bearer <DRIVER_TOKEN>
Content-Type: application/json
```
Request body:
```json
{
  "delivery_id": 16,
  "store_id": 1,
  "freezer_id": 1,
  "product_id": 1,
  "confirmed_stock_before_ball": 2.0,
  "delivered_qty_ball": 3.0
}
```
Response (201):
```json
{
  "success": true,
  "message": "Freezer confirmed successfully",
  "data": {
    "delivery_item": {
      "id": 101,
      "delivery_id": 16,
      "freezer_id": 1,
      "product_id": 1,
      "confirmed_stock_before_ball": "2.00",
      "delivered_qty_ball": "3.00"
    },
    "stock_check": {
      "sensor_stock_ball": 4.5,
      "confirmed_stock_before_ball": 2,
      "delivered_ball": 3,
      "sold_ball": 0,
      "expected_stock_ball": 5,
      "drift_ball": -0.5,
      "result": "MATCH"
    }
  }
}
```
> `delivery_items` row dibuat (per pasangan freezer/produk). Komposisi freezer `(freezer 1, product 1)` bertambah 3,0.

### 6.3 Confirm Freezer - Produk 2 (Multi-Product)
```text
POST /api/delivery-items/confirm
Authorization: Bearer <DRIVER_TOKEN>
```
Request body:
```json
{
  "delivery_id": 16,
  "store_id": 1,
  "freezer_id": 1,
  "product_id": 2,
  "confirmed_stock_before_ball": 1.0,
  "delivered_qty_ball": 2.0
}
```
Response (201): `data.delivery_item.id = 102`.
> Row kedua (beda product_id). Duplikat `(freezer+product)` dalam delivery sama → 422.

### 6.4 Cek Freezer Compositions
```text
GET /api/freezers/1/compositions
Authorization: Bearer <DRIVER_TOKEN>
```
Response (200):
```json
{
  "success": true,
  "message": "Freezer product compositions retrieved successfully",
  "data": [
    { "id": 1, "freezer_id": 1, "product_id": 1, "qty_ball": "3.00",
      "product": { "id": 1, "code": "ICE-10", "name": "Es Kristal 10 KG" } },
    { "id": 2, "freezer_id": 1, "product_id": 2, "qty_ball": "2.00",
      "product": { "id": 2, "code": "ICE-15", "name": "Es Kristal 15 KG" } }
  ]
}
```
> Ini "isi kulkas saat ini per produk" dari transaksi driver, bukan hasil bagi total IoT.

### 6.5 Cek Delivery Items
```text
GET /api/deliveries/16/items
Authorization: Bearer <WAREHOUSE_TOKEN>
```
Response (200): `count: 2`, tiap item ada `product_id` + `delivered_qty_ball`.

### 6.6 Cek Delivery Detail (totals terupdate)
```text
GET /api/deliveries/16
Authorization: Bearer <DRIVER_TOKEN>
```
Response (200):
```json
{
  "success": true,
  "data": { "id": 16, "total_qty_delivered_ball": "5.00" },
  "totals": {
    "initial_qty_loaded_ball": 50,
    "total_qty_delivered_ball": 5,
    "remaining_ball": 45,
    "items_count": 2
  }
}
```

### 6.7 Cek Warehouse Stock (sudah terpotong saat load-out)
```text
GET /api/warehouses/1/available-stock
```
Response (200): `total_delivered: 5`, `available_stock: 189.5`.
> Barang yang sudah di-confirm driver (dibawa keluar gudang) langsung mengurangi stok gudang.

---

## 7. Record Sales (Per Produk, PENDING)

### 7.1 Record Sale - Produk 1
```text
POST /api/delivery-items/101/sales
Authorization: Bearer <DRIVER_TOKEN>
Content-Type: application/json
```
Request body:
```json
{ "product_id": 1, "qty_ball": 2.0 }
```
Response (201):
```json
{
  "success": true,
  "data": {
    "sale": {
      "id": 301,
      "freezer_id": 1,
      "product_id": 1,
      "qty_ball": "2.00",
      "unit_price": "10000.00",
      "total_amount": "20000.00",
      "status": "PENDING"
    }
  }
}
```

### 7.2 Record Sale - Produk 2
```text
POST /api/delivery-items/102/sales
Authorization: Bearer <DRIVER_TOKEN>
```
Request body:
```json
{ "product_id": 2, "qty_ball": 1.0 }
```
Response (201): `sale.id = 302`, `unit_price = 15000`, `status = PENDING`.

> Harga diambil dari produk di server, driver tidak mengirim harga. Produk harus pernah di-deliver ke freezer tsb di delivery ini.

### 7.3 Admin Approve / Reject Sale
```text
POST /api/sales/301/approve
Authorization: Bearer <ADMIN_TOKEN>
```
Response (200):
```json
{ "success": true, "message": "Sale approved", "data": { "id": 301, "status": "CONFIRMED" } }
```

```text
POST /api/sales/302/reject
Authorization: Bearer <ADMIN_TOKEN>
```
Response (200): `"status": "VOID"`.

Efek:
- `approve` → CONFIRMED → masuk piutang/outstanding **dan komposisi freezer − qty** (barang keluar).
- `reject` → VOID → tidak masuk piutang, **komposisi tidak berubah**, baris tetap ada (audit).

### 7.4 Verifikasi Komposisi Setelah Approve
```text
GET /api/freezers/1/compositions
```
Response (200):
```json
{
  "success": true,
  "data": [
    { "product_id": 1, "qty_ball": "1.00" },
    { "product_id": 2, "qty_ball": "2.00" }
  ]
}
```
> Product 1: 3,0 − 2,0 = 1,0. Product 2 tetap 2,0 (sale-nya VOID). Dibatasi minimum 0.

---

## 8. Depart / Stop Settlement (opsional)

```text
POST /api/deliveries/16/stores/1/depart
Authorization: Bearer <DRIVER_TOKEN>
```
Atau cek preview:
```text
GET /api/deliveries/16/stores/1/settlement-preview
Authorization: Bearer <DRIVER_TOKEN>
```
Response (200): total penjualan CONFIRMED + outstanding sebelumnya.

---

## 9. Complete Delivery

```text
POST /api/deliveries/16/complete
Authorization: Bearer <DRIVER_TOKEN>
Content-Type: application/json
```
Request body:
```json
{ "total_qty_returned_ball": 45.0 }
```
Response (200):
```json
{
  "success": true,
  "message": "Delivery completed successfully",
  "summary": {
    "delivery_id": 16,
    "status": "COMPLETED",
    "loaded": "50.00",
    "delivered": 5,
    "returned": "45.00",
    "balance_verified": true
  }
}
```
> Balance: loaded 50 = delivered 5 + returned 45 ✓. Kalau tidak cocok → 422 dengan detail loaded/delivered/returned.

---

## 10. Verifikasi Akhir

- [ ] `GET /api/deliveries/16` → `totals` akurat (berapa turun, berapa sisa)
- [ ] `GET /api/deliveries/16/items` → tiap produk tercatat (`product_id` + `delivered_qty_ball`)
- [ ] `GET /api/freezers/1/compositions` → komposisi per produk sesuai realita driver
- [ ] Komposisi naik saat confirm, turun saat sale `CONFIRMED`, tetap saat `VOID`
- [ ] `GET /api/warehouses/1/available-stock` turun sesuai `delivery_items` (`available_stock = produced_posted − delivered`)
- [ ] Drift tolerance 0,5 tetap dipakai (`stock_check`)
- [ ] Production tetap boleh pecahan (5,5 / 194,5)

---

## Notes - Bug yang dicatat (deliveries/16)

Saat driver sudah menurunkan barang, di `deliveries/{deliveryId}` angka "sudah turun / sisa" harusnya ter-update.
Dengan `totals` di show() + `delivery_items.delivered_qty_ball` yang terisi saat confirm, ini sekarang terlihat.

Jika masih 0:
1. Cek `GET /api/deliveries/{deliveryId}/items` → ada items?
2. Cek field `delivered_qty_ball` terisi (bukan 0)
3. Pastikan confirm pakai `POST /api/delivery-items/confirm`
4. Cek response confirm sukses (201)

---

## Testing Tips

- Pakai 2 produk beda di freezer yang sama untuk validasi multi-produk (rule: unik freezer+produk per delivery)
- Confirm produk sama 2× → 422 "This product already confirmed..."
- Komposisi **bertambah** (load-in), bukan overwrite
- Uji approve lalu reject: hanya approve yang mengubah komposisi
- Bandingkan `available-stock` sebelum vs sesudah confirm untuk melihat stok gudang terpotong saat load-out