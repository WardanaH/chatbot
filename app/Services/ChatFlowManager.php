<?php

namespace App\Services;

use App\Models\ChatSession;
use Illuminate\Support\Facades\Log;

class ChatFlowManager
{
    public const STATE_GREETING = 'GREETING_INTENT';

    public const STATE_KETENTUAN = 'KETENTUAN_ORDER';

    public const STATE_ISI_FORM = 'ISI_FORM';

    public const STATE_REVIEW = 'REVIEW_ACC';

    public const STATE_CABANG = 'PILIH_CABANG';

    public const STATE_SELESAI = 'SELESAI';

    public const CABANG_LIST = ['banjarmasin', 'liang_anggang', 'banjarbaru', 'martapura'];

    public function __construct(
        private readonly FonnteService $fonnte,
        private readonly GroqService $groq,
        private readonly MasterDataService $masterData,
    ) {}

    public function run(string $noWa, string $pesan): void
    {
        $session = ChatSession::firstOrCreate(
            ['no_wa' => $noWa],
            ['step_saat_ini' => self::STATE_GREETING],
        );

        if ($this->isReset($pesan)) {
            $this->reset($session);

            return;
        }

        $state = $session->step_saat_ini;

        if (! is_string($state) || $state === '' || $state === 'null') {
            $state = self::STATE_GREETING;
            $session->step_saat_ini = self::STATE_GREETING;
            $session->save();
        }

        $method = 'handle'.str_replace('_', '', ucwords(strtolower($state), '_'));

        Log::info('ChatFlowManager: memproses pesan.', [
            'no_wa' => $noWa,
            'state' => $state,
            'pesan' => $pesan,
        ]);

        if (! method_exists($this, $method)) {
            Log::warning('ChatFlowManager: state tidak dikenal, reset ke greeting.', ['state' => $state]);
            $this->reset($session);

            return;
        }

        $this->{$method}($session, $pesan);
    }

    private function handleGreetingIntent(ChatSession $session, string $pesan): void
    {
        if ($this->larikMengandung($pesan, config('bot.form.kata_kunci.pesan'))) {
            $this->mulaiOrder($session);

            return;
        }

        $intent = $this->groq->routerPredict($pesan, self::STATE_GREETING);

        if ($intent === 'pesan') {
            $this->mulaiOrder($session);

            return;
        }

        $sudahDisapa = (bool) $session->sudah_disapa;

        if (! $sudahDisapa) {
            $this->fonnte->sendText($session->no_wa, config('bot.faq.teks_menu'));
            $session->update(['sudah_disapa' => true]);
        }

        if ($intent === 'sapaan' || $this->deteksiSapaan($pesan)) {
            if ($sudahDisapa) {
                $this->fonnte->sendText(
                    $session->no_wa,
                    'Hai kak! 😊 Ada yang bisa aku bantu? Ketik *`!pesan`* kalau mau order cetak, atau tanya langsung aja seputar RGP.'
                );
            }

            return;
        }

        $this->jawabPertanyaanLalu(
            $session,
            $pesan,
            'Kalau mau mulai order cetak, ketik *`!pesan`* ya kak 😊',
            true
        );
    }

    private function mulaiOrder(ChatSession $session): void
    {
        $intro = "Halo kak, selamat datang di layanan order online *Restu Guru Promosindo* 😊\n\n".
            "Kak mau pesan cetak apa hari ini? Kalau boleh tahu, kira-kira *target selesai* nya kapan ya?\n\n".
            'Sebentar, aku jelaskan dulu ketentuan order online kami ya 👇';

        $this->fonnte->sendText($session->no_wa, $intro);
        $this->sendKetentuan($session->no_wa);

        $session->update(['step_saat_ini' => self::STATE_KETENTUAN]);
    }

