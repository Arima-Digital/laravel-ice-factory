# Plan — Multi Produk per Kulkas

**Status:** Sudah dieksekusi. Keputusan bagian 8 sudah dijawab dan masuk ke kode.
**Tanggal:** 2026-09-29

---

## 1. Masalahnya

BRD sekarang mengasumsikan satu kulkas hanya berisi satu jenis produk. Asumsinya ada di
`product_id` pada tabel `freezers`, dan di rumus `Product Weight` yang ditulis tunggal
di BRD baris 690.

Sensor load cell hanya mengirim satu angka kilogram. Kalau kulkas berisi lebih dari satu
jenis, angka itu tidak bisa diterjemahkan menjadi komposisi. Contoh:

```
Sensor: 60 kg di dalam kulkas

1 produk  : 60 kg = 6 ball ICE-10                    → jelas
2 produk  : 60 kg = 3×ICE-10 + 2×ICE-15?             → bisa
            60 kg = 1×ICE-05 + 1×ICE-10 + 1×ICE-20?  → juga bisa
            60 kg = 6×ICE-05?                        → juga bisa
```

Empat kombinasi berbeda, semuanya menghasilkan 60 kg. Sensor tidak pernah mengirim
informasi tentang komposisi, jadi tidak ada rumus yang bisa mengembalikannya.

**Yang sudah bekerja dan tidak berubah:** aturan `1 ball = 10 kg`. Karena satuan ini tidak
bergantung pada produk, sensor tetap bisa menghitung total isi kulkas dan saran pengiriman
persis seperti sekarang.

---

## 2. Keputusan yang sudah diambil

| Topik | Keputusan | Sumber |
| --- | --- | --- |
| Satuan ball | 1 ball = 10 kg untuk semua ukuran produk | Sudah diimplementasikan |
| Pembulatan | Angka asli disimpan penuh, dibulatkan ribuan hanya saat ditampilkan | Bos programmer |
| Kulkas boleh campur | Ya | Bos programmer |
| Sumber data isi kulkas | `delivery_items`, bukan `freezers.product_id` | Bos programmer |
| Penjualan | Dicatat driver, bukan diturunkan dari berat | Bos programmer |
| Harga | Per ball, `1 ball = 10 kg` | Bos programmer |
| Payment | Tidak berubah | Bos programmer |
| Warehouse multi | Sudah ada dari awal, tidak diubah | Sudah ada di kode |

Semua keputusan terbuka di bagian 8 sudah dijawab. Lihat bagian 8.

---

## 3. Prinsip

> Sensor hanya tahu total berat. Detail produknya dicatat oleh orang.

Sensor berubah dari **sumber angka** menjadi **pemeriksa angka**.

```
SEBELUM:
  Sensor → estimated stock → auto-computed sale

SESUDAH:
  Sensor → total isi kulkas (cek saja)
  Driver → komposisi produk (sumber sebenarnya)
  Sistem → cross-check, warning kalau tidak cocok
```

---

## 4. Perubahan skema

Sudah diterapkan di `database/migrations/2026_09_29_000001_allow_multiple_products_per_freezer.php`.
Tiga perubahan kolom. Tidak ada tabel baru.

| Tabel | Aksi | Alasan |
| --- | --- | --- |
| `freezers` | hapus `product_id` | Satu kulkas tidak lagi punya satu produk |
| `delivery_items` | tambah `product_id` | Mencatat produk apa yang benar-benar ada di kulkas |
| `sales` | tambah `product_id` | Harga per produk bisa berbeda |

### 4.1 Kenapa `freezers.product_id` dihapus, bukan dibuat nullable

Kalau kolomnya tetap ada dan hanya jadi nullable, ada dua sumber data: `freezers.product_id`
dan `delivery_items.product_id`. Keduanya bisa berbeda dan tidak ada yang tahu mana yang
benar.

Kalau nullable, FE juga hanya tahu "produk fokus kulkas ini", bukan daftar produk yang
benar-benar ada. FE akan menampilkan dropdown yang salah.

Butuh tabel baru `freezer_products` kalau mau daftar lengkap per kulkas. **Keputusan ini
menolak tabel baru demi kesederhanaan**, dengan konsekuensi: FE mengetahui isi kulkas
dengan membaca `delivery_items`, dan kulkas yang belum pernah diisi delivery akan terlihat
kosong. Ini memang kondisi sebenarnya.

