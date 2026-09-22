<?php

namespace App\Jobs;

use App\Services\ChatFlowManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(
        public string $noWa,
        public string $pesan,
    ) {}

    public function handle(ChatFlowManager $chatFlowManager): void
    {
        try {
            $chatFlowManager->run($this->noWa, $this->pesan);
        } catch (\Throwable $e) {
            Log::error('ProcessWhatsAppMessage: job gagal.', [
                'no_wa' => $this->noWa,
                'pesan' => $this->pesan,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
