<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Services\ChatFlowManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatFlowSelesaiTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_baru_setelah_selesai_memulai_ulang_ke_greeting(): void
    {
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true], 200),
        ]);

        $session = ChatSession::create([
            'no_wa' => '6282151419620',
            'step_saat_ini' => ChatFlowManager::STATE_SELESAI,
            'data_order' => [
                'nama_pemesan' => 'Budi',
                'produk' => 'Spanduk',
                'cabang' => 'banjarbaru',
            ],
            'cabang' => 'banjarbaru',
            'is_komplain' => true,
        ]);

        app(ChatFlowManager::class)->run($session->no_wa, 'Halo, apakah bisa order?');

        $session->refresh();

        $this->assertSame(ChatFlowManager::STATE_KETENTUAN, $session->step_saat_ini);
        $this->assertNull($session->data_order);
        $this->assertNull($session->cabang);
        $this->assertFalse($session->is_komplain);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.fonnte.com/send'));

        $sentPesan = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? '');

        $this->assertTrue(
            $sentPesan->contains(fn ($m) => str_contains($m, 'selamat datang')),
            'Sapaan greeting harus terkirim setelah order baru dimulai.'
        );
    }
}
