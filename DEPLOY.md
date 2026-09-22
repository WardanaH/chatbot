# Deploy ke Hostinger (Shared Hosting) — Subdomain

Panduan deploy bot ke paket **Hostinger Shared Hosting** dengan **subdomain** khusus (mis. `bot.<domain-utama>`). Instruksi memakai terminal (SSH) dan hPanel.

**Placeholder yang dipakai di panduan ini:**

| Placeholder | Contoh |
| --- | --- |
| `<sub>` | `bot` |
| `<domain-utama>` | `restugurupromosindo.com` |
| `<sub-domain>` | `bot.restugurupromosindo.com` |
| `<user-host>` | `u123456789` |

## Prasyarat

- Paket Hostinger yang punya **SSH** & **Cron Job** (minimal *Business* / *Premium*).
- Domain utama sudah aktif di Hostinger.
- **SSH Access** diaktifkan (lihat Langkah 3).
- Repo GitHub ini bersifat publik di `https://github.com/WardanaH/chatbot.git` (jika privat, gunakan deploy key/klone via SSH).

---

## Langkah 1 — Buat MySQL Database

1. hPanel → **Databases → MySQL** → **Add Database**.
2. Buat **database** baru, mis. `u<user-host>_chatbot`.
3. Buat **database user** baru (mis. `u<user-host>_bot`) dengan **password kuat**, centang *All Privileges* pada database tadi.
4. Catat: nama DB, user, password — dipakai di Step 7.

## Langkah 2 — Buat Subdomain

1. hPanel → **Websites → Subdomains** → **Create Subdomain**.
2. Subdomain: `<sub>` (otomatis menjadi `<sub-domain>`).
3. **Document Root: arahkan langsung ke folder `public` Laravel**, misalnya:

   ```
   public_html/<sub>/public
   ```

   > Ini metode yang **direkomendasikan**: server langsung menyajikan `index.php` Laravel dan isi `.env`/`app/` tetap tersembunyi. Folder `public_html/<sub>/` akan dibuat otomatis oleh hPanel sebagai tempat proyek.

## Langkah 3 — Aktifkan SSH

1. hPanel → **Advanced → SSH Access** → aktifkan.
2. Tambahkan SSH **public key** (dari mesin lokal) di hPanel → **Advanced → SSH Keys**, atau pakai username/password SSH.
3. Tes di terminal lokal:

   ```bash
   ssh <user-host>@<domain-utama>
   ```

## Langkah 4 — Upload Kode

Dari SSH, buka folder subdomain dan klone repo:

```bash
cd ~/domains/<domain-utama>/public_html/<sub>
git clone https://github.com/WardanaH/chatbot.git .
```

> Hapus file default yang dibuat hPanel di folder tersebut jika ada (mis. `index.html`) sebelum klone.

## Langkah 5 — Pastikan Document Root Benar

Jika **Langkap 2** berhasil mengarahkan document root ke `public` → selesai, lanjut ke Langkah 6.

**Fallback** (jika hPanel menolak document root ke subfolder): biarkan document root ke `public_html/<sub>`, lalu buat file `.htaccess` di **root proyek** dengan isi:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

## Langkah 6 — Install Dependency (tanpa dev)

```bash
cd ~/domains/<domain-utama>/public_html/<sub>
composer install --no-dev --optimize-autoloader
```

## Langkah 7 — Konfigurasi `.env` Produksi

```bash
cp .env.example .env
```

Edit `.env` (via `nano .env` atau hPanel **File Manager**) dengan nilai berikut:

```ini
APP_NAME="RG Boy"
APP_ENV=production
APP_KEY=                # isi via: php artisan key:generate --force
APP_DEBUG=false
APP_URL=https://<sub-domain>

LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=u<user-host>_chatbot
DB_USERNAME=u<user-host>_bot
DB_PASSWORD=<password-db>

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

FONNTE_TOKEN=<token-fonnte>
GROQ_API_KEY=<api-key-groq>
RGP_API_BASE_URL=https://restugurupromosindo.com
BOT_MASTER_DATA_ENABLED=false
```

Lanjutkan:

```bash
php artisan key:generate --force
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
chmod -R 775 storage bootstrap/cache
```

