export type WardBedOption = {
    value: string;
    label: string;
};

export type WardMasterState = 'ACTIVE' | 'RETIRED';

export type BedOccupancyState = 'AVAILABLE' | 'OCCUPIED' | 'RETIRED';

export type WardBedActionUrls = {
    update_url: string | null;
    retire_url: string | null;
};

export type InpatientBedOccupant = {
    patient_name?: string | null;
    medical_record_number?: string | null;
    encounter_public_id?: string | null;
    open_url?: string | null;
};

export type InpatientBedProjection = {
    public_id: string;
    code: string;
    display_name: string;
    room_label: string;
    service_class: string;
    state: WardMasterState;
    version: number;
    occupancy: {
        state: BedOccupancyState;
        occupant?: InpatientBedOccupant | null;
    };
    actions: WardBedActionUrls;
};

export type InpatientWardProjection = {
    public_id: string;
    code: string;
    display_name: string;
    state: WardMasterState;
    version: number;
    summary: {
        active_beds: number;
        occupied_beds: number;
        available_beds: number;
    };
    actions: WardBedActionUrls & {
        create_bed_url: string | null;
    };
    beds: InpatientBedProjection[];
};

export type WardBedFilters = {
    q: string;
    ward_code: string;
    service_class: string;
    occupancy_state: string;
    master_state: string;
};

export type WardBedCensusProps = {
    generated_at: string;
    filters: WardBedFilters;
    totals: {
        active_wards: number;
        active_beds: number;
        occupied_beds: number;
        available_beds: number;
    };
    wards: InpatientWardProjection[];
    permissions: {
        can_view_census: boolean;
        can_manage_master: boolean;
    };
    commands: {
        create_ward_url: string | null;
    };
    filter_options: {
        wards: WardBedOption[];
        service_classes: WardBedOption[];
    };
    reason_options: WardBedOption[];
    read_error?: string | null;
};

export type WardBedMasterAction =
    | {
          kind: 'CREATE_WARD';
          url: string;
      }
    | {
          kind: 'UPDATE_WARD';
          url: string;
          ward: InpatientWardProjection;
      }
    | {
          kind: 'RETIRE_WARD';
          url: string;
          ward: InpatientWardProjection;
      }
    | {
          kind: 'CREATE_BED';
          url: string;
          ward: InpatientWardProjection;
      }
    | {
          kind: 'UPDATE_BED';
          url: string;
          ward: InpatientWardProjection;
          bed: InpatientBedProjection;
      }
    | {
          kind: 'RETIRE_BED';
          url: string;
          ward: InpatientWardProjection;
          bed: InpatientBedProjection;
      };
