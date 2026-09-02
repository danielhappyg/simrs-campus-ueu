import type {
    TariffCareSetting,
    TariffMasterState,
} from './tariff-master-types';

export type LaboratoryTariffSource = {
    public_id: string;
    code: string;
    display_name: string;
    specimen_type: string;
    component_count: number;
    state: TariffMasterState;
    master_version_public_id: string;
    master_version: number;
    master_content_digest: string;
};

export type LaboratoryTariffOption = {
    public_id: string;
    code: string;
    display_name: string;
    care_setting: TariffCareSetting;
    state: TariffMasterState;
    version_public_id: string;
    version: number;
    content_digest: string;
    amount_rupiah: number;
    effective_from: string;
};

export type LaboratoryTariffBinding = {
    public_id: string;
    state: TariffMasterState;
    version: number;
    content_digest: string;
    latest_head_version: number;
    latest_head_content_digest: string;
    latest_head_state: TariffMasterState;
    source: LaboratoryTariffSource;
    care_setting: TariffCareSetting;
    tariff: LaboratoryTariffOption;
    effective_from: string;
    effective_until: string | null;
    actions: {
        history_url: string;
        revise_url: string | null;
        retire_url: string | null;
    };
};

export type LaboratoryTariffGap = {
    source: LaboratoryTariffSource;
    care_setting: TariffCareSetting;
    reason_code:
        | 'TARIF_BELUM_DIPETAKAN'
        | 'TARIF_TIDAK_EFEKTIF'
        | 'KONTEKS_TIDAK_COCOK'
        | 'BUKTI_TIDAK_KONSISTEN';
    reason_label: string;
    detail: string;
};

export type LaboratoryTariffBindingHistoryVersion = {
    public_id: string;
    version: number;
    state: TariffMasterState;
    effective_from: string;
    effective_until: string | null;
    tariff: LaboratoryTariffOption;
    reason: string;
    authored_at: string;
    authored_by: string;
    previous_content_digest: string | null;
    content_digest: string;
};

export type LaboratoryTariffMappingProps = {
    as_of_date: string;
    source_master_version: string;
    source_master_content_digest: string;
    source_trigger: {
        code: 'ORIGINAL_VERIFIED_RESULT_V1';
        label: string;
    };
    sources: LaboratoryTariffSource[];
    tariff_options: LaboratoryTariffOption[];
    mappings: LaboratoryTariffBinding[];
    gaps: LaboratoryTariffGap[];
    history: {
        binding_public_id: string;
        source: LaboratoryTariffSource;
        care_setting: TariffCareSetting;
        versions: LaboratoryTariffBindingHistoryVersion[];
    } | null;
    permissions: { can_manage: boolean };
    commands: { create_url: string | null };
    read_error?: string | null;
};
