<?php

return [

    'biaya' => [
        'penanganan_online' => 10000,
        'desain_mulai' => 15000,
        'dp' => 30000,
        'revisi_minor_maks' => 3,
    ],

    'jam_operasional' => 'Senin - Sabtu (09.00 - 22.00 WITA)',

    'master_data' => [
        // Tarik bahan/produk/harga dari REST API restugurupromosindo.com?
        // Endpoint saat ini timeout; nonaktifkan agar isi form tidak delay ~25-30 detik.
        'enabled' => env('BOT_MASTER_DATA_ENABLED', false),
    ],

    'form' => [
        'field' => [
            'nama_pemesan' => 'Nama Pemesan',
            'no_wa' => 'Nomor WhatsApp',
            'produk' => 'Produk',
            'ukuran' => 'Ukuran',
            'bahan' => 'Bahan',
            'jumlah' => 'Jumlah',
            'selesai' => 'Permintaan Selesai',
            'status_desain' => 'Status Desain (Sudah/Belum)',
        ],
        'kata_kunci' => [
            'setuju' => ['setuju', 'oke', 'ok', 'lanjut', 'gas', 'ya', 'siap', 'beres', 'transfer', 'sudah kirim'],
            'acc' => ['acc', 'ok', 'oke', 'setuju', 'acc, lanjut', 'lanjut produksi'],
            'reset' => ['reset', 'mulai ulang', 'ulang', 'coba lagi'],
            'order_baru' => ['mau order', 'bisa order', 'boleh order', 'order lagi', 'pesan lagi', 'mau pesan', 'orderan', 'order', 'pesan', 'cetak', 'beli', 'halo', 'lanjut order'],
            'pesan' => ['!pesan', 'pesan', 'order', 'cetak', 'beli'],
        ],
    ],

    'faq' => [
        'nama_bot' => 'RG Boy',
        'teks_menu' => "Halo kak! 👋 Aku *RG Boy*, asisten virtual *Restu Guru Promosindo*.\n\n".
            "Berikut yang bisa aku bantu kak:\n".
            "1️⃣ *Pesan Cetak* — ketik `!pesan` (atau langsung bilang mau pesan apa) untuk mulai order spanduk, banner, kartu nama, dll.\n".
            "2️⃣ *Tanya-tanya* — langsung tanya aja soal RGP (produk, harga, bahan, jam operasional, kontak, cabang, dll).\n\n".
            'Mau mulai yang mana kak? 😊',
        'teks_bukan_rgp' => "Saya RG Boy, Saya akan memberikan jawaban apapun mengenai Restu Guru Promosindo. Berikut Perintah yang dapat anda gunakan:\n".
            "1️⃣ *!pesan* — mulai order cetak (spanduk, banner, kartu nama, dll)\n".
            "2️⃣ *Tanya langsung* — tanya apa saja seputar Restu Guru Promosindo\n".
            '3️⃣ *reset* — mulai percakapan dari awal',
        'kata_tanya' => ['berapa', 'berapakah', 'harga', 'apa', 'apakah', 'kenapa', 'mengapa', 'kapan', 'dimana', 'di mana', 'bagaimana', 'cara'],
        'sapaan' => ['halo', 'hai', 'helo', 'hi', 'pagi', 'siang', 'sore', 'malam', 'assalamualaikum', 'assalamu\'alaikum', 'selamat datang', 'permisi'],
    ],

    'cabang' => [
        'banjarmasin' => [
            'label' => 'Banjarmasin',
            'admin_wa' => '6282151419620', // isi nomor admin cabang, format: 628xx
        ],
        'liang_anggang' => [
            'label' => 'Liang Anggang',
            'admin_wa' => '6282151419620',
        ],
        'banjarbaru' => [
            'label' => 'Banjarbaru',
            'admin_wa' => '6282151419620',
        ],
        'martapura' => [
            'label' => 'Martapura',
            'admin_wa' => '6282151419620',
        ],
    ],
];
