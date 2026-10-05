# STEP 1 — AUTH & MASTER DATA

**Untuk siapa:** Backend. Ini catatan kerja, bukan dokumen FE.
**Acuan:** `BRD_ICE_FACTORY_FINAL.md`, `ice_factory_technical_erd_mvp.md`
**Status:** Sudah diimplementasikan, 72 test hijau

> **FE tidak perlu dokumen ini.** Kontrak API ada di Swagger, import dari
> `https://ice-factory.arimadigital.co.id/swagger/v1`. Yang ada di sini cuma urutan
> pemanggilan, alasan di balik keputusan, dan hal yang masih perlu dikonfirmasi ke bos.

---

## Cara Mengakses API

| Kebutuhan | Alamat |
| --- | --- |
| Base URL produksi | `https://ice-factory.arimadigital.co.id` |
| Swagger UI (bisa dibaca di browser) | `https://ice-factory.arimadigital.co.id/swagger` |
| File OpenAPI untuk import | `https://ice-factory.arimadigital.co.id/swagger/v1` |
| Halaman kesehatan | `https://ice-factory.arimadigital.co.id/up` |

Swagger UI terbuka untuk siapa saja, tidak perlu login. Di dalamnya ada tombol **Authorize** di kanan atas untuk mengisi `Bearer {token}` supaya bisa mencoba endpoint yang butuh login.

---

## Tujuan Langkah Ini

Sebelum ada produksi atau pengiriman, sistem harus bisa membedakan tiga orang dan menyimpan data dasar yang dipakai semua langkah berikutnya.

Skenario ini menyiapkan:

1. Tiga akun dengan peran berbeda
2. Satu jenis produk es, atau dua kalau mau mencoba ukuran berbeda
3. Dua gudang
4. Dua kendaraan
5. Dua toko
6. Enam freezer, tiga di setiap toko

Semua endpoint aplikasi memakai satu prefix, `/api`. Master data sudah lebih dulu pindah di langkah ini; modul transaksi (production, delivery, sales, payment, settlement) menyusul ke prefix yang sama, jadi tidak ada lagi URL `/admin/*` dan Swagger hanya punya satu permukaan.

Tiga peran sistem:

| Peran | Boleh | Larangan |
| --- | --- | --- |
| `ADMIN` | Semua endpoint | Tidak ada |
| `WAREHOUSE` | Baca semua master data, termasuk daftar driver dan stok gudang | Tidak boleh menulis master data apa pun, tidak boleh kelola user |
| `DRIVER` | Baca daftar toko dan daftar freezer per toko | Tidak boleh akses endpoint master data lain |

---

## Urutan Pemanggilan

Ikuti urutan ini. Kalau dilompat, akan dapat 422.

| # | Endpoint | Method | Peran | bergantung pada |
| --- | --- | --- | --- | --- |
| 1 | `/api/auth/login` | POST | publik | — |
| 2 | `/api/auth/me` | GET | semua | #1 |
| 3 | `/api/users` | POST | ADMIN | #1 |
| 4 | `/api/users` | GET | ADMIN | #3 |
| 5 | `/api/drivers` | GET | ADMIN, WAREHOUSE | #3 |
| 6 | `/api/products` | POST | ADMIN | #1 |
| 7 | `/api/warehouses` | POST | ADMIN | #1 |
| 8 | `/api/vehicles` | POST | ADMIN | #1 |
| 9 | `/api/stores` | POST | ADMIN | #1 |
| 10 | `/api/freezers` | POST | ADMIN | #6, #9 |
| 11 | `/api/stores/{storeId}/freezers` | GET | ADMIN, WAREHOUSE, DRIVER | #10 |
| 12 | `/api/auth/logout` | POST | semua | — |

Semua request setelah nomor 1 wajib memakai header:

```text
Authorization: Bearer {token}
Accept: application/json
```

Header `Accept: application/json` wajib dipasang. Error 401 dan 403 sudah selalu berbentuk JSON, tetapi error validasi 422 bergantung pada header ini. Tanpa header tersebut, 422 bisa terbalik menjadi halaman redirect HTML.

---

## 1. Login

**Kapan dipakai:** pertama kali aplikasi dibuka, atau setiap kali token dianggap sudah tidak berlaku.

```text
POST /api/auth/login
```

Request:

```json
{
  "username": "admin",
  "password": "password123"
}
```

Response `200`:

```json
{
  "message": "Login successful",
  "user": {
    "id": 1,
    "username": "admin",
    "email": "admin@icefactory.local",
    "role": "ADMIN",
    "created_at": "2026-08-26T14:52:53.000000Z",
    "updated_at": "2026-08-26T14:52:53.000000Z"
  },
  "token": "1|DacxhbJfOLvzKaAAojmtw3L0bliQg1dweFrVZvYA8835d45b",
  "expires_in": 2592000
}
```