### 4.2 Kenapa tidak menyimpan `[1,2]` di satu kolom

Kolom `product_id` adalah `foreignId` dengan foreign key ke `products.id`
(`database/migrations/2026_08_25_000006_create_freezers_table.php:30`). Nilai `[1,2]` akan
ditolak foreign key karena bukan ID yang valid.

Menyimpan sebagai string `"1,2"` merusak relasi dan tidak bisa di-query. Menyimpan JSON
butuh raw query untuk join dan tidak bisa di-index. Keduanya membuat data bisa rusak tanpa
validasi.

### 4.3 Bentuk akhir

```
freezers                     delivery_items                  sales
├─ id                        ├─ id                           ├─ id
├─ store_id                  ├─ delivery_id                  ├─ store_id
├─ code                      ├─ store_id                     ├─ freezer_id
├─ max_capacity_ball         ├─ freezer_id                   ├─ product_id     ← BARU
└─ tare_weight_kg            ├─ product_id      ← BARU       ├─ delivery_item_id
                            ├─ confirmed_stock_before_ball  ├─ qty_ball
                            └─ delivered_qty_ball           ├─ unit_price
                                                             └─ status
                                                                ├─ PENDING     ← BARU
                                                                ├─ CONFIRMED
                                                                └─ VOID        ← BARU
```

Catatan nullable: `delivery_items.product_id` `NOT NULL`, karena baris tanpa produk bukan
pengiriman. `sales.product_id` nullable, karena penjualan boleh outlive produk yang dirujuk.

---

## 5. Perubahan kode

### 5.1 `app/Http/Controllers/Admin/DeliveryItemController.php`

`confirmFreezer()`. Perubahan:

1. **Blok `$previousItem` dihapus.** Hitungan `qtySold = previousStockAfter - currentStockBefore`
   dan pengambilan harga dari `Product::find($freezer->product_id)` tidak berlaku lagi, karena
   `freezers.product_id` sudah tidak ada dan selisih total tidak bisa diatribusikan ke produk.

2. **Validasi tambah `product_id`:**

   ```php
   'product_id' => 'required|exists:products,id',
   ```

3. **Blok auto-create `Sale` dihapus.** Penjualan sekarang diinput terpisah lewat
   `recordSale()`, bukan turunan dari selisih stok.

4. **Satu freezer bisa punya banyak baris.** Pengecekan "already confirmed" ditambah
   `->where('product_id', $request->product_id)`, supaya driver bisa menginput dua produk
   untuk kulkas yang sama dalam satu kunjungan, tanpa produk yang sama bisa diulang.

5. **Respons `sales_calculated`, `qty_sold`, `sales_amount` dihapus**, diganti `stock_check`
   berisi perbandingan sensor dengan input driver.

6. **`GET /admin/delivery-items/{id}` ikut mengembalikan `stock_check`.** Sensor terus
   melapor setelah driver pergi dan persetujuan penjualan mengubah hitungannya, jadi
   `stock_check` dihitung ulang tiap dipanggil, bukan disimpan dari saat konfirmasi.

### 5.2 Endpoint baru: pencatatan penjualan

`POST admin/delivery-items/{id}/sales`

```
{
  "product_id": 1,
  "qty_ball": 3
}
```

Respons `201` dengan `total_amount` yang sudah dihitung server dan `status` `PENDING`.

Tidak ada `unit_price` di request. Nilainya diambil dari `products.selling_price` milik
`product_id` tersebut, supaya harga tidak bisa diketik bebas dan tidak bisa berbeda per
transaksi untuk produk yang sama.

`product_id` wajib sudah pernah dikonfirmasi untuk freezer dan delivery yang sama, kalau
tidak akan ditolak 422. Tanpa itu, penjualan tidak cocok dengan pengiriman mana pun.

### 5.3 `app/Http/Controllers/Admin/SaleController.php`

Tambah endpoint approval admin:

```
POST admin/sales/{id}/approve
POST admin/sales/{id}/reject
```

`PENDING` → `CONFIRMED` atau `VOID`. Sales `PENDING` tidak dihitung ke settlement.

### 5.4 Yang TIDAK berubah

