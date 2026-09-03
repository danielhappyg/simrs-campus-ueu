<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Models\Doctor;
use App\Support\Registration\ClinicBookingSurface;
use Illuminate\Database\Seeder;

class OutpatientMastersSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            [
                'code' => 'UMUM',
                'name' => 'Poliklinik Umum',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Siti Aminah, Sp.PD',
                        'specialty' => 'Penyakit Dalam',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 13:00–16:00', 'day_label' => 'Rabu', 'starts_at' => '13:00:00', 'ends_at' => '16:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Budi Santoso',
                        'specialty' => 'Umum',
                        'schedules' => [
                            ['label' => 'Selasa 08:00–11:00', 'day_label' => 'Selasa', 'starts_at' => '08:00:00', 'ends_at' => '11:00:00'],
                            ['label' => 'Jumat 09:00–12:00', 'day_label' => 'Jumat', 'starts_at' => '09:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'GIGI',
                'name' => 'Poliklinik Gigi',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'drg. Maya Putri',
                        'specialty' => 'Gigi',
                        'schedules' => [
                            ['label' => 'Senin 09:00–12:00', 'day_label' => 'Senin', 'starts_at' => '09:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Kamis 13:00–15:00', 'day_label' => 'Kamis', 'starts_at' => '13:00:00', 'ends_at' => '15:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'ANAK',
                'name' => 'Poliklinik Anak',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Rina Wulandari, Sp.A',
                        'specialty' => 'Anak',
                        'schedules' => [
                            ['label' => 'Selasa 08:00–12:00', 'day_label' => 'Selasa', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Kamis 08:00–11:00', 'day_label' => 'Kamis', 'starts_at' => '08:00:00', 'ends_at' => '11:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Nabila Putri, Sp.A',
                        'specialty' => 'Sp.A',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Fajar Mahendra, Sp.A',
                        'specialty' => 'Sp.A',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Sinta Larasati, Sp.A',
                        'specialty' => 'Sp.A',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'JANTUNG',
                'name' => 'Poliklinik Jantung dan Pembuluh Darah',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Andi Pratama, Sp.JP',
                        'specialty' => 'Jantung',
                        'schedules' => [
                            ['label' => 'Rabu 08:00–11:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '11:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Aditya Ramadhan, Sp.JP',
                        'specialty' => 'Sp.JP',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Melati Wulandari, Sp.JP',
                        'specialty' => 'Sp.JP',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Surya Dharmawan, Sp.JP',
                        'specialty' => 'Sp.JP',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'IGD',
                'name' => 'Instalasi Gawat Darurat',
                'booking_surface' => ClinicBookingSurface::EMERGENCY,
                'doctors' => [
                    [
                        'name' => 'dr. Eko Wijaya, Sp.EM',
                        'specialty' => 'Emergency Medicine',
                        'schedules' => [
                            ['label' => 'Shift Pagi 07:00–14:00', 'day_label' => 'Setiap hari', 'starts_at' => '07:00:00', 'ends_at' => '14:00:00'],
                            ['label' => 'Shift Sore 14:00–21:00', 'day_label' => 'Setiap hari', 'starts_at' => '14:00:00', 'ends_at' => '21:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Fitri Rahmawati',
                        'specialty' => 'Emergency Medicine',
                        'schedules' => [
                            ['label' => 'Shift Pagi 07:00–14:00', 'day_label' => 'Setiap hari', 'starts_at' => '07:00:00', 'ends_at' => '14:00:00'],
                            ['label' => 'Shift Sore 14:00–21:00', 'day_label' => 'Setiap hari', 'starts_at' => '14:00:00', 'ends_at' => '21:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'PD',
                'name' => 'Poliklinik Penyakit Dalam',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Andika Pratama, Sp.PD',
                        'specialty' => 'Sp.PD',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Ratna Kusumawati, Sp.PD',
                        'specialty' => 'Sp.PD',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Bima Adinugraha, Sp.PD',
                        'specialty' => 'Sp.PD',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'BEDAH',
                'name' => 'Poliklinik Bedah Umum',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Arief Wicaksana, Sp.B',
                        'specialty' => 'Sp.B',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Maya Puspitasari, Sp.B',
                        'specialty' => 'Sp.B',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Reza Kurniawan, Sp.B',
                        'specialty' => 'Sp.B',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'OG',
                'name' => 'Poliklinik Obstetri dan Ginekologi',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Dewi Anggraini, Sp.OG',
                        'specialty' => 'Sp.OG',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Yoga Pranata, Sp.OG',
                        'specialty' => 'Sp.OG',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Citra Maharani, Sp.OG',
                        'specialty' => 'Sp.OG',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'MATA',
                'name' => 'Poliklinik Mata',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Raka Permana, Sp.M',
                        'specialty' => 'Sp.M',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Indah Safitri, Sp.M',
                        'specialty' => 'Sp.M',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Gita Paramesti, Sp.M',
                        'specialty' => 'Sp.M',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'THT',
                'name' => 'Poliklinik THT',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Dimas Nugroho, Sp.T.H.T',
                        'specialty' => 'Sp.T.H.T',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Shafira Aulia, Sp.T.H.T',
                        'specialty' => 'Sp.T.H.T',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Kevin Santoso, Sp.T.H.T.',
                        'specialty' => 'Sp.T.H.T',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'SARAF',
                'name' => 'Poliklinik Saraf',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Bagus Wiratama, Sp.N',
                        'specialty' => 'Sp.N',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Laila Rahmawati, Sp.N',
                        'specialty' => 'Sp.N',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Hendra Saputra, Sp.N',
                        'specialty' => 'Sp.N',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'DVE',
                'name' => 'Poliklinik Dermatologi, Venereologi, dan Estetika',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Karin Amelia, Sp.D.V.E',
                        'specialty' => 'Sp.D.V.E',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Taufik Hidayat, Sp.D.V.E',
                        'specialty' => 'Sp.D.V.E',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Nadia Febriani, Sp.D.V.E',
                        'specialty' => 'Sp.D.V.E',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'JIWA',
                'name' => 'Poliklinik Kedokteran Jiwa/Psikiatri',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Rina Handayani, Sp.KJ',
                        'specialty' => 'Sp.KJ',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Ahmad Firdaus, Sp.KJ',
                        'specialty' => 'Sp.KJ',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Sarah Anindita, Sp.KJ',
                        'specialty' => 'Sp.KJ',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'PARU',
                'name' => 'Poliklinik Paru',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Yudi Hartono, Sp.P',
                        'specialty' => 'Sp.P',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Fitri Oktaviani, Sp.P',
                        'specialty' => 'Sp.P',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Galih Pamungkas, Sp.P',
                        'specialty' => 'Sp.P',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'ORTHO',
                'name' => 'Poliklinik Orthopedi dan Traumatologi',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Doni Setiawan, Sp.OT',
                        'specialty' => 'Sp.OT',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Vina Marlina, Sp.OT',
                        'specialty' => 'Sp.OT',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Rangga Prakoso, Sp.OT',
                        'specialty' => 'Sp.OT',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'URO',
                'name' => 'Poliklinik Urologi',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Eko Prasetyo, Sp.U',
                        'specialty' => 'Sp.U',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Tiara Maharani, Sp.U',
                        'specialty' => 'Sp.U',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Wahyu Kuncoro, Sp.U',
                        'specialty' => 'Sp.U',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'BS',
                'name' => 'Poliklinik Bedah Saraf',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Irfan Maulana, Sp.BS',
                        'specialty' => 'Sp.BS',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Putri Ayuningtyas, Sp.BS',
                        'specialty' => 'Sp.BS',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Bayu Herlambang, Sp.BS',
                        'specialty' => 'Sp.BS',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'BMM',
                'name' => 'Poliklinik Bedah Mulut dan Maksilofasial',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'drg. Rizky Alamsyah, Sp.B.M.M',
                        'specialty' => 'Sp.B.M.M',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'drg. Hana Lestari, Sp.B.M.M',
                        'specialty' => 'Sp.B.M.M',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'drg. Marco Wijaya, Sp.B.M.M',
                        'specialty' => 'Sp.B.M.M',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'KG',
                'name' => 'Poliklinik Konservasi Gigi',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'drg. Ayu Pertiwi, Sp.KG',
                        'specialty' => 'Sp.KG',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'drg. Denny Firmansyah, Sp.KG',
                        'specialty' => 'Sp.KG',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'drg. Rossa Meilani, Sp.KG',
                        'specialty' => 'Sp.KG',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'PERIO',
                'name' => 'Poliklinik Periodonsia',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'drg. Luthfi Hakim, Sp.Perio',
                        'specialty' => 'Sp.Perio',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'drg. Wulan Cahyani, Sp.Perio',
                        'specialty' => 'Sp.Perio',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'drg. Steven Gunawan, Sp.Perio',
                        'specialty' => 'Sp.Perio',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'REHAB',
                'name' => 'Poliklinik Rehabilitasi Medik',
                'booking_surface' => ClinicBookingSurface::OUTPATIENT,
                'doctors' => [
                    [
                        'name' => 'dr. Intan Prameswari, Sp.K.F.R',
                        'specialty' => 'Sp.K.F.R',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Yohan Kurnia, Sp.K.F.R',
                        'specialty' => 'Sp.K.F.R',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Fani Kusuma, Sp.K.F.R',
                        'specialty' => 'Sp.K.F.R',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'ANESTESI',
                'name' => 'Pelayanan Anestesiologi dan Terapi Intensif',
                'booking_surface' => ClinicBookingSurface::SUPPORTING,
                'doctors' => [
                    [
                        'name' => 'dr. Damar Wisesa, Sp.An-TI',
                        'specialty' => 'Sp.An-TI',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Niken Larasati, Sp.An-TI',
                        'specialty' => 'Sp.An-TI',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Aldi Syahputra, Sp.An-TI',
                        'specialty' => 'Sp.An-TI',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'RAD',
                'name' => 'Pelayanan Radiologi',
                'booking_surface' => ClinicBookingSurface::SUPPORTING,
                'doctors' => [
                    [
                        'name' => 'dr. Hani Purnamasari, Sp.Rad',
                        'specialty' => 'Sp.Rad',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Rio Adiputra, Sp.Rad',
                        'specialty' => 'Sp.Rad',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Amalia Khairunnisa, Sp.Rad',
                        'specialty' => 'Sp.Rad',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'PK',
                'name' => 'Pelayanan Patologi Klinik',
                'booking_surface' => ClinicBookingSurface::SUPPORTING,
                'doctors' => [
                    [
                        'name' => 'dr. Diah Puspaningrum, Sp.PK',
                        'specialty' => 'Sp.PK',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Fikri Anwar, Sp.PK',
                        'specialty' => 'Sp.PK',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                    [
                        'name' => 'dr. Monica Wijayanti, Sp.PK',
                        'specialty' => 'Sp.PK',
                        'schedules' => [
                            ['label' => 'Senin 08:00–12:00', 'day_label' => 'Senin', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Rabu 08:00–12:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($catalog as $clinicData) {
            $clinic = Clinic::query()->updateOrCreate(
                ['code' => $clinicData['code']],
                [
                    'name' => $clinicData['name'],
                    'booking_surface' => ClinicBookingSurface::assertKnown($clinicData['booking_surface']),
                    'is_active' => true,
                ],
            );

            foreach ($clinicData['doctors'] as $doctorData) {
                $doctor = Doctor::query()->updateOrCreate(
                    [
                        'clinic_id' => $clinic->id,
                        'name' => $doctorData['name'],
                    ],
                    [
                        'specialty' => $doctorData['specialty'],
                        'is_active' => true,
                    ],
                );

                foreach ($doctorData['schedules'] as $scheduleData) {
                    ClinicSchedule::query()->updateOrCreate(
                        [
                            'clinic_id' => $clinic->id,
                            'doctor_id' => $doctor->id,
                            'label' => $scheduleData['label'],
                        ],
                        [
                            'day_label' => $scheduleData['day_label'],
                            'starts_at' => $scheduleData['starts_at'],
                            'ends_at' => $scheduleData['ends_at'],
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }
}