Response `422` kalau username atau password salah:

```json
{
  "message": "The provided credentials are incorrect.",
  "errors": {
    "username": ["The provided credentials are incorrect."]
  }
}
```

**Catatan:** username yang tidak ada dan password yang salah sengaja mengembalikan pesan yang sama. Jangan bedsakan di tampilan.

**Simpan `token`, jangan `user`.** `password_hash` tidak pernah muncul di response mana pun.

---

## 2. Cek sesi

**Kapan dipakai:** saat aplikasi dibuka kembali, untuk memastikan token masih hidup sebelum menampilkan halaman utama.

```text
GET /api/auth/me
```

Response `200`:

```json
{
  "message": "Authenticated user retrieved successfully",
  "user": {
    "id": 1,
    "username": "admin",
    "email": "admin@icefactory.local",
    "role": "ADMIN",
    "created_at": "2026-08-26T14:52:53.000000Z",
    "updated_at": "2026-08-26T14:52:53.000000Z"
  }
}
```

Response `401` kalau token tidak dikirim, kedaluwarsa, atau dicabut:

```json
{
  "message": "Unauthenticated",
  "error": "Missing or invalid API token. Please login first."
}
```

Saat dapat 401, arahkan pengguna ke halaman login.

---

## 3. Buat akun baru

**Kapan dipakai:** hanya oleh `ADMIN`, untuk menambah staff gudang atau driver.

```text
POST /api/users
```

Request:

```json
{
  "username": "budi01",
  "email": "budi@icefactory.local",
  "password": "password123",
  "role": "DRIVER"
}
```

Response `201`:

```json
{
  "success": true,
  "message": "User created successfully",
  "data": {
    "id": 2,
    "username": "budi01",
    "email": "budi@icefactory.local",
    "role": "DRIVER",
    "created_at": "2026-09-28T09:00:00.000000Z",
    "updated_at": "2026-09-28T09:00:00.000000Z"
  }
}
```