> Jika mengubah nilai apa pun di `.env` setelah `config:cache`, jalankan `php artisan config:clear` dulu lalu `config:cache` lagi.

## Langkah 8 — Pilih Versi PHP

1. hPanel → **Advanced → PHP Configuration** (atau pada *Websites → Manage → PHP*).
2. Pilih **PHP 8.2 atau lebih baru** — sesuaikan dengan versi CLI.
3. Pastikan ekstensi aktif: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `exif`, `fileinfo`, `intl`, `tokenizer`.

## Langkah 9 — Jalankan Worker Queue via Cron

Shared hosting tidak mengizinkan proses berjalan terus-menerus, jadi jalankan worker lewat **Cron Job** setiap 1 menit (cara standar):

1. hPanel → **Advanced → Cron Jobs** → **Add Cron Job**.
2. Interval: `* * * * *` (setiap menit).
3. Command (sesuaikan path PHP dengan versi yang dipilih — cek via `which php`):

   ```bash
   cd ~/domains/<domain-utama>/public_html/<sub> && php artisan queue:work --tries=1 --timeout=0 --stop-when-empty >> storage/logs/queue.log 2>&1
   ```

   Atau dengan path penuh (contoh PHP 8.2):

   ```bash
   cd ~/domains/<domain-utama>/public_html/<sub> && /opt/alt/php82/usr/bin/php artisan queue:work --tries=1 --timeout=0 --stop-when-empty >> storage/logs/queue.log 2>&1
   ```

> `--stop-when-empty` membuat worker berhenti setelah semua job selesai; cron menjalankannya lagi di menit berikutnya. Cek log via `tail -f storage/logs/queue.log`.

## Langkah 10 — Aktifkan SSL (HTTPS)

1. hPanel → **Websites → Manage Pilih Subdomain → Security → SSL**.
2. Aktifkan **Let's Encrypt** (gratis).
3. Pastikan `APP_URL` sudah pakai `https://` (Langkah 7).

## Langkah 11 — Set Webhook di Fonnte

1. Buka **Dashboard Fonnte** → pilih perangkat (sender) yang dipakai bot.
2. Set **Webhook URL** ke:

   ```
   https://<sub-domain>/api/webhook/fonnte
   ```

3. Simpan. Webhook akan membalas `200 OK` dan memproses pesan di background (via queue).

## Langkah 12 — Uji Coba

1. Buka `https://<sub-domain>` di browser → harus tampil halaman Laravel (bukan 403/404).
2. Kirim pesan WhatsApp ke nomor device (mis. `halo`) → bot membalas menu RG Boy.
3. Cek jalur order penuh: `!pesan` → `setuju` → isi form → `ACC` → pilih cabang → rekap ter-forward ke admin cabang.

---

## Troubleshooting

| Masalah | Penyebab & Solusi |
| --- | --- |
| 403 / 404 saat buka domain | Document root belum ke `public` Laravel → cek Langkah 2/5, atau `.htaccess` fallback belum dibuat |
| 500 Internal Server Error | `index.php` salah lokasi / permission salah → jalankan `chmod -R 775 storage bootstrap/cache` |
| `artisan: command not found` | PHP CLI path berbeda → pakai path penuh `/opt/alt/php<ver>/usr/bin/php artisan ...` |
| Pesan masuk tapi tidak dibalas | Worker queue tidak jalan → cek cron Langkah 9 dan `storage/logs/queue.log`; cek tabel `jobs` di database |
| `APP_KEY` kosong / error enkripsi | Jalankan `php artisan key:generate --force` |
| `.env` berubah tapi tidak kebaca | Hapus cache config → `php artisan config:clear` lalu `config:cache` lagi |
| Webhook Fonnte tidak masuk | Pastikan SSL aktif, URL `https://<sub-domain>/api/webhook/fonnte` benar, dan device Fonnte diaktifkan |
| GraphQL/REST API RGP lambat | `BOT_MASTER_DATA_ENABLED` default `false`; jangan aktifkan bila endpoint RGP masih timeout |

## Update / Redeploy Berikutnya

```bash
cd ~/domains/<domain-utama>/public_html/<sub>
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Kronologi migrasi tidak bisa `rollback` beruntun di shared hosting; pastikan backup DB lewat hPanel → **Databases → Backups** sebelum eksekusi di produksi.