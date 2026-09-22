<?php

namespace Tests\Unit;

use App\Services\GroqService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqServiceTest extends TestCase
{
    public function test_parse_order_form_returns_valid_json(): void
    {
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"nama_pemesan":"Budi","produk":"Spanduk","ukuran":"1x1 m","bahan":"Albatros","jumlah":"1","selesai":"3 hari","status_desain":"belum"}',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(GroqService::class);

        $hasil = $service->parseOrderForm('Nama Budi, produk spanduk ukuran 1x1 m bahan albatros, jumlah 1, selesai 3 hari, desain belum', '');

        $this->assertIsArray($hasil);
        $this->assertSame('Budi', $hasil['nama_pemesan'] ?? null);
        $this->assertSame('Spanduk', $hasil['produk'] ?? null);
        $this->assertSame('Albatros', $hasil['bahan'] ?? null);
    }

    public function test_parse_order_form_handles_markdown_wrapped_json(): void
    {
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => "```json\n{\"nama_pemesan\":\"Siti\",\"produk\":\"X-Banner\"}\n```",
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(GroqService::class);

        $hasil = $service->parseOrderForm('Siti pesan x-banner', '');

        $this->assertSame('Siti', $hasil['nama_pemesan'] ?? null);
        $this->assertSame('X-Banner', $hasil['produk'] ?? null);
    }

    public function test_router_predict_returns_decoded_intent(): void
    {
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"intent": "sapaan"}',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(GroqService::class);

        $this->assertSame('sapaan', $service->routerPredict('halo kak', 'GREETING_INTENT'));
    }

    public function test_router_predict_fallback_lainnya_when_invalid(): void
    {
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => 'teks bukan json'],
                    ],
                ],
            ], 200),
        ]);

        $service = app(GroqService::class);

        $this->assertSame('lainnya', $service->routerPredict('halo', 'GREETING_INTENT'));
    }

    public function test_ask_rgp_returns_jawaban_for_relevant_topic(): void
    {
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"relevan": true, "jawaban": "Harga spanduk mulai Rp 65.000 kak."}',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(GroqService::class);

        $jawaban = $service->askRgp('harga spanduk berapa?');

        $this->assertSame('Harga spanduk mulai Rp 65.000 kak.', $jawaban);
    }

    public function test_ask_rgp_returns_fallback_text_when_not_relevant(): void
    {
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"relevan": false, "jawaban": "Saya tidak tahu."}',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(GroqService::class);

        $jawaban = $service->askRgp('berapa harga tiket pesawat?');

        $this->assertSame(config('bot.faq.teks_bukan_rgp'), $jawaban);
    }
}
