export type CodedStatus = {
    code: string;
    label: string;
};

export type SyntheticPatientSummary = {
    publicId: string;
    fullName: string;
    birthDate: string;
    administrativeSex?: string;
    mrn: string | null;
    recordStatus?: string;
    synthetic: true;
};

export type RegistrationAppointment = {
    publicId: string;
    appointmentCode: string;
    scheduledAt: string;
    visitReason: string;
    status: CodedStatus;
    canCheckIn: boolean;
    checkInUrl: string;
    patient: SyntheticPatientSummary;
    encounter: {
        publicId: string;
        number: string;
        status: CodedStatus;
        location: string;
        url: string;
    } | null;
};

export type PatientContext = SyntheticPatientSummary & {
    administrativeSex: string;
    allergyStatus: string;
};

export type EncounterContext = {
    publicId: string;
    number: string;
    status: CodedStatus;
    serviceType: string;
    location: string;
    periodStart: string | null;
    environmentMode: string;
};
