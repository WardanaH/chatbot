<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MasterDataService
{
    private array $endpointKandidat = [
        'bahan' => ['/api/bahan', '/api/bahan-cetak'],
        'produk' => ['/api/produk', '/api/daftar-produk'],
        'harga' => ['/api/harga', '/api/daftar-harga'],
    ];

    public function semua(): array
    {
        $bahan = $this->bahan();
        $produk = $this->produk();
        $harga = $this->harga();

        return [
            'bahan' => $bahan,
            'produk' => $produk,
            'harga' => $harga,
        ];
    }

    public function bahan(): array
    {
        return $this->ambil('bahan', $this->endpointKandidat['bahan']);
    }

    public function produk(): array
    {
        return $this->ambil('produk', $this->endpointKandidat['produk']);
    }

    public function harga(): array
    {
        return $this->ambil('harga', $this->endpointKandidat['harga']);
    }

    private function ambil(string $jenis, array $kandidat): array
    {
        return Cache::remember("master_data_{$jenis}", now()->addMinutes(5), function () use ($jenis, $kandidat) {
            $baseUrl = rtrim((string) config('services.rgp.base_url'), '/');

            foreach ($kandidat as $path) {
                try {
                    $response = Http::timeout(5)->get($baseUrl.$path);

                    if (! $response->successful()) {
                        continue;
                    }

                    $data = $response->json();

                    // Terima respons array polos atau berbingkai seperti {data: [...]}.
                    if (is_array($data) && array_key_exists('data', $data)) {
                        $data = $data['data'];
                    }

                    if (is_array($data)) {
                        return $data;
                    }
                } catch (\Throwable $e) {
                    Log::warning("MasterDataService: gagal ambil {$jenis} dari {$path}.", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [];
        });
    }
}
