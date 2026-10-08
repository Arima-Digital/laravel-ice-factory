# TAHAP 2 — MULTI-GUDANG & MULTI-PRODUK: DAFTAR API YANG TERLIBAT

**Untuk siapa:** Backend, tim API (frontend), dan tester.
**Acuan:** `BRD_ICE_FACTORY_FINAL.md`, `ice_factory_technical_erd_mvp.md`
**Status:** Sudah diimplementasikan, 108 test hijau

> Kontrak lengkap tetap ada di Swagger. File ini menjawab satu pertanyaan saja:
> **API mana saja yang berubah di tahap ini, dan apa yang harus menyesuaikan diri.**

---

## Ringkasan Dua Hal yang Terjadi

Tahap ini sebenarnya dua pekerjaan yang saling mengunci:

1. **Semua path pindah dari `/admin/*` ke `/api/*`.** Tidak ada alias, tidak ada redirect.
2. **`productions.warehouse_id` ditambahkan** supaya stok benar-benar dihitung per gudang.

Keduanya belum bisa dipisah: kalau frontend sudah pindah ke `/api` tapi stok masih global,
angka yang tampil di layar tidak bisa dipercaya. Kalau stok sudah per gudang tapi frontend
masih panggil `/admin`, tidak ada yang berubah sama sekali dari sisi user.

---

## 1. Perubahan Path: `/admin/*` → `/api/*`

Semua endpoint transaksi dipindahkan. Ini **breaking change**: tidak ada alias, sehingga
frontend yang masih memanggil `/admin/*` akan mendapat 404 sampai diperbarui.

Aturan pemetaannya sepele: ganti awalan `/admin` menjadi `/api`. Nama path setelahnya tidak
berubah, jadi yang perlu diedit di frontend hanya prefix-nya.

| Metode | Lama (404 sekarang) | Baru | Role |
| --- | --- | --- | --- |
| **Dashboard** | | | |
| GET | `/admin/dashboard/summary` | `/api/dashboard/summary` | ADMIN |
| GET | `/admin/dashboard/daily` | `/api/dashboard/daily` | ADMIN |
| GET | `/admin/dashboard/weekly` | `/api/dashboard/weekly` | ADMIN |
| GET | `/admin/dashboard/monthly-pl` | `/api/dashboard/monthly-pl` | ADMIN |
| **Production** | | | |
| GET | `/admin/productions` | `/api/productions` | ADMIN, WAREHOUSE |
| POST | `/admin/productions` | `/api/productions` | ADMIN, WAREHOUSE |
| GET | `/admin/productions/{id}` | `/api/productions/{id}` | ADMIN, WAREHOUSE |
| PUT | `/admin/productions/{id}` | `/api/productions/{id}` | ADMIN, WAREHOUSE |
| DELETE | `/admin/productions/{id}` | `/api/productions/{id}` | ADMIN, WAREHOUSE |
| POST | `/admin/productions/{id}/post` | `/api/productions/{id}/post` | ADMIN, WAREHOUSE |
| GET | `/admin/productions/date/{date}` | `/api/productions/date/{date}` | ADMIN, WAREHOUSE |
| GET | `/admin/productions/status/{status}` | `/api/productions/status/{status}` | ADMIN, WAREHOUSE |
| **Warehouse** | | | |
| GET | `/admin/warehouses` | `/api/warehouses` | ADMIN, WAREHOUSE |
| POST | `/admin/warehouses` | `/api/warehouses` | ADMIN |
| GET | `/admin/warehouses/{id}` | `/api/warehouses/{id}` | ADMIN, WAREHOUSE |
| PUT | `/admin/warehouses/{id}` | `/api/warehouses/{id}` | ADMIN |
| DELETE | `/admin/warehouses/{id}` | `/api/warehouses/{id}` | ADMIN |
| GET | `/admin/warehouses/{id}/available-stock` | `/api/warehouses/{id}/available-stock` | ADMIN, WAREHOUSE |

### Ringkasan 44 path

Semua 44 path `/admin/*` pindah ke `/api/*`. Path master data (auth, users, products, stores,
vehicles, warehouses, freezers, drivers) **tidak berubah posisinya** — semuanya sudah `/api`
sejak tahap 1.

