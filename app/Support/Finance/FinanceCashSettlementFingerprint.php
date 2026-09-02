<?php

namespace App\Support\Finance;

use App\Models\FinanceCashSettlement;

final class FinanceCashSettlementFingerprint
{
    public function settlement(FinanceCashSettlement $settlement): string
    {
        $evidence = [
            $settlement->receipt_number, $settlement->bill_id, $settlement->bill_version_id,
            $settlement->encounter_id, $settlement->patient_id, $settlement->cashier_user_id,
            $settlement->cashier_name_snapshot,
            $settlement->bill_public_id_snapshot, $settlement->bill_number_snapshot,
            $settlement->bill_version_public_id_snapshot, $settlement->bill_version_snapshot,
            $settlement->encounter_public_id_snapshot, $settlement->patient_public_id_snapshot,
            $settlement->patient_name_snapshot, $settlement->medical_record_number_snapshot,
            $settlement->care_setting, $settlement->coverage_profile_snapshot,
            $settlement->coverage_label_snapshot, $settlement->coverage_exclusion_snapshot,
            $settlement->payment_method, $settlement->state,
            $settlement->amount, $settlement->source_set_digest,
            $settlement->bill_version_content_digest, $settlement->settled_at,
        ];
        if ($settlement->prior_net_collected_amount_snapshot !== null) {
            $evidence[] = 'NET_CASH_CORRECTION_V1';
            $evidence[] = $settlement->prior_net_collected_amount_snapshot;
        }
        if ($settlement->collection_binding_required) {
            $evidence[] = 'CASHIER_COLLECTION_BINDING_REQUIRED_V1';
        }

        return FinanceCanonicalJson::digest($evidence);
    }

    public function result(FinanceCashSettlement $settlement): string
    {
        return FinanceCanonicalJson::digest([
            FinanceCashSettlementService::OPERATION_SETTLE,
            $settlement->public_id,
            $settlement->content_digest,
            $settlement->bill_version_public_id_snapshot,
            $settlement->bill_version_content_digest,
            $settlement->amount,
            $settlement->source_set_digest,
        ]);
    }
}
