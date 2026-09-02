<?php

namespace App\Support\Authorization;

final class RoleCapabilityMatrix
{
    public const ROLE_REGISTRAR = 'registrar';

    public const ROLE_NURSE = 'nurse';

    public const ROLE_PHYSICIAN = 'physician';

    public const ROLE_RMIK = 'rmik';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_RADIOLOGY_TECHNOLOGIST = 'radiology_technologist';

    public const ROLE_RADIOLOGIST = 'radiologist';

    public const ROLE_LABORATORY_TECHNOLOGIST = 'laboratory_technologist';

    public const ROLE_LABORATORY_VERIFIER = 'laboratory_verifier';

    public const ROLE_PHARMACIST = 'pharmacist';

    public const ROLE_PHARMACY_TECHNICIAN = 'pharmacy_technician';

    public const ROLE_PHARMACY_INVENTORY_CONTROLLER = 'pharmacy_inventory_controller';

    public const ROLE_CASHIER = 'cashier';

    public const ROLE_CASHIER_SUPERVISOR = 'cashier_supervisor';

    public const ROLE_FINANCE_STEWARD = 'finance_steward';

    public const ROLE_PROCUREMENT_OFFICER = 'procurement_officer';

    public const ROLE_PROCUREMENT_APPROVER = 'procurement_approver';

    public const ROLE_WAREHOUSE_RECEIVER = 'warehouse_receiver';

    public const ROLE_WAREHOUSE_INVENTORY_CONTROLLER = 'warehouse_inventory_controller';

    public const ROLE_WAREHOUSE_INVENTORY_SUPERVISOR = 'warehouse_inventory_supervisor';