Dua berubah di luar pola sederhana:

| Apa | Lama | Baru | Kenapa |
| --- | --- | --- | --- |
| Webhook IoT | `/admin/iot/logs` | `/api/iot/logs` | Alias lama sudah menunjuk action yang sama, jadi cukup dihapus |
| Sync IoT | *(tidak ada di spec)* | `/api/iot/sync` | Route-nya selalu ada di kode, tapi belum pernah terdokumentasi |

`/api/iot/sync` mendukung GET dan POST. Endpoint ini dipanggil scheduler di Dokploy, bukan
dari frontend, jadi provavelmente tidak perlu FE tahu.

### Yang perlu dikerjakan frontend

Cari seluruh `/admin` di base URL dan konfigurasi API client, lalu ganti ke `/api`. Tidak ada
perubahan nama endpoint, tidak ada perubahan struktur response, tidak ada parameter baru yang
wajib selain satu (lihat `POST /api/productions` di bawah).

---

## 2. API yang Berubah Bentuk

### `POST /api/productions` — `warehouse_id` jadi wajib

Satu-satunya perubahan request di seluruh tahap ini.

```json
{
  "product_id": 1,
  "warehouse_id": 1,
  "production_date": "2026-09-30",
  "qty_produced_ball": 100,
  "qty_reject_ball": 5
}
```

`warehouse_id` tidak dikirim → **422** dengan `errors.warehouse_id`.

**Kenapa wajib, tapi `PUT` tidak?** Batch yang sudah tercatat sebelum kolom ini ada tidak punya
gudang, jadi membutanya wajib di `PUT` membuat baris lama tidak bisa diedit sama sekali.
`PUT /api/productions/{id}` menerima `warehouse_id` opsional karena itu.

Field baru di response: `warehouse_id` (bisa null) dan `warehouse` (object gudang, kalau ada).

### `GET /api/productions` — dua filter baru

```
GET /api/productions?warehouse_id=1
GET /api/productions?product_id=1
```

Keduanya opsional dan bisa dipakai bersamaan. Tanpa filter, perilakunya sama seperti sebelumnya.

### `GET /api/warehouses/{id}/available-stock` — field baru

```json
{
  "data": {
    "warehouse_id": 1,
    "warehouse_code": "WH-001",
    "warehouse_name": "Gudang Pusat",
    "total_produced_posted": 77,
    "total_delivered": 16,
    "available_stock": 61,
    "unassigned_production_qty": 0
  }
}
```

`unassigned_production_qty` satu-satunya field tambahan. Catatan: angka ini adalah total
produksi `POSTED` yang belum dikaitkan ke gudang mana pun. Angka ini **tidak** dihitung di
`available_stock` gudang mana pun.

| Nilai | Arti | Apa yang harus dilakukan |
| --- | --- | --- |
| 0 | Tidak ada produksi yang menganggur | Tidak ada tindakan |
| > 0 | Ada produksi `POSTED` yang belum diatribusikan ke gudang mana pun | Perlu ditermin gudang-nya, jangan disembunyikan |

Contoh dari data seed: Gudang Pusat (WH-001) = 61, Gudang Utara (WH-002) = 60. Dua angka
berbeda itu memang yang diharapkan. Sebelum tahap ini keduanya melaporkan angka yang sama.

---

## 3. API yang Tidak Berubah

Endpoint berikut tetap `/api/*` dan tidak berubah bentuk sama sekali. Tidak perlu ada
penyesuaian di frontend, dicantumkan hanya supaya tim API tahu mana yang aman:

- Seluruh auth: `login`, `logout`, `logout-all`, `refresh`, `me`
- Master data: `users`, `products`, `stores`, `vehicles`, `warehouses`, `freezers`, `drivers`
- Nested read: `/api/stores/{storeId}/freezers`
- Dashboard, settlement, payment, sale, delivery, delivery-item, IoT status/test-data

Untuk `GET /api/deliveries` dan `GET /api/deliveries/{id}` ada satu catatan yang **bukan**
bagian dari perubahan tahap ini, tapi berpengaruh ke tim API: endpoint ini terbuka untuk DRIVER, dan
sekarang mengembalikan seluruh data pengiriman. Kalau tim FE punya daftar pengiriman
per-toko, ini belum dipisah. Sudah tercatat di `check backend swagger.md`.