    private function handleKetentuanOrder(ChatSession $session, string $pesan): void
    {
        $setuju = $this->larikMengandung($pesan, config('bot.form.kata_kunci.setuju'));

        if (! $setuju) {
            $hasilRouter = $this->groq->routerPredict($pesan, self::STATE_KETENTUAN);

            if ($hasilRouter === 'pertanyaan') {
                $this->jawabPertanyaanLalu(
                    $session,
                    $pesan,
                    'Kalau sudah siap, balas *"setuju"* untuk menyetujui ketentuan dan lanjut isi form order ya 😊',
                    true
                );

                return;
            }

            if ($hasilRouter === 'sapaan') {
                $this->fonnte->sendText(
                    $session->no_wa,
                    'Hai kak 😊 Kalau sudah siap, balas *"setuju"* untuk lanjut isi form order ya.'
                );

                return;
            }

            $setuju = $hasilRouter === 'setuju_ketentuan';
        }

        if (! $setuju) {
            $this->fonnte->sendText(
                $session->no_wa,
                "Oke kak, tidak masalah 🙏\n\n".
                "Biar bisa lanjut ke pengisian form order, kakak perlu menyetujui ketentuan di atas ya.\n".
                'Kalau sudah siap, balas *"setuju"* atau konfirmasi kalau DP sudah dikirim 😊'
            );

            return;
        }

        $session->update([
            'step_saat_ini' => self::STATE_ISI_FORM,
            'data_order' => array_merge($session->data_order ?? [], ['no_wa' => $session->no_wa]),
        ]);

        $this->kirimIntroForm($session->no_wa);
    }

    private function handleIsiForm(ChatSession $session, string $pesan): void
    {
        if ($this->deteksiPertanyaan($pesan)) {
            $missing = $this->fieldKurang($session->data_order ?? []);

            $labelField = [];
            foreach ($missing as $field) {
                $labelField[] = '- '.config('bot.form.field')[$field];
            }

            $tindakLanjut = "Oke pertanyaannya sudah dijawab ya kak. Biar bisa lanjut, mohon lengkapi data berikut:\n".
                implode("\n", $labelField)."\n\n".
                'Ketik langsung jawabannya ya, misal *"produk spanduk, ukuran 1x1 meter, bahan albatros"*.';

            $this->jawabPertanyaanLalu($session, $pesan, $tindakLanjut);

            return;
        }

        $masterData = config('bot.master_data.enabled') ? $this->masterData->semua() : [];
        $konteksBalasan = $this->buildBalasanKonteks($session);
        $hasil = $this->groq->parseOrderForm($pesan, $konteksBalasan, $masterData);

        $dataOrder = $session->data_order ?? [];

        foreach (array_keys(config('bot.form.field')) as $field) {
            if (isset($hasil[$field]) && $hasil[$field] !== null && trim((string) $hasil[$field]) !== '') {
                $dataOrder[$field] = trim((string) $hasil[$field]);
            }
        }

        $missing = $this->fieldKurang($dataOrder);

        if (count($missing) > 0) {
            $session->update(['data_order' => $dataOrder]);

            $labelField = [];
            foreach ($missing as $field) {
                $labelField[] = '- '.config('bot.form.field')[$field];
            }

            $pesanField = "Beberapa data masih kosong kak, mohon lengkapi:\n".implode("\n", $labelField)."\n\n".
                'Ketik langsung jawabannya ya, misal *"produk spanduk, ukuran 1x1 meter, bahan albatros"*.';

            $this->fonnte->sendText($session->no_wa, $pesanField);

            return;
        }

        $session->update([
            'step_saat_ini' => self::STATE_REVIEW,
            'data_order' => $dataOrder,
        ]);

        $this->kirimPreview($session);
    }

