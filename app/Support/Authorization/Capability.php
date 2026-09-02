<?php

namespace App\Support\Authorization;

final class Capability
{
    public const PATIENT_SEARCH = 'patient.search';

    public const PATIENT_VIEW = 'patient.view';

    public const PATIENT_REGISTER = 'patient.register';

    public const ENCOUNTER_LIST = 'encounter.list';

    public const ENCOUNTER_OPEN = 'encounter.open';

    public const ENCOUNTER_CANCEL = 'encounter.cancel';

    public const CLINICAL_NURSING_WRITE = 'clinical.nursing.write';

    public const CLINICAL_MEDICAL_WRITE = 'clinical.medical.write';

    public const CLINICAL_ORDER_CREATE = 'clinical.order.create';

    public const CLINICAL_LAB_RESULT_WRITE = 'clinical.lab.result.write';

    public const CLINICAL_AMEND = 'clinical.amend';

    public const CLINICAL_OUTPATIENT_AMENDMENT_REQUEST = 'clinical.outpatient.amendment.request';

    public const CLINICAL_OUTPATIENT_AMENDMENT_APPROVE = 'clinical.outpatient.amendment.approve';

    public const CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_WRITE = 'clinical.outpatient.amendment.addendum.write';

    public const CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_FINALIZE = 'clinical.outpatient.amendment.addendum.finalize';

    public const RMIK_REVIEW = 'rmik.review';

    public const RMIK_CODING_WRITE = 'rmik.coding.write';

    public const RMIK_COMPLETENESS_SIGNOFF = 'rmik.completeness.signoff';

    public const AUDIT_VIEW = 'audit.view';

    public const USER_MANAGE = 'user.manage';

    public const ROLE_MANAGE = 'role.manage';

    public const SYNTHETIC_RESET = 'synthetic.reset';

    public const MASTER_MANAGE = 'master.manage';

    public const INPATIENT_WARD_BED_MANAGE = 'master.inpatient.ward-bed.manage';

    public const INPATIENT_OCCUPANCY_VIEW = 'inpatient.occupancy.view';

    public const INPATIENT_BED_TRANSFER = 'inpatient.bed.transfer';

    public const INPATIENT_DISCHARGE_SUMMARY_WRITE = 'clinical.inpatient.discharge-summary.write';

    public const INPATIENT_DISCHARGE_CODING_SOURCE_WRITE = 'clinical.inpatient.discharge-coding-source.write';

    public const INPATIENT_DISCHARGE_EXECUTE = 'clinical.inpatient.discharge.execute';

    public const INPATIENT_SUMMARY_ADDENDUM_REQUEST = 'clinical.inpatient.summary-addendum.request';

    public const INPATIENT_SUMMARY_ADDENDUM_APPROVE = 'clinical.inpatient.summary-addendum.approve';

    public const INPATIENT_SUMMARY_ADDENDUM_WRITE = 'clinical.inpatient.summary-addendum.write';

    public const INPATIENT_SUMMARY_ADDENDUM_FINALIZE = 'clinical.inpatient.summary-addendum.finalize';

    public const INPATIENT_SUMMARY_ADDENDUM_RMIK_REVIEW = 'rmik.inpatient.summary-addendum.review';

    public const INPATIENT_SUMMARY_ADDENDUM_RMIK_SIGNOFF = 'rmik.inpatient.summary-addendum.signoff';

    public const RADIOLOGY_MASTER_MANAGE = 'master.radiology.examination.manage';

    public const RADIOLOGY_ORDER_CREATE = 'clinical.radiology.order.create';

    public const RADIOLOGY_ORDER_CANCEL = 'clinical.radiology.order.cancel';

    public const RADIOLOGY_WORKLIST_PERFORM = 'clinical.radiology.worklist.perform';

    public const RADIOLOGY_REPORT_WRITE = 'clinical.radiology.report.write';

    public const RADIOLOGY_REPORT_VERIFY = 'clinical.radiology.report.verify';

    public const RADIOLOGY_REPORT_ACKNOWLEDGE = 'clinical.radiology.report.acknowledge';

    public const LABORATORY_MASTER_MANAGE = 'master.laboratory.examination.manage';

    public const LABORATORY_ORDER_CREATE = 'clinical.laboratory.order.create';

