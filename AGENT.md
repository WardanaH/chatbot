# Project Context: WhatsApp Bot Order Cetak - Restu Guru Promosindo

## 1. Peran & Tujuan

Kamu adalah AI Assistant (OpenCode) yang membantu mengembangkan sistem Bot WhatsApp Order Cetak berbasis Laravel. Bot ini terintegrasi dengan Fonnte (WhatsApp Gateway), Groq AI (LLM), dan REST API internal dari domain `restugurupromosindo.com`.

## 2. Standar Dokumentasi (WAJIB)

- **Sinkronisasi FLOW.md:** Jika selama proses penulisan kode terdapat perubahan, penambahan, atau penyesuaian pada alur percakapan (_state machine_), kamu **WAJIB** memperbarui isi file `FLOW.md` agar selalu sinkron dengan _logic_ yang ada di dalam kode. Jangan biarkan dokumentasi tertinggal dari kode.

## 3. Tech Stack & Aturan Main

- **Framework:** Laravel (PHP).
- **Database:** MySQL (Gunakan tabel `bot_sessions` untuk melacak `no_wa`, `step_saat_ini`, `sudah_disapa`, `data_order`, `cabang`, dan `is_komplain`).
- **Webhook & Antrean (Queue):**
    - Webhook dari Fonnte HARUS mengembalikan respons HTTP 200 secepat mungkin.
    - Semua pemrosesan teks, pemanggilan API Groq, dan API internal harus dieksekusi di background menggunakan Laravel Queue (Jobs).
- **AI Engine:** Groq API, tiga model (semua output JSON via `response_format`):
    - `openai/gpt-oss-20b` untuk routing intent cepat (`model_router`).
    - `openai/gpt-oss-120b` untuk NLP/NLU form order (`model_form`).
    - `openai/gpt-oss-120b` untuk FAQ RGP (`model_ask`), dengan knowledge base dari file `INFORMASI_RGP.md` dan fallback `config/bot.php` → `faq.teks_bukan_rgp`.
- **Eksternal API:** Gunakan `Illuminate\Support\Facades\Http` untuk menarik data dinamis (bahan, produk, harga) dari REST API `restugurupromosindo.com`. Fitur ini bisa dimatikan via `BOT_MASTER_DATA_ENABLED=false` (default) karena endpoint saat ini timeout (~25-30 detik).

## 4. Gaya Kode & Pengujian (Testing)

- **Penamaan:** Gunakan penamaan variabel/method dengan bahasa Inggris atau Indonesia yang konsisten (misal: `ProcessWhatsAppWebhook` atau `ChatSession`).
- **Error Handling:** Terapkan _Try-Catch_ yang ketat pada Job saat memanggil Groq atau Fonnte, agar _Job_ tidak _fail_ diam-diam.
- **Testing Pragmatis:** Jangan melakukan pengujian (_testing_) yang muluk-muluk atau _over-engineering_. Fokus pada _test_ yang sederhana, fungsional, dan _to-the-point_ (misal: memastikan webhook me-return 200 OK, dan memastikan respons Groq berhasil di-parsing sebagai JSON). Hindari setup _mocking_ atau _unit test_ yang terlalu kompleks kecuali diminta.
