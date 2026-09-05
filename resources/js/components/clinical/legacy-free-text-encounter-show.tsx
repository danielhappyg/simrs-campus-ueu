import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { LaboratoryEncounterPanel } from '@/components/clinical/laboratory/laboratory-encounter-panel';
import type { LaboratoryEncounterProjection } from '@/components/clinical/laboratory/types';
import { RadiologyEncounterPanel } from '@/components/clinical/radiology/radiology-encounter-panel';
import type { RadiologyEncounterProjection } from '@/components/clinical/radiology/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type EntryTypeOption = {
    value: string;
    label: string;
    allowed: boolean;
};

type ClinicalEntryRow = {
    public_id: string;
    entry_type: string;
    body: string;
    created_at: string | null;
    author_name: string | null;
};

type LabOrderRow = {
    public_id: string;
    test_code: string;
    test_label: string;
    clinical_question: string | null;
    status: string;
    requested_at: string;
    requested_by_name: string | null;
    result: {
        public_id: string;
        status: string;
        result_text: string;
        issued_at: string;
        entered_by_name: string | null;
    } | null;
};

type LabTestOption = {
    code: string;
    label: string;
};

type EncounterDetail = {
    public_id: string;
    status: string;
    clinic_name: string;
    doctor_name: string | null;
    schedule_label: string | null;
    ward_name?: string | null;
    ward_class?: string | null;
    bed_code?: string | null;
    continue_from?: string | null;
    payer_type: string;
    case_type?: string | null;
    accident_type?: string | null;
    queue_number: number | null;
    registered_at: string | null;
    visit_date: string | null;
    chief_complaint: string | null;
    patient: {
        public_id: string | null;
        medical_record_number: string | null;
        full_name: string | null;
        date_of_birth: string | null;
        sex: string | null;
        nik: string | null;
    };
    entries: ClinicalEntryRow[];
    lab_orders?: LabOrderRow[];
};

type Props = {
    variant?: 'rawat-jalan' | 'igd' | 'rawat-inap';
    indexPath?: string;
    showPathPrefix?: string;
    storeEntryPath?: string;
    storeLabOrderPath?: string;
    encounter: EncounterDetail;
    entryTypeOptions: EntryTypeOption[];
    labTestOptions?: LabTestOption[];
    canCreateLabOrder?: boolean;
    canWriteNursing: boolean;
    canWriteMedical: boolean;
    laboratory?: LaboratoryEncounterProjection;
    radiology?: RadiologyEncounterProjection;
};

const statusLabel: Record<string, string> = {
    REGISTERED: 'Registered',
    IN_EXAMINATION: 'In examination',
    READY_FOR_RM: 'Ready for records review',
    CLOSED: 'Closed',
};

const entryTypeLabel: Record<string, string> = {
    NURSING_INTAKE: 'Nursing assessment',
    MEDICAL_ASSESSMENT: 'Medical assessment',
};

const sexLabel: Record<string, string> = {
    male: 'Male',
    female: 'Female',
    other: 'Other',
    unknown: 'Unknown',
};

const labOrderStatusLabel: Record<string, string> = {
    ACTIVE: 'Awaiting results',
    COMPLETED: 'Completed',
    CANCELLED: 'Cancelled',
};

const labResultStatusLabel: Record<string, string> = {
    PRELIMINARY: 'Preliminary',
    FINAL: 'Final',
};

const payerLabel: Record<string, string> = {
    UMUM: 'Self-pay',
    BPJS: 'BPJS',
    LAINNYA: 'Other',
};

const continueLabel: Record<string, string> = {
    LANGSUNG: 'Direct',
    DARI_IGD: 'From Emergency Department',
    DARI_RJ: 'From Outpatient Care',
};

const clinicalTabs = [
    'Asesmen',
    'SOAP',
    'Diagnosa',
    'Tindakan',
    'Resep',
    'Order Lab',
    'Order Rad',
    'Riwayat',
] as const;

type ClinicalTab = (typeof clinicalTabs)[number];

const clinicalTabSlug: Record<ClinicalTab, string> = {
    Asesmen: 'asesmen',
    SOAP: 'soap',
    Diagnosa: 'diagnosa',
    Tindakan: 'tindakan',
    Resep: 'resep',
    'Order Lab': 'order-lab',
    'Order Rad': 'order-rad',
    Riwayat: 'riwayat',
};