| File | Alasan |
| --- | --- |
| `DeliveryItemController::getSuggestion()` | Total kilogram, tidak bergantung produk |
| `app/Http/Controllers/Admin/PaymentController.php` | `payment_type` TODAY/PAST_DAYS/DEBT tetap |
| `app/Http/Controllers/Admin/WarehouseController.php` | Sudah multi warehouse |
| `DeliveryController::getSummary()` | `sum('delivered_qty_ball')` masih valid |
| Route prefix | Step 1 tetap di `/api`, delivery, sales, payment tetap di `/admin` |

**Nyaris nol regresi di jalur IoT dan pembayaran.** Ini yang membuat perubahan ini relatif aman.

### 5.5 Yang berubah di luar daftar di atas

Bagian-bagian ini ikut berubah karena tidak bisa dihindari:

| File | Kenapa |
| --- | --- |
| `app/Models/Freezer.php` | Relasi `product()` diganti `products()`, tapi `BALL_KG`, `estimated`, dan `suggested` tidak disentuh |
| `app/Models/Product.php` | `freezers()` lewat `delivery_items`, ditambah `deliveryItems()` dan `sales()` |
| `app/Models/Sale.php` | Scope `confirmed()` jadi dipakai settlement, summary, dan `stock_check` |
| `app/Http/Controllers/Admin/SettlementController.php` | Sum-nya sama, tapi difilter `CONFIRMED` supaya `PENDING` tidak ikut |
| `app/Http/Controllers/Admin/SaleController.php` | Summary dipisah PENDING, CONFIRMED, VOID |
| `app/Http/Controllers/Admin/ProductController.php` | Berat terkunci kalau ada delivery atau sale, delete ditolak kalau masih dipakai |
| `app/Http/Controllers/Admin/FreezerController.php` | `product_id` keluar dari create dan update, `products` masuk ke response, filter `product_id` lewat riwayat |
| `database/factories/FreezerFactory.php` | Tidak lagi membuat `product_id` |
| `database/seeders/DatabaseSeeder.php` | `product_id` pindah dari freezer ke delivery item dan sale |

---

## 6. Contoh alur

### 6.1 Kirim 1 produk (95% kasus, 1 tap)

```
Sensor kulkas A: 2 ball, kapasitas 10
Saran: 8 ball

Driver: [TAP YES] → stok 2, kirim 8 ball ICE-10
       → 1 baris delivery_items
       → stock_check: 2 + 8 - 0 = 8, sensor 2 → DRIFT 6 ball
```

Sensor masih melaporkan angka lama karena kulkas baru saja diisi, jadi drift pada
menit-menit pertama wajar. Yang penting angkanya bisa dibandingkan, bukan selalu cocok.

### 6.2 Kirim 2 produk

```
Sensor kulkas A: 2 ball, kapasitas 10
Saran: 8 ball

Driver: ICE-10 → 5 ball
Driver: ICE-15 → 3 ball
       → 2 baris delivery_items
       → stock_check: 2 + 5 + 3 - 0 = 10, sensor 2 → DRIFT 8 ball
```

Sensor tidak bisa membagi angka 10 itu jadi 5 ball es 10 KG dan 5 ball es 15 KG. Karena itu
pecahannya input driver, dan sistem tidak pernah mencoba menebaknya.

### 6.3 Penjualan 2 jenis

```
Toko beli: 3 ball ICE-10, 1 ball ICE-15

Driver input:
  POST /admin/delivery-items/1/sales  { product_id: 1, qty_ball: 3 }
  POST /admin/delivery-items/1/sales  { product_id: 2, qty_ball: 1 }

Sistem:
  Sale 1: 3 ball × Rp 10.000 = Rp 30.000  PENDING
  Sale 2: 1 ball × Rp 15.000 = Rp 15.000  PENDING
  Total tagihan: Rp 45.000

Admin: [APPROVE] keduanya
Status: CONFIRMED
```

### 6.4 Drift, sensor tidak cocok

```
Titik awal: 10 ball sebelum kunjungan
Dikirim: 8 ball
Terjual: 4 ball

stock_check: 10 + 8 - 4 = 14
Sensor: 14 ball → MATCH ✓

Kalau terjualnya 5 ball:
stock_check: 10 + 8 - 5 = 13
Sensor: 14 ball → DRIFT 1 ball (10 kg) — WARNING
```

