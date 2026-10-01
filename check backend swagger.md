

| Prioritas | Hal yang perlu dilaporkan | Temuan di Swagger dan permintaan konkret |
| ----- | ----- | ----- |
| **P0** | Driver memulai pengiriman | `POST /api/deliveries/{id}/start` tertulis untuk Admin/Warehouse, tetapi contoh respons 403 menyebut Admin/Driver. Pastikan **DRIVER yang ditugaskan** bisa mengaksesnya dan perbaiki dokumentasi role. |
| **P0** | Driver menyelesaikan rute | Belum ada `finish-route` atau status `WAITING_RECONCILIATION`. Endpoint `complete` saat ini langsung mengubah `IN_PROGRESS` menjadi `COMPLETED` dan ditujukan ke Admin/Warehouse. Perlu langkah Driver **Selesai Berkeliling**, lalu langkah Warehouse untuk verifikasi dan penyelesaian akhir. |
| **P0** | Akses tagihan dan riwayat pembayaran | `GET /api/stores/{storeId}/settlement`, `settlement-history`, dan `payments` tertulis **Admin saja**. Berikan Driver akses baca yang dibatasi pada toko yang berhak ia tangani. |
| **P0** | Daftar seluruh freezer pada toko | Detail toko di Swagger belum mengembalikan daftar freezer dan belum terlihat endpoint untuk mengambil seluruh freezer milik toko. Aplikasi membutuhkannya agar Driver dapat memproses semuanya, termasuk freezer yang belum pernah dikunjungi. |
| **P0** | Freezer tidak dapat diperiksa | Belum ada cara mencatat hasil kunjungan selain konfirmasi stok. Tambahkan hasil pengecualian beserta alasan, dan tentukan bahwa kasus ini tetap dapat dikirim ke rekonsiliasi dengan penanda masalah. |
| **P1** | Memperbaiki input freezer | Ada `POST /api/delivery-items/confirm` dan `GET /api/delivery-items/{id}`, tetapi belum ada endpoint edit. Perlu edit oleh Driver selama pengiriman berjalan, sebelum pembayaran terkait `CONFIRMED`, dengan riwayat perubahan serta hitung ulang penjualan dan outstanding. Ini juga perlu menyelaraskan BRD yang saat ini menyebut penjualan terkunci setelah konfirmasi. |
| **P1** | Alokasi pembayaran | Contoh `POST /api/payments/collect` meminta `payment_type: TODAY`, sedangkan keputusan kita adalah pembayaran fleksibel dan **tagihan tertua dilunasi lebih dulu**. Minta backend memastikan alokasi otomatis dan mengembalikan rinciannya; Driver cukup memasukkan nominal serta metode. Tegaskan pula pembayaran tidak boleh melebihi outstanding, tidak ada saldo deposit, dan Rp0 tidak membuat record pembayaran. |
| **P1** | Bukti pembayaran yang ditolak | Upload bukti saat ini hanya disebut berlaku untuk pembayaran `PENDING`; pembayaran bisa menjadi `REJECTED` setelah keputusan Admin. Perlu mekanisme Driver mengunggah bukti pengganti, baik dengan membuka ulang pembayaran maupun membuat pengganti yang tertaut ke pembayaran lama. |
| **P1** | Receipt digital toko | Swagger memiliki upload **bukti transfer/QRIS**, tetapi belum terlihat endpoint untuk menerbitkan atau mengambil **receipt pembayaran untuk toko**. Perlu format receipt, identitas transaksi, nominal, metode, dan status yang jelas. Berbagi lewat WhatsApp tetap di luar lingkup saat ini. |
| **Verifikasi** | Kunjungan pertama dan pembatasan data Driver | Minta contoh respons resmi saat freezer pertama kali dikunjungi: penjualan Rp0 dan tidak ada pembayaran. Selain itu, `GET /api/deliveries` tertulis “semua pengiriman”; pastikan backend membatasi daftar dan detail sesuai Driver yang ditugaskan, bukan hanya menyaringnya di UI. |

## Multi-gudang (productions.warehouse_id)

