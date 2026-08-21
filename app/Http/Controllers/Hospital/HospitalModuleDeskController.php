<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Encounter\Models\Encounter;
use App\Modules\Patient\Enums\IdentifierType;
use App\Modules\Teaching\Enums\Capability;
use App\Modules\Teaching\Models\Assignment;
use App\Modules\Teaching\Services\HospitalSessionContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HospitalModuleDeskController extends Controller
{
    public function __construct(
        private readonly HospitalSessionContext $sessionContext,
    ) {}

    public function pemeriksaan(Request $request): Response
    {
        return $this->renderModule($request, 'pemeriksaan', 'Pemeriksaan', [
            Capability::IntakeWrite->value,
            Capability::MedicalAssessmentWrite->value,
            Capability::SupervisionReview->value,
            Capability::SessionFacilitate->value,
            Capability::PatientSearch->value,
        ], fn (Encounter $encounter): array => [
            [
                'label' => 'Asesmen awal',
                'url' => route('encounters.nursing-intake.show', $encounter),
            ],
            [
                'label' => 'Asesmen medis',
                'url' => route('encounters.medical-assessment.show', $encounter),
            ],
            [
                'label' => 'Ringkasan encounter',
                'url' => route('encounters.show', $encounter),
            ],
        ]);
    }

    public function rekamMedis(Request $request): Response
    {
        return $this->renderModule($request, 'rekam-medis', 'Rekam Medis', [
            Capability::RecordReview->value,
            Capability::CodingWrite->value,
            Capability::SupervisionReview->value,
            Capability::SessionFacilitate->value,
            Capability::PatientSearch->value,
            Capability::DebriefView->value,
        ], fn (Encounter $encounter): array => [
            [
                'label' => 'Kelengkapan RM',
                'url' => route('encounters.record-quality.show', $encounter),
            ],
            [
                'label' => 'Koding',
                'url' => route('encounters.coding.show', $encounter),
            ],
            [
                'label' => 'Linimasa',
                'url' => route('encounters.timeline.show', $encounter),
            ],
            [
                'label' => 'Ringkasan encounter',
                'url' => route('encounters.show', $encounter),
            ],
        ]);
    }

    public function apotek(Request $request): Response
    {
        return $this->renderModule($request, 'apotek', 'Apotek', [
            Capability::PharmacyReview->value,
            Capability::Dispense->value,
            Capability::SupervisionReview->value,
            Capability::SessionFacilitate->value,
            Capability::PatientSearch->value,
        ], fn (Encounter $encounter): array => [
            [
                'label' => 'Meja farmasi',
                'url' => route('encounters.pharmacy.show', $encounter),
            ],
            [
                'label' => 'Ringkasan encounter',
                'url' => route('encounters.show', $encounter),
            ],
        ]);
    }

    public function klaim(Request $request): Response
    {
        return $this->renderModule($request, 'klaim', 'Klaim', [
            Capability::ClaimManage->value,
            Capability::ClaimReview->value,
            Capability::CodingWrite->value,
            Capability::SessionFacilitate->value,
            Capability::ReportView->value,
        ], fn (Encounter $encounter): array => [
            [
                'label' => 'Simulasi E-Klaim',
                'url' => route('encounters.eclaim-simulation.show', $encounter),
            ],
            [
                'label' => 'Koding',
                'url' => route('encounters.coding.show', $encounter),
            ],
            [
                'label' => 'Ringkasan encounter',
                'url' => route('encounters.show', $encounter),
            ],
        ], educationalNote: 'Modul klaim bersifat edukatif. Tidak ada pengiriman klaim produksi atau koneksi BPJS nyata.');
    }

    public function laporan(Request $request): Response
    {
        return $this->renderModule($request, 'laporan', 'Laporan', [
            Capability::ReportView->value,
            Capability::DebriefView->value,
            Capability::SessionFacilitate->value,
        ], fn (Encounter $encounter): array => [
            [
                'label' => 'Ringkasan rawat jalan',
                'url' => route('encounters.reports.outpatient-summary', $encounter),
            ],
            [
                'label' => 'Bukti debrief',
                'url' => route('encounters.reports.debrief-evidence', $encounter),
            ],
            [
                'label' => 'Debrief',
                'url' => route('encounters.debrief.show', $encounter),
            ],
        ]);
    }

    public function bpjs(Request $request): Response
    {
        return $this->renderModule($request, 'bpjs', 'BPJS', [
            Capability::ClaimManage->value,
            Capability::ClaimReview->value,
            Capability::SessionFacilitate->value,
            Capability::ReportView->value,
            Capability::SessionView->value,
        ], fn (Encounter $encounter): array => [
            [
                'label' => 'Simulasi E-Klaim (edukatif)',
                'url' => route('encounters.eclaim-simulation.show', $encounter),
            ],
            [
                'label' => 'Pratinjau interoperabilitas lokal',
                'url' => route('encounters.interoperability-preview.show', $encounter),
            ],
        ], educationalNote: 'BPJS di SIMRS Campus UEU hanya postur pembelajaran. Tidak ada kredensial, endpoint, atau pengiriman data ke BPJS/SATUSEHAT produksi.');
    }

    public function kasir(Request $request): Response
    {
        return $this->renderModule($request, 'kasir', 'Kasir', [
            Capability::PatientRegister->value,
            Capability::ClaimManage->value,
            Capability::SessionFacilitate->value,
            Capability::SessionView->value,
        ], fn (Encounter $encounter): array => [
            [
                'label' => 'Ringkasan encounter',
                'url' => route('encounters.show', $encounter),
            ],
            [
                'label' => 'Simulasi E-Klaim',
                'url' => route('encounters.eclaim-simulation.show', $encounter),
            ],
        ], educationalNote: 'Kasir masih tipis: terhubung ke encounter yang sama. Pembayaran produksi belum diaktifkan.');
    }

    /**
     * @param  list<string>  $allowedCapabilities
     * @param  callable(Encounter): list<array{label: string, url: string}>  $actionsFor
     */
    private function renderModule(
        Request $request,
        string $key,
        string $title,
        array $allowedCapabilities,
        callable $actionsFor,
        ?string $educationalNote = null,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $assignments = $this->sessionContext->activeAssignments($user)
            ->filter(function (Assignment $assignment) use ($allowedCapabilities): bool {
                foreach ($allowedCapabilities as $capability) {
                    if ($assignment->hasCapability($capability)) {
                        return true;
                    }
                }

                return false;
            });

        if ($assignments->isEmpty()) {
            abort(403);
        }

        $session = $this->sessionContext->resolveSession(
            $assignments,
            is_string($request->query('session')) ? $request->query('session') : null,
            requireExplicitWhenMultiple: true,
        );

        if ($session === null) {
            return Inertia::render('hospital/session-picker', [
                'module' => [
                    'key' => $key,
                    'title' => $title,
                    'description' => "Pilih sesi simulasi untuk membuka modul {$title}.",
                ],
                'sessions' => $this->sessionContext->sessionOptions($assignments),
                'continueBaseUrl' => route('desk.'.$key),
            ]);
        }

        $encounters = Encounter::query()
            ->where('session_id', $session->getKey())
            ->with(['patient.identifiers', 'location', 'appointment'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return Inertia::render('hospital/module-desk', [
            'module' => [
                'key' => $key,
                'title' => $title,
                'educationalNote' => $educationalNote,
            ],
            'session' => [
                'publicId' => $session->public_id,
                'code' => $session->code,
                'courseCode' => $session->course_code,
                'scenarioTitle' => $session->scenario?->title,
            ],
            'sessions' => $this->sessionContext->sessionOptions($assignments),
            'encounters' => $encounters->map(function (Encounter $encounter) use ($actionsFor): array {
                $mrn = $encounter->patient->identifiers
                    ->firstWhere('type', IdentifierType::MedicalRecordNumber);

                return [
                    'publicId' => $encounter->public_id,
                    'number' => $encounter->encounter_number,
                    'status' => [
                        'code' => $encounter->status->value,
                        'label' => $encounter->status->label(),
                    ],
                    'patientName' => $encounter->patient->full_name,
                    'mrn' => $mrn?->value,
                    'location' => $encounter->location?->name,
                    'appointmentStatus' => $encounter->appointment?->status?->label(),
                    'overviewUrl' => route('encounters.show', $encounter),
                    'actions' => $actionsFor($encounter),
                ];
            })->values()->all(),
        ]);
    }
}