Sistem menampilkan warning, tapi **tidak memblokir**. Driver mungkin salah ketik, atau ada
kehilangan. Admin yang memutuskan.

Penjualan yang masih `PENDING` tidak dihitung sebagai barang yang sudah keluar, jadi sebelum
admin menyetujui, `stock_check` masih menghitung barang itu masih ada. Itu disengaja:
penjualan yang belum ditinjau belum boleh dianggap selesai.

---

## 7. Update BRD

### 7.1 `BRD_ICE_FACTORY_FINAL.md`

Nomor baris sengaja tidak dicantumkan karena bergeser terus setiap dokumen diedit. Yang berubah:

| Bagian | Perubahan |
| --- | --- |
| Alur driver, freezer A | Satu freezer ditulis sebagai dua produk, konfirmasi diulang per produk, penjualan dicatat per produk, dan `stock_check` membandingkan sensor dengan catatan driver |
| Alur driver, settlement | Penjualan ditulis manual dan berstatus `PENDING` sampai admin menyetujui, bukan hasil pengurangan stok |
| Catatan piutang | Penjualan `PENDING` bukan utang, dan penjualan yang ditolak tidak pernah jadi utang |
| 3.3 IoT Stock Estimation | Pembagi sensor jadi 10 kg, angka tidak dibulatkan, dan ada bagian yang menjelaskan bahwa sensor tidak tahu komposisi kulkas |
| 3.4 Delivery Confirmation | Konfirmasi diulang per produk, satu baris per pasangan kulkas/produk |
| 3.5 Sales Recording | Judul dan seluruh isinya diganti: penjualan dicatat driver per produk, harga dari server, approval admin |
| 4.2 Formula 2 | `Estimated Stock = (Last Weight - Tare Weight) / 10` |
| Acceptance criteria dan quick reference | Sesuai alur manual dan approval |

### 7.2 `ice_factory_technical_erd_mvp.md`

| Bagian | Perubahan |
| --- | --- |
| Diagram `FREEZERS` | Kolom `product_id` dihapus |
| Diagram `DELIVERY_ITEMS` | Kolom `product_id` ditambah |
| Diagram `SALES` | Kolom `product_id` ditambah, status jadi PENDING, CONFIRMED, VOID |
| Relasi | Ditambah `PRODUCTS ||--o{ DELIVERY_ITEMS` dan `PRODUCTS ||--o{ SALES` |
| 4.4 `freezers` | Dijelaskan kenapa tidak ada `product_id` dan kenapa kapasitas boleh pecahan |
| 4.5 Stock Calculation | Pembagi tetap 10 kg, plus catatan bahwa angkanya total isi kulkas |
| 4.9 `delivery_items` | Satu baris per pasangan freezer/produk, driver input 3 hal |
| 4.10 `sales` | `qty_ball` dicatat driver, harga dari server, status tiga nilai, hanya CONFIRMED masuk settlement |
| 11. MVP Checklist | Poin sales jadi dicatat per produk dan disetujui admin |

### 7.3 Cara menjelaskan ke bos non-programmer

> "Sensor masih dipakai, untuk tahu total isi kulkas. Tapi pencatatan yang laku bukan dari
> sensor, dari driver. Dan sekarang sensor bisa cek ulang apa yang dicatat driver, jadi
> kalau ada yang keliru atau hilang, sistem kasih tahu sendiri."

---

## 8. Keputusan yang sudah diambil

### 8.1 Harga per ball atau per sak

Jadi **per ball**. `unit_price` yang tersimpan benar-benar harga 1 ball, jadi FE tidak perlu
konversi apa pun.

`unit_price` diambil dari `products.selling_price` di server, bukan dari request driver.
Kalau dari request, produk yang sama bisa terjual dengan dua harga berbeda pada kunjungan
berbeda, dan selisihnya tidak pernah terlihat.

### 8.2 Pembulatan uang

Nominal **disimpan penuh** dan tidak dibulatkan, termasuk `total_amount`. Pembulatan saat
tampil belum diterapkan seragam, jadi FE sebaiknya menampilkan angka yang sama dengan yang
server kirim untuk sementara.

Pembulanan per baris atau per total belum diputuskan. Kalau nanti diputuskan, lebih masuk akal
per total karena yang bayar toko satu tagihan.

