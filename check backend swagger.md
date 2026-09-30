

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
