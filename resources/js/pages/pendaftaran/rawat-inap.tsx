import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type Option = { value: string; label: string };

type BedCatalogue = {
    public_id: string;
    code: string;
    display_name: string;
    service_class: string;
};

type WardCatalogue = {
    public_id: string;
    code: string;
    display_name: string;
    beds: BedCatalogue[];
};

type PatientRow = {
    public_id: string;
    medical_record_number: string;
    nik: string | null;
    full_name: string;
    date_of_birth: string | null;
    sex: string;
    phone: string | null;
};

type EncounterRow = {
    public_id: string;
    status: string;
    ward_name: string | null;
    ward_class: string | null;
    bed_code: string | null;
    continue_from: string | null;
    payer_type: string;
    queue_number: number | null;
    registered_at: string | null;
    chief_complaint: string | null;
    cancellation?: {
        reason_code: string;
        note: string | null;
        cancelled_at: string | null;
        cancelled_by: string | null;
    } | null;
    patient: {
        public_id: string | null;
        medical_record_number: string | null;
        full_name: string | null;
    };
};

type PendingEmergencyAdmission = {
    source_encounter_public_id: string | null;
    disposition_public_id: string;
    disposition_version: number;
    signed_at: string | null;
    payer_type: string | null;
    admission_reason: string | null;
    patient: {
        medical_record_number: string | null;
        full_name: string | null;
    };
    handoff_url: string | null;
};

type PendingOutpatientAdmission = {
    source_encounter_public_id: string | null;
    disposition_public_id: string;
    disposition_version: number;
    signed_at: string | null;
    payer_type: string | null;
    admission_reason: string | null;
    patient: {
        medical_record_number: string | null;
        full_name: string | null;
    };
    handoff_url: string | null;
};

type Filters = {
    q: string;
    ward: string;
    payer: string;
    continue_from: string;
    date_from: string;
    date_to: string;
};

type Props = {
    q: string;
    searchResults: PatientRow[];
    todaysEncounters: EncounterRow[];
    pendingEmergencyAdmissions?: PendingEmergencyAdmission[];
    pendingOutpatientAdmissions?: PendingOutpatientAdmission[];
    wards: WardCatalogue[];
    wardOptions: Option[];
    sexOptions: Option[];
    payerOptions: Option[];
    continueFromOptions: Option[];
    filters: Filters;
    canRegister: boolean;
    canOpen?: boolean;
    canCancel?: boolean;
};

const statusLabel: Record<string, string> = {
    REGISTERED: 'Registered',
    IN_EXAMINATION: 'In examination',
    READY_FOR_RM: 'Ready for medical records',
    CLOSED: 'Closed',
    CANCELLED: 'Cancelled',
};

const statusChipClass: Record<string, string> = {
    REGISTERED: 'bg-[#e8f2fa] text-[#123b63]',
    IN_EXAMINATION: 'bg-[#fff4eb] text-[#c2410c]',
    READY_FOR_RM: 'bg-[#ecfdf5] text-[#047857]',
    CLOSED: 'bg-[#f1f5f9] text-[#64748b]',
    CANCELLED: 'bg-[#fef2f2] text-[#b42318] ring-1 ring-inset ring-[#fecaca]',
};

const cancellationReasons = [
    { value: 'SALAH_PENDAFTARAN', label: 'Registration error' },
    { value: 'DUPLIKAT_KUNJUNGAN', label: 'Duplicate visit' },
    {
        value: 'PASIEN_TIDAK_MELANJUTKAN',
        label: 'Patient did not continue',
    },
    {
        value: 'PERUBAHAN_RENCANA_SEBELUM_PELAYANAN',
        label: 'Plan changed before care',
    },
] as const;

const cancellationReasonLabel = Object.fromEntries(
    cancellationReasons.map((reason) => [reason.value, reason.label]),
) as Record<string, string>;

function newCancellationKey(): string {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `cancel-${Date.now()}-${Math.random().toString(16).slice(2)}`
    );
}