    private function handleReviewAcc(ChatSession $session, string $pesan): void
    {
        $acc = $this->larikMengandung($pesan, config('bot.form.kata_kunci.acc'));

        if (! $acc) {
            $hasilRouter = $this->groq->routerPredict($pesan, self::STATE_REVIEW);

            if ($hasilRouter === 'pertanyaan') {
                $this->jawabPertanyaanLalu(
                    $session,
                    $pesan,
                    'Silakan cek kembali detail pesanan di atas. Kalau sudah benar, balas *"ACC"* ya 😊',
                    true
                );

                return;
            }

            if ($hasilRouter === 'sapaan') {
                $this->fonnte->sendText(
                    $session->no_wa,
                    'Hai kak 😊 Silakan cek ringkasan pesanan di atas. Kalau sudah benar, balas *"ACC"* ya.'
                );

                return;
            }

            $acc = $hasilRouter === 'acc_desain';
        }

        if (! $acc) {
            $this->fonnte->sendText(
                $session->no_wa,
                "Baik kak, silakan cek kembali detail pesanannya di atas.\n".
                'Kalau ada yang mau diubah, sebutkan perubahannya. Kalau sudah benar, balas *"ACC"* ya 😊'
            );

            return;
        }

        $session->update(['step_saat_ini' => self::STATE_CABANG]);

        $this->fonnte->sendText(
            $session->no_wa,
            'Terima kasih kak! 🙏 Produksi berjalan setelah DP Rp'.number_format(config('bot.biaya.dp'), 0, ',', '.')." terkonfirmasi.\n\n".
            "*✋ PENTING:* Jika sudah *ACC*, kesalahan produksi setelahnya menjadi tanggung jawab kakak ya.\n\n".
            "Sebelum dikerjakan, pesanan mau diambil dari cabang mana kak?\n".
            "1. Banjarmasin\n2. Liang Anggang\n3. Banjarbaru\n4. Martapura"
        );
    }

    private function handlePilihCabang(ChatSession $session, string $pesan): void
    {
        if ($this->deteksiPertanyaan($pesan)) {
            $this->jawabPertanyaanLalu(
                $session,
                $pesan,
                "Pesanan mau diambil dari cabang mana kak?\n".
                "1. Banjarmasin\n2. Liang Anggang\n3. Banjarbaru\n4. Martapura"
            );

            return;
        }

        $cabang = $this->deteksiCabang($pesan);

        if ($cabang === null) {
            $this->fonnte->sendText(
                $session->no_wa,
                "Maaf kak, cabang yang tersedia:\n".
                "1. Banjarmasin\n2. Liang Anggang\n3. Banjarbaru\n4. Martapura\n\n".
                'Silakan ketik nama cabangnya ya 😊'
            );

            return;
        }

        $session->update(['cabang' => $cabang, 'step_saat_ini' => self::STATE_SELESAI]);

        $this->kirimRekapUtuh($session);
    }

    private function handleSelesai(ChatSession $session, string $pesan): void
    {
        if ($this->isNewOrderRequest($pesan)) {
            $session->update([
                'step_saat_ini' => self::STATE_GREETING,
                'data_order' => null,
                'cabang' => null,
                'is_komplain' => false,
            ]);

            $this->handleGreetingIntent($session, $pesan);

            return;
        }

        $isKomplain = $session->is_komplain
            || $this->larikMengandung($pesan, ['komplain', 'keluhan', 'rusak', 'salah', 'cacat', 'kompensasi'])
            || $this->groq->routerPredict($pesan, self::STATE_SELESAI) === 'komplain';

        if ($isKomplain) {
            $session->update(['is_komplain' => true]);

            $this->fonnte->sendText(
                $session->no_wa,
                "Mohon maaf atas kendalanya kak 🙏\n".
                'Kami akan cek dulu kendala yang kakak alami, mohon tunggu konfirmasi dari admin ya. Terima kasih sudah sabar 🤗'
            );

            return;
        }

        if ($this->deteksiPertanyaan($pesan)) {
            $this->jawabPertanyaanLalu(
                $session,
                $pesan,
                'Selain itu, ada yang bisa aku bantu lagi kak? 😊'
            );

            return;
        }

        $this->fonnte->sendText(
            $session->no_wa,
            'Pesanan kakak sudah diteruskan ke admin cabang *'.ucwords(str_replace('_', ' ', $session->cabang ?? ''))."* 😊\n\n".
            'Cetakan selesai setelah lolos Quality Control (QC) dan siap diambil di jam operasional *'.config('bot.jam_operasional')."*.\n".
            'Terima kasih sudah order di Restu Guru Promosindo 🙏'
        );
    }

