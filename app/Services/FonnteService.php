<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FonnteService
{
    public function sendText(string $noWa, string $pesan): bool
    {
        $token = config('services.fonnte.token');
        $baseUrl = config('services.fonnte.base_url');

        if (empty($token)) {
            Log::warning("FonnteService: token belum dikonfigurasi. Pesan dikirim ke {$noWa} di-skip.", ['pesan' => $pesan]);

            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => $token])
                ->post($baseUrl.'/send', [
                    'target' => $noWa,
                    'message' => $pesan,
                ]);

            if ($response->failed()) {
                Log::error('FonnteService: gagal mengirim pesan.', [
                    'no_wa' => $noWa,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            Log::info('FonnteService: pesan terkirim.', [
                'no_wa' => $noWa,
                'status' => $response->status(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('FonnteService: exception saat mengirim pesan.', [
                'no_wa' => $noWa,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
