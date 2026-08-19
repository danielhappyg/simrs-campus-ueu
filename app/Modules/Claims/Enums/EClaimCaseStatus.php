<?php

namespace App\Modules\Claims\Enums;

enum EClaimCaseStatus: string
{
    case ClaimCreated = 'CLAIM_CREATED';
    case DataStaged = 'DATA_STAGED';
    case Grouped = 'GROUPED';
    case Finalized = 'FINALIZED';
    case SubmissionSimulated = 'SUBMISSION_SIMULATED';

    public function label(): string
    {
        return match ($this) {
            self::ClaimCreated => 'Klaim dibuat',
            self::DataStaged => 'Data klaim tersusun',
            self::Grouped => 'Hasil grouper simulasi tersedia',
            self::Finalized => 'Final simulasi',
            self::SubmissionSimulated => 'Pengiriman disimulasikan',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::ClaimCreated => $target === self::DataStaged,
            self::DataStaged => $target === self::Grouped,
            self::Grouped => $target === self::Finalized,
            self::Finalized => $target === self::SubmissionSimulated,
            self::SubmissionSimulated => false,
        };
    }
}
