<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\ClinicSchedule;
use App\Models\ClinicalEntry;
use App\Models\Doctor;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Models\WilayahVillage;
use Database\Seeders\InpatientMastersSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TeachingCensusSeeder extends Seeder
{
    public function run(): void
    {
        if (\App\Models\WilayahProvince::query()->count() < 4) {
            $this->call(WilayahMinimalSeeder::class);
        }
        $this->call(OutpatientMastersSeeder::class);

        $registrar = User::query()->where('email', 'registrar.demo@example.invalid')->first()
            ?? User::query()->where('email', 'mahasiswa.rmik@example.invalid')->first()
            ?? User::query()->orderBy('id')->first();

        $nurse = User::query()->where('email', 'nurse.demo@example.invalid')->first() ?? $registrar;
        $physician = User::query()->where('email', 'physician.demo@example.invalid')->first() ?? $registrar;

        if ($registrar === null) {
            $this->command?->warn('TeachingCensusSeeder skipped: no demo users.');

            return;
        }

        $rjClinic = Clinic::query()->where('code', 'UMUM')->first();
        $igdClinic = Clinic::query()->where('code', 'IGD')->first();
        $gigiClinic = Clinic::query()->where('code', 'GIGI')->first();

        if ($rjClinic === null || $igdClinic === null) {
            $this->command?->warn('TeachingCensusSeeder skipped: clinics missing.');

            return;
        }

        $wards = InpatientMastersSeeder::wardsCatalogue();
        $addresses = $this->addressCatalogue();
        $today = Carbon::today();

        $blueprints = $this->patientBlueprints();

        DB::transaction(function () use (
            $blueprints,
            $addresses,
            $registrar,
            $nurse,
            $physician,
            $rjClinic,
            $igdClinic,
            $gigiClinic,
            $wards,
            $today,
        ): void {
            foreach ($blueprints as $index => $blueprint) {
                $address = $addresses[$index % count($addresses)];
                $mrn = $blueprint['mrn'];

                $patient = Patient::query()->updateOrCreate(
                    ['medical_record_number' => $mrn],
                    [
                        'nik' => $blueprint['nik'],
                        'full_name' => $blueprint['full_name'],
                        'place_of_birth' => $blueprint['place_of_birth'],
                        'date_of_birth' => $blueprint['date_of_birth'],
                        'sex' => $blueprint['sex'],
                        'religion' => $blueprint['religion'],
                        'education' => $blueprint['education'],
                        'occupation' => $blueprint['occupation'],
                        'province_code' => $address['province_code'],
                        'province' => $address['province'],
                        'city_code' => $address['city_code'],
                        'city' => $address['city'],
                        'district_code' => $address['district_code'],
                        'district' => $address['district'],
                        'village_code' => $address['village_code'],
                        'village' => $address['village'],
                        'address_line' => 'Jl. Sintetis No. '.($index + 1),
                        'domicile' => $address['city'],
                        'phone' => '0000000000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                        'email' => 'synth.patient.'.($index + 1).'@example.invalid',
                        'ethnicity' => 'Sintetis',
                        'language' => 'Indonesia',
                        'notes' => 'Data sintesis pengajaran — bukan pasien nyata.',
                        'responsible_party_name' => 'PJ '.$blueprint['full_name'],
                        'is_synthetic' => true,
                        'created_by_user_id' => $registrar->id,
                    ],
                );

                $kind = $blueprint['kind'];

                if ($kind === 'RJ') {
                    $clinic = ($index % 5 === 0 && $gigiClinic !== null) ? $gigiClinic : $rjClinic;
                    $this->seedOutpatientEncounter(
                        $patient,
                        $clinic,
                        $registrar,
                        $nurse,
                        $physician,
                        $blueprint['status'],
                        $today->copy()->subDays($blueprint['days_ago']),
                        $index,
                    );
                } elseif ($kind === 'IGD') {
                    $this->seedEmergencyEncounter(
                        $patient,
                        $igdClinic,
                        $registrar,
                        $nurse,
                        $physician,
                        $blueprint['status'],
                        $today->copy()->subDays($blueprint['days_ago']),
                        $index,
                    );
                } else {
                    $ward = $wards[$index % count($wards)];
                    $bed = $ward['beds'][$index % count($ward['beds'])];
                    $this->seedInpatientEncounter(
                        $patient,
                        $registrar,
                        $nurse,
                        $physician,
                        $blueprint['status'],
                        $today->copy()->subDays($blueprint['days_ago']),
                        $ward['name'],
                        $ward['class'],
                        $bed,
                        $index,
                    );
                }
            }
        });

        $this->command?->info('Teaching census patients: '.Patient::query()->where('is_synthetic', true)->where('medical_record_number', 'like', 'SYNTH-CENSUS-%')->count());
    }

    /**
     * @return list<array{
     *     mrn: string,
     *     nik: string,
     *     full_name: string,
     *     place_of_birth: string,
     *     date_of_birth: string,
     *     sex: string,
     *     religion: string,
     *     education: string,
     *     occupation: string,
     *     kind: 'RJ'|'IGD'|'RI',
     *     status: string,
     *     days_ago: int
     * }>
     */
    private function patientBlueprints(): array
    {
        $names = [
            'Andi Pratama Sintetis', 'Bunga Lestari Sintetis', 'Citra Dewi Sintetis', 'Dedi Kurniawan Sintetis',
            'Eka Putri Sintetis', 'Fajar Nugroho Sintetis', 'Gita Maharani Sintetis', 'Hadi Santoso Sintetis',
            'Indah Permata Sintetis', 'Joko Widodo Sintetis', 'Kartika Sari Sintetis', 'Lukman Hakim Sintetis',
            'Maya Anggraini Sintetis', 'Nanda Putra Sintetis', 'Olivia Tan Sintetis', 'Putra Wijaya Sintetis',
            'Qori Amalia Sintetis', 'Raka Firmansyah Sintetis', 'Sinta Belia Sintetis', 'Tono Saputra Sintetis',
            'Umi Kulsum Sintetis', 'Vina Melati Sintetis', 'Wawan Setiawan Sintetis', 'Xenia Putri Sintetis',
            'Yudi Hermawan Sintetis', 'Zahra Nabila Sintetis', 'Agus Salim Sintetis', 'Bella Safira Sintetis',
            'Cahyo Ramadhan Sintetis', 'Dian Puspita Sintetis', 'Erwin Gunawan Sintetis', 'Fitri Handayani Sintetis',
            'Gilang Ramadhan Sintetis', 'Hana Pertiwi Sintetis', 'Irfan Maulana Sintetis', 'Juli Astuti Sintetis',
        ];

        $statusesRj = [
            Encounter::STATUS_REGISTERED,
            Encounter::STATUS_IN_EXAMINATION,
            Encounter::STATUS_READY_FOR_RM,
            Encounter::STATUS_CLOSED,
        ];

        $kinds = array_merge(
            array_fill(0, 18, 'RJ'),
            array_fill(0, 10, 'IGD'),
            array_fill(0, 8, 'RI'),
        );

        $out = [];
        foreach ($names as $i => $name) {
            $kind = $kinds[$i];
            $status = match ($kind) {
                'RJ' => $statusesRj[$i % count($statusesRj)],
                'IGD' => $i % 3 === 0 ? Encounter::STATUS_IN_EXAMINATION : Encounter::STATUS_REGISTERED,
                default => $i % 4 === 0 ? Encounter::STATUS_READY_FOR_RM : Encounter::STATUS_IN_EXAMINATION,
            };

            $out[] = [
                'mrn' => sprintf('SYNTH-CENSUS-%03d', $i + 1),
                'nik' => sprintf('32010101%08d', 10000000 + $i),
                'full_name' => $name,
                'place_of_birth' => ['Jakarta', 'Bekasi', 'Yogyakarta', 'Denpasar', 'Depok'][$i % 5],
                'date_of_birth' => Carbon::parse('1990-01-01')->addDays($i * 37)->toDateString(),
                'sex' => $i % 2 === 0 ? Patient::SEX_LAKI_LAKI : Patient::SEX_PEREMPUAN,
                'religion' => Patient::RELIGION_VALUES[$i % count(Patient::RELIGION_VALUES)],
                'education' => Patient::EDUCATION_VALUES[$i % count(Patient::EDUCATION_VALUES)],
                'occupation' => Patient::OCCUPATION_VALUES[$i % count(Patient::OCCUPATION_VALUES)],
                'kind' => $kind,
                'status' => $status,
                'days_ago' => $i % 5,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{
     *     province_code: string,
     *     province: string,
     *     city_code: string,
     *     city: string,
     *     district_code: string,
     *     district: string,
     *     village_code: string,
     *     village: string
     * }>
     */
    private function addressCatalogue(): array
    {
        $villages = WilayahVillage::query()
            ->with('district.regency.province')
            ->orderBy('code')
            ->limit(20)
            ->get();

        if ($villages->isEmpty()) {
            return [[
                'province_code' => '31',
                'province' => 'DKI Jakarta',
                'city_code' => '3173',
                'city' => 'Kota Jakarta Barat',
                'district_code' => '317301',
                'district' => 'Kebon Jeruk',
                'village_code' => '3173011001',
                'village' => 'Kedoya Utara',
            ]];
        }

        return $villages->map(function (WilayahVillage $village): array {
            $district = $village->district;
            $regency = $district?->regency;
            $province = $regency?->province;

            return [
                'province_code' => $province?->code ?? '31',
                'province' => $province?->name ?? 'DKI Jakarta',
                'city_code' => $regency?->code ?? '3173',
                'city' => $regency?->name ?? 'Kota Jakarta Barat',
                'district_code' => $district?->code ?? '317301',
                'district' => $district?->name ?? 'Kebon Jeruk',
                'village_code' => $village->code,
                'village' => $village->name,
            ];
        })->all();
    }

    private function seedOutpatientEncounter(
        Patient $patient,
        Clinic $clinic,
        User $registrar,
        User $nurse,
        User $physician,
        string $status,
        Carbon $visitDate,
        int $index,
    ): void {
        $doctor = Doctor::query()->where('clinic_id', $clinic->id)->where('is_active', true)->orderBy('id')->first();
        $schedule = $doctor
            ? ClinicSchedule::query()->where('doctor_id', $doctor->id)->where('is_active', true)->orderBy('id')->first()
            : null;

        $marker = 'SYNTH-ENC-RJ-'.sprintf('%03d', $index + 1);

        $encounter = Encounter::query()->updateOrCreate(
            ['booking_code' => $marker],
            [
                'patient_id' => $patient->id,
                'care_setting' => Encounter::CARE_SETTING_OUTPATIENT,
                'status' => $status,
                'clinic_name' => $clinic->name,
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor?->id,
                'clinic_schedule_id' => $schedule?->id,
                'doctor_name' => $doctor?->name,
                'schedule_label' => $schedule?->label,
                'visit_date' => $visitDate,
                'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
                'payer_type' => $index % 3 === 0 ? Encounter::PAYER_BPJS : Encounter::PAYER_UMUM,
                'insurance_number' => $index % 3 === 0 ? 'SYNTH-BPJS-'.sprintf('%04d', $index + 1) : null,
                'queue_number' => ($index % 40) + 1,
                'registered_at' => $visitDate->copy()->setTime(7 + ($index % 8), ($index * 3) % 60),
                'registered_by_user_id' => $registrar->id,
                'chief_complaint' => 'Keluhan sintesis untuk uji desktop pendaftaran/pemeriksaan.',
            ],
        );

        $this->seedNotesIfNeeded($encounter, $nurse, $physician, $status);
    }

    private function seedEmergencyEncounter(
        Patient $patient,
        Clinic $clinic,
        User $registrar,
        User $nurse,
        User $physician,
        string $status,
        Carbon $visitDate,
        int $index,
    ): void {
        $doctor = Doctor::query()->where('clinic_id', $clinic->id)->where('is_active', true)->orderBy('id')->first();
        $schedule = $doctor
            ? ClinicSchedule::query()->where('doctor_id', $doctor->id)->where('is_active', true)->orderBy('id')->first()
            : null;

        $marker = 'SYNTH-ENC-IGD-'.sprintf('%03d', $index + 1);

        $encounter = Encounter::query()->updateOrCreate(
            ['booking_code' => $marker],
            [
                'patient_id' => $patient->id,
                'care_setting' => Encounter::CARE_SETTING_EMERGENCY,
                'status' => $status,
                'clinic_name' => $clinic->name,
                'clinic_id' => $clinic->id,
                'doctor_id' => $doctor?->id,
                'clinic_schedule_id' => $schedule?->id,
                'doctor_name' => $doctor?->name,
                'schedule_label' => $schedule?->label,
                'visit_date' => $visitDate,
                'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
                'payer_type' => Encounter::PAYER_UMUM,
                'queue_number' => ($index % 20) + 1,
                'registered_at' => $visitDate->copy()->setTime(1 + ($index % 20), ($index * 5) % 60),
                'registered_by_user_id' => $registrar->id,
                'chief_complaint' => 'Triase sintesis — keluhan akut pengajaran.',
                'case_type' => $index % 2 === 0 ? Encounter::CASE_NON_BEDAH : Encounter::CASE_BEDAH,
                'accident_type' => $index % 4 === 0 ? Encounter::ACCIDENT_YES : Encounter::ACCIDENT_NONE,
            ],
        );

        $this->seedNotesIfNeeded($encounter, $nurse, $physician, $status);
    }

    private function seedInpatientEncounter(
        Patient $patient,
        User $registrar,
        User $nurse,
        User $physician,
        string $status,
        Carbon $visitDate,
        string $wardName,
        string $wardClass,
        string $bedCode,
        int $index,
    ): void {
        $marker = 'SYNTH-ENC-RI-'.sprintf('%03d', $index + 1);

        // Soft dual-book: skip creating a second OPEN stay on the same bed.
        $openConflict = Encounter::query()
            ->where('care_setting', Encounter::CARE_SETTING_INPATIENT)
            ->where('ward_name', $wardName)
            ->where('ward_class', $wardClass)
            ->where('bed_code', $bedCode)
            ->where('status', '!=', Encounter::STATUS_CLOSED)
            ->where('booking_code', '!=', $marker)
            ->exists();

        if ($openConflict && $status !== Encounter::STATUS_CLOSED) {
            $status = Encounter::STATUS_CLOSED;
        }

        $encounter = Encounter::query()->updateOrCreate(
            ['booking_code' => $marker],
            [
                'patient_id' => $patient->id,
                'care_setting' => Encounter::CARE_SETTING_INPATIENT,
                'status' => $status,
                'clinic_name' => $wardName,
                'visit_date' => $visitDate,
                'admission_mode' => Encounter::ADMISSION_DATANG_SENDIRI,
                'payer_type' => Encounter::PAYER_BPJS,
                'insurance_number' => 'SYNTH-RI-BPJS-'.sprintf('%04d', $index + 1),
                'registered_at' => $visitDate->copy()->setTime(10, ($index * 7) % 60),
                'registered_by_user_id' => $registrar->id,
                'chief_complaint' => 'Rawat inap sintesis — observasi pengajaran.',
                'ward_name' => $wardName,
                'ward_class' => $wardClass,
                'bed_code' => $bedCode,
                'continue_from' => $index % 2 === 0 ? Encounter::CONTINUE_LANGSUNG : Encounter::CONTINUE_DARI_IGD,
            ],
        );

        $this->seedNotesIfNeeded($encounter, $nurse, $physician, $status);
    }

    private function seedNotesIfNeeded(Encounter $encounter, User $nurse, User $physician, string $status): void
    {
        if ($status === Encounter::STATUS_REGISTERED) {
            return;
        }

        ClinicalEntry::query()->updateOrCreate(
            [
                'encounter_id' => $encounter->id,
                'entry_type' => ClinicalEntry::TYPE_NURSING_INTAKE,
            ],
            [
                'author_user_id' => $nurse->id,
                'body' => 'Asesmen keperawatan sintesis. TTV dalam batas pengajaran. '.$encounter->booking_code,
            ],
        );

        if (in_array($status, [Encounter::STATUS_READY_FOR_RM, Encounter::STATUS_CLOSED], true)) {
            ClinicalEntry::query()->updateOrCreate(
                [
                    'encounter_id' => $encounter->id,
                    'entry_type' => ClinicalEntry::TYPE_MEDICAL_ASSESSMENT,
                ],
                [
                    'author_user_id' => $physician->id,
                    'body' => 'Asesmen medis sintesis. Rencana terapi pengajaran. '.$encounter->booking_code,
                ],
            );
        }
    }
}
