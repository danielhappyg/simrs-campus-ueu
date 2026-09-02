import type {
    TariffCareSetting,
    TariffMasterState,
} from './tariff-master-types';

export type RadiologyTariffSource = {
    public_id: string;
    code: string;
    display_name: string;
    state: TariffMasterState;
    master_version_public_id: string;
    master_version: number;
    master_content_digest: string;
};

export type RadiologyTariffOption = {
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

export type RadiologyTariffBinding = {
    public_id: string;
    state: TariffMasterState;
    version: number;
    content_digest: string;
    latest_head_version: number;
    latest_head_content_digest: string;
    latest_head_state: TariffMasterState;
    source: RadiologyTariffSource;
    care_setting: TariffCareSetting;
    tariff: RadiologyTariffOption;
    effective_from: string;
    effective_until: string | null;
    actions: {
        history_url: string;
        revise_url: string | null;
        retire_url: string | null;
    };
};

export type RadiologyTariffGap = {
    source: RadiologyTariffSource;
    care_setting: TariffCareSetting;
    reason_code:
        | 'TARIF_BELUM_DIPETAKAN'
        | 'TARIF_TIDAK_EFEKTIF'
        | 'KONTEKS_TIDAK_COCOK'
        | 'BUKTI_TIDAK_KONSISTEN';
    reason_label: string;
    detail: string;
};

export type RadiologyTariffBindingHistoryVersion = {
    public_id: string;
    version: number;
    state: TariffMasterState;
    effective_from: string;
    effective_until: string | null;
    tariff: RadiologyTariffOption;
    reason: string;
    authored_at: string;
    authored_by: string;
    previous_content_digest: string | null;
    content_digest: string;
};

export type RadiologyTariffMappingProps = {
    as_of_date: string;
    source_master_version: string;
    source_master_content_digest: string;
    sources: RadiologyTariffSource[];
    tariff_options: RadiologyTariffOption[];
    mappings: RadiologyTariffBinding[];
    gaps: RadiologyTariffGap[];
    history: {
        binding_public_id: string;
        source: RadiologyTariffSource;
        care_setting: TariffCareSetting;
        versions: RadiologyTariffBindingHistoryVersion[];
    } | null;
    permissions: { can_manage: boolean };
    commands: { create_url: string | null };
    read_error?: string | null;
};
