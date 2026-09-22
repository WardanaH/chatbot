<?php

use App\Http\Controllers\FonnteWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/fonnte', [FonnteWebhookController::class, 'handle']);
