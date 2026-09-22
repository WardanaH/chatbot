# Alur Percakapan Bot (State Machine)

Alur ini mengacu pada SOP "Fast Reply ONLINE.pdf" dan **sinkron dengan implementasi `app/Services/ChatFlowManager.php`**. Bot membaca `step_saat_ini` pada tabel `bot_sessions` untuk menentukan langkah selanjutnya.

Nama state yang dipakai di kode:

`GREETING_INTENT` → `KETENTUAN_ORDER` → `ISI_FORM` → `REVIEW_ACC` → `PILIH_CABANG` → `SELESAI`

Browser kata kunci detektif deterministik dilakukan terlebih dahulu; fallback ke Groq router (`openai/gpt-oss-20b`) untuk intent bebas-form. Sesi baru dibuat saat pesan pertama (state default `GREETING_INTENT`). Ketik *"reset" / "mulai ulang"* kapan saja untuk mengulang dari awal.

Bot menjawab pertanyaan umum seputar RGP via `GroqService::askRgp` (model `openai/gpt-oss-120b`) dengan knowledge base dari file `INFORMASI_RGP.md` (root proyek). Pertanyaan yang **bukan tentang RGP** dibalas teks tetap dari `config/bot.php` (`faq.teks_bukan_rgp`): *"Saya RG Boy… Berikut Perintah yang dapat anda gunakan…"* + daftar perintah.

## STATE 1: GREETING_INTENT
*   **Pemicu:** Pesan masuk pertama kali dari pelanggan (atau reset).
*   **Deteksi order:** Jika pesan mengandung perintah `!pesan` / kata kunci order (`pesan`, `order`, `cetak`, `beli` di `config/bot.php` → `kata_kunci.pesan`) atau intent router `pesan` → bot kirim intro order + syarat order (lihat STATE 2), state → `KETENTUAN_ORDER`.
*   **Menu/sapaan:** Pada sapaan (`halo`, `pagi`, `assalamualaikum`, dll. atau intent `sapaan`), bot mengirim pesan menu/instruksi RG Boy: order via `!pesan`, tanya langsung seputar RGP. Menu dikirim hanya **sekali per sesi** (kolom `sudah_disapa`); sapaan berikutnya dibalas singkat. State **tetap `GREETING_INTENT`**.
*   **Pertanyaan:** Intent `pertanyaan` / pesan bertanya lain → bot menjawab via `askRgp` (jawaban RGP atau teks fallback jika non-RGP), state tetap `GREETING_INTENT` (tidak maju ke order).
*   **Update State:** Hanya maju ke `KETENTUAN_ORDER` saat pelanggan ingin order.

## STATE 2: KETENTUAN_ORDER
*   **Aksi:** Bot mengirimkan syarat order online:
    *   Biaya penanganan online Rp. 10.000.
    *   Biaya desain mulai Rp. 15.000 (gratis jika file siap cetak).
    *   Revisi minor desain maksimal 3x.
    *   Kewajiban DP Rp. 30.000 untuk menyetujui proses.
*   **Validasi:** Deteksi persetujuan pelanggan via kata kunci (`setuju`, `oke`, `lanjut`, `transfer`, dll.) atau intent `setuju_ketentuan` dari Groq router.
*   **FAQ:** Jika router mengembalikan intent `pertanyaan` → bot menjawab via `askRgp`, lalu menyodorkan ulang pertanyaan persetujuan ketentuan (state tetap). Intent `sapaan` → balasan singkat ramah (tanpa panggil AI), state tetap.
*   **Update State:** Jika setuju → `ISI_FORM` (pesan intro form dikirim, `no_wa` di-prefill ke `data_order`). Jika tidak → bot mengingatkan syarat dan menunggu konfirmasi.

## STATE 3: ISI_FORM
*   **Aksi:** Bot meminta pelanggan mengisi 8 field order (boleh sekaligus atau bertahap):
    1.  `nama_pemesan`
    2.  `no_wa` (diisi otomatis dari nomor pengirim)
    3.  `produk`
    4.  `ukuran`
    5.  `bahan` *(data bahan/produk/harga ditarik live dari REST API `restugurupromosindo.com` via `MasterDataService`, cache 5 menit; bisa dimatikan via `BOT_MASTER_DATA_ENABLED=false` karena endpoint saat ini timeout)*
    6.  `jumlah`
    7.  `selesai`
    8.  `status_desain` (Sudah/Belum)