Response `422` kalau username atau email sudah dipakai:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "username": ["The username has already been taken."]
  }
}
```

**Catatan field:**
- `role` hanya boleh `ADMIN`, `WAREHOUSE`, atau `DRIVER`
- `password` minimal 6 karakter
- `email` boleh dikosongkan, tapi kalau diisi harus unik

**Catatan penting:** response hanya berisi `id`, `username`, `email`, dan `role`. Tidak ada kolom nama. Lihat bagian Deviasi di bawah.

---

## 4. Daftar semua akun

```text
GET /api/users
```

Response `200`:

```json
{
  "success": true,
  "message": "Users retrieved successfully",
  "data": [
    { "id": 1, "username": "admin", "email": "admin@icefactory.local", "role": "ADMIN" },
    { "id": 2, "username": "rudi01", "email": "rudi@icefactory.local", "role": "WAREHOUSE" },
    { "id": 3, "username": "budi01", "email": "budi@icefactory.local", "role": "DRIVER" }
  ]
}
```

Ada juga `GET /api/users/role/{role}` untuk memfilter per peran, dan `GET /api/users/{user}`, `PUT /api/users/{user}`, `DELETE /api/users/{user}` untuk mengelola satu akun.

---

## 5. Daftar driver

**Kapan dipakai:** saat menyusun rencana pengiriman, untuk mengisi kolom driver. Lihat `/api/warehouses/{id}/available-stock` di Langkah 2, dan form rencana pengiriman di Langkah 3.

```text
GET /api/drivers
```

Response `200`:

```json
{
  "success": true,
  "message": "Drivers retrieved successfully",
  "data": [
    { "id": 3, "username": "budi01", "role": "DRIVER" },
    { "id": 4, "username": "sari01", "role": "DRIVER" }
  ],
  "count": 2
}
```

Response `403` kalau yang memanggil bukan `ADMIN` atau `WAREHOUSE`:

```json
{
  "message": "Forbidden",
  "error": "Access denied",
  "details": {
    "your_role": "DRIVER",
    "your_permissions": "Freezer confirmation and payment collection",
    "required_roles": "ADMIN, WAREHOUSE",
    "allowed_permissions": [
      "Full access to dashboard, reporting, user management, dan semua operasi",
      "Production management dan delivery planning"
    ]
  }
}
```

**Catatan:** daftar ini hanya berisi user dengan peran `DRIVER`. Jangan pakai `GET /api/users` untuk mengisi form driver, karena itu hanya bisa dipanggil `ADMIN` dan mengembalikan semua akun.

**Catatan penting:** yang tampil di dropdown adalah `username`, misalnya `budi01`, bukan nama orang. Lihat bagian Deviasi.

---

## 6. Buat produk

**Kapan dipakai:** sebelum membuat freezer, karena freezer wajib menunjuk produk.

```text
POST /api/products
```

Request:

```json
{
  "code": "ICE-10",
  "name": "Es Kristal 10 KG",
  "weight_kg": 10,
  "selling_price": 10000
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Product created successfully",
  "data": {
    "id": 1,
    "code": "ICE-10",
    "name": "Es Kristal 10 KG",
    "weight_kg": "10.00",
    "selling_price": "10000.00",
    "ball_equivalent": 1,
    "created_at": "2026-09-28T09:05:00.000000Z",
    "updated_at": "2026-09-28T09:05:00.000000Z"
  }
}
```

**Catatan field:**
- `code` unik, misalnya `ICE-10` untuk 10 kg, `ICE-15` untuk 15 kg, `ICE-05` untuk 5 kg
- `weight_kg` berat satu kemasan produk dalam kg
- `selling_price` harga **per ball** dalam rupiah, bukan per kg
- `ball_equivalent` dihitung otomatis, jangan dikirim saat membuat produk

### Aturan 1 ball = 10 kg

Semua perhitungan stok memakai satu satuan: **1 ball = 10 kg**. Satu ball adalah satuan berat, bukan benda fisik, jadi satu jenis es bisa jadi pecahan ball.

| Produk | `weight_kg` | `ball_equivalent` |
| --- | --- | --- |
| Es Kristal 10 KG | 10 | 1 |
| Es Batu 15 KG | 15 | 1.5 |
| Es Cube 5 KG | 5 | 0.5 |

Konsekuensinya:

- 1 ball selalu berarti 10 kg, berapa pun ukuran produknya. Kulkas 10 ball = 100 kg, kulkas 7.5 ball = 75 kg. Kapasitas tidak bergantung pada produk.
- `weight_kg` **tidak dipakai menghitung stok freezer.** Stok selalu `net_weight / 10`, bukan dibagi `weight_kg`. Yang penting dari `weight_kg` cuma nilai `ball_equivalent`-nya dan harga jualnya.
- Harga harus dianggap harga per ball. Kalau produk 5 kg dihargai 40.000, maka harga 10 kg dianggap 80.000. Ini belum dikonfirmasi ke bos, karena kalau maksudnya harga per kemasan, rumus `Sales Amount = Qty Sold × selling_price` harus diubah.
- Angka ball boleh pecahan, jadi semua input dan tampilan harus mendukung desimal. Kalau penyimpanan menyimpan pecahan, yang dikembalikan sudah dibulatkan ke kelipatan 0,5 ball. Jangan bulatkan lagi di FE, atau angkanya bisa tidak cocok dengan kapasitas.

### Mengubah berat produk

`PUT /api/products/{id}` menolak `weight_kg` yang berbeda kalau produk sudah dipakai. Akan dapat `422`:

```json
{
  "success": false,
  "message": "Product weight cannot be changed because it is already in use",
  "errors": {
    "weight_kg": ["Locked: 1 freezer(s) already report stock using this weight."]
  }
}
```

Alasannya, mengubah berat akan menulis ulang semua pembacaan sensor freezer yang memakai produk itu, dan membuat saran pengiriman lama jadi tidak berlaku. Untuk es batu 15 kg, buat produk dengan kode baru, bukan mengubah ICE-10. Mengirim ulang `weight_kg` yang sama tetap berhasil, jadi form yang selalu mengirim semua field tidak akan error.

### Menghapus produk

`DELETE /api/products/{id}` hanya berhasil kalau produk belum dipakai kulkas dan belum punya data produksi. Kalau sudah, dapat `422` (bukan `500`):

```json
{
  "success": false,
  "message": "Product cannot be deleted because it is still referenced",
  "reason": "used by 1 freezer(s)",
  "freezer_count": 1,
  "hint": "Freeze a new product code for a different weight instead of editing or deleting this one."
}
```

Gunakan `reason` dan `freezer_count` untuk menjelaskan ke pengguna. Intinya: jangan pernah mengubah atau menghapus produk yang sudah dipakai, buat yang baru.

---

## 7. Buat gudang

```text
POST /api/warehouses
```

Request:

```json
{
  "code": "WH-JKT-01",
  "name": "Gudang Jakarta Utara"
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Warehouse created successfully",
  "data": {
    "id": 1,
    "code": "WH-JKT-01",
    "name": "Gudang Jakarta Utara",
    "created_at": "2026-09-28T09:06:00.000000Z",
    "updated_at": "2026-09-28T09:06:00.000000Z"
  }
}
```

Buat dua gudang, misalnya `WH-JKT-01` dan `WH-BEK-01`. Pada langkah ini gudang masih kosong. Stok masuk setelah ada produksi yang di-`POST`, dan itu dikerjakan di Langkah 2.

Endpoint `GET /api/warehouses/{id}/available-stock` sudah tersedia dan jalannya tidak akan berubah lagi. Panggilannya sekarang selalu mengembalikan nol karena belum ada produksi. Jangan dipakai sebagai sumber angka sampai Langkah 2 selesai.

---

## 8. Buat kendaraan

```text
POST /api/vehicles
```

Request:

```json
{
  "code": "VH-001",
  "plate_number": "B2345XYZ",
  "name": "Avanza Putih"
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Vehicle created successfully",
  "data": {
    "id": 1,
    "code": "VH-001",
    "plate_number": "B2345XYZ",
    "name": "Avanza Putih",
    "created_at": "2026-09-28T09:07:00.000000Z",
    "updated_at": "2026-09-28T09:07:00.000000Z"
  }
}
```

**Catatan:** satu kendaraan boleh dipakai lebih dari satu gudang. Aturan pemakaian kendaraan dicek saat pengiriman mulai, bukan di sini.

---

## 9. Buat toko

```text
POST /api/stores
```

Request:

```json
{
  "code": "RSA-001",
  "name": "Toko Rapi",
  "owner_name": "Joko",
  "phone": "081234567890",
  "address": "Jl. Kebon Sirih No. 12, Jakarta",
  "latitude": -6.1148,
  "longitude": 106.8215
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Store created successfully",
  "data": {
    "id": 1,
    "code": "RSA-001",
    "name": "Toko Rapi",
    "owner_name": "Joko",
    "phone": "081234567890",
    "address": "Jl. Kebon Sirih No. 12, Jakarta",
    "latitude": "-6.11480000",
    "longitude": "106.82150000",
    "created_at": "2026-09-28T09:08:00.000000Z",
    "updated_at": "2026-09-28T09:08:00.000000Z"
  }
}
```

**Catatan field:**
- `code` unik
- `latitude` dan `longitude` opsional, tapi wajib diisi kalau aplikasi memakai peta atau urutan rute
- `owner_name` dan `phone` dipakai Driver saat tiba di toko

`GET /api/stores` dan `GET /api/stores/{id}` boleh dipanggil `DRIVER` juga, karena Driver butuh info toko saat kunjungan. Per personalize data Driver baru berlaku di Langkah 3.

---

## 10. Buat freezer

**Kapan dipakai:** setelah produk dan toko ada. Satu toko boleh punya beberapa freezer, dan `code` harus unik untuk seluruh sistem.

```text
POST /api/freezers
```

Request untuk freezer pertama di `RSA-001`:

```json
{
  "store_id": 1,
  "code": "FRZ-RSA-001-A",
  "sim_number": "899012345678901",
  "max_capacity_ball": 10,
  "tare_weight_kg": 45.5
}
```

Kapasitas boleh pecahan, karena 1 ball = 10 kg dan kulkas bisa berisi beberapa ukuran es:

```json
{
  "store_id": 1,
  "code": "FRZ-RSA-001-B",
  "max_capacity_ball": 7.5,
  "tare_weight_kg": 45.5
}
```

Tidak ada `product_id` di request. Kulkas dibuat kosong; produk apa saja yang pernah dikirim ke
 dalamnya dicatat saat driver konfirmasi pengiriman. Satu kulkas bisa menampung beberapa produk.

Response `201`:

```json
{
  "success": true,
  "message": "Freezer created successfully",
  "data": {
    "id": 1,
    "store_id": 1,
    "code": "FRZ-RSA-001-A",
    "sim_number": "899012345678901",
    "max_capacity_ball": "10.00",
    "tare_weight_kg": "45.50",
    "last_weight_kg": null,
    "last_temperature_c": null,
    "last_door_status": "CLOSED",
    "last_seen_at": null,
    "estimated_stock_ball": 0,
    "suggested_delivery_ball": 10,
    "iot_confidence": "LOW",
    "store": { "id": 1, "code": "RSA-001", "name": "Toko Rapi" },
    "products": []
  }
}
```

`products` kosong karena kulkas ini baru dibuat. Isinya bertambah begitu driver mengonfirmasi
pengiriman. Daftar itu diturunkan dari riwayat delivery item, jadi menunjukkan produk yang pernah
ada di kulkas, bukan komposisi stok saat ini: sensor berat hanya tahu total isi kulkas, tidak tahu
produk apa di dalamnya.

Response `422` kalau `code` freezer sudah dipakai:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "code": ["The code has already been taken."]
  }
}
```