### 8.3 Apakah `confirmed_stock_before_ball` masih dipakai

Tetap dipakai dan tetap `required`. Nilai ini adalah titik awal pembanding sensor, jadi
menghilangkannya akan menghilangkan satu-satunya angka yang bisa dipercaya di antara angka
sensor dan angka driver.

`stock_check` menghitung ulang dari kunjungan terakhir, bukan menjumlahkan seluruh riwayat
kulkas. Menjumlahkan riwayat akan menghitung ulang isi yang sudah tercakup di titik awal dan
selalu melaporkan selisih. Ini sudah ada testnya.

### 8.4 Apakah production perlu `product_id` per kulkas

Tidak perlu perubahan. `productions.product_id` sudah menunjuk batch yang dibuat, dan
`delivery_items.product_id` yang menunjuk produk yang benar-benar dikirim ke kulkas.

---

## 9. Urutan pengerjaan

Semua langkah sudah dikerjakan dan diuji.

| # | Langkah | Hasil |
| --- | --- | --- |
| 1 | Migration | `2026_09_29_000001_allow_multiple_products_per_freezer`, sudah diuji up, down, dan up lagi di MySQL dengan data nyata |
| 2 | `Freezer` model dan `FreezerController` | `product_id` hilang dari form dan response, `products` ikut, filter `product_id` lewat riwayat |
| 3 | `DeliveryItemController::confirmFreezer()` | Satu baris per pasangan kulkas/produk, tidak lagi membuat sale |
| 4 | `POST admin/delivery-items/{id}/sales` | `recordSale()`, harga dari server, hanya produk yang sudah dikirim |
| 5 | `SaleController` | `approve`/`reject`, summary dipisah PENDING, CONFIRMED, VOID |
| 6 | `SettlementController` | Hanya CONFIRMED, `pending_sales` dilaporkan terpisah |
| 7 | Test | `tests/Feature/Delivery/SaleApprovalTest.php`, 20 test. Seluruh suite 95 test hijau |
| 8 | Swagger | `openapi.json` 66 path, 90 operasi, 3 endpoint baru, `StockCheck` baru |
| 9 | BRD dan ERD | Bagian 3.4, 3.5, 4.2, dan bagian driver selesai; ERD sudah ikut |

### 9.1 Dua hal yang tidak terlihat di rencana

**Rollback MySQL butuh urutan khusus.** Index komposit `(freezer_id, product_id)` yang ditambahkan
`up()` ternyata menjadi satu-satunya index yang diawali `freezer_id`, jadi InnoDB memperlakukannya
sebagai index pendukung foreign key `freezer_id` dan menolak menghapusnya. `down()` harus melepas
foreign key freezer, menghapus index, menghapus kolom, lalu memasang foreign key freezer lagi.

Ditambah MySQL tidak menghapus index komposit kalau hanya satu kolomnya yang dihapus, jadi tanpa
`dropIndex()` eksplisit `up()` berikutnya gagal dengan `Duplicate key name`. Jalur `up → down → up`
sudah diulang dua kali tanpa gagal.

**Rollback tidak bisa dibalik.** `down()` harus memilih satu produk untuk tiap kulkas, jadi kulkas
yang pernah berisi dua produk akan menyempit jadi produk yang tercatat terakhir. Roll-forward
setelah itu akan menempelkan produk tunggal itu ke semua delivery item dan sale kulkas tersebut.
Rollback di database yang riwayatnya penting berarti produk yang lebih lama hilang.

### 9.2 Yang perlu diketahui FE

| Perubahan |ampak |
| --- | --- |
| `GET /api/freezers` tidak lagi mengembalikan `product_id` | Pakai array `products`, yang isinya produk yang pernah dikirim |
| `POST /api/freezers` tidak lagi menerima `product_id` | Kulkas dibuat kosong |
| `POST /admin/delivery-items/confirm` wajib `product_id` | Satu panggilan per produk per kulkas |
| Respons `confirm` tidak lagi punya `sales_calculated` | Ada `stock_check` sebagai gantinya |
| `GET /admin/delivery-items/{id}` membungkus `delivery_item` dan menambah `stock_check` | Bentuk `data` berubah |
| `GET /admin/sales` punya `summary.PENDING` | Total utama tetap hanya CONFIRMED |