---

## 4. Perilaku yang Tidak Terlihat di JSON tapi Penting

Tiga hal ini tidak terlihat di JSON, tapi mengubah angka yang tampil.

**Perhitungan stok sudah benar per gudang.** Sebelumnya `available-stock` menjumlah seluruh
tabel `productions` sambil menerima `warehouse_id` sebagai argumen — id itu tidak pernah
sampai ke query, jadi semua gudang melaporkan angka yang sama dan delivery bisa lolos dari
gudang yang stoknya kosong. Sekarang kedua sisi (produksi dan delivery) di-scope ke gudang yang
sama.

**Delivery `CANCELLED` tidak mengurangi stok.** Status ini memang ada di enum tapi tidak pernah
diisi di mana pun. Filter tetap ditambahkan karena itu kondisi yang masuk akal kalau nanti ada
tombol pembatalan, dan lebih aman daripada mengurangi stok untuk barang yang tidak bergerak.

**Dashboard sengaja tetap global.** `GET /api/dashboard/summary` masih menjumlah seluruh
produksi dan seluruh delivery, tanpa filter gudang. Kalau tim FE menampilkan angka dashboard
lalu angka available stock per gudang di halaman yang sama, keduanya **akan berbeda**.
Ini disengaja, bukan bug — dashboard dipakai untuk overview. Kalau tim FE butuh versi
dashboard per gudang, itu permintaan baru.

**Rencana pengiriman sekarang punya tujuan.** `POST /api/deliveries` wajib menerima
`stores[]`; urutan kunjungan dihitung otomatis nearest-first dari gudang asal dan disimpan di
tabel `delivery_stops`. Sebelumnya rencana hanya berisi driver, kendaraan, gudang, dan qty
muat — tidak ada satu pun catatan ke mana driver harus pergi. `store_id` baru muncul setelah
driver sudah berdiri di depan toko tersebut, jadi selama delivery berjalan tidak ada yang bisa
menjawab "driver ini seharusnya ke mana".

Akibatnya tiga hal:

- `GET /api/deliveries/{id}/route` adalah jawaban baru untuk pertanyaan itu: urutan toko, jarak
  per leg, total km, dan estimasi durasi.
- `POST /api/delivery-items/confirm` menolak toko yang tidak ada di rute (422).
- `GET /api/deliveries` untuk role DRIVER hanya mengembalikan delivery miliknya sendiri.

Datang dan pergi di satu toko juga dipisah, mengikuti BRD §2.3 yang menampilkannya sebagai dua
langkah ("ARRIVE AT STOP #1" lalu adegan di toko):

- `POST .../stops/{storeId}/arrive` — idempoten, waktu pertama tidak ditimpa
- `POST .../stops/{storeId}/depart` — menutup stop; stop yang tidak ada barangnya **tidak**
  ditandai `VISITED`, hanya `departed_at` yang terisi
- `POST .../stops/{storeId}/skip` — `reason` wajib
- `GET .../stops/{storeId}/settlement-preview` — utang sebelumnya + penjualan stop ini

Sebelum ini, toko yang didatangi lalu kosong tidak bisa ditutup sama sekali karena satu-satunya
cara menutup stop adalah konfirmasi freezer, dan `stops_completed` jadi melaporkan angka yang lebih
kecil dari kenyataan. `GET /api/deliveries/{id}/summary` sekarang menambah `stops_departed` dan
`stops_skipped` supaya keduanya bisa dibaca terpisah.

Jarak dihitung dari garis lurus dikali faktor 1,35 sebagai pendekatan jarak jalan, tanpa
panggilan API peta. Leg yang salah satu ujungnya tidak punya koordinat dilaporkan `leg_km:
null`, bukan ditebak.

`collection_target` ada di `deliveries` tapi **bukan input plan**: `POST`/`PUT
/api/deliveries` tidak menerimanya, karena BRD §2.2 hanya memintakan driver, kendaraan, daftar
toko, qty muat, dan catatan. Target penagihan muncul di layar progres dan dashboard driver, dan
kolomnya dipertahankan supaya kedua layar itu tidak ikut rusak.

