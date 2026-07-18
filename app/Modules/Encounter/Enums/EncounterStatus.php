<?php

namespace App\Modules\Encounter\Enums;

enum EncounterStatus: string
{
    case Planned = 'PLANNED';
    case Arrived = 'ARRIVED';
    case InIntake = 'IN_INTAKE';
    case Escalated = 'ESCALATED';
    case WaitingClinician = 'WAITING_CLINICIAN';
    case InConsultation = 'IN_CONSULTATION';
    case AwaitingResult = 'AWAITING_RESULT';
    case AwaitingPharmacy = 'AWAITING_PHARMACY';
    case ClosurePending = 'CLOSURE_PENDING';
    case ClinicallyClosed = 'CLINICALLY_CLOSED';
    case RecordReview = 'RECORD_REVIEW';
    case AmendmentPending = 'AMENDMENT_PENDING';
    case Finalized = 'FINALIZED';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';
    case TransferredSimulation = 'TRANSFERRED_SIMULATION';
    case DepartedOnRequest = 'DEPARTED_ON_REQUEST';

    /**
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Planned => [self::Arrived, self::Cancelled, self::NoShow],
            self::Arrived => [self::InIntake, self::Cancelled],
            self::InIntake => [self::WaitingClinician, self::Escalated, self::Cancelled, self::DepartedOnRequest],
            self::Escalated => [self::WaitingClinician, self::TransferredSimulation, self::Cancelled],
            self::WaitingClinician => [self::InConsultation, self::Cancelled, self::DepartedOnRequest],
            self::InConsultation => [self::AwaitingResult, self::AwaitingPharmacy, self::ClosurePending, self::DepartedOnRequest],
            self::AwaitingResult => [self::InConsultation, self::Cancelled, self::DepartedOnRequest],
            self::AwaitingPharmacy => [self::InConsultation, self::ClosurePending, self::Cancelled, self::DepartedOnRequest],
            self::ClosurePending => [self::ClinicallyClosed, self::InConsultation, self::DepartedOnRequest],
            self::ClinicallyClosed => [self::RecordReview, self::AmendmentPending],
            self::RecordReview => [self::AmendmentPending, self::Finalized],
            self::AmendmentPending => [self::RecordReview],
            self::Finalized => [self::AmendmentPending],
            self::TransferredSimulation => [self::RecordReview],
            self::Cancelled, self::NoShow, self::DepartedOnRequest => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNextStates(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Terencana',
            self::Arrived => 'Tiba',
            self::InIntake => 'Asesmen awal',
            self::Escalated => 'Dihentikan untuk eskalasi',
            self::WaitingClinician => 'Menunggu klinisi',
            self::InConsultation => 'Dalam konsultasi',
            self::AwaitingResult => 'Menunggu hasil',
            self::AwaitingPharmacy => 'Menunggu farmasi',
            self::ClosurePending => 'Menunggu penutupan',
            self::ClinicallyClosed => 'Ditutup secara klinis',
            self::RecordReview => 'Telaah rekam medis',
            self::AmendmentPending => 'Menunggu amendemen',
            self::Finalized => 'Difinalisasi untuk simulasi',
            self::Cancelled => 'Dibatalkan',
            self::NoShow => 'Tidak hadir',
            self::TransferredSimulation => 'Dialihkan dalam simulasi',
            self::DepartedOnRequest => 'Pulang atas permintaan sendiri',
        };
    }
}
