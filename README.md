# Bot WhatsApp Order Cetak — Restu Guru Promosindo (RG Boy)

Bot WhatsApp otomatis untuk menerima order cetak (spanduk, banner, kartu nama, dsb.) sekaligus menjawab pertanyaan pelanggan seputar Restu Guru Promosindo (RGP). Dibangun dengan **Laravel 12** dan terintegrasi dengan:

- **Fonnte** — WhatsApp Gateway (menerima pesan via webhook + mengirim balasan).
- **Groq AI** — klasifikasi intent, parsing isi form order, dan menjawab FAQ.
- **REST API internal** `restugurupromosindo.com` — master data bahan/produk/harga (opsional).

> Alur percakapan lengkap: [`FLOW.md`](FLOW.md) · Rencana kerja: [`PLAN.md`](PLAN.md) · Knowledge base FAQ: [`INFORMASI_RGP.md`](INFORMASI_RGP.md) · Panduan deploy: [`DEPLOY.md`](DEPLOY.md)

## Fitur

- **State machine 6 tahap**: `GREETING_INTENT` → `KETENTUAN_ORDER` → `ISI_FORM` → `REVIEW_ACC` → `PILIH_CABANG` → `SELESAI` (sesi disimpan per nomor WhatsApp di tabel `bot_sessions`).
- **Order cetak** — mulai dengan ketik `!pesan` atau niat order natural; input form boleh sekaligus atau bertahap.
- **FAQ RG Boy** — jawab pertanyaan seputar RGP dari knowledge base `INFORMASI_RGP.md`; pertanyaan non-RGP dibalas teks tetap.
- **Review & handoff** — ringkasan pesanan + konfirmasi ACC, pilih cabang, rekap di-forward ke admin cabang via Fonnte.
- **Komplain & order baru** — pasca-selesai, pelanggan bisa komplain atau order lagi.
- **Proses asinkron** — webhook balas `200 OK` seketika, pemrosesan (termasuk panggilan AI dan kirim pesan) berjalan di Laravel Queue.

## Prasyarat

- PHP **^8.2** (CLI + ekstensi `pdo_mysql`, `curl`, `mbstring`, `openssl`).
- Composer 2.
- MySQL.
- (Opsional) Node.js 18+ — hanya untuk build aset Vite; bot ini tidak menampilkan frontend.

## Setup Lokal

1. Install dependency:

   ```bash
   composer install
   ```

2. Buat file `.env`:

   ```bash
   cp .env.example .env
   ```

3. Isi `.env` — setidaknya bagian berikut:

   ```ini
   APP_NAME="RG Boy"
   APP_URL=http://localhost:8000

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=chatbot
   DB_USERNAME=root
   DB_PASSWORD=

   FONNTE_TOKEN=isi_token_fonnte
   GROQ_API_KEY=isi_api_key_groq
   ```

4. Generate app key dan buat skema tabel:

   ```bash
   php artisan key:generate
   php artisan migrate
   ```

5. (Opsional) Build aset Vite:

   ```bash
   npm install && npm run build
   ```

### Variabel `.env` khusus bot

| Variabel | Contoh | Keterangan |
| --- | --- | --- |
| `FONNTE_TOKEN` | `xxxxx` | Token API perangkat (device) di dashboard Fonnte |
| `FONNTE_BASE_URL` | `https://api.fonnte.com` | Base URL API Fonnte |
| `GROQ_API_KEY` | `gsk_xxxxx` | API key Groq |
| `GROQ_BASE_URL` | `https://api.groq.com/openai/v1` | Base URL API Groq |
| `GROQ_MODEL_ROUTER` | `openai/gpt-oss-20b` | Model klasifikasi intent (cepat) |
| `GROQ_MODEL_FORM` | `openai/gpt-oss-120b` | Model parsing isi form order |
| `GROQ_MODEL_ASK` | `openai/gpt-oss-120b` | Model FAQ RGP (`askRgp`) |
| `RGP_API_BASE_URL` | `https://restugurupromosindo.com` | Base URL REST API RGP |
| `BOT_MASTER_DATA_ENABLED` | `false` | Tarik master data bahan/produk/harga live; **default mati** karena endpoint RGP timeout (~25–30 dtk) |

Driver antrean/cache/sesi di `.env.example` (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`) — biarkan default; ketiganya memakai tabel database (di-buat oleh `php artisan migrate`).

## Menjalankan Bot

Cara termudah — satu perintah (web server + queue worker + log otomatis):

```bash
composer run dev
```

Atau manual (dua terminal):

```bash
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
```

> **PENTING:** tanpa queue worker aktif, pesan yang masuk hanya menumpuk di tabel `jobs` dan tidak dibalas.

## Webhook Fonnte

Bot menerima pesan via webhook Fonnte di endpoint:

```
POST /api/webhook/fonnte
```

URL webhook harus bisa diakses publik. Untuk tes lokal, gunakan tunnel (mis. ngrok):

```bash
ngrok http 8000
```

Lalu di **Dashboard Fonnte** (pada perangkat/sender yang dipakai), set URL webhook ke:

```
https://<id-tunnel>.ngrok-free.app/api/webhook/fonnte
```

Cara mendapat token Fonnte: halaman **Sender** di dashboard Fonnte → salin token perangkat → isi ke `FONNTE_TOKEN`.

## Menjalankan Test

```bash
composer test    # atau: php artisan test
```

## Struktur Inti

```
app/Http/Controllers/FonnteWebhookController.php   # terima webhook + dispatch job
app/Jobs/ProcessWhatsAppMessage.php                # job antrean pemroses pesan
app/Services/ChatFlowManager.php                   # state machine percakapan
app/Services/GroqService.php                       # router intent, askRgp (FAQ), parse order
app/Services/FonnteService.php                     # kirim pesan via API Fonnte
app/Services/MasterDataService.php                 # tarik master data dari REST API RGP
app/Models/ChatSession.php                         # model tabel bot_sessions
config/bot.php                                     # biaya, keyword, faq, cabang + admin_wa
INFORMASI_RGP.md                                   # knowledge base FAQ RG Boy
```