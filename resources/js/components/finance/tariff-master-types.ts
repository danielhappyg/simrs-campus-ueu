export type TariffMasterState = 'ACTIVE' | 'RETIRED';
export type TariffCareSetting = 'OUTPATIENT' | 'EMERGENCY' | 'INPATIENT';
export type TariffServiceDomain =
    'GENERAL_SERVICE' | 'LABORATORY' | 'RADIOLOGY' | 'ACCOMMODATION';
export type TariffMasterKind = 'group' | 'component' | 'catalogue' | 'tariff';

export type TariffMasterAction = {
    revise_url: string | null;
    retire_url: string | null;
    history_url: string;
};

export type TariffMasterHead = {
    public_id: string;
    code: string;
    display_name: string;
    state: TariffMasterState;
    version: number;
    content_digest: string;
    actions: TariffMasterAction;
};

export type CostComponentGroup = TariffMasterHead & {
    component_count: number;
};

export type CostComponent = TariffMasterHead & {
    description: string | null;
    terminology_label: string | null;
    group: { public_id: string; code: string; display_name: string };
    tariff_count: number;
};

export type TariffCatalogue = TariffMasterHead & { tariff_count: number };

export type TariffItem = TariffMasterHead & {
    latest_head_version: number;
    latest_head_content_digest: string;
    latest_head_state: TariffMasterState;
    catalogue: { public_id: string; code: string; display_name: string };
    component: { public_id: string; code: string; display_name: string };
    care_setting: TariffCareSetting;
    service_domain: TariffServiceDomain;
    reference_label: string | null;
    ward_class_label: string | null;
    amount_rupiah: number;
    effective_from: string;
    next_effective_from: string | null;
    is_effective: boolean;
};

export type TariffHistoryVersion = {
    public_id: string;
    version: number;
    state: TariffMasterState;
    effective_from: string | null;
    effective_until: string | null;
    display_name: string;
    amount_rupiah: number | null;
    reason: string;
    authored_at: string;
    authored_by: string;
    content_digest: string;
};

export type TariffMasterProps = {
    as_of_date: string;
    groups: CostComponentGroup[];
    components: CostComponent[];
    catalogues: TariffCatalogue[];
    tariffs: TariffItem[];
    history: {
        kind: TariffMasterKind;
        code: string;
        display_name: string;
        versions: TariffHistoryVersion[];
    } | null;
    permissions: { can_manage: boolean };
    commands: {
        create_group_url: string | null;
        create_component_url: string | null;
        create_catalogue_url: string | null;
        create_tariff_url: string | null;
    };
    read_error?: string | null;
};
