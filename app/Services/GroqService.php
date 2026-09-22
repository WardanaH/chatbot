<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    private array $konteksKelas = [
        'bahan' => 'Daftar bahan yang tersedia, format: "nama_kode - nama_bahan".',
        'produk' => 'Daftar produk cetak yang tersedia.',
        'harga' => 'Estimasi harga untuk masing-masing produk/bahan.',
    ];

    public function routerPredict(string $teksPelanggan, string $labelState): string
    {
        $system = <<<'PROMPT'
Kamu adalah router intent untuk bot WhatsApp percetakan. Tugasmu hanya memilih SATU label intent dari daftar yang diberikan berdasarkan pesan pelanggan.

Tentukan apakah pesan pelanggan merupakan salah satu dari intent berikut:
- "pesan": pelanggan ingin mulai/lanjut memesan cetak secara langsung (menyebut niat order, `!pesan`, nama produk cetak dll).
- "sapaan": pelanggan sekadar menyapa/membuka percakapan ("halo", "pagi", "assalamualaikum", dll), tanpa niat order atau bertanya.
- "pertanyaan": pelanggan bertanya seputar Restu Guru Promosindo (produk, harga, bahan, jam operasional, kontak, cabang, ketentuan, cara) TANPA berniat order saat itu juga.
- "setuju_ketentuan": pelanggan menyetujui ketentuan order, menyatakan sudah transfer DP, atau ingin lanjut memesan.
- "acc_desain": pelanggan menyetujui desain/ringkasan pesanan (kata "acc", "oke", "setuju").
- "komplain": pelanggan menyampaikan keluhan/komplain.
- "pilih_cabang": pelanggan memilih salah satu cabang (banjarmasin, liang anggang, banjarbaru, martapura).
- "reset": pelanggan ingin memulai ulang dari awal.
- "lainnya": jika tidak termasuk di atas.

Balas hanya dalam format JSON object: {"intent": "<salah satu intent>"} tanpa teks tambahan.
PROMPT;

        $raw = $this->completeJson($system, $teksPelanggan, config('services.groq.model_router'));

        $data = $this->decodeJson($raw, []);

        $intent = $data['intent'] ?? 'lainnya';

        return is_string($intent) ? $intent : 'lainnya';
    }

    /**
     * Jawab pertanyaan umum pelanggan seputar Restu Guru Promosindo (RGP).
     *
     * Menggunakan knowledge base dari file INFORMASI_RGP.md. Jika pertanyaan
     * tidak berkaitan dengan RGP, mengembalikan teks fallback dari config/bot.php.
     */
    public function askRgp(string $pertanyaan): string
    {
        $kb = $this->knowledgeBaseRgp();

        $system = <<<PROMPT
Kamu adalah "RG Boy", asisten virtual yang ramah dari Restu Guru Promosindo (RGP), perusahaan percetakan, digital printing, dan advertising di Kalimantan Selatan.

Berikut knowledge base tentang RGP:
$kb

Aturan:
- Jawab pertanyaan pelanggan secara ramah, ringkas, dan jelas dalam bahasa Indonesia.
- Jawaban harus BERSUMBER dari knowledge base di atas. Jika info tidak ada, arahkan pelanggan untuk konfirmasi ke admin cabang.
- Jika pertanyaan TIDAK berkaitan dengan RGP (bukan seputar percetakan, promosi, produk, harga, layanan, kontak, lokasi, ketentuan, atau perintah bot), set "relevan" ke false.
- Perintah yang bisa pelanggan gunakan: `!pesan` untuk mulai order cetak, tanya langsung untuk bertanya seputar RGP, `reset` untuk mengulang dari awal.

Balas hanya dalam format JSON object dengan kunci "relevan" (boolean) dan "jawaban" (string), tanpa teks tambahan.
PROMPT;

        $raw = $this->completeJson($system, $pertanyaan, config('services.groq.model_ask'));

        $data = $this->decodeJson($raw, []);

        $relevan = (bool) ($data['relevan'] ?? false);
        $jawaban = (string) ($data['jawaban'] ?? '');

        if (! $relevan || trim($jawaban) === '') {
            return (string) config('bot.faq.teks_bukan_rgp');
        }

        return $jawaban;
    }

    private function knowledgeBaseRgp(): string
    {
        $file = base_path('INFORMASI_RGP.md');

        return Cache::remember('kb_rgp', now()->addMinutes(5), function () use ($file) {
            if (! is_file($file)) {
                return 'Tidak ada data profil RGP.';
            }

            return (string) file_get_contents($file);
        });
    }

    /**
     * Parse balasan pelanggan ke dalam JSON field form order.
     *
     * @return array<string, mixed> Field yang berhasil diekstrak.
     */
    public function parseOrderForm(string $teksPelanggan, string $konteksBalasan, array $masterData = []): array
    {
        $konteks = '';

        if (! empty($masterData)) {
            $konteks = $this->buildMasterDataKonteks($masterData);
        }

        $system = <<<PROMPT
Kamu adalah asisten yang mengekstrak informasi order cetak dari percakapan pelanggan menjadi format JSON.

Percakapan sejauh ini:
$konteksBalasan

$konteks

Ekstrak field berikut yang TERSEBUT di percakapan. Jangan membuat data baru. Field yang tidak disebutkan dijawab dengan nilai null:
1. nama_pemesan (string)
2. no_wa (string)
3. produk (string) — contoh: spanduk, banner, stiker, x-banner, kartu nama, brosur, dsb
4. ukuran (string) — contoh: 1x1 meter, A3
5. bahan (string) — pakai kode/nama bahan yang terdaftar
6. jumlah (string) — angka dan satuan, contoh: "5", "1 pcs"
7. selesai (string) — permintaan selesai, contoh: "3 hari", "besok"
8. status_desain (string) — "sudah" atau "belum"

Balas hanya dalam format JSON object, kunci menggunakan snake_case, nilai string. Tolong jangan tambahkan teks lain di luar JSON.
PROMPT;

        $raw = $this->completeJson($system, $teksPelanggan, config('services.groq.model_form'));

        return $this->decodeJson($raw, []);
    }

    private function buildMasterDataKonteks(array $masterData): string
    {
        $bagian = [];

        foreach ($this->konteksKelas as $kunci => $deskripsi) {
            if (isset($masterData[$kunci]) && is_array($masterData[$kunci]) && count($masterData[$kunci])) {
                $bagian[] = $deskripsi."\n".json_encode($masterData[$kunci], JSON_UNESCAPED_UNICODE);
            }
        }

        return implode("\n", $bagian);
    }

    private function completeJson(string $system, string $user, string $model): string
    {
        $apiKey = config('services.groq.api_key');
        $baseUrl = config('services.groq.base_url');

        try {
            $response = Http::withToken($apiKey, 'Bearer')
                ->acceptJson()
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object'],
                ]);

            $body = $response->json();

            if ($response->failed() || ! isset($body['choices'][0]['message']['content'])) {
                Log::error('GroqService: respons gagal atau tidak valid.', [
                    'model' => $model,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return '';
            }

            return $body['choices'][0]['message']['content'];
        } catch (\Throwable $e) {
            Log::error('GroqService: exception saat memanggil API.', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function decodeJson(string $raw, mixed $default = null): mixed
    {
        if (empty($raw)) {
            return $default;
        }

        // Bersihkan kemungkinan pembungkus markdown ```json ... ```.
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Coba cari objek JSON pertama di dalam teks.
            $cocok = preg_match('/\{.*\}/s', $raw, $m);

            if (! $cocok) {
                Log::warning('GroqService: gagal decode JSON.', ['raw' => $raw]);

                return $default;
            }

            try {
                return json_decode($m[0], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e2) {
                Log::warning('GroqService: gagal decode JSON (fallback regex).', ['raw' => $raw]);

                return $default;
            }
        }
    }
}