    // ===== Helper internal =====

    private function sendKetentuan(string $noWa): void
    {
        $dp = number_format(config('bot.biaya.dp'), 0, ',', '.');
        $biayaOnline = number_format(config('bot.biaya.penanganan_online'), 0, ',', '.');
        $desainMulai = number_format(config('bot.biaya.desain_mulai'), 0, ',', '.');

        $pesan = "*Syarat Order Online*\n".
            '1. Biaya penanganan online Rp'.$biayaOnline."\n".
            '2. Biaya desain mulai Rp'.$desainMulai." (gratis jika file siap cetak)\n".
            '3. Revisi minor desain maksimal '.config('bot.biaya.revisi_minor_maks')."x\n".
            '4. *DP Rp'.$dp."* untuk menyetujui proses produksi\n\n".
            'Kalau kakak setuju/sudah kirim DP, balas *"setuju"* ya 😊';

        $this->fonnte->sendText($noWa, $pesan);
    }

    private function kirimIntroForm(string $noWa): void
    {
        $fieldList = implode("\n", array_map(fn ($label) => '- '.$label, config('bot.form.field')));

        $this->fonnte->sendText(
            $noWa,
            "Mantap kak, kita lanjut ke isi form order! 📝\n\n".
            "Mohon isi data berikut:\n".$fieldList."\n\n".
            "Boleh diketik sekaligus, contoh:\n".
            '*"Nama: Budi, Produk: Spanduk, Ukuran: 1x1m, Bahan: Albatros 280g, Jumlah: 1, Selesai: 3 hari, Desain: Belum"*'
        );
    }

    private function kirimPreview(ChatSession $session): void
    {
        $ringkasan = "Silakan dicek ringkasan pesanan kak, *pastikan ejaan, warna, dan ukuran sudah benar*:\n\n".
            $this->formatRekap($session->data_order)."\n\n".
            'Kalau sudah sesuai, balas *"ACC"* ya 😊';

        $this->fonnte->sendText($session->no_wa, $ringkasan);
    }

    private function kirimRekapUtuh(ChatSession $session): void
    {
        $cabang = str_replace('_', ' ', $session->cabang ?? '');
        $cabangKey = $session->cabang ?? '';
        $adminWa = config('bot.cabang.'.$cabangKey.'.admin_wa');

        $rekap = "*Rekap Pesanan*\n".$this->formatRekap($session->data_order).
            "\n*Cabang Pengambilan:* ".ucwords($cabang)."\n\n";

        if (! empty($adminWa)) {
            $link = 'https://wa.me/'.preg_replace('/[^0-9]/', '', $adminWa);
            $rekap .= 'Pesanan kakak diteruskan ke admin cabang *'.ucwords($cabang)."*.\n".
                "Klik untuk lanjut konfirmasi: {$link}";

            $this->forwardKeAdmin($session, $adminWa, $cabangKey);
        } else {
            $rekap .= 'Pesanan kakak diteruskan ke admin cabang *'.ucwords($cabang).'*. Nomor admin akan segera kami kirimkan 😊';
        }

        $this->fonnte->sendText($session->no_wa, $rekap);
    }

    private function forwardKeAdmin(ChatSession $session, string $adminWa, string $cabangKey): void
    {
        $dataOrder = $session->data_order ?? [];
        $namaPemesan = $dataOrder['nama_pemesan'] ?? '-';

        $pesanAdmin = '*PESANAN BARU - Cabang: '.ucwords(str_replace('_', ' ', $cabangKey))."*\n\n".
            $this->formatRekap($dataOrder).
            "\n\n*No. Pelanggan:* ".$session->no_wa.
            "\n*Nama:* ".$namaPemesan;

        if (
            $adminWa !== $session->no_wa
            && $adminWa !== '' && $adminWa !== null
        ) {
            $this->fonnte->sendText($adminWa, $pesanAdmin);

            return;
        }

        Log::warning('ChatFlowManager: admin_wa sama dengan no pelanggan, forward ke admin di-skip.', [
            'no_wa' => $session->no_wa,
            'cabang' => $cabangKey,
        ]);
    }