`stores` bersifat **wajib**. Ini breaking change: klien yang membuat delivery tanpa daftar
toko akan mendapat 422. Rencana yang tidak punya tujuan memang tidak bisa dipakai, jadi lebih
baik ditolak saat dibuat daripada diterima lalu tidak bisa dijalankan.

## 4a. Driver menyusun draft, admin yang menyalakannya

Aturan tim: **driver boleh membuat dan mengubah rencana selama masih DRAFT; yang mengubah
DRAFT → IN_PROGRESS hanya admin.**

| Endpoint | ADMIN | WAREHOUSE | DRIVER |
| --- | --- | --- | --- |
| `POST /api/deliveries` | ya | ya | ya, untuk dirinya sendiri |
| `PUT /api/deliveries/{id}` | ya | ya | ya, draft miliknya sendiri |
| `POST /api/deliveries/{id}/start` | ya | tidak | tidak |

Driver menulis usulan rute, admin memutuskan apakah rute itu jalan. Draft yang sudah disusun
driver tidak bisa disalakan olehnya sendiri, dan `PUT` tetap 422 begitu status bukan DRAFT —
jadi rencana yang disepakati tidak bisa berubah di tengah perjalanan.

`POST` dengan `driver_id` milik orang lain mendapat 403, begitu juga `PUT` yang mencoba
mengubah `driver_id`. Tanpa itu setiap driver bisa menaruh rute di dashboard rekannya, dan
rekannya akan melihat run yang tidak pernah dia setujui.

Catatan: BRD §2.3 memberi driver tombol `[START DELIVERY]` (`:417`), dan BRD §2.2 menaruh
"[CREATE & SEND TO DRIVER]" di tangan warehouse staff. Aturan ini menyimpang dari keduanya,
sehingga `POST /start` juga menutup akses warehouse yang sebelumnya ada. Kalau itu tidak
disengaja, cukup kembalikan route-nya ke `role:ADMIN,WAREHOUSE`.

**`1 ball = 10 kg`.** Sensor kulkas hanya tahu total isi, bukan komposisi produk.

## 4b. Saran IoT per toko (Smart Delivery)

BRD §2.2 membuka pagi dengan daftar yang perlu kiriman, diurutkan menurut prioritas, sebelum plan
dibuat. `GET /api/deliveries/suggestions` (`role:ADMIN,WAREHOUSE`, tanpa query parameter) sekarang
melayani layar itu. Tier diambil persis dari BRD: `HIGH` estimasi 0, `MEDIUM` 3-5, `LOW` di atas 5,
dan di dalam tier freezer paling kosong diurutkan dulu.

Response-nya satu list datar, **satu baris per toko**: `store_id`, `store`, `freezer_code`,
`estimated_stock_ball`, `suggest_ball`, `label`, diurutkan dari freezer paling kosong dulu.
`store_id` tidak pernah muncul dua kali, karena list ini yang dicoret warehouse; dulu diulang
per freezer sehingga terbaca sebagai data dobel. Dua angkanya sengaja dari freezer berbeda:
`estimated_stock_ball` (dan `label`-nya) dari freezer **paling kosong** itu yang disebut
`freezer_code`, sedangkan
`suggest_ball` dijumlahkan dari **seluruh freezer** toko itu karena itulah yang harus dibawa.
`label` polos tanpa emoji, jadi warnanya urusan FE; tier ikut baris yang sama supaya tidak
bertentangan dengan angkanya sendiri.

Sort memakai urutan estimated, bukan tier. BRD memang menulis HIGH lalu MEDIUM lalu LOW, tapi
1-2 ball jatuh di celah dan jadi `UNKNOWN`, jadi sort per tier akan menaruh toko berstok 1 ball
paling bawah — kebalikan dari daruratnya. Tier tetap label, bukan urutan antrean.

Tidak ada filter. Layar ini ada untuk menunjukkan seluruh gambaran sebelum ada plan, jadi filter
apa pun hanya menyembunyikan baris yang justru dicari; admin mencentang dari yang dikembalikan lalu
mengirim tokonya ke `POST /api/deliveries`.

