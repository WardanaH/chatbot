<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FonnteWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_returns_200_and_dispatches_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/webhook/fonnte', [
            'device' => '628xx',
            'sender' => '+6281234567890',
            'message' => 'halo kak',
            'name' => 'Budi',
        ]);

        $response->assertOk();

        Queue::assertPushed(ProcessWhatsAppMessage::class, function ($job) {
            return $job->noWa === '6281234567890' && $job->pesan === 'halo kak';
        });
    }

    public function test_webhook_returns_200_without_dispatched_job_when_data_missing(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/webhook/fonnte', [
            'device' => '628xx',
        ]);

        $response->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_sender_08_normalized_to_62_prefix(): void
    {
        Queue::fake();

        $this->postJson('/api/webhook/fonnte', [
            'sender' => '081234567890',
            'message' => 'tes',
        ]);

        Queue::assertPushed(ProcessWhatsAppMessage::class, fn ($job) => $job->noWa === '6281234567890');
    }
}