Response `422` kalau kapasitas kecil dari 0.1:

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "max_capacity_ball": ["The max capacity ball must be at least 0.1."]
  }
}
```

**Catatan field:**
- `tare_weight_kg` berat freezer saat kosong, tidak termasuk es. Sensor subtracting angka ini
- `max_capacity_ball` daya tampung dalam ball, boleh pecahan seperti 7.5
- `sim_number` opsional, dipakai untuk mengenali sensor
- Semua field decimal (`max_capacity_ball`, `tare_weight_kg`, `estimated_stock_ball`, `suggested_delivery_ball`) **dikembalikan sebagai string** seperti `"10.00"`. Parse sebagai angka di frontend, jangan disambung sebagai teks.

**Field yang tidak boleh diisi dari frontend:** `last_weight_kg`, `last_temperature_c`, `last_door_status`, dan `last_seen_at`. Keempatnya milik sensor IoT dan hanya diisi sistem. Kalau request Anda mengirim field tersebut, nilainya akan diabaikan.

**Rumus estimasi, sudah pakai aturan 1 ball = 10 kg:**

```text
estimated_stock_ball     = (last_weight_kg - tare_weight_kg) / 10, dibatasi 0 sampai max_capacity_ball
suggested_delivery_ball  = max_capacity_ball - estimated_stock_ball
```

Pembaginya 10, bukan `weight_kg` produk. Hasilnya **dibulatkan ke kelipatan 0,5 ball (5 kg)**, karena 5 kg itu kemasan terkecil yang ada dan tidak ada es yang dijual dalam 0,37 ball. Saran selalu dibulatkan **ke atas** supaya driver membawa cukup; estimasi diturunkan dari saran itu supaya `estimated + suggested` selalu sama dengan `max_capacity_ball`. Contoh: kulkas 10 ball yang terbaca 8,05 jadi `estimated 8` / `suggested 2`, dan yang terbaca 0,25 jadi `estimated 0` / `suggested 10`. Dua ujung yang perlu dijawab di FE: sisa di bawah 5 kg terbaca 0 dan sarannya jadi isi penuh, sedangkan pembacaan di **atas** kapasitas dijepit ke `max_capacity_ball` sehingga sarannya 0 — bukan 0,5 ball yang tidak muat di kulkas.

  Angka mentahnya tidak hilang: `last_weight_kg` masih dikembalikan apa adanya, dan setiap pembacaan sensor tersimpan di `freezer_logs`. Yang dibulatkan hanya angka restock-nya.

Tiga kondisi yang harus ditangani tampilan:

| Keadaan | `estimated_stock_ball` | `suggested_delivery_ball` | `iot_confidence` |
| --- | --- | --- | --- |
| Sensor jalan, data baru | sesuai hitungan | capacity - stok | `HIGH` |
| Data sudah tua | sesuai hitungan | capacity - stok | `MEDIUM` >60 menit, `LOW` >120 menit |
| `last_weight_kg` null | 0 | sama dengan kapasitas penuh | `LOW` |

Kalau `iot_confidence` `LOW`, tampilkan bahwa angka itu perkiraan lama atau belum ada, jangan disamarkan sebagai stok kosong atau sold out. Saran ini bukan instruksi, driver tetap menimbang fisik, dan angka yang driver ketik itulah yang dipakai menghitung penjualan.

Ada juga `GET /api/freezers` dengan filter opsional `store_id` dan `product_id`, serta `GET`, `PUT`, `DELETE /api/freezers/{id}`.

**Hapus freezer:** `DELETE /api/freezers/{id}` akan ditolak dengan 422 kalau freezer sudah pernah punya kunjungan pengiriman atau penjualan.

```json
{
  "success": false,
  "message": "Freezer cannot be deleted because it has transaction history",
  "details": {
    "delivery_items": 3,
    "sales": 12
  }
}
```

Gunakan angka di `details` untuk menjelaskan ke pengguna kenapa freezer tidak bisa dihapus. Jejak penjualan tidak boleh hilang, jadi freezer lama tidak dihapus, hanya tidak dipakai lagi.

---

## 11. Daftar freezer per toko

**Kapan dipakai:** saat Driver tiba di toko, untuk melihat semua freezer yang harusangani, termasuk yang belum pernah dikunjungi.

```text
GET /api/stores/1/freezers
```

Response `200`:

```json
{
  "success": true,
  "message": "Freezers retrieved successfully",
  "data": {
    "store": {
      "id": 1,
      "code": "RSA-001",
      "name": "Toko Rapi",
      "owner_name": "Joko",
      "phone": "081234567890",
      "address": "Jl. Kebon Sirih No. 12, Jakarta",
      "latitude": "-6.11480000",
      "longitude": "106.82150000"
    },
    "freezers": [
      {
        "id": 1,
        "code": "FRZ-RSA-001-A",
        "max_capacity_ball": 10,
        "tare_weight_kg": "45.50",
        "last_weight_kg": "65.50",
        "last_temperature_c": "-18.40",
        "last_door_status": "CLOSED",
        "last_seen_at": "2026-09-28T08:55:00.000000Z",
        "estimated_stock_ball": 2,
        "suggested_delivery_ball": 8,
        "product": { "id": 1, "code": "ICE-10", "name": "Es Kristal 10 KG" }
      },
      {
        "id": 2,
        "code": "FRZ-RSA-001-B",
        "max_capacity_ball": 8,
        "tare_weight_kg": "44.00",
        "last_weight_kg": "44.00",
        "last_temperature_c": "-17.90",
        "last_door_status": "OPEN",
        "last_seen_at": "2026-09-28T08:55:00.000000Z",
        "estimated_stock_ball": 0,
        "suggested_delivery_ball": 8,
        "product": { "id": 1, "code": "ICE-10", "name": "Es Kristal 10 KG" }
      }
    ],
    "count": 2
  }
}
```

**Mengapa endpoint ini penting:** tanpa ini, satu-satunya jalan untuk tahu stok freezer adalah memanggil `GET /api/freezers/{freezerId}/suggestion` satu per satu. Kalau toko punya tiga freezer, frontend harus melakukan tiga panggilan. Endpoint ini mengembalikan semuanya sekaligus.

Response `404` kalau id toko tidak ada:

```json
{
  "success": false,
  "message": "Store not found"
}
```

**Catatan:** pada langkah ini semua user bisa melihat semua toko. Pembatasan hanya toko yang ditugaskan ke Driver dikerjakan di Langkah 3.

---

## 12. Logout

```text
POST /api/auth/logout
```

Response `200`:

```json
{
  "message": "Logout successful"
}
```

Token yang dipakai ikut dicabut, jadi permintaan berikutnya dengan token sama akan mendapat 401. Untuk logout dari semua perangkat sekaligus, pakai `POST /api/auth/logout-all`.

---

## Ringkasan Data yang Dibuat

| Jenis | Jumlah | Kode |
| --- | --- | --- |
| User | 3 | admin (ADMIN), rudi01 (WAREHOUSE), budi01 (DRIVER) |
| Product | 1 atau 2 | ICE-10, tambah ICE-15 atau ICE-05 kalau mau uji ukuran lain |
| Warehouse | 2 | WH-JKT-01, WH-BEK-01 |
| Vehicle | 2 | VH-001, VH-002 |
| Store | 2 | RSA-001, RSA-002 |
| Freezer | 6 | FRZ-RSA-001-A/B/C, FRZ-RSA-002-A/B/C |

Satu toko bisa punya satu kulkas untuk beberapa ukuran es. Kulkas tidak memilih produk saat
dibuat, dan driver mencatat produk mana yang dikirim saat konfirmasi.

---

## Checklist untuk Frontend

Semua harus hijau sebelum lanjut ke Langkah 2.

**Autentikasi**
- [ ] Login benar mendapat 200 dan `token` tersimpan
- [ ] Login salah mendapat 422, bukan 500
- [ ] Pesan error tidak membedakan username tidak ada dan password salah
- [ ] `GET /api/auth/me` dengan token benar mendapat 200
- [ ] Semua request tanpa token mendapat 401 dan mengarahkan ke login
- [ ] Header `Accept: application/json` dikirim di semua request
- [ ] Logout membuat token tidak bisa dipakai lagi

**Peran**
- [ ] `ADMIN` bisa memanggil semua endpoint di dokumen ini
- [ ] `WAREHOUSE` mendapat 403 saat memanggil `POST /api/users`
- [ ] `DRIVER` mendapat 403 saat memanggil `GET /api/drivers`
- [ ] `DRIVER` mendapat 200 saat memanggil `GET /api/stores`
- [ ] Halaman atau tombol yang tidak diizinkan tidak ditampilkan, bukan hanya disembunyikan setelah 403

**Master data**
- [ ] Form produk menampilkan validasi `code` unik dari server
- [ ] Form produk menampilkan `ball_equivalent` sebagai teks informatif, bukan input
- [ ] Mengubah `weight_kg` pada produk yang sudah dipakai menampilkan pesan 422, bukan diam-diam gagal
- [ ] Menghapus produk yang masih dipakai kulkas menampilkan alasan, bukan 500
- [ ] Form gudang dan kendaraan berfungsi, dan `available-stock` baru diisi di Langkah 2
- [ ] Form toko meminta `latitude` dan `longitude` kalau fitur peta dipakai
- [ ] Form freezer hanya menampilkan enam field: toko, produk, kode, nomor SIM, kapasitas, berat kosong
- [ ] Input `max_capacity_ball` menerima desimal, dan labelnya menjelaskan satuan ball
- [ ] Field sensor tidak ada di form, dan kalau dikirim manual tetap diabaikan
- [ ] Hapus freezer yang punya riwayat menampilkan pesan 422 dengan alasan, bukan pesan teknis
- [ ] Daftar freezer per toko menampilkan penanda kalau `last_seen_at` null

**Tampilan**
- [ ] Kolom uang memakai format rupiah, misal `Rp 10.000`
- [ ] Semua angka ball dan kilogram di-parse dari string ke angka, lalu ditampilkan sebagai desimal
- [ ] Angka ball tampil sesuai langkah 0,5 (`8.05` tampil `8`), dan tidak dibulatkan lagi di FE
  - [ ] Kalau dua angka dijumlah, hasilnya sama dengan `max_capacity_ball`
- [ ] `iot_confidence` `LOW` terlihat jelas, dan saran isi tidak ditampilkan seolah-olah pasti
- [ ] Timestamp ditampilkan dalam waktu lokal Jakarta

---

## Keputusan yang sudah diambil

| Topik | Keputusan |
| --- | --- |
| Kolom nama di `users` | Tidak ditambah. Tabel `users` tetap seperti sekarang, dropdown driver menampilkan username seperti `budi01`. Contoh di BRD nomor 344 perlu dibaca sebagai username, bukan nama asli. |
| Aturan satuan ball | 1 ball = 10 kg untuk semua ukuran produk. Satu kolom angka sudah cukup untuk 5 kg sampai 20 kg, dan `max_capacity_ball` tidak perlu diubah jadi kilogram. |
| Kapasitas dalam satuan apa | Total ball, bukan kilogram. Ini sudah sesuai BRD (`max_capacity_ball`, "Max capacity: 10 ball") dan ERD. Yang diubah hanya tipe kolomnya dari `integer` ke `decimal(8,2)` supaya nilai pecahan seperti 7.5 bisa disimpan. |
| Pembulatan | Angka stok dibulatkan ke kelipatan 0,5 ball (5 kg), saran ke atas. Driver menimbang fisik sebagai penentu akhir, jadi pembulatan hanya mengubah berapa yang dibawa, bukan pencatatan penjualan. |

### Satu kulkas bisa beberapa produk

Sudah jadi keputusan, bukan lagi asumsi. `freezers.product_id` dihapus, dan `product_id` pindah ke
`delivery_items` dan `sales`.

Alasannya persis masalah yang tertulis di rumus BRD lama:

```
Estimated Stock = (Last Weight - Tare Weight) / Product Weight
```

`Product Weight` ditulis tunggal. Kalau satu kulkas boleh campur, sensor tetap hanya mengirim satu
angka kilogram, dan satu angka itu tidak bisa diterjemahkan menjadi "8 ball 5 kg" atau "4 ball
15 kg" karena keduanya sama-sama 40 kg. Yang hilang bukan cara menghitungnya, tapi informasi
komposisi yang memang tidak pernah dikirim.

Konsekuensi yang sudah diterapkan:

| Keputusan | Kenapa |
| --- | --- |
| `freezers.product_id` dihapus | Satu kulkas tidak lagi terikat satu jenis es. |
| `delivery_items.product_id` ditambahkan | Satu baris per pasangan kulkas/produk, jadi daftar produk kulkas tidak perlu tabel terpisah. |
| `sales.product_id` ditambahkan | Tiap produk punya harga sendiri, jadi es 15 kg tidak boleh dihitung dengan harga es 10 kg. |
| Pembagi IoT tetap 10 kg | Ball jadi satuan berat, bukan berat produk. Satu angka berlaku untuk semua ukuran. |
| Saran isi tetap total ball | Sensor tidak bisa dipecah per produk. Driver mengpecah sendiri saat konfirmasi. |
| Penjualan tidak diturunkan dari sensor | Sensor tidak bisa menunjukkan produk mana yang terjual. Driver mencatat per produk, admin memverifikasi. |

Yang tetap tidak bisa dijawab sistem: komposisi stok kulkas saat ini. `products` pada respons
freezer menunjukkan apa yang pernah dikirim, bukan apa yang ada sekarang. Kalau komposisi itu
dibutuhkan, sensor load cell tidak cukup; butuh sensor tambahan atau penimbangan manual per
produk.

---

## Referensi: skenario BRD yang jadi acuan

Bagian ini kutipan dari `BRD_ICE_FACTORY_FINAL.md`, dipakai sebagai rujukan saat membaca kode. Semua sudah berjalan di implementasi sekarang.

### IoT Stock Estimation (BRD 3.3)

```
Estimated Stock = (Last Weight - Tare Weight) / Product Weight