function cancellationTimeLabel(value: string | null): string {
    if (!value) {
        return 'Time unavailable';
    }

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

const payerLabel: Record<string, string> = {
    UMUM: 'Self-pay',
    BPJS: 'BPJS',
    LAINNYA: 'Other',
};

const continueLabel: Record<string, string> = {
    LANGSUNG: 'Direct',
    DARI_IGD: 'From emergency',
    DARI_RJ: 'From outpatient',
};

const sexLabel: Record<string, string> = {
    male: 'Male',
    female: 'Female',
    other: 'Other',
    unknown: 'Unknown',
};

const fieldClass =
    'border-input h-8 w-full rounded-md border bg-white px-2.5 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30 disabled:cursor-not-allowed disabled:opacity-60';

const registrationFieldLabels: Record<string, string> = {
    patient_public_id: 'Selected patient',
    full_name: 'Full name',
    date_of_birth: 'Date of birth',
    sex: 'Sex',
    nik: 'NIK',
    phone: 'Phone',
    ward_name: 'Ward',
    ward_class: 'Class',
    bed_code: 'Bed',
    bed_public_id: 'Bed',
    payer_type: 'Payment method',
    insurance_number: 'Guarantor number',
    continue_from: 'Admission source',
    admission_authority_type: 'Direct admission authority',
    admission_authority_reference: 'Admission authority reference number',
    chief_complaint: 'Chief complaint',
    is_synthetic: 'Patient data validation',
};

const registrationErrorTarget: Record<string, string> = {
    patient_public_id: 'patient-search',
    bed_public_id: 'bed_code',
    is_synthetic: 'full_name',
};

export default function PendaftaranRawatInap({
    q,
    searchResults,
    todaysEncounters,
    pendingEmergencyAdmissions = [],
    pendingOutpatientAdmissions = [],
    wards,
    wardOptions,
    sexOptions,
    payerOptions,
    continueFromOptions,
    filters,
    canRegister,
    canOpen = false,
    canCancel = false,
}: Props) {
    const canViewBedCensus =
        (
            usePage().props.auth as { capabilities?: string[] } | undefined
        )?.capabilities?.includes('inpatient.occupancy.view') ?? false;
    const [searchQ, setSearchQ] = useState(q);
    const [filterQ, setFilterQ] = useState(filters.q);
    const [filterWard, setFilterWard] = useState(filters.ward);
    const [filterPayer, setFilterPayer] = useState(filters.payer);
    const [filterContinue, setFilterContinue] = useState(filters.continue_from);
    const [filterDateFrom, setFilterDateFrom] = useState(filters.date_from);
    const [filterDateTo, setFilterDateTo] = useState(filters.date_to);
    const [selectedPatient, setSelectedPatient] = useState<PatientRow | null>(
        null,
    );
    const [validationAttempt, setValidationAttempt] = useState(0);
    const [cancelTarget, setCancelTarget] = useState<EncounterRow | null>(null);
    const [cancelDialogOpen, setCancelDialogOpen] = useState(false);
    const [cancellationAnnouncement, setCancellationAnnouncement] =
        useState('');
    const [outpatientHandoffTarget, setOutpatientHandoffTarget] =
        useState<PendingOutpatientAdmission | null>(null);
    const cancelTriggerRef = useRef<HTMLButtonElement | null>(null);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const admissionWards = wards.filter((ward) => ward.beds.length > 0);
    const initialWard = admissionWards[0];
    const initialBed = initialWard?.beds[0];

    const form = useForm({
        patient_public_id: '',
        full_name: '',
        date_of_birth: '',
        sex: sexOptions[0]?.value ?? 'unknown',
        nik: '',
        phone: '',
        ward_name: initialWard?.display_name ?? '',
        ward_class: initialBed?.service_class ?? '',
        bed_code: initialBed?.code ?? '',
        bed_public_id: initialBed?.public_id ?? '',
        payer_type: payerOptions[0]?.value ?? 'UMUM',
        insurance_number: '',
        continue_from: 'LANGSUNG',
        admission_authority_type: 'PLANNED_ORDER',
        admission_authority_reference: '',
        chief_complaint: '',
        is_synthetic: true,
    });

    const cancelForm = useForm({
        reason_code: '',
        note: '',
        idempotency_key: '',
    });
    const outpatientHandoffForm = useForm({
        expected_disposition_version: 0,
        bed_public_id: initialBed?.public_id ?? '',
        idempotency_key: '',
    });

    const selectedWard =
        wards.find((ward) =>
            ward.beds.some((bed) => bed.public_id === form.data.bed_public_id),
        ) ?? admissionWards[0];
    const availableBeds = selectedWard?.beds ?? [];
    const hasAvailableBed = availableBeds.some(
        (bed) => bed.public_id === form.data.bed_public_id,
    );
    const bedSelectionError = form.errors.bed_public_id ?? form.errors.bed_code;
    const registrationErrors = Object.entries(form.errors);

    useEffect(() => {
        if (validationAttempt > 0 && registrationErrors.length > 0) {
            errorSummaryRef.current?.focus();
        }
    }, [registrationErrors.length, validationAttempt]);

    useEffect(() => {
        const currentWard = wards.find((ward) =>
            ward.beds.some((bed) => bed.public_id === form.data.bed_public_id),
        );
        const currentBed = currentWard?.beds.find(
            (bed) => bed.public_id === form.data.bed_public_id,
        );

        if (currentWard && currentBed) {
            return;
        }

        const nextWard = wards.find((ward) => ward.beds.length > 0);
        const nextBed = nextWard?.beds[0];
        const nextPlacement = {
            ward_name: nextWard?.display_name ?? '',
            ward_class: nextBed?.service_class ?? '',
            bed_code: nextBed?.code ?? '',
            bed_public_id: nextBed?.public_id ?? '',
        };

        const needsReconciliation =
            form.data.ward_name !== nextPlacement.ward_name ||
            form.data.ward_class !== nextPlacement.ward_class ||
            form.data.bed_code !== nextPlacement.bed_code ||
            form.data.bed_public_id !== nextPlacement.bed_public_id;

        if (!needsReconciliation) {
            return;
        }

        let cancelled = false;
        queueMicrotask(() => {
            if (!cancelled) {
                form.setData({
                    ...form.data,
                    ...nextPlacement,
                });
            }
        });

        return () => {
            cancelled = true;
        };
        // Placement is reconciled only when server-projected availability changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [wards, form.data.bed_public_id]);

    const errorProps = (field: keyof typeof form.data) => {
        const message = form.errors[field];

        return {
            'aria-invalid': message ? true : undefined,
            'aria-describedby': message ? `${field}-error` : undefined,
        };
    };

    const applySearch = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/pendaftaran/rawat-inap',
            { q: searchQ || undefined },
            { preserveState: true, replace: true },
        );
    };

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/pendaftaran/rawat-inap',
            {
                q: filterQ || undefined,
                ward: filterWard || undefined,
                payer: filterPayer || undefined,
                continue_from: filterContinue || undefined,
                date_from: filterDateFrom || undefined,
                date_to: filterDateTo || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    const pickPatient = (patient: PatientRow) => {
        setSelectedPatient(patient);
        form.setData({
            ...form.data,
            patient_public_id: patient.public_id,
            full_name: patient.full_name,
            date_of_birth: patient.date_of_birth ?? '',
            sex: patient.sex,
            nik: patient.nik ?? '',
            phone: patient.phone ?? '',
        });
    };

    const clearPatient = () => {
        setSelectedPatient(null);
        form.setData({
            ...form.data,
            patient_public_id: '',
            full_name: '',
            date_of_birth: '',
            sex: sexOptions[0]?.value ?? 'unknown',
            nik: '',
            phone: '',
        });
    };

    const onWardChange = (wardPublicId: string) => {
        const ward = wards.find((item) => item.public_id === wardPublicId);

        if (!ward) {
            return;
        }

        const bed = ward.beds[0];

        form.setData({
            ...form.data,
            ward_name: ward.display_name,
            ward_class: bed?.service_class ?? '',
            bed_code: bed?.code ?? '',
            bed_public_id: bed?.public_id ?? '',
        });
    };

    const submitAdmission = (event: FormEvent) => {
        event.preventDefault();
        form.post('/pendaftaran/rawat-inap', {
            preserveScroll: true,
            onError: () => setValidationAttempt((attempt) => attempt + 1),
            onSuccess: () => {
                clearPatient();
                form.reset(
                    'chief_complaint',
                    'insurance_number',
                    'full_name',
                    'date_of_birth',
                    'nik',
                    'phone',
                );
            },
        });
    };

    const openCancellationDialog = (
        encounter: EncounterRow,
        trigger: HTMLButtonElement,
    ) => {
        cancelTriggerRef.current = trigger;
        setCancelTarget(encounter);
        setCancellationAnnouncement('');
        cancelForm.clearErrors();
        cancelForm.setData({
            reason_code: '',
            note: '',
            idempotency_key: newCancellationKey(),
        });
        setCancelDialogOpen(true);
    };

    const submitCancellation = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!cancelTarget) {
            return;
        }

        cancelForm.post(
            `/pendaftaran/kunjungan/${cancelTarget.public_id}/batalkan`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCancellationAnnouncement(
                        'Visit cancelled successfully.',
                    );
                    setCancelDialogOpen(false);
                    setCancelTarget(null);
                    cancelForm.reset();
                },
                onError: (errors) => {
                    setCancellationAnnouncement(
                        errors.cancellation ??
                            'The cancellation could not be saved. Check the cancellation reason and note.',
                    );
                },
            },
        );
    };

    const openOutpatientHandoff = (admission: PendingOutpatientAdmission) => {
        setOutpatientHandoffTarget(admission);
        outpatientHandoffForm.setData({
            expected_disposition_version: admission.disposition_version,
            bed_public_id: initialBed?.public_id ?? '',
            idempotency_key: newCancellationKey(),
        });
    };

    const submitOutpatientHandoff = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!outpatientHandoffTarget?.handoff_url) {
            return;
        }

        outpatientHandoffForm.post(outpatientHandoffTarget.handoff_url, {
            preserveScroll: true,
            onSuccess: () => setOutpatientHandoffTarget(null),
        });
    };

    return (
        <>
            <Head title="Inpatient registration" />

            <p className="sr-only" role="status" aria-live="polite">
                {cancellationAnnouncement}
            </p>

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-4 px-3 py-4 md:px-5 md:py-5">
                <CareSettingSubnav
                    items={[
                        {
                            href: '/pendaftaran/rawat-jalan',
                            label: 'Outpatient',
                        },
                        {
                            href: '/pendaftaran/igd',
                            label: 'Emergency',
                        },
                        {
                            href: '/pendaftaran/rawat-inap',
                            label: 'Inpatient',
                            active: true,
                        },
                        {
                            href: '/pendaftaran/rekap',
                            label: 'Summary',
                        },
                    ]}
                />

                <header className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                            Patient data · Inpatient registration
                        </h1>
                        <p className="mt-0.5 text-xs text-[#64748b]">
                            Manage patient identity, admission, ward, class, and
                            inpatient beds.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canViewBedCensus ? (
                            <Button
                                asChild
                                type="button"
                                variant="outline"
                                size="sm"
                                className="min-h-11"
                            >
                                <Link href="/manajemen-data/bangsal">
                                    View bed availability
                                </Link>
                            </Button>
                        ) : null}
                        {selectedPatient ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="min-h-11"
                                onClick={clearPatient}
                            >
                                New patient
                            </Button>
                        ) : null}
                    </div>
                </header>

                {pendingOutpatientAdmissions.length > 0 ? (
                    <section
                        aria-labelledby="pending-outpatient-admissions-title"
                        className="min-w-0 rounded-lg border border-[#bbf7d0] bg-[#f0fdf4] p-3"
                    >
                        <h2
                            id="pending-outpatient-admissions-title"
                            className="text-sm font-semibold text-[#14532d]"
                        >
                            Awaiting outpatient handoff
                        </h2>
                        <p className="mt-0.5 text-xs text-[#3f6212]">
                            The physician decision and medical document are
                            final. The registrar selects an available bed to
                            complete the admission.
                        </p>
                        <div className="mt-2 min-w-0 overflow-x-auto">
                            <table className="w-full min-w-[48rem] text-left text-sm">
                                <caption className="sr-only">
                                    Outpatient decisions for inpatient admission
                                    awaiting handoff
                                </caption>
                                <thead className="border-b border-[#bbf7d0] text-[0.7rem] tracking-wide text-[#3f6212] uppercase">
                                    <tr>
                                        <th className="px-2 py-1.5">Patient</th>
                                        <th className="px-2 py-1.5">
                                            Outpatient episode
                                        </th>
                                        <th className="px-2 py-1.5">
                                            Guarantor
                                        </th>
                                        <th className="px-2 py-1.5">
                                            Admission reason
                                        </th>
                                        <th className="px-2 py-1.5">Signed</th>
                                        <th className="px-2 py-1.5">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pendingOutpatientAdmissions.map(
                                        (admission) => (
                                            <tr
                                                key={
                                                    admission.disposition_public_id
                                                }
                                                className="border-b border-[#dcfce7] last:border-0"
                                            >
                                                <td className="px-2 py-2">
                                                    <p className="font-medium text-[#14532d]">
                                                        {admission.patient
                                                            .full_name ?? '—'}
                                                    </p>
                                                    <p className="font-mono text-xs text-[#64748b]">
                                                        {admission.patient
                                                            .medical_record_number ??
                                                            '—'}
                                                    </p>
                                                </td>
                                                <td className="px-2 py-2 font-mono text-xs text-[#475569]">
                                                    {admission.source_encounter_public_id ??
                                                        '—'}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {admission.payer_type
                                                        ? (payerLabel[
                                                              admission
                                                                  .payer_type
                                                          ] ??
                                                          admission.payer_type)
                                                        : '—'}
                                                </td>
                                                <td className="max-w-xs px-2 py-2 text-[#334155]">
                                                    {admission.admission_reason ??
                                                        '—'}
                                                </td>
                                                <td className="px-2 py-2 text-xs text-[#475569]">
                                                    {admission.signed_at
                                                        ? cancellationTimeLabel(
                                                              admission.signed_at,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-2 py-2 text-right">
                                                    {admission.handoff_url ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                openOutpatientHandoff(
                                                                    admission,
                                                                )
                                                            }
                                                            aria-label={`Select a bed and hand off outpatient care for ${admission.patient.full_name ?? 'patient'}`}
                                                        >
                                                            Select bed
                                                        </Button>
                                                    ) : null}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}

                {pendingEmergencyAdmissions.length > 0 ? (
                    <section
                        aria-labelledby="pending-emergency-admissions-title"
                        className="min-w-0 rounded-lg border border-[#bfdbfe] bg-[#eff6ff] p-3"
                    >
                        <h2
                            id="pending-emergency-admissions-title"
                            className="text-sm font-semibold text-[#0f172a]"
                        >
                            Awaiting emergency handoff
                        </h2>
                        <p className="mt-0.5 text-xs text-[#475569]">
                            Signed inpatient dispositions appear here. Select a
                            patient to continue to bed selection and the
                            recorded handoff.
                        </p>
                        <div className="mt-2 min-w-0 overflow-x-auto">
                            <table className="w-full min-w-[52rem] text-left text-sm">
                                <caption className="sr-only">
                                    Emergency inpatient dispositions awaiting
                                    handoff
                                </caption>
                                <thead className="border-b border-[#bfdbfe] text-[0.7rem] tracking-wide text-[#475569] uppercase">
                                    <tr>
                                        <th className="px-2 py-1.5">Patient</th>
                                        <th className="px-2 py-1.5">
                                            Emergency episode
                                        </th>
                                        <th className="px-2 py-1.5">
                                            Guarantor
                                        </th>
                                        <th className="px-2 py-1.5">
                                            Admission reason
                                        </th>
                                        <th className="px-2 py-1.5">Signed</th>
                                        <th scope="col" className="px-2 py-1.5">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pendingEmergencyAdmissions.map(
                                        (admission) => (
                                            <tr
                                                key={
                                                    admission.disposition_public_id
                                                }
                                                className="border-b border-[#dbeafe] last:border-0"
                                            >
                                                <td className="px-2 py-2">
                                                    <p className="font-medium text-[#0f172a]">
                                                        {admission.patient
                                                            .full_name ?? '—'}
                                                    </p>
                                                    <p className="font-mono text-xs text-[#64748b]">
                                                        {admission.patient
                                                            .medical_record_number ??
                                                            '—'}
                                                    </p>
                                                </td>
                                                <td className="px-2 py-2 font-mono text-xs text-[#475569]">
                                                    {admission.source_encounter_public_id ??
                                                        '—'}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {admission.payer_type
                                                        ? (payerLabel[
                                                              admission
                                                                  .payer_type
                                                          ] ??
                                                          admission.payer_type)
                                                        : '—'}
                                                </td>
                                                <td className="max-w-xs px-2 py-2 text-[#334155]">
                                                    {admission.admission_reason ??
                                                        '—'}
                                                </td>
                                                <td className="px-2 py-2 text-xs text-[#475569]">
                                                    {admission.signed_at
                                                        ? cancellationTimeLabel(
                                                              admission.signed_at,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-2 py-2 text-right">
                                                    {admission.handoff_url ? (
                                                        <Link
                                                            href={
                                                                admission.handoff_url
                                                            }
                                                            className="inline-flex min-h-11 items-center rounded-md px-2 text-xs font-semibold text-[#075985] hover:bg-[#dbeafe] hover:underline"
                                                            aria-label={
                                                                'Continue emergency handoff for ' +
                                                                (admission
                                                                    .patient
                                                                    .full_name ??
                                                                    'patient')
                                                            }
                                                        >
                                                            Continue handoff
                                                        </Link>
                                                    ) : null}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}

                <form
                    onSubmit={applySearch}
                    className="rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] p-3"
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-[14rem] flex-1 gap-1">
                            <Label
                                htmlFor="patient-search"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Find patient (MRN / National ID / Name)
                            </Label>
                            <Input
                                id="patient-search"
                                className={cn(fieldClass, 'bg-white')}
                                value={searchQ}
                                onChange={(e) => setSearchQ(e.target.value)}
                                placeholder="MRN / National ID / Name"
                            />
                        </div>
                        <Button type="submit" size="sm">
                            Search
                        </Button>
                    </div>
                </form>

                {searchResults.length > 0 ? (
                    <section className="min-w-0 rounded-lg border border-[#e2e8f0] bg-white p-3">
                        <h2 className="text-sm font-semibold text-[#0f172a]">
                            Patient search results
                        </h2>
                        <div className="mt-2 min-w-0 overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-left text-sm">
                                <caption className="sr-only">
                                    Inpatient patient search results
                                </caption>
                                <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                    <tr>
                                        <th className="px-2 py-1.5">MRN</th>
                                        <th className="px-2 py-1.5">Name</th>
                                        <th className="px-2 py-1.5">
                                            Date of birth
                                        </th>
                                        <th className="px-2 py-1.5">JK</th>
                                        <th scope="col" className="px-2 py-1.5">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {searchResults.map((patient) => (
                                        <tr
                                            key={patient.public_id}
                                            className="border-b border-[#f1f5f9] last:border-0"
                                        >
                                            <td className="px-2 py-1.5 font-mono text-xs">
                                                {patient.medical_record_number}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {patient.full_name}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {patient.date_of_birth ?? '—'}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {sexLabel[patient.sex] ??
                                                    patient.sex}
                                            </td>
                                            <td className="px-2 py-1.5 text-right">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={
                                                        selectedPatient?.public_id ===
                                                        patient.public_id
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    onClick={() =>
                                                        pickPatient(patient)
                                                    }
                                                >
                                                    Select
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}

                <form
                    onSubmit={submitAdmission}
                    className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4"
                >
                    <h2 className="text-sm font-semibold text-[#0f172a]">
                        Inpatient admission form
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b]">
                        Patient details + ward → class → bed.
                    </p>

                    {admissionWards.length === 0 ? (
                        <p
                            role="status"
                            aria-live="polite"
                            className="mt-3 rounded-md border border-[#fed7aa] bg-[#fff7ed] px-3 py-2 text-sm text-[#9a3412]"
                        >
                            No inpatient beds are available. Check availability
                            or contact the ward administrator.
                        </p>
                    ) : null}

                    {registrationErrors.length > 0 ? (
                        <div
                            ref={errorSummaryRef}
                            role="alert"
                            tabIndex={-1}
                            aria-labelledby="inpatient-registration-error-title"
                            className="mt-3 rounded-md border border-[#fecaca] bg-[#fef2f2] p-3 text-sm text-[#991b1b] focus-visible:ring-2 focus-visible:ring-[#b91c1c] focus-visible:ring-offset-2 focus-visible:outline-none"
                        >
                            <p
                                id="inpatient-registration-error-title"
                                className="font-semibold"
                            >
                                Inpatient registration cannot be saved yet.
                            </p>
                            <ul className="mt-1 list-disc space-y-0.5 pl-5">
                                {registrationErrors.map(([field, message]) => {
                                    const target =
                                        registrationErrorTarget[field] ?? field;
                                    const label =
                                        registrationFieldLabels[field] ?? field;

                                    return (
                                        <li key={field}>
                                            <a
                                                href={`#${target}`}
                                                className="underline underline-offset-2"
                                                onClick={(event) => {
                                                    event.preventDefault();
                                                    document
                                                        .getElementById(target)
                                                        ?.focus();
                                                }}
                                            >
                                                {label}: {message}
                                            </a>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ) : null}

                    <div className="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        {!selectedPatient ? (
                            <>
                                <div className="grid gap-1">
                                    <Label htmlFor="full_name">Full name</Label>
                                    <Input
                                        id="full_name"
                                        {...errorProps('full_name')}
                                        className={cn(fieldClass, 'uppercase')}
                                        value={form.data.full_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'full_name',
                                                e.target.value.toLocaleUpperCase(
                                                    'id-ID',
                                                ),
                                            )
                                        }
                                        disabled={!canRegister}
                                    />
                                    <InputError
                                        id="full_name-error"
                                        message={form.errors.full_name}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="date_of_birth">
                                        Date of birth
                                    </Label>
                                    <Input
                                        id="date_of_birth"
                                        {...errorProps('date_of_birth')}
                                        type="date"
                                        className={fieldClass}
                                        value={form.data.date_of_birth}
                                        onChange={(e) =>
                                            form.setData(
                                                'date_of_birth',
                                                e.target.value,
                                            )
                                        }
                                        disabled={!canRegister}
                                    />
                                    <InputError
                                        id="date_of_birth-error"
                                        message={form.errors.date_of_birth}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="sex">Sex</Label>
                                    <select
                                        id="sex"
                                        {...errorProps('sex')}
                                        className={fieldClass}
                                        value={form.data.sex}
                                        onChange={(e) =>
                                            form.setData('sex', e.target.value)
                                        }
                                        disabled={!canRegister}
                                    >
                                        {sexOptions.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        id="sex-error"
                                        message={form.errors.sex}
                                    />
                                </div>
                            </>
                        ) : (
                            <div className="rounded-md border border-[#e2e8f0] bg-[#f8fafc] p-2 md:col-span-2 lg:col-span-3">
                                <p className="text-sm font-medium text-[#0f172a]">
                                    {selectedPatient.full_name}
                                </p>
                                <p className="font-mono text-xs text-[#64748b]">
                                    {selectedPatient.medical_record_number}
                                    {selectedPatient.nik
                                        ? ` · NIK ${selectedPatient.nik}`
                                        : ''}
                                </p>
                            </div>
                        )}

                        <div className="grid gap-1">
                            <Label htmlFor="nik">NIK</Label>
                            <Input
                                id="nik"
                                {...errorProps('nik')}
                                className={fieldClass}
                                value={form.data.nik}
                                onChange={(e) =>
                                    form.setData(
                                        'nik',
                                        e.target.value
                                            .replace(/\D/g, '')
                                            .slice(0, 16),
                                    )
                                }
                                inputMode="numeric"
                                pattern="[0-9]{16}"
                                maxLength={16}
                                disabled={!canRegister}
                            />
                            <InputError
                                id="nik-error"
                                message={form.errors.nik}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="phone">Phone</Label>
                            <Input
                                id="phone"
                                {...errorProps('phone')}
                                className={fieldClass}
                                value={form.data.phone}
                                onChange={(e) =>
                                    form.setData('phone', e.target.value)
                                }
                                disabled={!canRegister}
                            />
                            <InputError
                                id="phone-error"
                                message={form.errors.phone}
                            />
                        </div>

                        <div className="grid gap-1">
                            <Label htmlFor="ward_name">Ward</Label>
                            <select
                                id="ward_name"
                                {...errorProps('ward_name')}
                                className={fieldClass}
                                value={selectedWard?.public_id ?? ''}
                                onChange={(e) => onWardChange(e.target.value)}
                                disabled={
                                    !canRegister || admissionWards.length === 0
                                }
                            >
                                {admissionWards.map((ward) => (
                                    <option
                                        key={ward.public_id}
                                        value={ward.public_id}
                                    >
                                        {ward.display_name} · {ward.code}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                id="ward_name-error"
                                message={form.errors.ward_name}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="ward_class">Class</Label>
                            <Input
                                id="ward_class"
                                {...errorProps('ward_class')}
                                className={fieldClass}
                                value={form.data.ward_class}
                                readOnly
                            />
                            <InputError
                                id="ward_class-error"
                                message={form.errors.ward_class}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="bed_code">Bed</Label>
                            <select
                                id="bed_code"
                                aria-invalid={
                                    bedSelectionError ? true : undefined
                                }
                                aria-describedby={
                                    bedSelectionError
                                        ? 'bed_code-error'
                                        : undefined
                                }
                                className={fieldClass}
                                value={form.data.bed_public_id}
                                onChange={(e) => {
                                    const bed = availableBeds.find(
                                        (item) =>
                                            item.public_id === e.target.value,
                                    );

                                    if (!bed) {
                                        return;
                                    }

                                    form.setData({
                                        ...form.data,
                                        ward_name:
                                            selectedWard?.display_name ?? '',
                                        ward_class: bed.service_class,
                                        bed_code: bed.code,
                                        bed_public_id: bed.public_id,
                                    });
                                }}
                                disabled={
                                    !canRegister || admissionWards.length === 0
                                }
                            >
                                {availableBeds.map((bed) => (
                                    <option
                                        key={bed.public_id}
                                        value={bed.public_id}
                                    >
                                        {bed.display_name} · {bed.code}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                id="bed_code-error"
                                message={bedSelectionError}
                            />
                        </div>

                        <div className="grid gap-1">
                            <Label htmlFor="payer_type">Payment method</Label>
                            <select
                                id="payer_type"
                                {...errorProps('payer_type')}
                                className={fieldClass}
                                value={form.data.payer_type}
                                onChange={(e) =>
                                    form.setData('payer_type', e.target.value)
                                }
                                disabled={!canRegister}
                            >
                                {payerOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                id="payer_type-error"
                                message={form.errors.payer_type}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="insurance_number">
                                Guarantor number
                            </Label>
                            <Input
                                id="insurance_number"
                                {...errorProps('insurance_number')}
                                className={fieldClass}
                                value={form.data.insurance_number}
                                onChange={(e) =>
                                    form.setData(
                                        'insurance_number',
                                        e.target.value,
                                    )
                                }
                                disabled={!canRegister}
                            />
                            <InputError
                                id="insurance_number-error"
                                message={form.errors.insurance_number}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="continue_from">
                                Admission source
                            </Label>
                            <Input
                                id="continue_from"
                                className={fieldClass}
                                value="Direct registration"
                                readOnly
                            />
                            <input
                                type="hidden"
                                name="continue_from"
                                value={form.data.continue_from}
                            />
                            <InputError
                                id="continue_from-error"
                                message={form.errors.continue_from}
                            />
                        </div>

                        <div className="grid gap-1">
                            <Label htmlFor="admission_authority_type">
                                Direct admission authority
                            </Label>
                            <select
                                id="admission_authority_type"
                                {...errorProps('admission_authority_type')}
                                className={fieldClass}
                                value={form.data.admission_authority_type}
                                onChange={(e) =>
                                    form.setData(
                                        'admission_authority_type',
                                        e.target.value,
                                    )
                                }
                                disabled={!canRegister}
                            >
                                <option value="PLANNED_ORDER">
                                    Planned admission order
                                </option>
                                <option value="EXTERNAL_REFERRAL">
                                    External referral
                                </option>
                            </select>
                            <InputError
                                id="admission_authority_type-error"
                                message={form.errors.admission_authority_type}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="admission_authority_reference">
                                Admission authority reference number
                            </Label>
                            <Input
                                id="admission_authority_reference"
                                {...errorProps('admission_authority_reference')}
                                className={fieldClass}
                                value={form.data.admission_authority_reference}
                                onChange={(e) =>
                                    form.setData(
                                        'admission_authority_reference',
                                        e.target.value,
                                    )
                                }
                                disabled={!canRegister}
                                required
                            />
                            <InputError
                                id="admission_authority_reference-error"
                                message={
                                    form.errors.admission_authority_reference
                                }
                            />
                        </div>

                        <div className="grid gap-1 md:col-span-2 lg:col-span-3">
                            <Label htmlFor="chief_complaint">
                                Chief complaint
                            </Label>
                            <textarea
                                id="chief_complaint"
                                {...errorProps('chief_complaint')}
                                className={cn(
                                    fieldClass,
                                    'min-h-[4.5rem] resize-y py-2',
                                )}
                                value={form.data.chief_complaint}
                                onChange={(e) =>
                                    form.setData(
                                        'chief_complaint',
                                        e.target.value,
                                    )
                                }
                                disabled={!canRegister}
                            />
                            <InputError
                                id="chief_complaint-error"
                                message={form.errors.chief_complaint}
                            />
                        </div>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            disabled={
                                !canRegister ||
                                !hasAvailableBed ||
                                form.processing
                            }
                        >
                            {form.processing
                                ? 'Saving…'
                                : 'Save inpatient registration'}
                        </Button>
                        {!canRegister ? (
                            <p className="text-xs text-[#64748b]">
                                This account does not have registration
                                permission.
                            </p>
                        ) : null}
                    </div>
                </form>

                <form
                    onSubmit={applyFilters}
                    className="rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] p-3"
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-[10rem] flex-1 gap-1">
                            <label
                                htmlFor="inpatient-filter-q"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                MRN / Name
                            </label>
                            <Input
                                id="inpatient-filter-q"
                                className={cn(fieldClass, 'bg-white')}
                                value={filterQ}
                                onChange={(e) => setFilterQ(e.target.value)}
                                placeholder="MRN / Name"
                            />
                        </div>
                        <div className="grid min-w-[10rem] gap-1">
                            <label
                                htmlFor="inpatient-filter-ward"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Ward
                            </label>
                            <select
                                id="inpatient-filter-ward"
                                className={fieldClass}
                                value={filterWard}
                                onChange={(e) => setFilterWard(e.target.value)}
                            >
                                <option value="">All wards</option>
                                {wardOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid min-w-[8rem] gap-1">
                            <label
                                htmlFor="inpatient-filter-payer"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Payment method
                            </label>
                            <select
                                id="inpatient-filter-payer"
                                className={fieldClass}
                                value={filterPayer}
                                onChange={(e) => setFilterPayer(e.target.value)}
                            >
                                <option value="">All</option>
                                {payerOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid min-w-[9rem] gap-1">
                            <label
                                htmlFor="inpatient-filter-origin"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Admission source
                            </label>
                            <select
                                id="inpatient-filter-origin"
                                className={fieldClass}
                                value={filterContinue}
                                onChange={(e) =>
                                    setFilterContinue(e.target.value)
                                }
                            >
                                <option value="">All</option>
                                {continueFromOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid min-w-[8rem] gap-1">
                            <label
                                htmlFor="inpatient-filter-date-from"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                From date
                            </label>
                            <Input
                                id="inpatient-filter-date-from"
                                type="date"
                                className={cn(fieldClass, 'bg-white')}
                                value={filterDateFrom}
                                onChange={(e) =>
                                    setFilterDateFrom(e.target.value)
                                }
                            />
                        </div>
                        <div className="grid min-w-[8rem] gap-1">
                            <label
                                htmlFor="inpatient-filter-date-to"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                To date
                            </label>
                            <Input
                                id="inpatient-filter-date-to"
                                type="date"
                                className={cn(fieldClass, 'bg-white')}
                                value={filterDateTo}
                                onChange={(e) =>
                                    setFilterDateTo(e.target.value)
                                }
                            />
                        </div>
                        <Button type="submit" size="sm" variant="secondary">
                            Apply filters
                        </Button>
                    </div>
                </form>

                <section className="min-w-0 rounded-lg border border-[#e2e8f0] bg-white p-3">
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold text-[#0f172a]">
                            Inpatient admission list
                        </h2>
                        <Link
                            href="/pemeriksaan/rawat-inap"
                            className="text-xs font-medium text-[#1b75bc] hover:underline"
                        >
                            Open examination worklist →
                        </Link>
                    </div>
                    <div className="min-w-0 overflow-x-auto">
                        <table className="w-full min-w-[56rem] text-left text-sm">
                            <caption className="sr-only">
                                Inpatient registration list
                            </caption>
                            <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th className="px-2 py-1.5">Queue</th>
                                    <th className="px-2 py-1.5">MRN</th>
                                    <th className="px-2 py-1.5">Name</th>
                                    <th className="px-2 py-1.5">Ward</th>
                                    <th className="px-2 py-1.5">Class</th>
                                    <th className="px-2 py-1.5">TT</th>
                                    <th className="px-2 py-1.5">
                                        Admission source
                                    </th>
                                    <th className="px-2 py-1.5">Payer</th>
                                    <th className="px-2 py-1.5">Status</th>
                                    <th className="px-2 py-1.5">
                                        Chief complaint
                                    </th>
                                    <th scope="col" className="px-2 py-1.5">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {todaysEncounters.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={11}
                                            className="px-2 py-6 text-[#64748b]"
                                        >
                                            No inpatient registrations match
                                            these filters.
                                        </td>
                                    </tr>
                                ) : (
                                    todaysEncounters.map((encounter) => (
                                        <tr
                                            key={encounter.public_id}
                                            className="border-b border-[#f1f5f9] last:border-0"
                                        >
                                            <td className="px-2 py-1.5 font-mono text-xs">
                                                {encounter.queue_number ?? '—'}
                                            </td>
                                            <td className="px-2 py-1.5 font-mono text-xs">
                                                {
                                                    encounter.patient
                                                        .medical_record_number
                                                }
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {encounter.patient.full_name}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {encounter.ward_name}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {encounter.ward_class}
                                            </td>
                                            <td className="px-2 py-1.5 font-mono text-xs">
                                                {encounter.bed_code}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {encounter.continue_from
                                                    ? (continueLabel[
                                                          encounter
                                                              .continue_from
                                                      ] ??
                                                      encounter.continue_from)
                                                    : '—'}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {payerLabel[
                                                    encounter.payer_type
                                                ] ?? encounter.payer_type}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                <span
                                                    className={cn(
                                                        'rounded px-1.5 py-0.5 text-[0.65rem] font-semibold',
                                                        statusChipClass[
                                                            encounter.status
                                                        ] ??
                                                            'bg-[#f1f5f9] text-[#64748b]',
                                                    )}
                                                >
                                                    {statusLabel[
                                                        encounter.status
                                                    ] ?? encounter.status}
                                                </span>
                                                {encounter.cancellation ? (
                                                    <div className="mt-1 max-w-[18rem] text-[0.68rem] leading-4 text-[#64748b]">
                                                        <p>
                                                            {cancellationReasonLabel[
                                                                encounter
                                                                    .cancellation
                                                                    .reason_code
                                                            ] ??
                                                                encounter
                                                                    .cancellation
                                                                    .reason_code}
                                                        </p>
                                                        <p>
                                                            {encounter
                                                                .cancellation
                                                                .cancelled_by ??
                                                                'Staff member unavailable'}{' '}
                                                            ·{' '}
                                                            {cancellationTimeLabel(
                                                                encounter
                                                                    .cancellation
                                                                    .cancelled_at,
                                                            )}
                                                        </p>
                                                        {encounter.cancellation
                                                            .note ? (
                                                            <p className="mt-0.5 break-words text-[#475569]">
                                                                {
                                                                    encounter
                                                                        .cancellation
                                                                        .note
                                                                }
                                                            </p>
                                                        ) : null}
                                                        <p className="mt-1 font-medium text-[#475569]">
                                                            The bed is available
                                                            again; placement
                                                            history remains
                                                            recorded.
                                                        </p>
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="max-w-[12rem] truncate px-2 py-1.5 text-[#64748b]">
                                                {encounter.chief_complaint ??
                                                    '—'}
                                            </td>
                                            <td className="px-2 py-1.5 text-right">
                                                <div className="flex items-center justify-end gap-3 whitespace-nowrap">
                                                    {canOpen &&
                                                    encounter.status !==
                                                        'CANCELLED' ? (
                                                        <Link
                                                            href={`/pemeriksaan/rawat-inap/${encounter.public_id}`}
                                                            aria-label={`Open examination for ${encounter.patient.full_name}`}
                                                            className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                        >
                                                            Open examination
                                                        </Link>
                                                    ) : null}
                                                    {encounter.status !==
                                                    'CANCELLED' ? (
                                                        <a
                                                            href={`/pendaftaran/kunjungan/${encounter.public_id}/cetak?docs=bukti,antrian`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            aria-label={`Print for ${encounter.patient.full_name}`}
                                                            className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                        >
                                                            Print
                                                        </a>
                                                    ) : (
                                                        <span className="text-xs text-[#64748b]">
                                                            History saved
                                                        </span>
                                                    )}
                                                    {canCancel ? (
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                encounter.status !==
                                                                'REGISTERED'
                                                            }
                                                            title={
                                                                encounter.status ===
                                                                'REGISTERED'
                                                                    ? undefined
                                                                    : 'Only registered visits can be cancelled.'
                                                            }
                                                            aria-label={`Cancel visit for ${encounter.patient.full_name}`}
                                                            aria-describedby={
                                                                encounter.status !==
                                                                'REGISTERED'
                                                                    ? `inpatient-cancel-blocked-${encounter.public_id}`
                                                                    : undefined
                                                            }
                                                            onClick={(event) =>
                                                                openCancellationDialog(
                                                                    encounter,
                                                                    event.currentTarget,
                                                                )
                                                            }
                                                            className="text-sm font-medium text-[#b42318] hover:underline focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#b42318] disabled:cursor-not-allowed disabled:text-[#94a3b8] disabled:no-underline"
                                                        >
                                                            Cancel visit
                                                        </button>
                                                    ) : null}
                                                    {canCancel &&
                                                    encounter.status !==
                                                        'REGISTERED' ? (
                                                        <span
                                                            id={`inpatient-cancel-blocked-${encounter.public_id}`}
                                                            className="sr-only"
                                                        >
                                                            Only registered
                                                            visits can be
                                                            cancelled.
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <Dialog
                    open={cancelDialogOpen}
                    onOpenChange={(open) => {
                        setCancelDialogOpen(open);

                        if (!open) {
                            setCancelTarget(null);
                        }
                    }}
                >
                    <DialogContent
                        className="max-h-[calc(100vh-2rem)] overflow-y-auto border-[#f3c7c3] p-0 sm:max-w-xl"
                        showCloseButton={false}
                        onCloseAutoFocus={(event) => {
                            event.preventDefault();
                            cancelTriggerRef.current?.focus();
                        }}
                    >
                        <DialogHeader className="border-b border-[#fee2e2] bg-[#fff8f7] px-5 py-4 text-left">
                            <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-[#b42318] uppercase">
                                Pre-care cancellation
                            </p>
                            <DialogTitle className="text-xl leading-7 text-[#0f172a]">
                                Cancel this visit before care begins?
                            </DialogTitle>
                            <DialogDescription className="leading-5 text-[#475569]">
                                This records the cancellation and placement
                                history. Visits that have already started care
                                cannot be cancelled from the registration desk.
                            </DialogDescription>
                        </DialogHeader>

                        {cancelTarget ? (
                            <form
                                onSubmit={submitCancellation}
                                className="grid gap-4 px-5 pb-5"
                            >
                                <div className="grid gap-3 rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] p-3 sm:grid-cols-2">
                                    <div>
                                        <p className="text-[0.68rem] tracking-wide text-[#64748b] uppercase">
                                            Patient
                                        </p>
                                        <p className="mt-0.5 font-medium text-[#0f172a]">
                                            {cancelTarget.patient.full_name}
                                        </p>
                                        <p className="font-mono text-xs text-[#64748b]">
                                            {cancelTarget.patient
                                                .medical_record_number ?? '—'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-[0.68rem] tracking-wide text-[#64748b] uppercase">
                                            Placement and queue
                                        </p>
                                        <p className="mt-0.5 font-medium text-[#0f172a]">
                                            {cancelTarget.ward_name ?? '—'} ·{' '}
                                            {cancelTarget.bed_code ?? '—'}
                                        </p>
                                        <p className="text-xs text-[#64748b]">
                                            Queue{' '}
                                            {cancelTarget.queue_number ?? '—'}
                                        </p>
                                    </div>
                                    <p className="border-t border-[#d7e6f3] pt-2 text-xs leading-5 text-[#475569] sm:col-span-2">
                                        The bed becomes available after the
                                        cancellation is successful. The queue
                                        number and placement history remain
                                        recorded.
                                    </p>
                                </div>

                                {cancellationAnnouncement ? (
                                    <div
                                        role="alert"
                                        className="rounded-md border border-[#fecaca] bg-[#fef2f2] px-3 py-2 text-sm text-[#991b1b]"
                                    >
                                        {cancellationAnnouncement}
                                    </div>
                                ) : null}

                                <div className="grid gap-1.5">
                                    <Label htmlFor="inpatient-cancellation-reason">
                                        Cancellation reason
                                    </Label>
                                    <select
                                        id="inpatient-cancellation-reason"
                                        required
                                        value={cancelForm.data.reason_code}
                                        onChange={(event) =>
                                            cancelForm.setData(
                                                'reason_code',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            cancelForm.errors.reason_code
                                                ? true
                                                : undefined
                                        }
                                        aria-describedby={
                                            cancelForm.errors.reason_code
                                                ? 'inpatient-cancellation-reason-error'
                                                : 'inpatient-cancellation-reason-help'
                                        }
                                        className={fieldClass}
                                    >
                                        <option value="">
                                            Select a cancellation reason
                                        </option>
                                        {cancellationReasons.map((reason) => (
                                            <option
                                                key={reason.value}
                                                value={reason.value}
                                            >
                                                {reason.label}
                                            </option>
                                        ))}
                                    </select>
                                    <p
                                        id="inpatient-cancellation-reason-help"
                                        className="text-xs text-[#64748b]"
                                    >
                                        Select the reason that best matches the
                                        registration event.
                                    </p>
                                    <InputError
                                        id="inpatient-cancellation-reason-error"
                                        message={cancelForm.errors.reason_code}
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="inpatient-cancellation-note">
                                        Cancellation note{' '}
                                        <span className="font-normal text-[#64748b]">
                                            (optional)
                                        </span>
                                    </Label>
                                    <textarea
                                        id="inpatient-cancellation-note"
                                        rows={3}
                                        maxLength={500}
                                        value={cancelForm.data.note}
                                        onChange={(event) =>
                                            cancelForm.setData(
                                                'note',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            cancelForm.errors.note
                                                ? true
                                                : undefined
                                        }
                                        aria-describedby={
                                            cancelForm.errors.note
                                                ? 'inpatient-cancellation-note-error'
                                                : 'inpatient-cancellation-note-help'
                                        }
                                        className="min-h-20 w-full resize-y rounded-md border border-[#cbd5e1] bg-white px-3 py-2 text-sm outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30"
                                    />
                                    <p
                                        id="inpatient-cancellation-note-help"
                                        className="text-xs text-[#64748b]"
                                    >
                                        Maximum 500 characters. Avoid
                                        unnecessary personal information.
                                    </p>
                                    <InputError
                                        id="inpatient-cancellation-note-error"
                                        message={cancelForm.errors.note}
                                    />
                                    <InputError
                                        id="inpatient-cancellation-idempotency-error"
                                        message={
                                            cancelForm.errors.idempotency_key
                                        }
                                    />
                                </div>

                                <DialogFooter className="border-t border-[#e2e8f0] pt-4">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setCancelDialogOpen(false)
                                        }
                                        disabled={cancelForm.processing}
                                    >
                                        Back
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={
                                            cancelForm.processing ||
                                            cancelForm.data.reason_code === ''
                                        }
                                        className="bg-[#b42318] text-white hover:bg-[#912018] focus-visible:ring-[#b42318]/30"
                                    >
                                        {cancelForm.processing
                                            ? 'Saving…'
                                            : 'Cancel visit'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        ) : null}
                    </DialogContent>
                </Dialog>
                <Dialog
                    open={outpatientHandoffTarget !== null}
                    onOpenChange={(open) => {
                        if (!open && !outpatientHandoffForm.processing) {
                            setOutpatientHandoffTarget(null);
                        }
                    }}
                >
                    <DialogContent className="max-w-lg p-0">
                        <DialogHeader className="border-b border-[#bbf7d0] bg-[#f0fdf4] px-5 py-4 text-left">
                            <DialogTitle className="text-xl leading-7 text-[#14532d]">
                                Outpatient to inpatient handoff
                            </DialogTitle>
                            <DialogDescription className="leading-5 text-[#3f6212]">
                                Select an available bed. The physician decision
                                and admission reason cannot be changed by the
                                registrar.
                            </DialogDescription>
                        </DialogHeader>
                        {outpatientHandoffTarget ? (
                            <form
                                className="space-y-4 px-5 pb-5"
                                onSubmit={submitOutpatientHandoff}
                            >
                                <div className="rounded-md border border-[#dcfce7] bg-[#f7fee7] p-3 text-sm">
                                    <p className="font-semibold text-[#14532d]">
                                        {outpatientHandoffTarget.patient
                                            .full_name ?? '—'}
                                    </p>
                                    <p className="font-mono text-xs text-[#64748b]">
                                        {outpatientHandoffTarget.patient
                                            .medical_record_number ?? '—'}
                                    </p>
                                    <p className="mt-2">
                                        <span className="font-medium">
                                            Admission reason:
                                        </span>{' '}
                                        {outpatientHandoffTarget.admission_reason ??
                                            '—'}
                                    </p>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="outpatient-handoff-bed">
                                        Available bed
                                    </Label>
                                    <select
                                        id="outpatient-handoff-bed"
                                        required
                                        className={fieldClass}
                                        value={
                                            outpatientHandoffForm.data
                                                .bed_public_id
                                        }
                                        onChange={(event) =>
                                            outpatientHandoffForm.setData(
                                                'bed_public_id',
                                                event.target.value,
                                            )
                                        }
                                        disabled={
                                            outpatientHandoffForm.processing ||
                                            admissionWards.length === 0
                                        }
                                    >
                                        {admissionWards.flatMap((ward) =>
                                            ward.beds.map((bed) => (
                                                <option
                                                    key={bed.public_id}
                                                    value={bed.public_id}
                                                >
                                                    {ward.display_name} ·{' '}
                                                    {bed.display_name} ·{' '}
                                                    {bed.service_class}
                                                </option>
                                            )),
                                        )}
                                    </select>
                                    <InputError
                                        id="outpatient-handoff-bed-error"
                                        message={
                                            outpatientHandoffForm.errors
                                                .bed_public_id
                                        }
                                    />
                                </div>
                                <DialogFooter className="border-t border-[#e2e8f0] pt-4">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            setOutpatientHandoffTarget(null)
                                        }
                                        disabled={
                                            outpatientHandoffForm.processing
                                        }
                                    >
                                        Back
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={
                                            outpatientHandoffForm.processing ||
                                            !outpatientHandoffForm.data
                                                .bed_public_id
                                        }
                                    >
                                        {outpatientHandoffForm.processing
                                            ? 'Saving…'
                                            : 'Complete handoff'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        ) : null}
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}

PendaftaranRawatInap.layout = () => ({
    breadcrumbs: [
        { title: 'Home', href: '/' },
        { title: 'Registration', href: '/pendaftaran/rawat-inap' },
        { title: 'Inpatient', href: '/pendaftaran/rawat-inap' },
    ] satisfies BreadcrumbItem[],
});
