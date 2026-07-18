<?php

namespace App\Modules\Teaching\Enums;

enum Capability: string
{
    case SessionView = 'session.view';
    case SessionFacilitate = 'session.facilitate';
    case PatientSearch = 'patient.search';
    case PatientRegister = 'patient.register';
    case IntakeWrite = 'intake.write';
    case MedicalAssessmentWrite = 'medical-assessment.write';
    case PrescriptionWrite = 'prescription.write';
    case PharmacyReview = 'pharmacy.review';
    case Dispense = 'pharmacy.dispense';
    case RecordReview = 'record.review';
    case CodingWrite = 'coding.write';
    case TerminologyManage = 'terminology.manage';
    case SupervisionReview = 'supervision.review';
    case SafetyDispositionRecord = 'safety-disposition.record';
    case EarlyDepartureRecord = 'early-departure.record';
    case DebriefView = 'debrief.view';
    case DebriefWrite = 'debrief.write';
    case ReportView = 'report.view';
    case SystemConfigure = 'system.configure';

    public function label(): string
    {
        return match ($this) {
            self::SessionView => 'Melihat sesi simulasi',
            self::SessionFacilitate => 'Memfasilitasi sesi',
            self::PatientSearch => 'Mencari pasien sintetis',
            self::PatientRegister => 'Mendaftarkan pasien sintetis',
            self::IntakeWrite => 'Mengisi asesmen awal',
            self::MedicalAssessmentWrite => 'Mengisi asesmen medis',
            self::PrescriptionWrite => 'Menulis resep simulasi',
            self::PharmacyReview => 'Melakukan telaah farmasi',
            self::Dispense => 'Mencatat penyerahan obat simulasi',
            self::RecordReview => 'Menelaah kelengkapan rekam medis',
            self::CodingWrite => 'Mengisi kode klinis',
            self::TerminologyManage => 'Mengelola release terminologi',
            self::SupervisionReview => 'Melakukan tinjauan supervisor',
            self::SafetyDispositionRecord => 'Mencatat keputusan eskalasi simulasi',
            self::EarlyDepartureRecord => 'Mencatat pulang atas permintaan sendiri',
            self::DebriefView => 'Melihat debrief',
            self::DebriefWrite => 'Menulis catatan debrief bersama',
            self::ReportView => 'Melihat laporan simulasi final',
            self::SystemConfigure => 'Mengelola konfigurasi sistem',
        };
    }
}