Dua kondisi tidak bisa masuk bucket BRD mana pun dan dilaporkan sebagai `UNKNOWN`:

- estimasi 1-2 ball — BRD meloncat dari 0 ke 3-5, jadi keduanya tidak masuk `HIGH` (0) maupun
  `MEDIUM` (3-5)
- freezer yang belum pernah melapor. Accessor `estimated_stock_ball` mengembalikan 0 saat
  `last_weight_kg` null, jadi tanpa penanganan terpisah tokonya akan masuk puncak daftar urgent
  hanya karena sensornya mati

Endpoint membaca `freezers` yang sudah tersimpan, bukan `IotDataService` yang masih mock, jadi
hanya telemetry yang sudah sync yang terbaca.

**`confidence` tidak lagi dikirim.** BRD:340 menulisnya tapi tidak menjelaskan rumusnya, jadi
angka yang bisa dikirim hanya angka yang tidak perlu dipercaya tanpa dasar. `label` plus
`estimated_stock_ball` di sebelahnya sudah menjelaskan dirinya sendiri.

**`payment_type` tidak berubah.** Tetap `TODAY` | `PAST_DAYS` | `DEBT`, dipilih driver saat
konfirmasi. Tetap manual sesuai BRD awal, tidak diturunkan dari tanggal.

---

## 5. Migrasi Database

Migration: `2026_09_30_000001_add_warehouse_id_to_productions_table.php`

Menambah kolom `warehouse_id` nullable dengan foreign key ke `warehouses.id`
(`ON DELETE RESTRICT`).

Backfill berjalan hanya kalau saat migration dijalankan **tepat satu gudang** ada di database.
Kalau ada lebih dari satu, semua batch lama dibiarkan `NULL`. Ini disengaja — dengan beberapa
gudang, tidak ada cara tahu batch lama milik gudang mana, jadi sistem memilih tidak
mengisinya secara diam-diam. Jumlahnya terlihat lewat `unassigned_production_qty`.

Perlu dijalankan sebelum deploy aplikasi:

```bash
php artisan migrate --force
```

> **Jangan `migrate:rollback` setelah data masuk.** Rollback menghapus kolom beserta
> nilainya, dan backfill tidak bisa memulihkannya. Ini sudah terjadi saat migration diuji
> di environment lokal: 4 batch seed kehilangan atribusi gudangnya dan harus di-seed ulang.

---

## 6. Daftar Per Endpoint

Role tidak berubah dari tahap 1. Group middleware berubah dari `web` ke `api` (CSRF dihapus,
semua respons error sekarang konsisten JSON). Autentikasi tetap token-based via Sanctum.

### Auth (5)
| Method | Path | Role |
| --- | --- | --- |
| POST | `/api/auth/login` | publik |
| POST | `/api/auth/logout` | authenticated |
| POST | `/api/auth/logout-all` | authenticated |
| POST | `/api/auth/refresh` | authenticated |
| GET | `/api/auth/me` | authenticated |

### Master Data (26)
| Method | Path | Role |
| --- | --- | --- |
| GET | `/api/drivers` | ADMIN, WAREHOUSE |
| GET | `/api/products` | ADMIN, WAREHOUSE |
| GET | `/api/products/{id}` | ADMIN, WAREHOUSE |
| POST | `/api/products` | ADMIN |
| PUT | `/api/products/{id}` | ADMIN |
| DELETE | `/api/products/{id}` | ADMIN |
| GET | `/api/stores` | ADMIN, WAREHOUSE, DRIVER |
| GET | `/api/stores/{id}` | ADMIN, WAREHOUSE, DRIVER |
| POST | `/api/stores` | ADMIN |
| PUT | `/api/stores/{id}` | ADMIN |
| DELETE | `/api/stores/{id}` | ADMIN |
| GET | `/api/stores/{storeId}/freezers` | ADMIN, WAREHOUSE, DRIVER |
| GET | `/api/vehicles` | ADMIN, WAREHOUSE |
| GET | `/api/vehicles/{id}` | ADMIN, WAREHOUSE |
| POST | `/api/vehicles` | ADMIN |
| PUT | `/api/vehicles/{id}` | ADMIN |
| DELETE | `/api/vehicles/{id}` | ADMIN |
| GET | `/api/warehouses` | ADMIN, WAREHOUSE |
| GET | `/api/warehouses/{id}` | ADMIN, WAREHOUSE |
| POST | `/api/warehouses` | ADMIN |
| PUT | `/api/warehouses/{id}` | ADMIN |
| DELETE | `/api/warehouses/{id}` | ADMIN |
| GET | `/api/freezers` | ADMIN, WAREHOUSE |
| GET | `/api/freezers/{id}` | ADMIN, WAREHOUSE |
| POST | `/api/freezers` | ADMIN |
| PUT | `/api/freezers/{id}` | ADMIN |
| DELETE | `/api/freezers/{id}` | ADMIN |

