<?php

namespace App\Modules\Claims\Enums;

enum EClaimAction: string
{
    case CreateClaim = 'CREATE_CLAIM';
    case StageClaimData = 'STAGE_CLAIM_DATA';
    case GroupClaim = 'GROUP_CLAIM';
    case FinalizeClaim = 'FINALIZE_CLAIM';
    case SimulateSubmission = 'SIMULATE_SUBMISSION';

    public function label(): string
    {
        return match ($this) {
            self::CreateClaim => 'Buat klaim',
            self::StageClaimData => 'Kirim data klaim',
            self::GroupClaim => 'Jalankan grouper simulasi',
            self::FinalizeClaim => 'Finalisasi klaim simulasi',
            self::SimulateSubmission => 'Simulasikan pengiriman',
        };
    }

    public function method(): string
    {
        return match ($this) {
            self::CreateClaim => 'new_claim',
            self::StageClaimData => 'set_claim_data',
            self::GroupClaim => 'grouper',
            self::FinalizeClaim => 'claim_final',
            self::SimulateSubmission => 'SIMULATE_SEND_CLAIM',
        };
    }

    public function requiredStatus(): ?EClaimCaseStatus
    {
        return match ($this) {
            self::CreateClaim => null,
            self::StageClaimData => EClaimCaseStatus::ClaimCreated,
            self::GroupClaim => EClaimCaseStatus::DataStaged,
            self::FinalizeClaim => EClaimCaseStatus::Grouped,
            self::SimulateSubmission => EClaimCaseStatus::Finalized,
        };
    }

    public function resultingStatus(): EClaimCaseStatus
    {
        return match ($this) {
            self::CreateClaim => EClaimCaseStatus::ClaimCreated,
            self::StageClaimData => EClaimCaseStatus::DataStaged,
            self::GroupClaim => EClaimCaseStatus::Grouped,
            self::FinalizeClaim => EClaimCaseStatus::Finalized,
            self::SimulateSubmission => EClaimCaseStatus::SubmissionSimulated,
        };
    }
}