`GET /api/warehouses/{id}/available-stock` sekarang benar-benar per gudang. Sebelumnya
endpoint ini menjumlah SELURUH tabel `productions`, sehingga dua gudang selalu melaporkan
angka yang sama, dan `POST /api/deliveries` bisa lolos walaupun gudang tujuan kosong.

- `GET /api/productions?warehouse_id=1` — filter batch per gudang
- `POST /api/productions` — `warehouse_id` sekarang wajib (422 bila tidak dikirim)
- `data.unassigned_production_qty` — batch POSTED yang belum punya gudang; tidak dihitung
  di `available_stock` gudang mana pun, dan tidak diatribusikan diam-diam

`payment_type` tidak berubah: `TODAY` | `PAST_DAYS` | `DEBT`, dipilih driver saat konfirmasi
(tetap manual, sesuai BRD awal).

## Rute pengiriman (delivery_stops)

Rencana pengiriman sekarang menyimpan tokotujuan. Sebelumnya `POST /api/deliveries` hanya
menerima driver, kendaraan, gudang, dan qty muat — tidak ada satu pun catatan ke mana driver
pergi. `store_id` baru muncul setelah driver sudah di depan toko itu, sehingga selama
pengiriman berjalan tidak ada yang bisa menjawab "driver ini seharusnya ke mana".

- `POST /api/deliveries` — `stores[]` **wajib**, minimal 1 toko. Urutan kunjungan dihitung
  nearest-first dari gudang asal, bukan urutan ketik. Breaking change: 422 bila `stores` kosong
- `PUT /api/deliveries/{id}` — `stores[]` mengganti seluruh rute (hanya status DRAFT)
- `GET /api/deliveries/{id}/route` — baru. Urutan toko, `leg_km` per leg, `total_km`,
  `total_minutes`, `current_stop`, dan progres `stops_visited`/`stops_total`
- `POST /api/delivery-items/confirm` — 422 bila toko tidak ada di rute; stop jadi `VISITED` pada
  konfirmasi freezer pertama di toko tersebut
- `GET /api/deliveries` — role DRIVER hanya menerima delivery miliknya sendiri (sebelumnya
  `Delivery::get()` tanpa filter, semua driver melihat semua pengiriman)
- Role endpoint: `POST`/`PUT /api/deliveries` terbuka untuk DRIVER, `POST /api/deliveries/{id}/start`
  khusus ADMIN (lihat bagian pemisahan draft di bawah)
- `collection_target` — **dihapus total**. BRD tidak punya kolom ini di form plan, dan
  kolomnya sudah di-drop lewat migration `2026_09_30_000007`, bukan sekadar disembunyikan
  dari validasi. Alasannya scope-nya beda: target penagihan yang BRD sebut ada di layar progres
  dan dashboard admin (BRD §2.1 "Target collection: Rp 1.5M") adalah angka harian perusahaan,
  bukan janji per pengiriman, jadi tidak mungkin menempel pada satu run. Kalau nanti dibutuhkan,
  ia milik periode (hari/bulan), bukan milik satu van
- `warehouses.latitude` / `warehouses.longitude` — kolom baru nullable, karena urutan rute
  dihitung dari gudang asal

Jarak memakai garis lurus dikali 1,35 sebagai pendekatan jarak jalan, tanpa API peta berbayar.
Leg yang salah satu ujungnya tidak punya koordinat dikembalikan `leg_km: null`.

## Saran IoT per toko (Smart Delivery)

BRD §2.2 membuka pagi dengan "Smart Delivery" — daftar yang perlu kiriman, diurutkan menurut
prioritas, **sebelum** plan dibuat. Endpoint ini hanya saran: tidak ada yang ditulis, dan
pemilihan toko tetap milik admin yang meneruskannya ke `POST /api/deliveries`.

- `GET /api/deliveries/suggestions` — baru. `role:ADMIN,WAREHOUSE`. Query opsional
  `store_ids[]` untuk mempersempit ke daftar pendek

Response-nya satu list datar, **satu baris per freezer**:

```
{ "success": true, "data": { "suggestions": [
  { "store_id": 1, "store": "RSA-001 (Toko Rapi)", "freezer_code": "FRZ-46674",
    "estimated_stock_ball": 0, "suggest_ball": 10, "label": "HIGH" },
  { "store_id": 1, "store": "RSA-001 (Toko Rapi)", "freezer_code": "FRZ-99444",
    "estimated_stock_ball": 2.5, "suggest_ball": 7.5, "label": "MEDIUM" } ] } }
```