    private function formatRekap(array $dataOrder): string
    {
        $baris = [];

        foreach (config('bot.form.field') as $field => $label) {
            $nilai = $dataOrder[$field] ?? '-';

            if (! empty($nilai)) {
                $baris[] = $label.' : '.$nilai;
            }
        }

        return implode("\n", $baris);
    }

    private function buildBalasanKonteks(ChatSession $session): string
    {
        $dataOrder = $session->data_order ?? [];
        $bagian = [];

        foreach (config('bot.form.field') as $field => $label) {
            $bagian[] = $label.' = '.(! empty($dataOrder[$field]) ? $dataOrder[$field] : '(belum diisi)');
        }

        return implode("\n", $bagian);
    }

    private function fieldKurang(array $dataOrder): array
    {
        $missing = [];

        foreach (array_keys(config('bot.form.field')) as $field) {
            if (empty($dataOrder[$field]) || trim((string) $dataOrder[$field]) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function deteksiCabang(string $pesan): ?string
    {
        $pesan = strtolower(trim($pesan));

        if ($pesan === '1' || str_contains($pesan, '1.')) {
            return 'banjarmasin';
        }

        if ($pesan === '2' || str_contains($pesan, '2.')) {
            return 'liang_anggang';
        }

        if ($pesan === '3' || str_contains($pesan, '3.')) {
            return 'banjarbaru';
        }

        if ($pesan === '4' || str_contains($pesan, '4.')) {
            return 'martapura';
        }

        foreach (self::CABANG_LIST as $cabang) {
            if (str_contains($pesan, str_replace('_', ' ', $cabang)) || str_contains($pesan, $cabang)) {
                return $cabang;
            }
        }

        return null;
    }

    private function larikMengandung(string $pesan, array $kataKunci): bool
    {
        $pesan = strtolower(trim($pesan));

        foreach ($kataKunci as $kata) {
            if ($pesan !== '' && str_contains($pesan, strtolower($kata))) {
                return true;
            }
        }

        return false;
    }

    private function isReset(string $pesan): bool
    {
        return $this->larikMengandung($pesan, config('bot.form.kata_kunci.reset'));
    }

    private function isNewOrderRequest(string $pesan): bool
    {
        return $this->larikMengandung($pesan, config('bot.form.kata_kunci.order_baru'));
    }

    private function reset(ChatSession $session): void
    {
        $session->update([
            'step_saat_ini' => self::STATE_GREETING,
            'sudah_disapa' => false,
            'data_order' => null,
            'cabang' => null,
            'is_komplain' => false,
        ]);

        $this->fonnte->sendText($session->no_wa, 'Oke kak, kita mulai dari awal ya 😊');
        $this->fonnte->sendText($session->no_wa, config('bot.faq.teks_menu'));
    }

    private function deteksiSapaan(string $pesan): bool
    {
        return $this->larikMengandung($pesan, config('bot.faq.sapaan'));
    }

    private function deteksiPertanyaan(string $pesan): bool
    {
        return trim($pesan) !== ''
            && (str_ends_with(trim($pesan), '?')
                || $this->larikMengandung($pesan, config('bot.faq.kata_tanya')));
    }

    private function jawabPertanyaanLalu(ChatSession $session, string $pesan, string $tindakLanjut, bool $yakin = false): void
    {
        if (! $yakin && ! $this->deteksiPertanyaan($pesan)) {
            return;
        }

        $jawaban = trim($this->groq->askRgp($pesan));

        $this->fonnte->sendText($session->no_wa, $jawaban."\n\n".$tindakLanjut);
    }
}