├─ Last weight from sensor: 52.5 kg
├─ Tare weight (empty freezer): 50 kg
├─ Product weight per ball: 10 kg
├─ Net weight: 52.5 - 50 = 2.5 kg
├─ Estimated stock: 2.5 / 10 = 0.25 ball
└─ Rounded: 0 ball (or show 0.25 ball if decimal)

Suggested Delivery = Max Capacity - Estimated Stock

├─ Max capacity: 10 ball
├─ Estimated stock: 0 ball
└─ Suggested: 10 - 0 = 10 ball
```

BRD menyebut pembulatan ("Rounded: 0 ball") tapi juga memberi jalan keluar menampilkan desimal. Yang dipakai adalah menampilkan desimal apa adanya, sesuai keputusan di atas.

### Driver 1-tap (BRD 3.4)

Typical case, 95% kasus:
```
├─ System: "Est stok 2 ball, suggest deliver 8. OK? [YES] [NO]"
├─ Driver: [TAP YES]
├─ Auto-fill: confirmed_stock = 2, delivered_qty = 8
└─ Status: CONFIRMED (1 tap)
```

Edge case, 5% kasus:
```
├─ Driver: "Stok lebih dari estimate, sebenarnya 3"
├─ Driver: [TAP NO]
├─ Input: Stock = 3
├─ System auto-calc: Deliver = 10 - 3 = 7
├─ Confirm: [YES]
└─ Status: CONFIRMED (3 inputs)
```

Edge case ini yang membuat driver yang mengetik angka jadi sumber kebenaran, bukan sensor. `qty_sold` dihitung dari `Previous Confirmed Stock - Current Confirmed Stock` (BRD formula 6), jadi penjualan tidak bergantung pada akurasi sensor sama sekali.

### Rumus lengkap (BRD lampiran 4.1 dan 4.2)

| # | Rumus | Status di kode |
| --- | --- | --- |
| 1 | `Estimated Stock = (Last Weight - Tare Weight) / Product Weight` | Sudah, sekarang dibagi `BALL_KG` (10) |
| 2 | `Suggested Delivery = Max Capacity - Estimated Stock` | Sudah, `Freezer::getSuggestedDeliveryBallAttribute` |
| 3 | `Current Load = Initial Load - SUM(Delivered so far)` | Ada di `getSummary`, belum diuji penuh |
| 4 | `Turnover Days = Max Capacity / Average Daily Sales` | Belum, masuk laporan |
| 6 | `Qty Sold = Previous Confirmed - Current Confirmed` | Sudah, `DeliveryItemController::confirmFreezer` |
| 7 | `Sales Amount = Qty Sold × Unit Price` | Sudah, otomatis saat konfirmasi |
| 8 | `Total Sales = SUM(Sales Amount per toko)` | Sudah, `SaleController::getByStore` |
| 9 | `Collection Rate = Total Paid / Total Sales × 100%` | Ada di settlement, belum diuji |

Rumus 4 dan 9 belum ada test, jadi jangan dianggap sudah benar.

---

## Deviasi dari BRD dan ERD

| Yang menyimpang | Kenapa |
| --- | --- |
| Tabel `users` tidak punya kolom nama | **Putusan final: tidak ditambah.** Konsekuensinya dropdown driver menampilkan `budi01`, jadi contoh di BRD nomor 344 harus dibaca sebagai username. |
| Tabel `users` tidak punya kolom telepon | Kebutuhan BRD nomor 208 belum terpenuhi. |
| Prefix URL tidak memakai `admin` | Disepakati. Penegakan peran lewat token. Modul Step 1 sudah pindah ke `/api`, sisanya dipindah per langkah. |
| Rumus stok membagi dengan 10 kg, bukan `weight_kg` produk | Supaya satu jenis es 5 kg sampai 20 kg muat di kolom angka yang sama, dan `max_capacity_ball` tidak perlu diubah jadi kilogram. Satu ball jadi satuan berat 10 kg. Belum dikonfirmasi ke bos. |
| Angka stok dibulatkan ke 0,5 ball | Awalnya tidak dibulatkan sama sekali, tapi FE complained mendapat `8.05` yang tidak bisa dibaca sebagai "delapan setengah". 0,5 ball = 5 kg dan itu kemasan terkecil di katalog (BRD:700), jadi tidak ada informasi yang berarti di bawah angka itu. BRD sudah ikut di-update. Saran dibulatkan ke atas demi "lebih baik bawa kelebihan daripada kurang". Angka driver tetap yang dipakai menghitung penjualan. |
| `max_capacity_ball` diubah dari `integer` ke `decimal(8,2)` | Supaya nilai pecahan seperti 7.5 ball bisa disimpan. Satuan tetap ball, tidak diubah ke kilogram, sesuai BRD. |