    public const LABORATORY_ORDER_CANCEL = 'clinical.laboratory.order.cancel';

    public const LABORATORY_SPECIMEN_COLLECT = 'clinical.laboratory.specimen.collect';

    public const LABORATORY_SPECIMEN_PROCESS = 'clinical.laboratory.specimen.process';

    public const LABORATORY_RESULT_WRITE = 'clinical.laboratory.result.write';

    public const LABORATORY_RESULT_VERIFY = 'clinical.laboratory.result.verify';

    public const LABORATORY_RESULT_ACKNOWLEDGE = 'clinical.laboratory.result.acknowledge';

    public const EMERGENCY_TRIAGE_MASTER_MANAGE = 'master.emergency.triage.manage';

    public const EMERGENCY_TRIAGE_WRITE = 'clinical.emergency.triage.write';

    public const EMERGENCY_DISPOSITION_WRITE = 'clinical.emergency.disposition.write';

    public const EMERGENCY_RESULT_FOLLOW_UP = 'clinical.emergency.result-follow-up.manage';

    public const EMERGENCY_INPATIENT_HANDOFF = 'clinical.emergency.inpatient-handoff.execute';

    public const EMERGENCY_DISPOSITION_COMPENSATE = 'clinical.emergency.disposition.compensate';

    public const PHARMACY_PRESCRIPTION_VIEW = 'clinical.pharmacy.prescription.view';

    public const PHARMACY_PRESCRIPTION_WRITE = 'clinical.pharmacy.prescription.write';

    public const PHARMACY_PRESCRIPTION_VERIFY = 'clinical.pharmacy.prescription.verify';

    public const PHARMACY_DISPENSE_PREPARE = 'clinical.pharmacy.dispense.prepare';

    public const PHARMACY_DISPENSE_HANDOVER = 'clinical.pharmacy.dispense.handover';

    public const PHARMACY_RETURN_RECORD = 'clinical.pharmacy.return.record';

    public const PHARMACY_INVENTORY_MANAGE = 'master.pharmacy.inventory.manage';

    public const WAREHOUSE_SUPPLIER_VIEW = 'warehouse.supplier.view';

    public const WAREHOUSE_SUPPLIER_MANAGE = 'warehouse.supplier.manage';

    public const WAREHOUSE_PURCHASE_ORDER_VIEW = 'warehouse.purchase-order.view';

    public const WAREHOUSE_PURCHASE_ORDER_CREATE = 'warehouse.purchase-order.create';

    public const WAREHOUSE_PURCHASE_ORDER_SUBMIT = 'warehouse.purchase-order.submit';

    public const WAREHOUSE_PURCHASE_ORDER_REVIEW = 'warehouse.purchase-order.review';

    public const WAREHOUSE_RECEIPT_VIEW = 'warehouse.receipt.view';

    public const WAREHOUSE_RECEIPT_RECORD = 'warehouse.receipt.record';

    public const WAREHOUSE_TRANSFER_VIEW = 'warehouse.transfer.view';

    public const WAREHOUSE_TRANSFER_DISPATCH = 'warehouse.transfer.dispatch';

    public const WAREHOUSE_TRANSFER_ACCEPT = 'warehouse.transfer.accept';

    public const WAREHOUSE_RETURN_SUPPLIER = 'warehouse.return.supplier';

    public const WAREHOUSE_RETURN_UNIT = 'warehouse.return.unit';

    public const WAREHOUSE_STOCK_CARD_VIEW = 'warehouse.stock-card.view';

    public const WAREHOUSE_CORRECTION_REQUEST = 'warehouse.correction.request';

    public const WAREHOUSE_CORRECTION_REVIEW = 'warehouse.correction.review';

    public const FINANCE_BILL_VIEW = 'finance.bill.view';

    public const FINANCE_BILL_ISSUE = 'finance.bill.issue';

    public const FINANCE_SETTLEMENT_VIEW = 'finance.settlement.view';

    public const FINANCE_SETTLEMENT_CREATE = 'finance.settlement.create';

    public const FINANCE_RECEIPT_VIEW = 'finance.receipt.view';

    public const FINANCE_SETTLEMENT_CORRECTION_VIEW = 'finance.settlement-correction.view';

    public const FINANCE_SETTLEMENT_CORRECTION_REQUEST = 'finance.settlement-correction.request';

