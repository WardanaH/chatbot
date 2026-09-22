# PLAN: Bot Jawab Pertanyaan Pelanggan (FAQ RGPromosindo)

> Status: **SUDAH DIKERJAKAN** (sesi terkini) — tersimpan sejarah rencana di bawah. Update file ini bila ada perubahan.
> Tujuan: bot WhatsApp tidak hanya melayani order cetak, tetapi juga menjawab pertanyaan umum seputar Restu Guru Promosindo (RGP).

## Status Eksekusi Terkini (2026-09-22)

Fitur "RG Boy" sudah diimplementasikan:

- **Grup sapaan:** pelanggan menyapa → bot kirim menu instruksi (`!pesan` untuk order, tanya langsung untuk FAQ), state tetap `GREETING_INTENT`.
- **Gerbang order:** `!pesan` ATAU niat order natural sama-sama memulai alur order → `KETENTUAN_ORDER`.
- **FAQ semua state:** `GroqService::askRgp` menjawab pertanyaan RGP di `GREETING`, `KETENTUAN_ORDER`, `ISI_FORM`, `REVIEW_ACC`, `PILIH_CABANG`, `SELESAI`. Setelah jawab, bot menyodorkan ulang pertanyaan orderan yang tertunda (persetujuan ketentuan, field form kosong, ACC, pilih cabang).
- **Non-RGP:** pertanyaan yang tidak berkaitan RGP dibalas teks persis `config('bot.faq.teks_bukan_rgp')` ("Saya RG Boy… Berikut Perintah yang dapat anda gunakan…") + daftar perintah.
- **Knowledge base:** dibaca dari `INFORMASI_RGP.md` (root proyek), cache 5 menit.
- **Bug ikutan:** `routerPredict()` diperbaiki agar mengembalikan label intent ter-decode (sebelumnya string JSON mentah sehingga perbandingan `===` di pemanggil tidak pernah cocok).
- Migrasi baru: kolom `sudah_disapa` (boolean) di `bot_sessions`.
- Test hijau: `GroqServiceTest` (askRgp/relevan-fallback, router decode) & `ChatFlowGreetingTest` (sapaan, `!pesan`, pertanyaan RGP, non-RGP, FAQ di ISI_FORM).

### Deviasi dari rencana awal
1. KB disimpan di file `INFORMASI_RGP.md` (bukan blok `config/bot.php`).
2. `askRgp` memakai respons JSON terstruktur (`{"relevan": bool, "jawaban": str}`) — bukan chat biasa — supaya teks fallback non-RGP dijamin 100% persis dari config, tanpa bergantung verbatim LLM.
3. Model `askRgp` memakai `openai/gpt-oss-120b` (`GROQ_MODEL_ASK`, default `config/services.php`).

## Latar Belakang

- Saat ini AI (`GroqService`) hanya dipakai untuk 2 hal:
  - `routerPredict()` — intent cepat (model `openai/gpt-oss-20b`).
  - `parseOrderForm()` — ekstraksi JSON form order (model `openai/gpt-oss-120b`).
- Belum ada kemampuan menjawab pertanyaan umum pelanggan (harga, produk, jam operasional, ketentuan, kontak, dll).
- Kredensial: API **Groq** (bukan OpenAI langsung) — `GROQ_API_KEY=gsk_...`, model `openai/gpt-oss-120b` & `openai/gpt-oss-20b`.

## Perubahan yang Direncanakan

### 1. `app/Services/GroqService.php` — method baru
- `askRgp(string $pertanyaan, array $masterData = []): string`
  - Panggil model `openai/gpt-oss-120b` (chat completions, bukan JSON).
  - System prompt berisi knowledge base RGP (profil, produk, harga, ketentuan, kontak, cabang) + master data (jika ada).
  - Instruksi: jawab ramah & ringkas dalam bahasa Indonesia; kalau tidak tahu → arahkan ke admin cabang.

### 2. Router intent baru `pertanyaan`
- Update system prompt `routerPredict()`:
  - Tambah intent `"pertanyaan"`: pelanggan bertanya tentang produk/harga/layanan/ketentuan RGP (bukan niat order langsung).
  - Intent lain tetap: `setuju_ketentuan`, `acc_desain`, `komplain`, `pilih_cabang`, `reset`, `lainnya`.

### 3. Integrasi state machine (`app/Services/ChatFlowManager.php`)
- Gerbang di `GREETING_INTENT`:
  - Router dipanggil saat pesan pertama.
  - Intent `pertanyaan` → kirim jawaban via `askRgp`, **state tetap GREETING** (jangan langsung maju ke KETENTUAN_ORDER).
  - Niat order / lainnya → alur normal (sapaan + syarat order).
- (Opsional / menunggu keputusan) Di tengah `ISI_FORM`/state lain, jika pesan more ke pertanyaan daripada isi form → jawab dulu lalu tanya field yang kurang.

### 4. Knowledge Base di `config/bot.php`
- Blok baru (data diisi oleh owner / user, karena model tidak bisa mengekstrak `Fast Reply ONLINE.pdf`):
  - `profil`: nama usaha, deskripsi singkat, alamat, jam operasional, kontak.
  - `produk`: daftar produk/jasa cetak (spanduk, banner, x-banner, kartu nama, brosur, stempel, dll) + keterangan.
  - `harga`: estimasi harga (atau referensi).
  - `kontak`: nomor admin per cabang (sudah ada di `cabang.*.admin_wa`).

### 5. Test pragmatis
- `Http::fake` untuk Groq:
  - `GroqServiceTest`: `askRgp` mengembalikan teks jawaban non-JSON.
  - Feature test: session `GREETING_INTENT`, kirim pesan pertanyaan ("Kak, harga spanduk berapa?") → router return `pertanyaan` → assert balasan terkirim & `step_saat_ini` tetap `GREETING_INTENT`.

### 6. Sinkronisasi Dokumentasi
- Update `FLOW.md`:
  - Tambah catatan FAQ di STATE 1 (GREETING): pesan pertanyaan umum → dijawab tanpa pindah state.
  - Sebutkan method `askRgp` dan model yang dipakai.
- Update `AGENT.md` bila diperlukan (tidak wajib; FLOW.md yang mandatory).

## Pertanyaan Terbuka (perlu keputusan user)

1. **Isi KB:** user memberikan langsung data RGP (produk, harga, jam operasional, kontak) ATAU assistant menyiapkan struktur + contoh di `config/bot.php` lalu user mengisi belakangan?
2. **Gerbang FAQ:** hanya di state `GREETING_INTENT`, atau juga harus menangani pertanyaan yang muncul di tengah form (`ISI_FORM` / state lain)?
3. **Data harga:** statis di `config/bot.php`, atau tetap menarik dari REST API `restugurupromosindo.com` (butuh path endpoint asli — `MasterDataService` saat ini timeout di endpoint tebakan `/api/produk`, `/api/daftar-produk`, dll)?

## Catatan Terselesaikan (dari sesi sebelumnya)

- Webhook Fonnte: payload asli bentuk top-level `sender` + `message` (sudah diperbaiki + dites).
- Header send Fonnte: `Authorization: <token>` tanpa Bearer (sudah diperbaiki).
- Forward rekap ke admin cabang: berjalan, tapi saat ini di-skip jika `admin_wa` == nomor pelanggan (kondisi tes karena semua admin diisi nomor owner). Saat produksi, isi `admin_wa` per cabang dengan nomor admin asli.
- Order baru setelah SELESAI: terdeteksi via kata kunci `order_baru` → reset ke GREETING (sudah berjalan).