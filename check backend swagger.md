

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
- `collection_target` — kolom baru di `deliveries`, sesuai BRD "Target collection: Rp 500K"
- `warehouses.latitude` / `warehouses.longitude` — kolom baru nullable, karena urutan rute
  dihitung dari gudang asal

Jarak memakai garis lurus dikali 1,35 sebagai pendekatan jarak jalan, tanpa API peta berbayar.
Leg yang salah satu ujungnya tidak punya koordinat dikembalikan `leg_km: null`. Endpoint saran IoT
per toko ("which stores need delivery", BRD §2.2) belum ada dan sengaja ditunda: `IotDataService`
masih memakai data mock, jadi peringkat toko yang dibangun di atasnya akan terlihat otoritatif
padahal bukan.

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

**`collection_target` ada dua scope di BRD.** Yang di `deliveries` milik satu pengiriman (BRD
§2.3, berdampingan dengan "Stops: 5 toko"). Yang di `GET /api/dashboard/summary` (BRD §2.1
"Target collection: Rp 1.5M") adalah target harian perusahaan dan **belum ada** —
`DashboardController@getSummary` tidak mengirimkannya, dan BRD tidak menyebut dari mana angka itu
disimpan. Perlu diputuskan: config, tabel settings, atau agregasi hari-hari sebelumnya.

**Isolasi driver masih setengah.** `GET /api/deliveries` sudah difilter ke milik sendiri, dan
sekarang `show()`, `/route`, `/summary`, serta keempat endpoint stop di atas juga menolak 403
untuk delivery driver lain. Yang belum ditutup: `GET /api/deliveries/{id}/items` dan
`GET /api/stores/{storeId}/settlement*` — keduanya `role:ADMIN` jadi driver tidak bisa, tapi
`role:ADMIN,DRIVER` di `stores/{storeId}/delivery-items` masih meloloskan data delivery toko
mana pun ke driver mana pun.
