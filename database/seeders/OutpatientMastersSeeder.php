<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Models\Doctor;
use Illuminate\Database\Seeder;

class OutpatientMastersSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            [
                'code' => 'UMUM',
                'name' => 'Poliklinik Umum',
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
                'doctors' => [
                    [
                        'name' => 'dr. Rina Wulandari, Sp.A',
                        'specialty' => 'Anak',
                        'schedules' => [
                            ['label' => 'Selasa 08:00–12:00', 'day_label' => 'Selasa', 'starts_at' => '08:00:00', 'ends_at' => '12:00:00'],
                            ['label' => 'Kamis 08:00–11:00', 'day_label' => 'Kamis', 'starts_at' => '08:00:00', 'ends_at' => '11:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'JANTUNG',
                'name' => 'Poliklinik Jantung',
                'doctors' => [
                    [
                        'name' => 'dr. Andi Pratama, Sp.JP',
                        'specialty' => 'Jantung',
                        'schedules' => [
                            ['label' => 'Rabu 08:00–11:00', 'day_label' => 'Rabu', 'starts_at' => '08:00:00', 'ends_at' => '11:00:00'],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'IGD',
                'name' => 'Instalasi Gawat Darurat',
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
        ];

        foreach ($catalog as $clinicData) {
            $clinic = Clinic::query()->updateOrCreate(
                ['code' => $clinicData['code']],
                [
                    'name' => $clinicData['name'],
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