    /**
     * @return array<string, array{name: string, description: string}>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_REGISTRAR => [
                'name' => 'Registrar',
                'description' => 'Front-office registration and encounter open/cancel',
            ],
            self::ROLE_NURSE => [
                'name' => 'Nurse',
                'description' => 'Nursing documentation for teaching encounters',
            ],
            self::ROLE_PHYSICIAN => [
                'name' => 'Physician',
                'description' => 'Medical documentation and clinical orders',
            ],
            self::ROLE_RMIK => [
                'name' => 'RMIK',
                'description' => 'Medical records coding, review, and completeness',
            ],
            self::ROLE_ADMIN => [
                'name' => 'Administrator',
                'description' => 'Teaching bootstrap admin for users, roles, audit, and reset',
            ],
            self::ROLE_RADIOLOGY_TECHNOLOGIST => [
                'name' => 'Radiology Technologist',
                'description' => 'Synthetic radiology worklist performance recording',
            ],
            self::ROLE_RADIOLOGIST => [
                'name' => 'Radiologist',
                'description' => 'Synthetic radiology report drafting and verification',
            ],
            self::ROLE_LABORATORY_TECHNOLOGIST => [
                'name' => 'Laboratory Technologist',
                'description' => 'Laboratory specimen receipt, assessment, and result drafting',
            ],
            self::ROLE_LABORATORY_VERIFIER => [
                'name' => 'Laboratory Verifier',
                'description' => 'Laboratory result verification and signed amendments',
            ],
            self::ROLE_PHARMACIST => [
                'name' => 'Pharmacist',
                'description' => 'Prescription verification, medication handover, and returns',
            ],
            self::ROLE_PHARMACY_TECHNICIAN => [
                'name' => 'Pharmacy Technician',
                'description' => 'FEFO medication preparation under pharmacist control',
            ],
            self::ROLE_PHARMACY_INVENTORY_CONTROLLER => [
                'name' => 'Pharmacy Inventory Controller',
                'description' => 'Medicine, depot, lot, and immutable stock-ledger management',
            ],
            self::ROLE_CASHIER => [
                'name' => 'Kasir',
                'description' => 'Tagihan, pelunasan, batch penerimaan kas, dan penyerahan setoran internal',
            ],
            self::ROLE_CASHIER_SUPERVISOR => [
                'name' => 'Supervisor Kasir',
                'description' => 'Tinjauan koreksi, pengembalian kas, dan verifikasi tutup batch kasir',
            ],
            self::ROLE_FINANCE_STEWARD => [
                'name' => 'Pengelola Tarif',
                'description' => 'Pengelolaan tarif dan komponen biaya yang berlaku efektif',
            ],
            self::ROLE_PROCUREMENT_OFFICER => [
                'name' => 'Petugas Pengadaan',
                'description' => 'Pengelolaan pemasok serta pembuatan dan pengajuan pesanan pembelian',
            ],
            self::ROLE_PROCUREMENT_APPROVER => [
                'name' => 'Penyetuju Pengadaan',
                'description' => 'Peninjauan pesanan pembelian dan persetujuan retur pemasok yang independen',
            ],
            self::ROLE_WAREHOUSE_RECEIVER => [
                'name' => 'Penerima Gudang Farmasi',
                'description' => 'Pencatatan penerimaan fisik stok sintetis dari pesanan yang telah disetujui',
            ],
            self::ROLE_WAREHOUSE_INVENTORY_CONTROLLER => [
                'name' => 'Pengelola Persediaan Gudang',
                'description' => 'Pengelolaan kustodi gudang, distribusi, retur, kartu stok, dan usulan koreksi',
            ],
            self::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR => [
                'name' => 'Supervisor Persediaan Gudang',
                'description' => 'Peninjauan independen atas koreksi transaksi stok yang bersifat append-only',
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        return [
            self::ROLE_REGISTRAR => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::PATIENT_REGISTER,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::ENCOUNTER_CANCEL,
                Capability::INPATIENT_OCCUPANCY_VIEW,
                Capability::INPATIENT_BED_TRANSFER,
                Capability::EMERGENCY_INPATIENT_HANDOFF,
                Capability::EMERGENCY_DISPOSITION_COMPENSATE,
            ],
            self::ROLE_NURSE => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::CLINICAL_NURSING_WRITE,
                Capability::CLINICAL_AMEND,
                Capability::INPATIENT_OCCUPANCY_VIEW,
                Capability::LABORATORY_SPECIMEN_COLLECT,
                Capability::EMERGENCY_TRIAGE_WRITE,
                Capability::PHARMACY_PRESCRIPTION_VIEW,
            ],
            self::ROLE_PHYSICIAN => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::CLINICAL_MEDICAL_WRITE,
                Capability::CLINICAL_ORDER_CREATE,
                Capability::CLINICAL_AMEND,
                Capability::CLINICAL_OUTPATIENT_AMENDMENT_REQUEST,
                Capability::CLINICAL_OUTPATIENT_AMENDMENT_APPROVE,
                Capability::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_WRITE,
                Capability::CLINICAL_OUTPATIENT_AMENDMENT_ADDENDUM_FINALIZE,
                Capability::INPATIENT_OCCUPANCY_VIEW,
                Capability::INPATIENT_DISCHARGE_SUMMARY_WRITE,
                Capability::INPATIENT_DISCHARGE_CODING_SOURCE_WRITE,
                Capability::INPATIENT_DISCHARGE_EXECUTE,
                Capability::INPATIENT_SUMMARY_ADDENDUM_REQUEST,
                Capability::INPATIENT_SUMMARY_ADDENDUM_APPROVE,
                Capability::INPATIENT_SUMMARY_ADDENDUM_WRITE,
                Capability::INPATIENT_SUMMARY_ADDENDUM_FINALIZE,
                Capability::RADIOLOGY_ORDER_CREATE,
                Capability::RADIOLOGY_ORDER_CANCEL,
                Capability::RADIOLOGY_REPORT_ACKNOWLEDGE,
                Capability::LABORATORY_ORDER_CREATE,
                Capability::LABORATORY_ORDER_CANCEL,
                Capability::LABORATORY_RESULT_ACKNOWLEDGE,
                Capability::EMERGENCY_DISPOSITION_WRITE,
                Capability::EMERGENCY_RESULT_FOLLOW_UP,
                Capability::PHARMACY_PRESCRIPTION_VIEW,
                Capability::PHARMACY_PRESCRIPTION_WRITE,
            ],
            self::ROLE_RMIK => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::RMIK_REVIEW,
                Capability::RMIK_CODING_WRITE,
                Capability::RMIK_COMPLETENESS_SIGNOFF,
                Capability::INPATIENT_OCCUPANCY_VIEW,
                Capability::INPATIENT_SUMMARY_ADDENDUM_RMIK_REVIEW,
                Capability::INPATIENT_SUMMARY_ADDENDUM_RMIK_SIGNOFF,
                Capability::PHARMACY_PRESCRIPTION_VIEW,
            ],
            self::ROLE_ADMIN => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::PATIENT_REGISTER,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::ENCOUNTER_CANCEL,
                Capability::AUDIT_VIEW,
                Capability::USER_MANAGE,
                Capability::ROLE_MANAGE,
                Capability::SYNTHETIC_RESET,
                Capability::MASTER_MANAGE,
                Capability::INPATIENT_WARD_BED_MANAGE,
                Capability::INPATIENT_OCCUPANCY_VIEW,
                Capability::RADIOLOGY_MASTER_MANAGE,
                Capability::LABORATORY_MASTER_MANAGE,
                Capability::EMERGENCY_TRIAGE_MASTER_MANAGE,
            ],
            self::ROLE_RADIOLOGY_TECHNOLOGIST => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::RADIOLOGY_WORKLIST_PERFORM,
            ],
            self::ROLE_RADIOLOGIST => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::RADIOLOGY_REPORT_WRITE,
                Capability::RADIOLOGY_REPORT_VERIFY,
            ],
            self::ROLE_LABORATORY_TECHNOLOGIST => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::LABORATORY_SPECIMEN_PROCESS,
                Capability::LABORATORY_RESULT_WRITE,
            ],
            self::ROLE_LABORATORY_VERIFIER => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::LABORATORY_RESULT_VERIFY,
            ],
            self::ROLE_PHARMACIST => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::PHARMACY_PRESCRIPTION_VIEW,
                Capability::PHARMACY_PRESCRIPTION_VERIFY,
                Capability::PHARMACY_DISPENSE_HANDOVER,
                Capability::PHARMACY_RETURN_RECORD,
            ],
            self::ROLE_PHARMACY_TECHNICIAN => [
                Capability::PATIENT_SEARCH,
                Capability::PATIENT_VIEW,
                Capability::ENCOUNTER_LIST,
                Capability::ENCOUNTER_OPEN,
                Capability::PHARMACY_PRESCRIPTION_VIEW,
                Capability::PHARMACY_DISPENSE_PREPARE,
            ],
            self::ROLE_PHARMACY_INVENTORY_CONTROLLER => [
                Capability::PHARMACY_INVENTORY_MANAGE,
                Capability::WAREHOUSE_TRANSFER_VIEW,
                Capability::WAREHOUSE_TRANSFER_ACCEPT,
                Capability::WAREHOUSE_RETURN_UNIT,
            ],
            self::ROLE_CASHIER => [
                Capability::FINANCE_BILL_VIEW,
                Capability::FINANCE_BILL_ISSUE,
                Capability::FINANCE_SETTLEMENT_VIEW,
                Capability::FINANCE_SETTLEMENT_CREATE,
                Capability::FINANCE_RECEIPT_VIEW,
                Capability::FINANCE_SETTLEMENT_CORRECTION_VIEW,
                Capability::FINANCE_SETTLEMENT_CORRECTION_REQUEST,
                Capability::FINANCE_SETTLEMENT_CORRECTION_RECEIPT_VIEW,
                Capability::FINANCE_TARIFF_VIEW,
                Capability::FINANCE_RADIOLOGY_TARIFF_VIEW,
                Capability::FINANCE_LABORATORY_TARIFF_VIEW,
                Capability::FINANCE_ACCOMMODATION_TARIFF_VIEW,
                Capability::FINANCE_CASHIER_COLLECTION_VIEW,
                Capability::FINANCE_CASHIER_COLLECTION_OPEN,
                Capability::FINANCE_CASHIER_COLLECTION_CLOSE_REQUEST,
                Capability::FINANCE_CASHIER_COLLECTION_RECOUNT,
                Capability::FINANCE_CASH_DEPOSIT_HANDOFF_VIEW,
                Capability::FINANCE_CASH_DEPOSIT_HANDOFF_CREATE,
            ],
            self::ROLE_CASHIER_SUPERVISOR => [
                Capability::FINANCE_SETTLEMENT_CORRECTION_VIEW,
                Capability::FINANCE_SETTLEMENT_CORRECTION_REVIEW,
                Capability::FINANCE_SETTLEMENT_REFUND_COMPLETE,
                Capability::FINANCE_SETTLEMENT_CORRECTION_RECEIPT_VIEW,
                Capability::FINANCE_CASHIER_COLLECTION_VIEW,
                Capability::FINANCE_CASHIER_COLLECTION_VERIFY,
                Capability::FINANCE_CASH_DEPOSIT_HANDOFF_VIEW,
            ],
            self::ROLE_FINANCE_STEWARD => [
                Capability::FINANCE_TARIFF_VIEW,
                Capability::FINANCE_TARIFF_MANAGE,
                Capability::FINANCE_RADIOLOGY_TARIFF_VIEW,
                Capability::FINANCE_RADIOLOGY_TARIFF_MANAGE,
                Capability::FINANCE_LABORATORY_TARIFF_VIEW,
                Capability::FINANCE_LABORATORY_TARIFF_MANAGE,
                Capability::FINANCE_ACCOMMODATION_TARIFF_VIEW,
                Capability::FINANCE_ACCOMMODATION_TARIFF_MANAGE,
            ],
            self::ROLE_PROCUREMENT_OFFICER => [
                Capability::WAREHOUSE_SUPPLIER_VIEW,
                Capability::WAREHOUSE_SUPPLIER_MANAGE,
                Capability::WAREHOUSE_PURCHASE_ORDER_VIEW,
                Capability::WAREHOUSE_PURCHASE_ORDER_CREATE,
                Capability::WAREHOUSE_PURCHASE_ORDER_SUBMIT,
            ],
            self::ROLE_PROCUREMENT_APPROVER => [
                Capability::WAREHOUSE_PURCHASE_ORDER_VIEW,
                Capability::WAREHOUSE_PURCHASE_ORDER_REVIEW,
                Capability::WAREHOUSE_RETURN_SUPPLIER,
            ],
            self::ROLE_WAREHOUSE_RECEIVER => [
                Capability::WAREHOUSE_RECEIPT_VIEW,
                Capability::WAREHOUSE_RECEIPT_RECORD,
            ],
            self::ROLE_WAREHOUSE_INVENTORY_CONTROLLER => [
                Capability::WAREHOUSE_TRANSFER_VIEW,
                Capability::WAREHOUSE_TRANSFER_DISPATCH,
                Capability::WAREHOUSE_RETURN_SUPPLIER,
                Capability::WAREHOUSE_RETURN_UNIT,
                Capability::WAREHOUSE_STOCK_CARD_VIEW,
                Capability::WAREHOUSE_CORRECTION_REQUEST,
            ],
            self::ROLE_WAREHOUSE_INVENTORY_SUPERVISOR => [
                Capability::WAREHOUSE_STOCK_CARD_VIEW,
                Capability::WAREHOUSE_CORRECTION_REVIEW,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function capabilitiesFor(string $roleSlug): array
    {
        return self::matrix()[$roleSlug] ?? [];
    }
}
