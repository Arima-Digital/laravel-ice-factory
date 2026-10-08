# Catatan lanjutan: freezer multi-produk

Ditulis dari session pembulatan 0.5 ball (commit `d5007d0`), supaya session baru bisa
langsung paham konteks tanpa membaca ulang seluruh riwayat.

## Perubahan yang mau dikerjakan

Satu freezer boleh berisi **lebih dari satu produk**, dan tiap produk punya stok sendiri.

Sebelumnya asumsi kita: satu freezer = satu produk (`freezers.products_id`), dan
`estimated_stock_ball` adalah total berat dari sensor dibagi 10 kg. Sensor **tidak bisa**
membedakan produk — ia hanya mengirim **total berat kulkas**. Karena itu pembagian per
produk hanya bisa ditebak, dan yang menentukan isi turun apa adalah **driver di lapangan**.

Alurnya yang disepakati:

```
IoT kirim total berat kulkas
        ↓
Suggestion minta "tambah X ball"
        ↓
Admin mengecek apakah suggestion valid dengan berat yang dibutuhkan
        ↓
Driver memutuskan produk mana yang diturunkan
        ↓
Delivery items mencatat barang apa saja yang benar-benar dibawa
```

Tiga peran jelas: IoT ukur total, admin sah/tolak, driver putuskan jenis dan catat realita.

## Yang sudah jadi dan jangan dipecah lagi

Semua ini sudah commit dan jalan, jadi perubahan multi-produk harus **dibangun di atasnya**,
bukan menggantinya:

- **Pembulatan 0.5 ball** — `app/Models/Freezer.php`. Saran dinaikkan ke langkah penuh,
  estimasi diturunkan dari saran itu, sehingga `estimated + suggested = max_capacity`
  selalu tepat. Bacaan di atas kapasitas di-clamp, jadi saran 0.
- **Drift tolerance 0.5** — `DeliveryItemController`. `MATCH` bila `abs(drift) <= 0.5`,
  karena angka dua sisi sudah dibulatkan dan pembulatan sendiri bisa meleset satu langkah.
- **Satu baris per toko** — `DeliverySuggestionService`. `store_id` unik, `freezer_code`
  menunjuk freezer paling kosong, `suggest_ball` = jumlah saran semua freezer di toko.
- **Spec ganda** — `/swagger` (lengkap) dan `/swagger/admin` (71 path, hanya ADMIN),
  diturunkan oleh `php artisan swagger:admin`. Edit `openapi.json`, jangan `admin.json`.
- **BRD dashboard** sudah dilengkapi 4 endpoint (`summary`, `daily`, `weekly`, `monthly-pl`).

## Satuan: bobot itu aslinya, ball cuma label

Poin yang paling gampang disalahpahami di session baru:

- **1 ball = 10 kg** — selalu. Tidak pernah berubah.
- **0,5 ball = 5 kg** — langkah terkecil yang dipakai di sistem.
- **Angka production BOLEH pecahan — termasuk `reject`.** Tidak ada satupun dari
  tiga angka (produced / reject / good) yang dibulatkan ke integer.

Contoh nyata (bukan teori):

```
Produced : 200 ball   = 2000 kg
Reject   :   5,5 ball =   55 kg     ← pecahan juga sah
Good     : 194,5 ball = 1945 kg     ← good = produced − reject
                                  194,5 + 5,5 = 200 ✓
```

Kenapa pecahan itu sah: karena production dicatat dari **timbangan**, bukan dari
hitungan kantong. Bobot mentah belum tentu bulat kelipatan 10 kg, jadi hasilnya
sering berupa `194,5`. Sebaliknya `0,5 ball` memang batas paling halus yang bisa
ditempati arti "setengah ball" — lebih halus dari itu sudah bukan setengah ball.

**Kode sudah siap, tidak perlu diubah:**

- Skema `productions` = `decimal(10,2)` (`2026_08_25_000003_create_productions_table.php`),
  jadi dua desimal masuk apa adanya.