Tier diambil persis dari BRD: `HIGH` estimasi 0, `MEDIUM` 3-5, `LOW` di atas 5. Di dalam
satu tier, freezer paling kosong diurutkan dulu, lalu yang paling butuh es.

Tiga hal yang perlu diketahui sebelum layar ini dipakai:

**BRD melompat dari 0 ke 3-5, jadi 1 dan 2 tidak masuk tier mana pun.** Freezer yang
hampir kosong itu tidak bisa truthfully dimasukkan ke `MEDIUM` (yang isinya 3-5) atau `HIGH`
(yang isinya 0). Keduanya dilapor sebagai `UNKNOWN`, bukan dipaksa ke tetangga terdekatnya.
Kalau memang mau 1-2 masuk `MEDIUM`, itu perubahan ambang — perlu diputuskan, bukan ditebak.

**Freezer yang belum pernah melapor bukan freezer kosong.** `estimated_stock_ball` di model
mengembalikan 0 saat `last_weight_kg` null, jadi sensor mati akan menaruh barisnya di puncak
daftar urgent. Service memeriksa `last_weight_kg` terpisah: tanpa bacaan → `UNKNOWN`, bukan
`HIGH`. Status sensor yang diam sudah cukup membuat saran itu tidak bisa diandalkan.

**Kuantitasnya total freezer, bukan per produk.** Load cell menimbang satu freezer utuh
dan tidak tahu isinya produk apa, jadi pembagian antar produk tetap keputusan driver di toko.

Endpoint ini membaca tabel `freezers` yang sudah tersimpan, **bukan** `IotDataService` yang
masih mock. Rankings therefore dihitung dari telemetry nyata — tapi hanya yang sudah masuk lewat
sink; angka yang belum sync tidak akan muncul.

## Kunjungan per stop (arrive / depart / skip)

BRD §2.3 memandangkan datang dan pergi sebagai dua langkah terpisah, sebelum adegan di toko.
Sebelumnya tidak ada endpoint-nya sama sekali, dan satu-satunya cara menutup stop adalah
konfirmasi freezer. Akibatnya toko yang didatangi lalu tidak ada yang dibeli **tidak bisa ditutup
sama sekali** — stop menggantung PENDING sampai run selesai, dan `stops_completed` melaporkan
angka yang lebih kecil dari kenyataan. Toko tutup juga tidak punya cara untuk dilewati.

- `POST /api/deliveries/{id}/stops/{storeId}/arrive` — mencatat kedatangan. Idempoten, waktu
  pertama tidak ditimpa. Balikannya membawa `store` + `freezers` (bersama status IoT) supaya
  layar tiba tidak perlu request tambahan
- `POST /api/deliveries/{id}/stops/{storeId}/depart` — mencatat kep departing. Stop yang punya
  baris konfirmasi ikut jadi `VISITED`; yang tidak punya **tetap PENDING** dan ditutup oleh
  `departed_at` saja, karena tidak ada barang di sana
- `POST /api/deliveries/{id}/stops/{storeId}/skip` — `reason` **wajib**, karena alasan yang
  membedakan toko tutup dari toko tidak bisa dilayani
- `GET /api/deliveries/{id}/stops/{storeId}/settlement-preview` — `outstanding_before_this_stop`
  + `this_visit_sales` = `payable_today`, plus `payment_options`

Tiga catatan yang mungkin perlu dikonfirmasi tim:

**Sales di stop ini masih `PENDING`, jadi belum jadi utang.** `payable_today` menjumlahkan
utang yang sudah di-approve dengan penjualan yang baru dicatat driver. Kalau sales dihitung dua
kali, pratinjau akan berbeda dengan saldo toko begitu pembayaran masuk. Sales dicari lewat
`delivery_item_id`, bukan langsung ke delivery, supaya penjualan run sebelumnya di toko yang sama
tidak ikut terhitung sebagai hasil kunjungan ini.

