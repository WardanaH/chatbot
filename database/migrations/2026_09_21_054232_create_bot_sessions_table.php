<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('no_wa', 32)->unique();
            $table->string('step_saat_ini', 50)->default('GREETING_INTENT');
            $table->json('data_order')->nullable();
            $table->string('cabang', 50)->nullable();
            $table->boolean('is_komplain')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_sessions');
    }
};