### Production (8)
| Method | Path | Role | Catatan tahap ini |
| --- | --- | --- | --- |
| GET | `/api/productions` | ADMIN, WAREHOUSE | + filter `warehouse_id`, `product_id` |
| POST | `/api/productions` | ADMIN, WAREHOUSE | + `warehouse_id` **wajib** |
| GET | `/api/productions/{id}` | ADMIN, WAREHOUSE | + `warehouse` di response |
| PUT | `/api/productions/{id}` | ADMIN, WAREHOUSE | + `warehouse_id` opsional |
| DELETE | `/api/productions/{id}` | ADMIN, WAREHOUSE | — |
| POST | `/api/productions/{id}/post` | ADMIN, WAREHOUSE | diperbaiki: `qty_*` tidak lagi null di error |
| GET | `/api/productions/date/{date}` | ADMIN, WAREHOUSE | diperbaiki: total tidak lagi 0 |
| GET | `/api/productions/status/{status}` | ADMIN, WAREHOUSE | — |

### Delivery & Delivery Item (21)
| Method | Path | Role |
| --- | --- | --- |
| GET | `/api/deliveries` | ADMIN, WAREHOUSE, DRIVER (driver hanya milik sendiri) |
| GET | `/api/deliveries/suggestions` | ADMIN, WAREHOUSE (8:00 AM, BRD:324-340) |
| GET | `/api/deliveries/{id}` | ADMIN, WAREHOUSE, DRIVER |
| POST | `/api/deliveries` | ADMIN, DRIVER (wajib `stores[]`; menyimpang dari BRD:342-352, keputusan tim) |
| PUT | `/api/deliveries/{id}` | ADMIN, DRIVER (`stores[]` mengganti rute; hanya DRAFT) |
| DELETE | `/api/deliveries/{id}` | ADMIN (hanya DRAFT) |
| POST | `/api/deliveries/{id}/post` | ADMIN (DRAFT → POSTED, cek stok) |
| POST | `/api/deliveries/{id}/start` | ADMIN, DRIVER (POSTED → IN_PROGRESS; BRD:417) |
| POST | `/api/deliveries/{id}/complete` | ADMIN, DRIVER (BRD:367) |
| GET | `/api/deliveries/{id}/summary` | ADMIN, WAREHOUSE, DRIVER |
| GET | `/api/deliveries/{id}/route` | ADMIN, WAREHOUSE, DRIVER |
| GET | `/api/deliveries/{deliveryId}/items` | ADMIN, WAREHOUSE |
| POST | `/api/deliveries/{id}/stops/{storeId}/arrive` | ADMIN, DRIVER (BRD:435) |
| POST | `/api/deliveries/{id}/stops/{storeId}/depart` | ADMIN, DRIVER (BRD:435) |
| POST | `/api/deliveries/{id}/stops/{storeId}/skip` | ADMIN, DRIVER (BRD:435) |
| GET | `/api/deliveries/{id}/stops/{storeId}/settlement-preview` | ADMIN, DRIVER (BRD:435) |
| POST | `/api/delivery-items/confirm` | ADMIN, DRIVER (toko harus ada di rute) |
| POST | `/api/delivery-items/{id}/sales` | ADMIN, DRIVER |
| GET | `/api/delivery-items/{id}` | ADMIN, DRIVER |
| GET | `/api/stores/{storeId}/delivery-items` | ADMIN, DRIVER |
| GET | `/api/freezers/{freezerId}/delivery-items` | ADMIN, DRIVER |
| GET | `/api/freezers/{freezerId}/suggestion` | ADMIN, DRIVER |

