<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FonnteWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        Log::info('FonnteWebhookController: menerima payload.', [
            'body' => $request->getContent(),
        ]);

        // Payload asli Fonnte: sender/message di level paling atas.
        $noWa = $this->normalisasiNomor($request->input('sender'));
        $pesan = trim((string) $request->input('message', ''));

        if ($noWa !== null && $pesan !== '') {
            ProcessWhatsAppMessage::dispatch($noWa, $pesan);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    private function normalisasiNomor(mixed $nomor): ?string
    {
        if ($nomor === null) {
            return null;
        }

        $bersih = preg_replace('/[^0-9]/', '', (string) $nomor);

        if ($bersih === '') {
            return null;
        }

        // Ubah format 08xx menjadi 628xx agar cocok dengan nomor WA internasional.
        if (str_starts_with($bersih, '0')) {
            $bersih = '62'.substr($bersih, 1);
        }

        return $bersih;
    }
}