- Validasi `numeric|min:0` — **tanpa `step`**, jadi `5,5` lolos.
- `qty_good_ball` dihitung otomatis di `ProductionController`, bukan input user.
- Tidak ada `round()` / `intval()` di seluruh `ProductionController`.

**Artinya untuk stok gudang:** `available_stock = SUM(qty_good) − SUM(delivered)`
(`WarehouseStockService`, `WarehouseController:168`) juga bisa pecahan setengah ball.
Ini **konsisten** dengan pembulatan 0,5 di freezer — dua sisi sama-sama langkah 0,5,
jadi tidak ada sisi yang perlu dirapikan ulang.

**Artinya untuk desain multi-produk (ini yang penting):**

```
Production   → punya product_id, jadi gudang TAHU stok per produk (194,5 ball Es Kristal)
Freezer      → cuma dapat total berat dari sensor, jadi TIDAK TAHU per produk
```

Ketidakseimbangan itu inti masalahnya: gudang tahu angka per produk, freezer cuma
tahu angka total. Jangan sampai desainnya malah melempar data yang sudah akurat
(per produk, dari production) jadi angka tebakan, lalu menyebutnya "stok per produk".
Untuk itu lihat keputusan 1 di bawah.

## Hal yang perlu diputuskan dulu sebelum nulis kode

Pertanyaan ini menentukan skema tabel, jadi jangan dilewati:

1. **Dari mana stok per produk datang?** Sensor cuma kirim total. Opsi: driver menimbang
   per produk saat turun, admin input manual, atau hasil = total dikurangi sisa yang
   diketahui. Ketiganya beda skema.
2. **Kapasitas kulkas jadi per produk atau tetap total?** Kalau per produk, pembagian
   `estimated_stock_ball` ke produk butuh rumus dan bisa tidak habis dibagi.
3. **Pembulatan 0.5 ball masih berlaku per produk, atau hanya untuk total?** Ini yang
   paling gampang salah: kalau per produk dijumlah, bisa meleset dari total yang
   sudah dibulatkan (lihat kenapa estimasi diturunkan dari saran, bukan dibulatkan terpisah).
4. **Apa itu "valid" bagi admin?** Apakah selisih terhadap berat yang dibutuhkan, dan
   ambangnya berapa? Sama soalnya dengan drift 0.5 — pilih ambang yang ada maknanya.
5. **Driver menentukan saat turun, tapi kapan di-commit?** Sebelum arrival, saat arrival,
   atau setelah selesai tiap freezer? Ini menentukan apakah delivery item bisa diedit.

## File yang kemungkinan besar disentuh

- `app/Models/Freezer.php` — pembulatan, kapasitas, relasi produk
- `app/Services/DeliverySuggestionService.php` — saran per toko
- `app/Http/Controllers/Admin/DeliveryItemController.php` — confirm, drift, sales
- `database/migrations/*freezers*` dan `*delivery_items*` — skema
- `app/Services/IotDataService.php` — total berat masuk, belum per produk
- `resources/swagger/openapi.json` + `php artisan swagger:admin`
- `BRD_ICE_FACTORY_FINAL.md` §2.2 dan §4.1
- `docs/stories/01_AUTH_MASTER_DATA.md` (aturan satuan), `03_TAHAP_2_API_TERLIBAT.md`

## Pekerjaan terbuka di luar ini

- **Rotasi password production.** Akun seed `admin` / `password123` terbukti bisa login
  di `https://ice-factory.arimadigital.co.id`. Perlu diganti/dihapus.
- **Kontrak inbound IoT** belum final: auth, credential, payload gateway, frekuensi.
  `/api/iot/logs` dan `/api/iot/sync` saat ini tanpa role.
- Backlog auth: invalid login `422` bukan `401`, envelope error tidak seragam, token
  tidak punya expiry meski `expires_in`, login tanpa rate limit, beberapa operation
  Swagger belum mendokumentasikan `500`.
- Ekstra lokal: server `127.0.0.1:8899`, token `roundcheck`, dan beberapa `freezer_logs`
  sementara dari pengujian. Password admin lokal juga `password123`.