Untuk layar admin, ada spec terpisah di `/swagger/admin`. Isinya bagian dari
`openapi.json` yang saja yang bisa dipanggil dengan token ADMIN, jadi tidak ada
endpoint driver atau warehouse yang perlu dikerjakan di layar admin. File-nya hasil
generate `php artisan swagger:admin`, bukan ditulis tangan.

### Dashboard & Settlement (8)
| Method | Path | Role |
| --- | --- | --- |
| GET | `/api/dashboard/summary` | ADMIN |
| GET | `/api/dashboard/daily` | ADMIN |
| GET | `/api/dashboard/weekly` | ADMIN |
| GET | `/api/dashboard/monthly-pl` | ADMIN |
| GET | `/api/settlements/statistics` | ADMIN |
| GET | `/api/settlements/outstanding/summary` | ADMIN |
| GET | `/api/settlements/overdue` | ADMIN |
| GET | `/api/stores/{storeId}/settlement` | ADMIN |
| GET | `/api/stores/{storeId}/settlement-history` | ADMIN |

### Payment (6)
| Method | Path | Role |
| --- | --- | --- |
| POST | `/api/payments/collect` | ADMIN, DRIVER |
| POST | `/api/payments/{id}/upload-receipt` | ADMIN, DRIVER |
| POST | `/api/payments/{id}/confirm` | ADMIN |
| GET | `/api/payments/summary/all` | ADMIN |
| GET | `/api/payments/pending/all` | ADMIN |
| GET | `/api/stores/{storeId}/payments` | ADMIN |

### Sale (9)
| Method | Path | Role |
| --- | --- | --- |
| GET | `/api/sales` | ADMIN |
| GET | `/api/sales/{id}` | ADMIN |
| GET | `/api/sales/summary/all` | ADMIN |
| GET | `/api/sales/date/{date}` | ADMIN |
| GET | `/api/sales/status/{status}` | ADMIN |
| POST | `/api/sales/{id}/approve` | ADMIN |
| POST | `/api/sales/{id}/reject` | ADMIN |
| GET | `/api/stores/{storeId}/sales` | ADMIN |
| GET | `/api/freezers/{freezerId}/sales` | ADMIN |

### IoT (5)
| Method | Path | Role | Catatan |
| --- | --- | --- | --- |
| GET | `/api/iot/test-data` | ADMIN | — |
| GET | `/api/iot/status` | ADMIN | — |
| GET | `/api/iot/sync` | publik | baru di spec |
| POST | `/api/iot/sync` | publik | baru di spec |
| POST | `/api/iot/logs` | publik | path berubah dari `/admin/iot/logs` |

### User (5)
| Method | Path | Role |
| --- | --- | --- |
| GET | `/api/users` | authenticated |
| PUT | `/api/users/{user}` | ADMIN |
| DELETE | `/api/users/{user}` | authenticated |
| GET | `/api/users/{user}` | authenticated |
| GET | `/api/users/role/{role}` | ADMIN |

Total: 91 operasi HTTP di 66 path, semuanya `/api/*`.

---

## 7. Yang Perlu Dilakukan tim API

1. Ganti prefix `/admin` → `/api` di API client. Ini wajib, tidak ada alias.
2. Kirim `warehouse_id` di `POST /api/productions`. Tanpa itu akan 422.
3. Tampilkan `unassigned_production_qty` kalau nilainya lebih dari nol, jangan disembunyikan.
4. Kalau menampilkan dashboard dan available stock di layar yang sama, siapkan penjelasan
   kenapa angkanya berbeda. Dashboard global, available stock per gudang.
5. Kalau butuh daftar batch per gudang, pakai `GET /api/productions?warehouse_id=X`. Jangan
   ambil semua lalu filter di sisi FE: batch tanpa gudang tidak akan cocok dengan filter
   mana pun, sehingga bisa ikut terhitung ke gudang yang kebetulan sedang dibuka.