const clinicalTabLabel: Record<ClinicalTab, string> = {
    Asesmen: 'Assessment',
    SOAP: 'SOAP',
    Diagnosa: 'Diagnosis',
    Tindakan: 'Procedures',
    Resep: 'Prescriptions',
    'Order Lab': 'Laboratory orders',
    'Order Rad': 'Radiology orders',
    Riwayat: 'History',
};

const entryErrorTargets: Record<string, { id: string; label: string }> = {
    entry_type: { id: 'entry_type', label: 'Note type' },
    body: { id: 'body', label: 'Note content' },
};

const labOrderErrorTargets: Record<string, { id: string; label: string }> = {
    test_code: { id: 'test_code', label: 'Examination' },
    clinical_question: {
        id: 'clinical_question',
        label: 'Clinical question',
    },
};

function presentErrors(errors: Record<string, string | undefined>) {
    return Object.entries(errors).filter(
        (entry): entry is [string, string] =>
            typeof entry[1] === 'string' && entry[1] !== '',
    );
}

export default function LegacyFreeTextEncounterShow({
    variant = 'rawat-jalan',
    indexPath = '/pemeriksaan/rawat-jalan',
    storeEntryPath,
    storeLabOrderPath,
    encounter,
    entryTypeOptions,
    labTestOptions = [],
    canCreateLabOrder = false,
    canWriteNursing,
    canWriteMedical,
    laboratory,
    radiology,
}: Props) {
    const { flash } = usePage().props;
    const isIgd = variant === 'igd';
    const isInpatient = variant === 'rawat-inap';
    const isOutpatient = variant === 'rawat-jalan';
    const entryPostPath =
        storeEntryPath ??
        `/pemeriksaan/rawat-jalan/${encounter.public_id}/entries`;
    const labOrderPostPath =
        storeLabOrderPath ??
        `/pemeriksaan/rawat-jalan/${encounter.public_id}/lab-orders`;
    const labOrders = encounter.lab_orders ?? [];
    const allowedOptions = entryTypeOptions.filter((option) => option.allowed);
    const canWrite = variant !== 'igd' && (canWriteNursing || canWriteMedical);
    const closed = encounter.status === 'CLOSED';
    const [activeTab, setActiveTab] = useState<ClinicalTab>('Asesmen');
    const [entryValidationAttempt, setEntryValidationAttempt] = useState(0);
    const [labOrderValidationAttempt, setLabOrderValidationAttempt] =
        useState(0);
    const entryErrorSummaryRef = useRef<HTMLDivElement>(null);
    const labOrderErrorSummaryRef = useRef<HTMLDivElement>(null);

    const form = useForm({
        entry_type: allowedOptions[0]?.value ?? '',
        body: '',
    });

    const labForm = useForm({
        test_code: labTestOptions[0]?.code ?? '',
        clinical_question: '',
    });

    const entryErrors = presentErrors(form.errors);
    const labOrderErrors = presentErrors(labForm.errors);
    const entryErrorFingerprint = entryErrors
        .map(([field, message]) => `${field}:${message}`)
        .join('|');
    const labOrderErrorFingerprint = labOrderErrors
        .map(([field, message]) => `${field}:${message}`)
        .join('|');

    useEffect(() => {
        if (entryValidationAttempt > 0 && entryErrors.length > 0) {
            entryErrorSummaryRef.current?.focus();
        }
    }, [entryErrorFingerprint, entryErrors.length, entryValidationAttempt]);

    useEffect(() => {
        if (labOrderValidationAttempt > 0 && labOrderErrors.length > 0) {
            labOrderErrorSummaryRef.current?.focus();
        }
    }, [
        labOrderErrorFingerprint,
        labOrderErrors.length,
        labOrderValidationAttempt,
    ]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(entryPostPath, {
            preserveScroll: true,
            onError: () => setEntryValidationAttempt((attempt) => attempt + 1),
            onSuccess: () => form.reset('body'),
        });
    };

    const submitLabOrder = (event: FormEvent) => {
        event.preventDefault();
        labForm.post(labOrderPostPath, {
            preserveScroll: true,
            onError: () =>
                setLabOrderValidationAttempt((attempt) => attempt + 1),
            onSuccess: () => labForm.reset('clinical_question'),
        });
    };

    const tabIsLive = (tab: ClinicalTab) => {
        if (tab === 'Asesmen' || tab === 'Riwayat') {
            return true;
        }

        if (tab === 'Order Lab') {
            return laboratory !== undefined || isOutpatient;
        }

        return tab === 'Order Rad' && radiology !== undefined;
    };

    const tabId = (tab: ClinicalTab) => `clinical-tab-${clinicalTabSlug[tab]}`;
    const tabPanelId = (tab: ClinicalTab) =>
        `clinical-tabpanel-${clinicalTabSlug[tab]}`;
    const handleTabKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        tab: ClinicalTab,
    ) => {
        const liveTabs = clinicalTabs.filter(tabIsLive);
        const currentIndex = liveTabs.indexOf(tab);
        let nextTab: ClinicalTab | undefined;

        if (event.key === 'ArrowRight') {
            nextTab = liveTabs[(currentIndex + 1) % liveTabs.length];
        } else if (event.key === 'ArrowLeft') {
            nextTab =
                liveTabs[
                    (currentIndex - 1 + liveTabs.length) % liveTabs.length
                ];
        } else if (event.key === 'Home') {
            nextTab = liveTabs[0];
        } else if (event.key === 'End') {
            nextTab = liveTabs[liveTabs.length - 1];
        }

        if (nextTab) {
            event.preventDefault();
            setActiveTab(nextTab);
            document.getElementById(tabId(nextTab))?.focus();
        }
    };

    return (
        <>
            <Head
                title={`Clinical Care — ${encounter.patient.full_name ?? 'Encounter'}`}
            />

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5 md:py-5">
                {typeof flash?.error === 'string' && flash.error !== '' ? (
                    <div
                        role="alert"
                        aria-live="assertive"
                        aria-atomic="true"
                        className="rounded-md border border-[#fecaca] bg-[#fef2f2] px-3 py-2 text-sm text-[#991b1b]"
                    >
                        {flash.error}
                    </div>
                ) : null}
                {typeof flash?.success === 'string' && flash.success !== '' ? (
                    <div
                        role="status"
                        aria-live="polite"
                        aria-atomic="true"
                        className="rounded-md border border-[#bbf7d0] bg-[#f0fdf4] px-3 py-2 text-sm text-[#166534]"
                    >
                        {flash.success}
                    </div>
                ) : null}

                <div>
                    <Link
                        href={indexPath}
                        className="text-sm font-medium text-[#1b75bc] hover:underline"
                    >
                        ← Back to the{' '}
                        {isInpatient
                            ? 'inpatient worklist'
                            : isIgd
                              ? 'emergency worklist'
                              : 'outpatient worklist'}
                    </Link>
                </div>

                <header className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-semibold tracking-tight text-[#0f172a]">
                                    {encounter.patient.full_name}
                                </h1>
                                <span className="rounded-md bg-[#f1f5f9] px-2 py-0.5 text-[0.7rem] font-semibold text-[#123b63]">
                                    {statusLabel[encounter.status] ??
                                        encounter.status}
                                </span>
                                {encounter.queue_number != null ? (
                                    <span className="rounded-md bg-[#e8f2fa] px-2 py-0.5 font-mono text-[0.7rem] font-semibold text-[#123b63]">
                                        Queue {encounter.queue_number}
                                    </span>
                                ) : null}
                            </div>
                            <p className="mt-1 font-mono text-xs text-[#64748b]">
                                {encounter.patient.medical_record_number}
                                {encounter.patient.nik
                                    ? ` · NIK ${encounter.patient.nik}`
                                    : ''}
                                {isIgd && encounter.case_type
                                    ? ` · Kasus ${encounter.case_type}`
                                    : ''}
                                {isInpatient && encounter.bed_code
                                    ? ` · TT ${encounter.bed_code}`
                                    : ''}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {(
                                [
                                    'Cetak',
                                    'EMR history',
                                    'Order',
                                    'Resep',
                                ] as const
                            ).map((label) => (
                                <button
                                    key={label}
                                    type="button"
                                    disabled
                                    className="inline-flex h-8 items-center rounded-md border border-[#c5d9eb] bg-[#f8fbfe] px-2.5 text-xs font-medium text-[#64748b] opacity-70"
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-[0.65rem] tracking-wide text-[#64748b] uppercase">
                                Tgl lahir / JK
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.patient.date_of_birth ?? '—'}
                                {' · '}
                                {encounter.patient.sex
                                    ? (sexLabel[encounter.patient.sex] ??
                                      encounter.patient.sex)
                                    : '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[0.65rem] tracking-wide text-[#64748b] uppercase">
                                {isInpatient
                                    ? 'Ward / Class'
                                    : 'Clinic / Physician'}
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {isInpatient
                                    ? `${encounter.ward_name ?? encounter.clinic_name} · ${encounter.ward_class ?? '—'}`
                                    : encounter.clinic_name}
                                {!isInpatient && encounter.doctor_name
                                    ? ` · ${encounter.doctor_name}`
                                    : ''}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[0.65rem] tracking-wide text-[#64748b] uppercase">
                                {isInpatient
                                    ? 'Bed / Payer'
                                    : 'Schedule / Payer'}
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {isInpatient
                                    ? (encounter.bed_code ??
                                      encounter.schedule_label ??
                                      '—')
                                    : (encounter.schedule_label ?? '—')}
                                {' · '}
                                {payerLabel[encounter.payer_type] ??
                                    encounter.payer_type}
                                {isInpatient && encounter.continue_from
                                    ? ` · ${continueLabel[encounter.continue_from] ?? encounter.continue_from}`
                                    : ''}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-[0.65rem] tracking-wide text-[#64748b] uppercase">
                                Chief complaint
                            </dt>
                            <dd className="font-medium text-[#0f172a]">
                                {encounter.chief_complaint || '—'}
                            </dd>
                        </div>
                    </dl>
                </header>

                <div
                    role="tablist"
                    aria-label="Clinical care sections"
                    className="flex flex-wrap gap-1 border-b border-[#e2e8f0] pb-px"
                >
                    {clinicalTabs.map((tab) => {
                        const live = tabIsLive(tab);

                        return (
                            <button
                                key={tab}
                                id={tabId(tab)}
                                type="button"
                                role="tab"
                                disabled={!live}
                                aria-disabled={!live}
                                aria-selected={live && activeTab === tab}
                                aria-controls={
                                    live ? tabPanelId(tab) : undefined
                                }
                                tabIndex={live && activeTab === tab ? 0 : -1}
                                onClick={() => live && setActiveTab(tab)}
                                onKeyDown={(event) =>
                                    live && handleTabKeyDown(event, tab)
                                }
                                className={cn(
                                    'rounded-t-md px-3 py-1.5 text-xs font-medium',
                                    activeTab === tab && live
                                        ? 'ring-b-white bg-white text-[#1b75bc] ring-1 ring-[#e2e8f0]'
                                        : 'text-[#64748b]',
                                    !live && 'cursor-not-allowed opacity-50',
                                )}
                            >
                                {clinicalTabLabel[tab]}
                            </button>
                        );
                    })}
                </div>

                {activeTab === 'Order Lab' && laboratory ? (
                    <div
                        id={tabPanelId('Order Lab')}
                        role="tabpanel"
                        aria-labelledby={tabId('Order Lab')}
                    >
                        <LaboratoryEncounterPanel projection={laboratory} />
                    </div>
                ) : activeTab === 'Order Lab' && isOutpatient ? (
                    <div
                        id={tabPanelId('Order Lab')}
                        role="tabpanel"
                        aria-labelledby={tabId('Order Lab')}
                        className="grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]"
                    >
                        <section className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                    Order laboratorium
                                </h2>
                                <Link
                                    href="/pemeriksaan/laboratorium"
                                    className="text-xs font-medium text-[#1b75bc] hover:underline"
                                >
                                    Open laboratory desk →
                                </Link>
                            </div>
                            <div className="mt-3 space-y-2">
                                {labOrders.length === 0 ? (
                                    <p className="text-sm text-[#64748b]">
                                        No laboratory orders for this encounter.
                                    </p>
                                ) : (
                                    labOrders.map((order) => (
                                        <article
                                            key={order.public_id}
                                            className="rounded-md border border-[#e2e8f0] bg-[#f8fafc] p-3"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <h3 className="text-sm font-semibold text-[#0f172a]">
                                                    {order.test_label}
                                                    <span className="ml-2 font-mono text-xs font-normal text-[#64748b]">
                                                        {order.test_code}
                                                    </span>
                                                </h3>
                                                <span className="rounded-md bg-[#e8f2fa] px-2 py-0.5 text-[0.65rem] font-semibold text-[#123b63]">
                                                    {labOrderStatusLabel[
                                                        order.status
                                                    ] ?? order.status}
                                                </span>
                                            </div>
                                            {order.clinical_question ? (
                                                <p className="mt-2 text-xs text-[#64748b]">
                                                    Clinical:{' '}
                                                    {order.clinical_question}
                                                </p>
                                            ) : null}
                                            <p className="mt-1 text-xs text-[#64748b]">
                                                {order.requested_by_name}
                                                {' · '}
                                                {new Date(
                                                    order.requested_at,
                                                ).toLocaleString('en-GB')}
                                            </p>
                                            {order.result ? (
                                                <div className="mt-3 rounded-md border border-[#bbf7d0] bg-[#f0fdf4] p-2.5">
                                                    <p className="text-xs font-semibold text-[#166534]">
                                                        Result (
                                                        {labResultStatusLabel[
                                                            order.result.status
                                                        ] ??
                                                            order.result.status}
                                                        )
                                                    </p>
                                                    <p className="mt-1 text-sm whitespace-pre-wrap text-[#0f172a]">
                                                        {
                                                            order.result
                                                                .result_text
                                                        }
                                                    </p>
                                                    <p className="mt-1 text-xs text-[#64748b]">
                                                        {
                                                            order.result
                                                                .entered_by_name
                                                        }
                                                        {' · '}
                                                        {new Date(
                                                            order.result
                                                                .issued_at,
                                                        ).toLocaleString(
                                                            'en-GB',
                                                        )}
                                                    </p>
                                                </div>
                                            ) : null}
                                        </article>
                                    ))
                                )}
                            </div>
                        </section>

                        {canCreateLabOrder && !closed ? (
                            <section className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                    Buat order lab
                                </h2>
                                <form
                                    onSubmit={submitLabOrder}
                                    className="mt-3 space-y-3"
                                >
                                    {labOrderErrors.length > 0 ? (
                                        <div
                                            ref={labOrderErrorSummaryRef}
                                            role="alert"
                                            tabIndex={-1}
                                            aria-labelledby="lab-order-error-summary-title"
                                            className="rounded-md border border-[#fecaca] bg-[#fef2f2] p-3 text-sm text-[#991b1b] focus-visible:ring-2 focus-visible:ring-[#b91c1c] focus-visible:ring-offset-2 focus-visible:outline-none"
                                        >
                                            <p
                                                id="lab-order-error-summary-title"
                                                className="font-semibold"
                                            >
                                                The laboratory request could not
                                                be saved.
                                            </p>
                                            <ul className="mt-1 list-disc space-y-0.5 pl-5">
                                                {labOrderErrors.map(
                                                    ([field, message]) => {
                                                        const target =
                                                            labOrderErrorTargets[
                                                                field
                                                            ];

                                                        return (
                                                            <li key={field}>
                                                                {target ? (
                                                                    <a
                                                                        href={`#${target.id}`}
                                                                        className="underline underline-offset-2"
                                                                        onClick={(
                                                                            event,
                                                                        ) => {
                                                                            event.preventDefault();
                                                                            document
                                                                                .getElementById(
                                                                                    target.id,
                                                                                )
                                                                                ?.focus();
                                                                        }}
                                                                    >
                                                                        {
                                                                            target.label
                                                                        }
                                                                        :{' '}
                                                                        {
                                                                            message
                                                                        }
                                                                    </a>
                                                                ) : (
                                                                    message
                                                                )}
                                                            </li>
                                                        );
                                                    },
                                                )}
                                            </ul>
                                        </div>
                                    ) : null}
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="test_code">
                                            Examination
                                        </Label>
                                        <select
                                            id="test_code"
                                            className="h-8 rounded-md border border-input bg-white px-2.5 text-sm"
                                            value={labForm.data.test_code}
                                            aria-invalid={
                                                labForm.errors.test_code
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                labForm.errors.test_code
                                                    ? 'lab-order-test-code-error'
                                                    : undefined
                                            }
                                            onChange={(e) =>
                                                labForm.setData(
                                                    'test_code',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        >
                                            {labTestOptions.map((test) => (
                                                <option
                                                    key={test.code}
                                                    value={test.code}
                                                >
                                                    {test.label} ({test.code})
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            id="lab-order-test-code-error"
                                            message={labForm.errors.test_code}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="clinical_question">
                                            Clinical question (optional)
                                        </Label>
                                        <textarea
                                            id="clinical_question"
                                            className="min-h-24 rounded-md border border-input bg-white px-2.5 py-2 text-sm"
                                            value={
                                                labForm.data.clinical_question
                                            }
                                            aria-invalid={
                                                labForm.errors.clinical_question
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                labForm.errors.clinical_question
                                                    ? 'lab-order-clinical-question-error'
                                                    : undefined
                                            }
                                            onChange={(e) =>
                                                labForm.setData(
                                                    'clinical_question',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Example: anemia evaluation, diabetes follow-up…"
                                        />
                                        <InputError
                                            id="lab-order-clinical-question-error"
                                            message={
                                                labForm.errors.clinical_question
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            labForm.processing ||
                                            !labForm.data.test_code
                                        }
                                        className="w-full bg-[#1b75bc] hover:bg-[#1665a3]"
                                    >
                                        Save laboratory order
                                    </Button>
                                </form>
                            </section>
                        ) : (
                            <section className="rounded-lg border border-dashed border-[#e2e8f0] bg-[#f8fafc] p-4 text-sm text-[#64748b]">
                                {closed
                                    ? 'The encounter is closed — laboratory orders cannot be added.'
                                    : 'This account cannot create laboratory requests.'}
                            </section>
                        )}
                    </div>
                ) : null}

                {activeTab === 'Order Rad' && radiology ? (
                    <div
                        id={tabPanelId('Order Rad')}
                        role="tabpanel"
                        aria-labelledby={tabId('Order Rad')}
                    >
                        <RadiologyEncounterPanel projection={radiology} />
                    </div>
                ) : null}

                {(activeTab === 'Asesmen' || activeTab === 'Riwayat') && (
                    <div
                        id={tabPanelId(activeTab)}
                        role="tabpanel"
                        aria-labelledby={tabId(activeTab)}
                        className="grid gap-3 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]"
                    >
                        <section className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                            <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                Clinical notes
                            </h2>
                            <div className="mt-3 space-y-2">
                                {encounter.entries.length === 0 ? (
                                    <p className="text-sm text-[#64748b]">
                                        No notes yet.
                                    </p>
                                ) : (
                                    encounter.entries.map((entry) => (
                                        <article
                                            key={entry.public_id}
                                            className="rounded-md border border-[#e2e8f0] bg-[#f8fafc] p-3"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <h3 className="text-sm font-semibold text-[#0f172a]">
                                                    {entryTypeLabel[
                                                        entry.entry_type
                                                    ] ?? entry.entry_type}
                                                </h3>
                                                <p className="text-xs text-[#64748b]">
                                                    {entry.author_name}
                                                    {entry.created_at
                                                        ? ` · ${new Date(entry.created_at).toLocaleString('en-GB')}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <p className="mt-2 text-sm leading-relaxed whitespace-pre-wrap text-[#0f172a]">
                                                {entry.body}
                                            </p>
                                        </article>
                                    ))
                                )}
                            </div>
                        </section>

                        {canWrite && !closed ? (
                            <section className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                                <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                    Add assessment
                                </h2>
                                <form
                                    onSubmit={submit}
                                    className="mt-3 space-y-3"
                                >
                                    {entryErrors.length > 0 ? (
                                        <div
                                            ref={entryErrorSummaryRef}
                                            role="alert"
                                            tabIndex={-1}
                                            aria-labelledby="clinical-entry-error-summary-title"
                                            className="rounded-md border border-[#fecaca] bg-[#fef2f2] p-3 text-sm text-[#991b1b] focus-visible:ring-2 focus-visible:ring-[#b91c1c] focus-visible:ring-offset-2 focus-visible:outline-none"
                                        >
                                            <p
                                                id="clinical-entry-error-summary-title"
                                                className="font-semibold"
                                            >
                                                The clinical note could not be
                                                saved.
                                            </p>
                                            <ul className="mt-1 list-disc space-y-0.5 pl-5">
                                                {entryErrors.map(
                                                    ([field, message]) => {
                                                        const target =
                                                            entryErrorTargets[
                                                                field
                                                            ];

                                                        return (
                                                            <li key={field}>
                                                                {target ? (
                                                                    <a
                                                                        href={`#${target.id}`}
                                                                        className="underline underline-offset-2"
                                                                        onClick={(
                                                                            event,
                                                                        ) => {
                                                                            event.preventDefault();
                                                                            document
                                                                                .getElementById(
                                                                                    target.id,
                                                                                )
                                                                                ?.focus();
                                                                        }}
                                                                    >
                                                                        {
                                                                            target.label
                                                                        }
                                                                        :{' '}
                                                                        {
                                                                            message
                                                                        }
                                                                    </a>
                                                                ) : (
                                                                    message
                                                                )}
                                                            </li>
                                                        );
                                                    },
                                                )}
                                            </ul>
                                        </div>
                                    ) : null}
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="entry_type">
                                            Note type
                                        </Label>
                                        <select
                                            id="entry_type"
                                            className="h-8 rounded-md border border-input bg-white px-2.5 text-sm"
                                            value={form.data.entry_type}
                                            aria-invalid={
                                                form.errors.entry_type
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                form.errors.entry_type
                                                    ? 'clinical-entry-type-error'
                                                    : undefined
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'entry_type',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        >
                                            {allowedOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            id="clinical-entry-type-error"
                                            message={form.errors.entry_type}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="body">
                                            Note content
                                        </Label>
                                        <textarea
                                            id="body"
                                            className="min-h-36 rounded-md border border-input bg-white px-2.5 py-2 text-sm"
                                            value={form.data.body}
                                            aria-invalid={
                                                form.errors.body
                                                    ? true
                                                    : undefined
                                            }
                                            aria-describedby={
                                                form.errors.body
                                                    ? 'clinical-entry-body-error'
                                                    : undefined
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'body',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                            placeholder="Tuliskan asesmen…"
                                        />
                                        <InputError
                                            id="clinical-entry-body-error"
                                            message={form.errors.body}
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            form.processing ||
                                            !form.data.entry_type
                                        }
                                        className="w-full bg-[#1b75bc] hover:bg-[#1665a3]"
                                    >
                                        Save note
                                    </Button>
                                </form>
                            </section>
                        ) : (
                            <section className="rounded-lg border border-dashed border-[#e2e8f0] bg-[#f8fafc] p-4 text-sm text-[#64748b]">
                                {variant === 'igd'
                                    ? 'Legacy emergency notes remain available as read-only history. New entries use structured emergency documentation.'
                                    : closed
                                      ? 'This visit is closed; no notes can be added.'
                                      : 'This account cannot write clinical notes.'}
                            </section>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

LegacyFreeTextEncounterShow.layout = (props: Props) => {
    const indexPath =
        props.indexPath ??
        (props.variant === 'igd'
            ? '/pemeriksaan/igd'
            : props.variant === 'rawat-inap'
              ? '/pemeriksaan/rawat-inap'
              : '/pemeriksaan/rawat-jalan');
    const showPrefix =
        props.showPathPrefix ??
        (props.variant === 'igd'
            ? '/pemeriksaan/igd'
            : props.variant === 'rawat-inap'
              ? '/pemeriksaan/rawat-inap'
              : '/pemeriksaan/rawat-jalan');

    return {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            {
                title: 'Examination',
                href: indexPath,
            },
            {
                title: props.encounter.patient.full_name ?? 'Detail',
                href: `${showPrefix}/${props.encounter.public_id}`,
            },
        ] satisfies BreadcrumbItem[],
    };
};
