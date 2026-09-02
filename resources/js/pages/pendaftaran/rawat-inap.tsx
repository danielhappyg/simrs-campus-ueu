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
    REGISTERED: 'Terdaftar',
    IN_EXAMINATION: 'Pemeriksaan',
    READY_FOR_RM: 'Siap RM',
    CLOSED: 'Ditutup',
    CANCELLED: 'Dibatalkan',
};

const statusChipClass: Record<string, string> = {
    REGISTERED: 'bg-[#e8f2fa] text-[#123b63]',
    IN_EXAMINATION: 'bg-[#fff4eb] text-[#c2410c]',
    READY_FOR_RM: 'bg-[#ecfdf5] text-[#047857]',
    CLOSED: 'bg-[#f1f5f9] text-[#64748b]',
    CANCELLED: 'bg-[#fef2f2] text-[#b42318] ring-1 ring-inset ring-[#fecaca]',
};

const cancellationReasons = [
    { value: 'SALAH_PENDAFTARAN', label: 'Salah pendaftaran' },
    { value: 'DUPLIKAT_KUNJUNGAN', label: 'Duplikat kunjungan' },
    {
        value: 'PASIEN_TIDAK_MELANJUTKAN',
        label: 'Pasien tidak melanjutkan',
    },
    {
        value: 'PERUBAHAN_RENCANA_SEBELUM_PELAYANAN',
        label: 'Perubahan rencana sebelum pelayanan',
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
        return 'Waktu tidak tersedia';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

const payerLabel: Record<string, string> = {
    UMUM: 'Umum',
    BPJS: 'BPJS',
    LAINNYA: 'Lainnya',
};

const continueLabel: Record<string, string> = {
    LANGSUNG: 'Langsung',
    DARI_IGD: 'Dari IGD',
    DARI_RJ: 'Dari RJ',
};

const sexLabel: Record<string, string> = {
    LAKI_LAKI: 'Laki-laki',
    PEREMPUAN: 'Perempuan',
    TIDAK_DIKETAHUI: 'Tidak diketahui',
};

const fieldClass =
    'border-input h-8 w-full rounded-md border bg-white px-2.5 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30 disabled:cursor-not-allowed disabled:opacity-60';

const registrationFieldLabels: Record<string, string> = {
    patient_public_id: 'Pasien terpilih',
    full_name: 'Nama lengkap',
    date_of_birth: 'Tanggal lahir',
    sex: 'Jenis kelamin',
    nik: 'NIK',
    phone: 'Telepon',
    ward_name: 'Bangsal',
    ward_class: 'Kelas',
    bed_code: 'Tempat tidur',
    bed_public_id: 'Tempat tidur',
    payer_type: 'Cara bayar',
    insurance_number: 'Nomor penjamin',
    continue_from: 'Asal atau kelanjutan',
    chief_complaint: 'Keluhan utama',
    is_synthetic: 'Validasi data pasien',
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
    const cancelTriggerRef = useRef<HTMLButtonElement | null>(null);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const admissionWards = wards.filter((ward) => ward.beds.length > 0);
    const initialWard = admissionWards[0];
    const initialBed = initialWard?.beds[0];

    const form = useForm({
        patient_public_id: '',
        full_name: '',
        date_of_birth: '',
        sex: sexOptions[0]?.value ?? 'LAKI_LAKI',
        nik: '',
        phone: '',
        ward_name: initialWard?.display_name ?? '',
        ward_class: initialBed?.service_class ?? '',
        bed_code: initialBed?.code ?? '',
        bed_public_id: initialBed?.public_id ?? '',
        payer_type: payerOptions[0]?.value ?? 'UMUM',
        insurance_number: '',
        continue_from: continueFromOptions[0]?.value ?? 'LANGSUNG',
        chief_complaint: '',
        is_synthetic: true,
    });

    const cancelForm = useForm({
        reason_code: '',
        note: '',
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
            sex: sexOptions[0]?.value ?? 'LAKI_LAKI',
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
                        'Kunjungan berhasil dibatalkan.',
                    );
                    setCancelDialogOpen(false);
                    setCancelTarget(null);
                    cancelForm.reset();
                },
                onError: (errors) => {
                    setCancellationAnnouncement(
                        errors.cancellation ??
                            'Pembatalan belum dapat disimpan. Periksa alasan dan catatan pembatalan.',
                    );
                },
            },
        );
    };

    return (
        <>
            <Head title="Pendaftaran Rawat Inap" />

            <p className="sr-only" role="status" aria-live="polite">
                {cancellationAnnouncement}
            </p>

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-4 px-3 py-4 md:px-5 md:py-5">
                <CareSettingSubnav
                    items={[
                        {
                            href: '/pendaftaran/rawat-jalan',
                            label: 'Rawat Jalan',
                        },
                        {
                            href: '/pendaftaran/igd',
                            label: 'IGD',
                        },
                        {
                            href: '/pendaftaran/rawat-inap',
                            label: 'Rawat Inap',
                            active: true,
                        },
                        {
                            href: '/pendaftaran/rekap',
                            label: 'Rekap',
                        },
                    ]}
                />

                <header className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                            Data Pasien · Pendaftaran Rawat Inap
                        </h1>
                        <p className="mt-0.5 text-xs text-[#64748b]">
                            Kelola identitas pasien, admisi, bangsal, kelas, dan
                            tempat tidur rawat inap.
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
                                    Lihat ketersediaan TT
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
                                Pasien baru
                            </Button>
                        ) : null}
                    </div>
                </header>

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
                                Cari pasien (No.RM / NIK / Nama)
                            </Label>
                            <Input
                                id="patient-search"
                                className={cn(fieldClass, 'bg-white')}
                                value={searchQ}
                                onChange={(e) => setSearchQ(e.target.value)}
                                placeholder="No.RM / NIK / Nama"
                            />
                        </div>
                        <Button type="submit" size="sm">
                            Cari
                        </Button>
                    </div>
                </form>

                {searchResults.length > 0 ? (
                    <section className="min-w-0 rounded-lg border border-[#e2e8f0] bg-white p-3">
                        <h2 className="text-sm font-semibold text-[#0f172a]">
                            Hasil pencarian pasien
                        </h2>
                        <div className="mt-2 min-w-0 overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-left text-sm">
                                <caption className="sr-only">
                                    Hasil pencarian pasien untuk rawat inap
                                </caption>
                                <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                    <tr>
                                        <th className="px-2 py-1.5">No. RM</th>
                                        <th className="px-2 py-1.5">Nama</th>
                                        <th className="px-2 py-1.5">
                                            Tgl lahir
                                        </th>
                                        <th className="px-2 py-1.5">JK</th>
                                        <th scope="col" className="px-2 py-1.5">
                                            <span className="sr-only">
                                                Aksi
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
                                                    Pilih
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
                        Form masuk rawat inap
                    </h2>
                    <p className="mt-0.5 text-xs text-[#64748b]">
                        Identitas ringkas + bangsal → kelas → tempat tidur.
                    </p>

                    {admissionWards.length === 0 ? (
                        <p
                            role="status"
                            aria-live="polite"
                            className="mt-3 rounded-md border border-[#fed7aa] bg-[#fff7ed] px-3 py-2 text-sm text-[#9a3412]"
                        >
                            Tidak ada tempat tidur rawat inap yang tersedia.
                            Periksa ketersediaan atau hubungi pengelola bangsal.
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
                                Pendaftaran rawat inap belum dapat disimpan.
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
                                    <Label htmlFor="full_name">
                                        Nama lengkap
                                    </Label>
                                    <Input
                                        id="full_name"
                                        {...errorProps('full_name')}
                                        className={fieldClass}
                                        value={form.data.full_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'full_name',
                                                e.target.value,
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
                                        Tanggal lahir
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
                                    <Label htmlFor="sex">Jenis kelamin</Label>
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
                                    form.setData('nik', e.target.value)
                                }
                                disabled={!canRegister}
                            />
                            <InputError
                                id="nik-error"
                                message={form.errors.nik}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="phone">Telepon</Label>
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
                            <Label htmlFor="ward_name">Bangsal</Label>
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
                            <Label htmlFor="ward_class">Kelas</Label>
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
                            <Label htmlFor="bed_code">Tempat tidur</Label>
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
                            <Label htmlFor="payer_type">Cara bayar</Label>
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
                                No. penjamin
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
                                Asal / kelanjutan
                            </Label>
                            <select
                                id="continue_from"
                                {...errorProps('continue_from')}
                                className={fieldClass}
                                value={form.data.continue_from}
                                onChange={(e) =>
                                    form.setData(
                                        'continue_from',
                                        e.target.value,
                                    )
                                }
                                disabled={!canRegister}
                            >
                                {continueFromOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                id="continue_from-error"
                                message={form.errors.continue_from}
                            />
                        </div>

                        <div className="grid gap-1 md:col-span-2 lg:col-span-3">
                            <Label htmlFor="chief_complaint">
                                Keluhan utama
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
                                ? 'Menyimpan…'
                                : 'Simpan pendaftaran RI'}
                        </Button>
                        {!canRegister ? (
                            <p className="text-xs text-[#64748b]">
                                Akun ini tidak memiliki izin pendaftaran.
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
                                No. RM / Nama
                            </label>
                            <Input
                                id="inpatient-filter-q"
                                className={cn(fieldClass, 'bg-white')}
                                value={filterQ}
                                onChange={(e) => setFilterQ(e.target.value)}
                                placeholder="No.RM / Nama"
                            />
                        </div>
                        <div className="grid min-w-[10rem] gap-1">
                            <label
                                htmlFor="inpatient-filter-ward"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Bangsal
                            </label>
                            <select
                                id="inpatient-filter-ward"
                                className={fieldClass}
                                value={filterWard}
                                onChange={(e) => setFilterWard(e.target.value)}
                            >
                                <option value="">Semua bangsal</option>
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
                                Cara bayar
                            </label>
                            <select
                                id="inpatient-filter-payer"
                                className={fieldClass}
                                value={filterPayer}
                                onChange={(e) => setFilterPayer(e.target.value)}
                            >
                                <option value="">Semua</option>
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
                                Asal
                            </label>
                            <select
                                id="inpatient-filter-origin"
                                className={fieldClass}
                                value={filterContinue}
                                onChange={(e) =>
                                    setFilterContinue(e.target.value)
                                }
                            >
                                <option value="">Semua</option>
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
                                Dari tgl
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
                                Sampai tgl
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
                            Terapkan filter
                        </Button>
                    </div>
                </form>

                <section className="min-w-0 rounded-lg border border-[#e2e8f0] bg-white p-3">
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold text-[#0f172a]">
                            Daftar masuk rawat inap
                        </h2>
                        <Link
                            href="/pemeriksaan/rawat-inap"
                            className="text-xs font-medium text-[#1b75bc] hover:underline"
                        >
                            Buka worklist pemeriksaan →
                        </Link>
                    </div>
                    <div className="min-w-0 overflow-x-auto">
                        <table className="w-full min-w-[56rem] text-left text-sm">
                            <caption className="sr-only">
                                Daftar pendaftaran rawat inap
                            </caption>
                            <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th className="px-2 py-1.5">Antrian</th>
                                    <th className="px-2 py-1.5">No. RM</th>
                                    <th className="px-2 py-1.5">Nama</th>
                                    <th className="px-2 py-1.5">Bangsal</th>
                                    <th className="px-2 py-1.5">Kelas</th>
                                    <th className="px-2 py-1.5">TT</th>
                                    <th className="px-2 py-1.5">Asal</th>
                                    <th className="px-2 py-1.5">Penjamin</th>
                                    <th className="px-2 py-1.5">Status</th>
                                    <th className="px-2 py-1.5">Keluhan</th>
                                    <th scope="col" className="px-2 py-1.5">
                                        <span className="sr-only">Aksi</span>
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
                                            Belum ada pendaftaran rawat inap
                                            untuk filter ini.
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
                                                                'Petugas tidak tersedia'}{' '}
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
                                                            Tempat tidur
                                                            tersedia kembali;
                                                            riwayat penempatan
                                                            tetap disimpan.
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
                                                            aria-label={`Buka pemeriksaan untuk ${encounter.patient.full_name}`}
                                                            className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                        >
                                                            Buka pemeriksaan
                                                        </Link>
                                                    ) : null}
                                                    {encounter.status !==
                                                    'CANCELLED' ? (
                                                        <a
                                                            href={`/pendaftaran/kunjungan/${encounter.public_id}/cetak?docs=bukti,antrian`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            aria-label={`Cetak untuk ${encounter.patient.full_name}`}
                                                            className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                        >
                                                            Cetak
                                                        </a>
                                                    ) : (
                                                        <span className="text-xs text-[#64748b]">
                                                            Riwayat tersimpan
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
                                                                    : 'Hanya kunjungan berstatus Terdaftar yang dapat dibatalkan.'
                                                            }
                                                            aria-label={`Batalkan kunjungan ${encounter.patient.full_name}`}
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
                                                            Batalkan Kunjungan
                                                        </button>
                                                    ) : null}
                                                    {canCancel &&
                                                    encounter.status !==
                                                        'REGISTERED' ? (
                                                        <span
                                                            id={`inpatient-cancel-blocked-${encounter.public_id}`}
                                                            className="sr-only"
                                                        >
                                                            Hanya kunjungan
                                                            berstatus Terdaftar
                                                            yang dapat
                                                            dibatalkan.
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
                                Pembatalan pra-pelayanan
                            </p>
                            <DialogTitle className="text-xl leading-7 text-[#0f172a]">
                                Batalkan kunjungan sebelum pelayanan?
                            </DialogTitle>
                            <DialogDescription className="leading-5 text-[#475569]">
                                Tindakan ini menyimpan pembatalan dan riwayat
                                penempatan. Kunjungan yang sudah mulai dilayani
                                tidak dapat dibatalkan dari meja pendaftaran.
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
                                            Pasien
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
                                            Penempatan dan antrian
                                        </p>
                                        <p className="mt-0.5 font-medium text-[#0f172a]">
                                            {cancelTarget.ward_name ?? '—'} ·{' '}
                                            {cancelTarget.bed_code ?? '—'}
                                        </p>
                                        <p className="text-xs text-[#64748b]">
                                            Antrian{' '}
                                            {cancelTarget.queue_number ?? '—'}
                                        </p>
                                    </div>
                                    <p className="border-t border-[#d7e6f3] pt-2 text-xs leading-5 text-[#475569] sm:col-span-2">
                                        Tempat tidur tersedia kembali setelah
                                        pembatalan berhasil. Nomor antrian dan
                                        riwayat penempatan tetap tersimpan.
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
                                        Alasan pembatalan
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
                                            Pilih alasan pembatalan
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
                                        Pilih alasan yang paling sesuai dengan
                                        kejadian pendaftaran.
                                    </p>
                                    <InputError
                                        id="inpatient-cancellation-reason-error"
                                        message={cancelForm.errors.reason_code}
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="inpatient-cancellation-note">
                                        Catatan pembatalan{' '}
                                        <span className="font-normal text-[#64748b]">
                                            (opsional)
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
                                        Maksimal 500 karakter. Hindari data
                                        pribadi yang tidak diperlukan.
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
                                        Kembali
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
                                            ? 'Menyimpan…'
                                            : 'Batalkan Kunjungan'}
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
        { title: 'Beranda', href: '/' },
        { title: 'Pendaftaran', href: '/pendaftaran/rawat-inap' },
        { title: 'Rawat Inap', href: '/pendaftaran/rawat-inap' },
    ] satisfies BreadcrumbItem[],
});