    public const FINANCE_SETTLEMENT_CORRECTION_REVIEW = 'finance.settlement-correction.review';

    public const FINANCE_SETTLEMENT_REFUND_COMPLETE = 'finance.settlement-refund.complete';

    public const FINANCE_SETTLEMENT_CORRECTION_RECEIPT_VIEW = 'finance.settlement-correction.receipt.view';

    public const FINANCE_TARIFF_VIEW = 'finance.tariff.view';

    public const FINANCE_TARIFF_MANAGE = 'finance.tariff.manage';

    public const FINANCE_RADIOLOGY_TARIFF_VIEW = 'finance.radiology-tariff.view';

    public const FINANCE_RADIOLOGY_TARIFF_MANAGE = 'finance.radiology-tariff.manage';

    public const FINANCE_LABORATORY_TARIFF_VIEW = 'finance.laboratory-tariff.view';

    public const FINANCE_LABORATORY_TARIFF_MANAGE = 'finance.laboratory-tariff.manage';

    public const FINANCE_ACCOMMODATION_TARIFF_VIEW = 'finance.accommodation-tariff.view';

    public const FINANCE_ACCOMMODATION_TARIFF_MANAGE = 'finance.accommodation-tariff.manage';

    public const FINANCE_CASHIER_COLLECTION_VIEW = 'finance.cashier-collection.view';

    public const FINANCE_CASHIER_COLLECTION_OPEN = 'finance.cashier-collection.open';

    public const FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST = 'finance.cashier-collection.close-request';

    public const FINANCE_CASHIER_COLLECTION_RECOUNT = 'finance.cashier-collection.recount';

    public const FINANCE_CASHIER_COLLECTION_VERIFY = 'finance.cashier-collection.verify';

    public const FINANCE_CASH_DEPOSIT_HANDOFF_VIEW = 'finance.cash-deposit-handoff.view';