****`/start` bukan lagi milik admin saja.** Sebelumnya `POST /api/deliveries/{id}/start` hanya
ADMIN. Sekarang `ADMIN, DRIVER`, dan persetujuan dipisah ke `POST /api/deliveries/{id}/post`
(ADMIN). Alasannya BRD §2.3 `:417` memberi driver tombol `[START DELIVERY]`, dan BRD `:367`
menyebut "Budi submitted: Delivery complete" — jadi driver menekan start dan menutup run-nya
sendiri. `PUT` dan `DELETE` tetap `ADMIN, DRIVER` seperti sebelumnya.

**Isolasi driver masih setengah.** `GET /api/deliveries` sudah difilter ke milik sendiri, dan
sekarang `show()`, `/route`, `/summary`, `PUT /api/deliveries/{id}`, `/start`, `/complete`, serta
keempat endpoint stop di atas juga menolak 403 untuk delivery driver lain. Yang belum ditutup:
`GET /api/deliveries/{id}/items` dan `GET /api/stores/{storeId}/settlement*` — keduanya
`role:ADMIN` jadi driver tidak bisa, tapi `role:ADMIN,DRIVER` di `stores/{storeId}/delivery-items`
masih meloloskan data delivery toko mana pun ke driver mana pun.

## Rencana miliknya admin + driver; warehouse menjaga stok, memuat, dan membaca

Status `deliveries` sekarang punya `POSTED` di antara `DRAFT` dan `IN_PROGRESS`
(migration `2026_09_30_000006`). Persetujuan dan menyalakan dipisah jadi dua endpoint karena
keduanya langkah orang berbeda:

| Langkah | Endpoint | Role | Bukti |
| --- | --- | --- | --- |
| Susun draft (DRAFT) | `POST /api/deliveries` | ADMIN, DRIVER | Keputusan tim, menyimpang dari BRD |
| Susun draft, ubah draft (DRAFT) | `PUT /api/deliveries/{id}` | ADMIN, DRIVER | BRD tidak menyebut driver menyusun rencana; driver boleh menyiapkan run-nya sendiri |
| Setujui (DRAFT → POSTED) | `POST /api/deliveries/{id}/post` | ADMIN | Keputusan tim: persetujuan milik pemilik |
| Jalankan (POSTED → IN_PROGRESS) | `POST /api/deliveries/{id}/start` | ADMIN, DRIVER | BRD:417 `[VIEW ROUTE] → [START DELIVERY]` di section 2.3 DRIVER |
| Tutup (IN_PROGRESS → COMPLETED) | `POST /api/deliveries/{id}/complete` | ADMIN, DRIVER | BRD:367 "Budi submitted: Delivery complete" |
| Catat kunjungan toko | `arrive` / `depart` / `skip` / `settlement-preview` | ADMIN, DRIVER | BRD:435 "At Store - SETTLEMENT WORKFLOW (Main Process)", seluruhnya driver |
| Hapus draft | `DELETE /api/deliveries/{id}` | ADMIN | BRD tidak punya baris delete/cancel sama sekali |

Yang dihapus dari warehouse, dan alasannya: `/start`, `/complete`, keempat endpoint stop,
`POST /api/deliveries`, `PUT`, dan `DELETE`. Alasannya bukan satu, dan dipisah supaya tidak
tercampur:

- **`/start`, `/complete`, stop visit** — BRD mengalokasikan langkah itu ke driver. BRD:421 hanya
  memberi warehouse tugas "Verify & load 50 ball", BRD:367 menutup run dari sisi Budi, BRD:435+
  seluruhnya driver. Tidak ada satu pun baris BRD yang memberi warehousehak menekan Start,
  menutup run, atau mencatat kunjungan.
- **`POST` dan `PUT /api/deliveries`** — **ini menyimpang dari BRD dan itu keputusan tim.**
  BRD:342-352 menaruh `[CREATE DELIVERY PLAN]` di dalam section 2.2 WAREHOUSE STAFF. Tim
  memutuskan penyusunan dan persetujuan rencana milik admin + driver, jadi akses warehouse ke
  endpoint itu dicabut. Dicatat eksplisit supaya tidak ditemukan ulang nanti sebagai bug.
