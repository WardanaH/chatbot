<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    use HasFactory;

    protected $table = 'bot_sessions';

    protected $fillable = [
        'no_wa',
        'step_saat_ini',
        'sudah_disapa',
        'data_order',
        'cabang',
        'is_komplain',
    ];

    protected $casts = [
        'data_order' => 'array',
        'is_komplain' => 'boolean',
        'sudah_disapa' => 'boolean',
    ];
}
