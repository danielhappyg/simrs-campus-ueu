import type { TariffMasterState } from './tariff-master-types';

export type AccommodationTariffSource = {
    public_id: string;
    code: string;
    display_name: string;
    ward_code: string;
    ward_display_name: string;
    room_label: string;
    service_class_label: string;
    state: TariffMasterState;
    bed_version_public_id: string;
    bed_version: number;
    bed_content_digest: string;
};

export type AccommodationTariffOption = {
    public_id: string;
    code: string;
    display_name: string;
    state: TariffMasterState;
    version_public_id: string;
    version: number;
    content_digest: string;
    amount_rupiah: number;
    effective_from: string;
};

export type AccommodationTariffBinding = {
    public_id: string;
    state: TariffMasterState;
    version: number;
    content_digest: string;
    latest_head_version: number;
    latest_head_content_digest: string;
    latest_head_state: TariffMasterState;
    source: AccommodationTariffSource;
    tariff: AccommodationTariffOption;
    effective_from: string;
    effective_until: string | null;
    actions: {
        history_url: string;
        revise_url: string | null;
        retire_url: string | null;
    };
};

export type AccommodationTariffGap = {
    source: AccommodationTariffSource;
    reason_code:
        | 'TARIF_BELUM_DIPETAKAN'
        | 'TARIF_TIDAK_EFEKTIF'
        | 'KONTEKS_TIDAK_COCOK'
        | 'BUKTI_TIDAK_KONSISTEN';
    reason_label: string;
    detail: string;
};

export type AccommodationTariffBindingHistoryVersion = {
    public_id: string;
    version: number;
    state: TariffMasterState;
    effective_from: string;
    effective_until: string | null;
    tariff: AccommodationTariffOption;
    reason: string;
    authored_at: string;
    authored_by: string;
    previous_content_digest: string | null;
    content_digest: string;
};

export type AccommodationTariffMappingProps = {
    as_of_date: string;
    source_master_version: string;
    source_master_content_digest: string;
    source_trigger: {
        code: 'CLOSED_OCCUPANCY_DAY_V1';
        label: string;
    };
    sources: AccommodationTariffSource[];
    tariff_options: AccommodationTariffOption[];
    mappings: AccommodationTariffBinding[];
    gaps: AccommodationTariffGap[];
    history: {
        binding_public_id: string;
        source: AccommodationTariffSource;
        versions: AccommodationTariffBindingHistoryVersion[];
    } | null;
    permissions: { can_manage: boolean };
    commands: { create_url: string | null };
    read_error?: string | null;
};