    public const FINANCE_CASH_DEPOSIT_HANDOFF_CREATE = 'finance.cash-deposit-handoff.create';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PATIENT_SEARCH,
            self::PATIENT_VIEW,
            self::PATIENT_REGISTER,
            self::ENCOUNTER_LIST,
            self::ENCOUNTER_OPEN,
            self::ENCOUNTER_CANCEL,
            self::CLINICAL_NURSING_WRITE,
            self::CLINICAL_MEDICAL_WRITE,
            self::CLINICAL_ORDER_CREATE,
            self::CLINICAL_LAB_RESULT_WRITE,
            self::CLINICAL_AMEND,
            self::CLINICAL_OUTPATIENT_AMENDMENT_REQUEST,
            self::CLINICAL_OUTPATIENT_AMENDMENT_APPROVE,
            self::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_WRITE,
            self::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_FINALIZE,
            self::RMIK_REVIEW,
            self::RMIK_CODING_WRITE,
            self::RMIK_COMPLETENESS_SIGNOFF,
            self::AUDIT_VIEW,
            self::USER_MANAGE,
            self::ROLE_MANAGE,
            self::SYNTHETIC_RESET,
            self::MASTER_MANAGE,
            self::INPATIENT_WARD_BED_MANAGE,
            self::INPATIENT_OCCUPANCY_VIEW,
            self::INPATIENT_BED_TRANSFER,
            self::INPATIENT_DISCHARGE_SUMMARY_WRITE,
            self::INPATIENT_DISCHARGE_CODING_SOURCE_WRITE,
            self::INPATIENT_DISCHARGE_EXECUTE,
            self::INPATIENT_SUMMARY_ADDENDUM_REQUEST,
            self::INPATIENT_SUMMARY_ADDENDUM_APPROVE,
            self::INPATIENT_SUMMARY_ADDENDUM_WRITE,
            self::INPATIENT_SUMMARY_ADDENDUM_FINALIZE,
            self::INPATIENT_SUMMARY_ADDENDUM_RMIK_REVIEW,
            self::INPATIENT_SUMMARY_ADDENDUM_RMIK_SIGNOFF,
            self::RADIOLOGY_MASTER_MANAGE,
            self::RADIOLOGY_ORDER_CREATE,
            self::RADIOLOGY_ORDER_CANCEL,
            self::RADIOLOGY_WORKLIST_PERFORM,
            self::RADIOLOGY_REPORT_WRITE,
            self::RADIOLOGY_REPORT_VERIFY,
            self::RADIOLOGY_REPORT_ACKNOWLEDGE,
            self::LABORATORY_MASTER_MANAGE,
            self::LABORATORY_ORDER_CREATE,
            self::LABORATORY_ORDER_CANCEL,
            self::LABORATORY_SPECIMEN_COLLECT,
            self::LABORATORY_SPECIMEN_PROCESS,
            self::LABORATORY_RESULT_WRITE,
            self::LABORATORY_RESULT_VERIFY,
            self::LABORATORY_RESULT_ACKNOWLEDGE,
            self::EMERGENCY_TRIAGE_MASTER_MANAGE,
            self::EMERGENCY_TRIAGE_WRITE,
            self::EMERGENCY_DISPOSITION_WRITE,
            self::EMERGENCY_RESULT_FOLLOW_UP,
            self::EMERGENCY_INPATIENT_HANDOFF,
            self::EMERGENCY_DISPOSITION_COMPENSATE,
            self::PHARMACY_PRESCRIPTION_VIEW,
            self::PHARMACY_PRESCRIPTION_WRITE,
            self::PHARMACY_PRESCRIPTION_VERIFY,
            self::PHARMACY_DISPENSE_PREPARE,
            self::PHARMACY_DISPENSE_HANDOVER,
            self::PHARMACY_RETURN_RECORD,
            self::PHARMACY_INVENTORY_MANAGE,
            self::WAREHOUSE_SUPPLIER_VIEW,
            self::WAREHOUSE_SUPPLIER_MANAGE,
            self::WAREHOUSE_PURCHASE_ORDER_VIEW,
            self::WAREHOUSE_PURCHASE_ORDER_CREATE,
            self::WAREHOUSE_PURCHASE_ORDER_SUBMIT,
            self::WAREHOUSE_PURCHASE_ORDER_REVIEW,
            self::WAREHOUSE_RECEIPT_VIEW,
            self::WAREHOUSE_RECEIPT_RECORD,
            self::WAREHOUSE_TRANSFER_VIEW,
            self::WAREHOUSE_TRANSFER_DISPATCH,
            self::WAREHOUSE_TRANSFER_ACCEPT,
            self::WAREHOUSE_RETURN_SUPPLIER,
            self::WAREHOUSE_RETURN_UNIT,
            self::WAREHOUSE_STOCK_CARD_VIEW,
            self::WAREHOUSE_CORRECTION_REQUEST,
            self::WAREHOUSE_CORRECTION_REVIEW,
            self::FINANCE_BILL_VIEW,
            self::FINANCE_BILL_ISSUE,
            self::FINANCE_SETTLEMENT_VIEW,
            self::FINANCE_SETTLEMENT_CREATE,
            self::FINANCE_RECEIPT_VIEW,
            self::FINANCE_SETTLEMENT_CORRECTION_VIEW,
            self::FINANCE_SETTLEMENT_CORRECTION_REQUEST,
            self::FINANCE_SETTLEMENT_CORRECTION_REVIEW,
            self::FINANCE_SETTLEMENT_REFUND_COMPLETE,
            self::FINANCE_SETTLEMENT_CORRECTION_RECEIPT_VIEW,
            self::FINANCE_TARIFF_VIEW,
            self::FINANCE_TARIFF_MANAGE,
            self::FINANCE_RADIOLOGY_TARIFF_VIEW,
            self::FINANCE_RADIOLOGY_TARIFF_MANAGE,
            self::FINANCE_LABORATORY_TARIFF_VIEW,
            self::FINANCE_LABORATORY_TARIFF_MANAGE,
            self::FINANCE_ACCOMMODATION_TARIFF_VIEW,
            self::FINANCE_ACCOMMODATION_TARIFF_MANAGE,
            self::FINANCE_CASHIER_COLLECTION_VIEW,
            self::FINANCE_CASHIER_COLLECTION_OPEN,
            self::FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST,
            self::FINANCE_CASHIER_COLLECTION_RECOUNT,
            self::FINANCE_CASHIER_COLLECTION_VERIFY,
            self::FINANCE_CASH_DEPOSIT_HANDOFF_VIEW,
            self::FINANCE_CASH_DEPOSIT_HANDOFF_CREATE,
        ];
    }
}