- **`DELETE`** — BRD tidak menyebut delete atau cancel delivery di mana pun ("delete" nol
  kemunculan di seluruh dokumen). Ini murni keputusan tim, dan dicabut juga dari driver:
  rencana adalah catatan admin tentang apa yang disepakati, jadi driver yang jadi batal
  membiarkan draft-nya DRAFT.

Warehouse **tetap boleh membaca** semua itu (`GET /deliveries`, `/{id}`, `/route`, `/summary`,
`/items`, `/suggestions`). BRD:258-260 menaruh "Delivery plans & status", "IoT suggestions", dan
"Active deliveries progress" di What They See, dan BRD:374 butuh total terkirim untuk
"Wardhouse stock auto-update: 250 - 48 = 202 ball". Membaca bukan bertindak: section yang sama
tidak menaruh warehouse di Start, Complete, maupun langkah toko.

`POSTED` berarti terkunci: `PUT` dan `DELETE` menolak dengan 422, dan tidak ada endpoint
`POSTED → DRAFT`. Koreksi rencana berarti cancel lalu buat draft baru. Stok gudang dicek
kembali di `/post`, bukan hanya di `/start`, supaya rencana yang disetujui atas janji stok yang
sudah terpakai gagal sekarang, saat masih ada manusia yang memutuskan, bukan besok pagi di
area loading.

## Spec dan route tidak boleh berbeda lagi

`tests/Feature/ApiContractTest` membaca route table sungguhan lalu membandingkannya dengan
`resources/swagger/openapi.json`, jadi spec tidak bisa lagi diam-diam bohong. Yang dijaga:

- setiap route `/api` ada di spec, dan setiap endpoint di spec ada di route;
- tiap operation mendeklarasikan `x-roles`, dan nilainya harus sama dengan `role:` di
  `routes/api.php` — persis, bukan "memuat role yang sama";
- kalimat "Endpoint ini butuh ..." di description 403 harus cocok dengan `x-roles`;
- contoh response 403 harus menyebut role yang memang ditolak, bukan role yang diizinkan.

Inilah yang menangkap enam endpoint yang rolesnya sudah dilepas di `routes/api.php` tapi masih
mengiklankan `ADMIN, WAREHOUSE, DRIVER` di spec, dan `/post` yang hidup di route tapi tidak ada
di spec sama sekali. Kuncinya: cek turunan API setiap kali role berubah, karena spec tidak pernah
menyebut role middleware-nya sendiri.

## Bentuk response suggestion

Baris diulang **per freezer**, bukan dijumlah per toko, dan `label` dihitung dari
`estimated_stock_ball` di baris yang sama. Alasannya: begitu satu baris cuma berisi satu
kode freezer, angka total toko yang tertulis di sebelahnya akan terbaca seolah-ohliah
itu bacaan kulkas itu. Kalau label ikut total toko, baris dengan estimasi 0 bisa tertulis
`MEDIUM` — baris jadi bertentangan dengan angkanya sendiri.

Konsekuensinya `store` bisa muncul beberapa kali, dan itu memang perlu: dua freezer di
toko yang sama butuh kiriman berbeda. Freezer yang berbeda labelnya juga diurutkan
menyatu dengan baris toko lain, bukan dikelompokkan per toko.

`label` sengaja polos (`HIGH`), bukan `🔴 HIGH PRIORITY (Est stock 0)`. Emoji dan teks
ambang itu milik FE; mengulang ambangnya di payload cuma membuka pintu label meleset
dari angkanya.

Field yang sengaja tidak dikirim: `no` (FE cukup iterate untuk nomor), `confidence`,
`warehouse_id`, `tier`, `generated_at`, dan `note`. `confidence` pernah ada karena BRD:340
menulisnya, tapi BRD tidak menjelaskan rumusnya — jadi yang bisa dikirim hanya angka yang
tidak perlu dipercaya tanpa dasar, dan layar ini sudah cukup menjelaskan dirinya lewat
`label` plus angka di sebelahnya.

Catatan: IoT di `IotDataService` masih mock gateway. Saran membaca tabel `freezers`, bukan
gateway itu, jadi angka yang tampil adalah pembacaan yang benar-benar tersimpan.

