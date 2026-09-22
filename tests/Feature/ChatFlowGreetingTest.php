<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Services\ChatFlowManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatFlowGreetingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeServis(string $intent, array $ask = ['relevan' => true, 'jawaban' => 'Ini jawaban RGP.']): void
    {
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true], 200),
            'https://api.groq.com/*' => function ($request) use ($intent, $ask) {
                $model = $request['model'] ?? '';

                if ($model === config('services.groq.model_router')) {
                    return Http::response([
                        'choices' => [['message' => ['content' => json_encode(['intent' => $intent])]]],
                    ], 200);
                }

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode($ask)]]],
                ], 200);
            },
        ]);
    }

    private function pesanTerkirim(string $substring): bool
    {
        $pesan = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['message'] ?? '');

        return $pesan->contains(fn ($m) => str_contains($m, $substring));
    }

    public function test_sapaan_di_greeting_mengirim_menu_tanpa_pindah_state(): void
    {
        $this->fakeServis('sapaan');

        $session = ChatSession::create([
            'no_wa' => '6282151419620',
            'step_saat_ini' => ChatFlowManager::STATE_GREETING,
        ]);

        app(ChatFlowManager::class)->run($session->no_wa, 'Halo kak');

        $session->refresh();

        $this->assertSame(ChatFlowManager::STATE_GREETING, $session->step_saat_ini);
        $this->assertTrue($session->sudah_disapa);
        $this->assertTrue($this->pesanTerkirim('!pesan'), 'Menu berisi perintah !pesan harus terkirim.');
    }

    public function test_perintah_pesan_memulai_alur_order(): void
    {
        $this->fakeServis('pesan');

        $session = ChatSession::create([
            'no_wa' => '6282151419620',
            'step_saat_ini' => ChatFlowManager::STATE_GREETING,
        ]);

        app(ChatFlowManager::class)->run($session->no_wa, '!pesan');

        $session->refresh();

        $this->assertSame(ChatFlowManager::STATE_KETENTUAN, $session->step_saat_ini);
        $this->assertTrue($this->pesanTerkirim('selamat datang'), 'Intro order harus terkirim.');
        $this->assertTrue($this->pesanTerkirim('Syarat Order Online'), 'Syarat order harus terkirim.');
    }

    public function test_pertanyaan_rgp_di_greeting_dijawab_tanpa_pindah_state(): void
    {
        $this->fakeServis('pertanyaan');

        $session = ChatSession::create([
            'no_wa' => '6282151419620',
            'step_saat_ini' => ChatFlowManager::STATE_GREETING,
        ]);

        app(ChatFlowManager::class)->run($session->no_wa, 'Kak, harga spanduk berapa?');

        $session->refresh();

        $this->assertSame(ChatFlowManager::STATE_GREETING, $session->step_saat_ini);
        $this->assertTrue($this->pesanTerkirim('Ini jawaban RGP.'), 'Balasan jawaban AI harus terkirim.');
    }

    public function test_pertanyaan_bukan_rgp_dibalas_teks_fallback(): void
    {
        $this->fakeServis('lainnya', ['relevan' => false, 'jawaban' => 'Saya tidak tahu.']);

        $session = ChatSession::create([
            'no_wa' => '6282151419620',
            'step_saat_ini' => ChatFlowManager::STATE_GREETING,
        ]);

        app(ChatFlowManager::class)->run($session->no_wa, 'Berapa harga tiket pesawat ke Jakarta?');

        $session->refresh();

        $this->assertSame(ChatFlowManager::STATE_GREETING, $session->step_saat_ini);
        $this->assertTrue(
            $this->pesanTerkirim('Saya RG Boy, Saya akan memberikan jawaban apapun mengenai Restu Guru Promosindo.'),
            'Pertanyaan non-RGP harus dibalas teks fallback persis.'
        );
    }

    public function test_pertanyaan_saat_isi_form_dijawab_lalu_field_ditanya_ulang(): void
    {
        $this->fakeServis('pertanyaan');

        $session = ChatSession::create([
            'no_wa' => '6282151419620',
            'step_saat_ini' => ChatFlowManager::STATE_ISI_FORM,
            'data_order' => ['nama_pemesan' => 'Budi'],
        ]);

        app(ChatFlowManager::class)->run($session->no_wa, 'Bahan untuk spanduk yang bagus apa ya?');

        $session->refresh();

        $this->assertSame(ChatFlowManager::STATE_ISI_FORM, $session->step_saat_ini, 'State harus tetap ISI_FORM.');
        $this->assertTrue($this->pesanTerkirim('Ini jawaban RGP.'), 'Pertanyaan harus dijawab.');
        $this->assertTrue($this->pesanTerkirim('mohon lengkapi data berikut'), 'Field yang kurang harus ditanyakan ulang.');
    }
}