*   **Proses AI:** `GroqService::parseOrderForm` (model `openai/gpt-oss-120b`) mem-parsing balasan pelanggan ke JSON, lalu di-merge ke kolom `data_order`.
*   **FAQ:** Jika pesan terdeteksi sebagai pertanyaan (kata tanya `berapa`, `harga`, `apa`, `kenapa`, dll. atau berakhiran `?`) → bot menjawab via `askRgp`, lalu **menyodorkan ulang daftar field yang masih kosong** (state tetap `ISI_FORM`, data form tidak diubah).
*   **Update State:** Jika ada field kosong → bot menyebut field yang kurang dan tetap di `ISI_FORM`. Jika semua field terisi → kirim ringkasan/preview → `REVIEW_ACC`.

## STATE 4: REVIEW_ACC
*   **Aksi:** Bot mengirimkan ringkasan pesanan (`data_order`) untuk dicek pelanggan (Ejaan, Warna, Ukuran, dll).
*   **Validasi:** Deteksi "ACC" via kata kunci (`acc`, `oke`, dsb.) atau intent `acc_desain` dari Groq router. Bot memberikan peringatan bahwa kesalahan setelah ACC Produksi adalah tanggung jawab pelanggan, plus info DP Rp. 30.000.
*   **FAQ:** Jika router mengembalikan intent `pertanyaan` → bot menjawab via `askRgp` lalu mengingatkan untuk cek ringkasan & balas "ACC" (state tetap). Intent `sapaan` → balasan singkat ramah.
*   **Update State:** Jika ACC → `PILIH_CABANG`.

## STATE 5: PILIH_CABANG (HANDOFF)
*   **Aksi:** Bot menanyakan cabang pengambilan/pengerjaan (Banjarmasin, Liang Anggang, Banjarbaru, Martapura). Pilihan bisa via nomor (1-4) atau nama cabang.
*   **FAQ:** Jika pesan terdeteksi sebagai pertanyaan → bot menjawab via `askRgp` lalu menanyakan ulang pilihan cabang (state tetap).
*   **Eksekusi:** Setelah pilih → simpan `cabang`, lalu:
    1.  Forward rekap pesanan utuh ke nomor admin cabang (`admin_wa` di `config/bot.php`) lewat Fonnte dengan format "PESANAN BARU - Cabang: <cabang>" + no. pelanggan + nama pemesan (di-skip bila `admin_wa` sama dengan nomor pelanggan, agar tidak muncul dobel di nomor yang sama saat masa tes).
    2.  Kirim rekap ke pelanggan + link `wa.me` admin cabang untuk lanjut konfirmasi.
*   **Update State:** → `SELESAI`.

## STATE 6: SELESAI
*   **Aksi (Order Baru):** Jika pelanggan berminat memesan lagi (kata kunci `order_baru`: `mau order`, `bisa order`, `order lagi`, `pesan lagi`, `cetak`, `halo`, dll. di `config/bot.php`), bot me-redet alur: reset `data_order`/`cabang`/`is_komplain`, state → `GREETING_INTENT`, lalu ke STATE 1.
*   **Aksi (Normal):** Bot menginformasikan pesanan diteruskan ke admin cabang, cetakan selesai setelah lolos Quality Control, siap diambil jam operasional (Senin-Sabtu 09.00-22.00).
*   **Aksi (Komplain):** Jika intent komplain terdeteksi (kata kunci `komplain`, `keluhan`, `rusak`, dsb. atau intent `komplain` dari router), bot set `is_komplain=true`, kirim permohonan maaf dan mohon tunggu pengecekan.
*   **FAQ:** Jika pesan terdeteksi sebagai pertanyaan → bot menjawab via `askRgp`, state tetap `SELESAI`.

## Konfigurasi
*   `config/bot.php` — biaya/DP/jam operasional, label field form, kata kunci intent (`kata_kunci.pesan` untuk mulai order), daftar cabang + nomor admin (`admin_wa`), dan blok `faq` (nama bot RG Boy, teks menu, teks fallback non-RGP, kata tanya & sapaan).
*   `INFORMASI_RGP.md` (root) — knowledge base profil/produk/harga/kontak RGP yang dibaca `askRgp`.
*   `config/services.php` — kredensial Fonnte (`fonnte`), Groq (`groq`), dan endpoint REST API (`rgp`).
*   `.env` — `FONNTE_TOKEN`, `GROQ_API_KEY`, `RGP_API_BASE_URL`, `GROQ_MODEL_FORM` (`openai/gpt-oss-120b`), `GROQ_MODEL_ROUTER` (`openai/gpt-oss-20b`), `GROQ_MODEL_ASK` (`openai/gpt-oss-120b`).