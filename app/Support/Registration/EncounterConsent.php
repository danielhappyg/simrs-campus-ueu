<?php

namespace App\Support\Registration;

use InvalidArgumentException;

final class EncounterConsent
{
    public const FORM_TITLE = 'GENERAL CONSENT';

    public const FORM_SUBTITLE = 'Persetujuan Umum';

    public const HOSPITAL_NAME = 'RS ESA UNGGUL';

    public const HOSPITAL_ADDRESS = 'Jl. Arjuna Utara No 9 Kebon Jeruk, Jakarta Barat';

    public const INTRO = 'Saya menyetujui untuk dirawat di RS Esa Unggul sebagai pasien rawat jalan.';

    /**
     * @return list<array{title: string, body: string}>
     */
    public static function clauses(): array
    {
        return [
            ['title' => 'HAK DAN KEWAJIBAN SEBAGAI PASIEN', 'body' => 'Saya mengakui bahwa dalam proses pendaftaran untuk mendapatkan perawatan di RS Esa Unggul dan penandatanganan dokumen ini, saya telah mendapat informasi tentang hak-hak dan kewajiban saya sebagai pasien.'],
            ['title' => 'PERSETUJUAN PELAYANAN KESEHATAN', 'body' => 'Saya menyetujui dan memberikan persetujuan untuk mendapat pelayanan kesehatan di RS Esa Unggul. Dengan ini, saya meminta dan memberikan kuasa kepada pihak rumah sakit, dokter, perawat, dan tenaga kesehatan lainnya untuk memberikan asuhan keperawatan, pemeriksaan fisik yang dilakukan oleh dokter dan perawat, melakukan prosedur diagnostik, radiologi dan/atau terapi, serta tata laksana sesuai pertimbangan dokter yang diperlukan atau disarankan dalam perawatan saya.Hal ini mencakup seluruh pemeriksaan dan prosedur diagnostik rutin, termasuk sinar-X, pemberian dan/atau tindakan medis serta penyuntikan (intramuskular, intravena, dan prosedur invasif lainnya), produk farmasi dan obat-obatan, pemasangan alat kesehatan (kecuali yang membutuhkan persetujuan khusus/tertulis), serta pengambilan darah untuk pemeriksaan laboratorium atau pemeriksaan patologi yang dibutuhkan untuk pengobatan dan tindakan yang aman.'],
            ['title' => 'PRIVASI', 'body' => 'Saya memberi kuasa kepada RS Esa Unggul untuk menjaga privasi dan kerahasiaan penyakit saya selama dalam perawatan.RAHASIA KEDOKTERAN; Saya setuju bahwa RS Esa Unggul wajib menjamin rahasia kedokteran saya, baik untuk kepentingan perawatan maupun pengobatan, kecuali saya mengungkapkannya sendiri atau kepada orang lain yang saya beri kuasa sebagai penjamin.MEMBUKA RAHASIA KEDOKTERAN; Saya setuju untuk membuka rahasia kedokteran terkait kondisi kesehatan, asuhan, dan pengobatan yang saya terima kepada: Dokter dan tenaga kesehatan lain yang memberikan asuhan kepada saya.• Perusahaan asuransi kesehatan, BPJS, atau pihak lain yang menjamin pembiayaan saya.'],
            ['title' => 'BARANG PRIBADI', 'body' => 'Saya setuju untuk tidak membawa barang-barang berharga yang tidak diperlukan selama dalam perawatan RS Esa Unggul. Saya memahami dan menyetujui RS Esa Unggul tidak bertanggung jawab terhadap kehilangan, kerusakan, atau pencurian barang berharga.'],
            ['title' => 'PENGAJUAN KELUHAN', 'body' => 'Saya menyatakan bahwa saya telah menerima informasi tentang adanya tata cara mengajukan dan mengatasi keluhan terkait pelayanan medis yang diberikan terhadap diri saya. Saya setuju untuk mengikuti tata cara mengajukan keluhan sesuai prosedur yang ada.'],
            ['title' => 'KEWAJIBAN PEMBAYARAN', 'body' => 'Saya menyatakan setuju, baik sebagai wali maupun sebagai pasien, bahwa sesuai pertimbangan pelayanan yang diberikan kepada pasien, saya wajib membayar total/menyelesaikan biaya pelayanan berdasarkan acuan biaya dan ketentuan RS Esa Unggul.'],
        ];
    }

    public static function assertPngDataUrl(string $value): string
    {
        if (preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $value) !== 1) {
            throw new InvalidArgumentException('Consent signature must be a PNG data URL.');
        }

        if (strlen($value) > 400_000) {
            throw new InvalidArgumentException('Consent signature is too large.');
        }

        return $value;
    }
}
