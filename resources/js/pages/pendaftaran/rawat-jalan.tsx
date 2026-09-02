import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { cloneElement, useEffect, useMemo, useRef, useState } from 'react';
import type { AriaAttributes, FormEvent, ReactElement, ReactNode } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import InputError from '@/components/input-error';
import { OperationalPagination } from '@/components/operational-pagination';
import type { OperationalPaginationMeta } from '@/components/operational-pagination';
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

type ScheduleOption = {
    public_id: string;
    label: string;
    day_label: string | null;
};

type DoctorOption = {
    public_id: string;
    name: string;
    specialty: string | null;
    schedules: ScheduleOption[];
};

type ClinicOption = {
    public_id: string;
    code: string;
    name: string;
    doctors: DoctorOption[];
};

type PatientRow = {
    public_id: string;
    medical_record_number: string;
    nik: string | null;
    full_name: string;
    place_of_birth: string | null;
    date_of_birth: string | null;
    sex: string;
    religion: string | null;
    education: string | null;
    occupation: string | null;
    province_code: string | null;
    province: string | null;
    city_code: string | null;
    city: string | null;
    district_code: string | null;
    district: string | null;
    village_code: string | null;
    village: string | null;
    address_line: string | null;
    domicile: string | null;
    phone: string | null;
    email: string | null;
    ethnicity: string | null;
    marital_status: string | null;
    language: string | null;
    notes: string | null;
    responsible_party_name: string | null;
};

type EncounterRow = {
    public_id: string;
    status: string;
    clinic_name: string;
    doctor_name: string | null;
    schedule_label: string | null;
    payer_type: string;
    queue_number: number | null;
    registered_at: string | null;
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

type DeskVariant = 'rawat-jalan' | 'igd';

type Props = {
    variant?: DeskVariant;
    q: string;
    searchResults: PatientRow[];
    searchResultsTruncated?: boolean;
    todaysEncounters: EncounterRow[];
    todaysEncountersPagination?: OperationalPaginationMeta | null;
    clinics: ClinicOption[];
    sexOptions: Option[];
    maritalOptions: Option[];
    religionOptions: Option[];
    educationOptions: Option[];
    occupationOptions: Option[];
    ethnicityOptions: Option[];
    languageOptions: Option[];
    payerOptions: Option[];
    admissionOptions: Option[];
    caseTypeOptions?: Option[];
    accidentTypeOptions?: Option[];
    wilayahProvinces: Option[];
    canRegister: boolean;
    canCancel?: boolean;
};

async function fetchWilayahOptions(url: string): Promise<Option[]> {
    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return [];
        }

        const payload = (await response.json()) as { options?: Option[] };

        return payload.options ?? [];
    } catch {
        return [];
    }
}

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

const sexLabel: Record<string, string> = {
    LAKI_LAKI: 'Laki-laki',
    PEREMPUAN: 'Perempuan',
    TIDAK_DIKETAHUI: 'Tidak diketahui',
};

const payerLabel: Record<string, string> = {
    UMUM: 'Umum',
    BPJS: 'BPJS',
    LAINNYA: 'Lainnya',
};

const fieldClass =
    'border-input h-8 w-full rounded-md border bg-white px-2.5 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30 disabled:cursor-not-allowed disabled:opacity-60';

const selectClass = fieldClass;

type FieldControlProps = Pick<
    AriaAttributes,
    'aria-describedby' | 'aria-invalid'
>;

type FieldChild =
    | ReactElement<FieldControlProps & { id?: string }>
    | ((controlProps: FieldControlProps) => ReactNode);

function Field({
    id,
    label,
    children,
    error,
    className,
}: {
    id: string;
    label: string;
    children: FieldChild;
    error?: string;
    className?: string;
}) {
    const errorId = `${id}-error`;
    const controlProps: FieldControlProps = {
        'aria-invalid': error ? true : undefined,
        'aria-describedby': error ? errorId : undefined,
    };
    const control =
        typeof children === 'function'
            ? children(controlProps)
            : children.props.id === id
              ? cloneElement(children, controlProps)
              : (() => {
                    throw new Error(
                        `Field ${id} requires a matching control id or a render function.`,
                    );
                })();

    return (
        <div className={cn('grid gap-1', className)}>
            <Label htmlFor={id} className="text-xs font-medium text-[#475569]">
                {label}
            </Label>
            {control}
            <InputError id={errorId} message={error} />
        </div>
    );
}

type RegistrationErrorField = {
    keys: string[];
    label: string;
    targetId?: string;
};

const registrationErrorFields: RegistrationErrorField[] = [
    {
        keys: ['patient_public_id'],
        label: 'Pasien',
        targetId: 'patient-search',
    },
    {
        keys: ['medical_record_number'],
        label: 'Nomor rekam medis',
        targetId: 'medical_record_number',
    },
    { keys: ['nik'], label: 'NIK', targetId: 'nik' },
    { keys: ['full_name'], label: 'Nama pasien', targetId: 'full_name' },
    { keys: ['sex'], label: 'Jenis kelamin', targetId: 'sex' },
    {
        keys: ['place_of_birth'],
        label: 'Tempat lahir',
        targetId: 'place_of_birth',
    },
    {
        keys: ['date_of_birth'],
        label: 'Tanggal lahir',
        targetId: 'date_of_birth',
    },
    {
        keys: ['marital_status'],
        label: 'Status pernikahan',
        targetId: 'marital_status',
    },
    { keys: ['religion'], label: 'Agama', targetId: 'religion' },
    { keys: ['education'], label: 'Pendidikan', targetId: 'education' },
    { keys: ['occupation'], label: 'Pekerjaan', targetId: 'occupation' },
    {
        keys: ['province_code', 'province'],
        label: 'Provinsi',
        targetId: 'province_code',
    },
    {
        keys: ['city_code', 'city'],
        label: 'Kabupaten/Kota',
        targetId: 'city_code',
    },
    {
        keys: ['district_code', 'district'],
        label: 'Kecamatan',
        targetId: 'district_code',
    },
    {
        keys: ['village_code', 'village'],
        label: 'Kelurahan',
        targetId: 'village_code',
    },
    { keys: ['address_line'], label: 'Dusun/Jalan', targetId: 'address_line' },
    { keys: ['domicile'], label: 'Domisili', targetId: 'domicile' },
    { keys: ['phone'], label: 'Telepon', targetId: 'phone' },
    { keys: ['email'], label: 'Email', targetId: 'email' },
    { keys: ['ethnicity'], label: 'Suku', targetId: 'ethnicity' },
    { keys: ['language'], label: 'Bahasa', targetId: 'language' },
    { keys: ['notes'], label: 'Catatan pasien', targetId: 'notes' },
    {
        keys: ['responsible_party_name'],
        label: 'Penanggung jawab',
        targetId: 'responsible_party_name',
    },
    { keys: ['booking_code'], label: 'Kode booking', targetId: 'booking_code' },
    {
        keys: ['visit_date'],
        label: 'Tanggal kunjungan',
        targetId: 'visit_date',
    },
    {
        keys: ['clinic_public_id'],
        label: 'Poliklinik atau unit',
        targetId: 'clinic_public_id',
    },
    {
        keys: ['doctor_public_id'],
        label: 'Dokter',
        targetId: 'doctor_public_id',
    },
    {
        keys: ['schedule_public_id'],
        label: 'Jadwal atau shift',
        targetId: 'schedule_public_id',
    },
    { keys: ['case_type'], label: 'Kasus tindakan', targetId: 'case_type' },
    {
        keys: ['accident_type'],
        label: 'Jenis kecelakaan',
        targetId: 'accident_type',
    },
    {
        keys: ['admission_mode'],
        label: 'Cara masuk',
        targetId: 'admission_mode',
    },
    { keys: ['payer_type'], label: 'Cara bayar', targetId: 'payer_type' },
    {
        keys: ['insurance_number'],
        label: 'Nomor asuransi',
        targetId: 'insurance_number',
    },
    {
        keys: ['chief_complaint'],
        label: 'Catatan kunjungan',
        targetId: 'chief_complaint',
    },
];

type RegistrationErrorEntry = {
    key: string;
    label: string;
    message: string;
    targetId?: string;
};

function registrationErrorEntries(
    errors: Record<string, string | undefined>,
    isIgd: boolean,
): RegistrationErrorEntry[] {
    const remainingKeys = new Set(
        Object.keys(errors).filter((key) => Boolean(errors[key])),
    );
    const entries: RegistrationErrorEntry[] = registrationErrorFields.flatMap(
        (field) => {
            const key = field.keys.find((candidate) =>
                remainingKeys.has(candidate),
            );

            if (!key) {
                return [];
            }

            remainingKeys.delete(key);
            field.keys.forEach((alias) => remainingKeys.delete(alias));

            return [
                {
                    key,
                    label: field.label,
                    message: errors[key] as string,
                    targetId:
                        isIgd && key === 'clinic_public_id'
                            ? undefined
                            : field.targetId,
                },
            ];
        },
    );

    remainingKeys.forEach((key) => {
        entries.push({
            key,
            label: 'Formulir pendaftaran',
            message: errors[key] as string,
        });
    });

    return entries;
}

function RegistrationErrorSummary({
    entries,
    focusAttempt,
}: {
    entries: RegistrationErrorEntry[];
    focusAttempt: number;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const fingerprint = entries
        .map((entry) => `${entry.key}:${entry.message}`)
        .join('|');

    useEffect(() => {
        if (entries.length > 0) {
            ref.current?.focus();
        }
    }, [entries.length, fingerprint, focusAttempt]);

    if (entries.length === 0) {
        return null;
    }

    return (
        <div
            ref={ref}
            role="alert"
            tabIndex={-1}
            aria-labelledby="registration-error-summary-title"
            className="mb-4 rounded-md border border-[#fecaca] bg-[#fef2f2] p-3 text-sm text-[#991b1b] focus-visible:ring-2 focus-visible:ring-[#b91c1c] focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <p id="registration-error-summary-title" className="font-semibold">
                Pendaftaran belum dapat disimpan.
            </p>
            <p className="mt-1">
                Periksa bagian berikut tanpa menghapus data yang sudah diisi:
            </p>
            <ul className="mt-2 list-disc space-y-1 pl-5">
                {entries.map((entry) => (
                    <li key={entry.key}>
                        {entry.targetId ? (
                            <a
                                href={`#${entry.targetId}`}
                                className="font-medium underline underline-offset-2"
                                onClick={(event) => {
                                    event.preventDefault();
                                    document
                                        .getElementById(
                                            entry.targetId as string,
                                        )
                                        ?.focus();
                                }}
                            >
                                {entry.label}: {entry.message}
                            </a>
                        ) : (
                            <>
                                <span className="font-medium">
                                    {entry.label}:
                                </span>{' '}
                                {entry.message}
                            </>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function DeskSection({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="min-w-0">
            <h2 className="mb-2 border-b border-[#e2e8f0] pb-1.5 text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                {title}
            </h2>
            <div className="grid gap-2.5">{children}</div>
        </section>
    );
}

function ActionStub({ label }: { label: string }) {
    return (
        <button
            type="button"
            disabled
            title="Belum tersedia"
            className="inline-flex h-8 items-center rounded-md border border-[#c5d9eb] bg-[#f8fbfe] px-2.5 text-xs font-medium text-[#64748b] opacity-70"
        >
            {label}
            <span className="ml-1.5 text-[0.65rem] text-[#94a3b8]">· stub</span>
        </button>
    );
}

const PRINTABLE_FLAGS = ['sep', 'gelang', 'kartu', 'consent'] as const;

function encounterPrintUrl(publicId: string, docs: string[]): string {
    const params = new URLSearchParams({ docs: docs.join(',') });

    return `/pendaftaran/kunjungan/${publicId}/cetak?${params.toString()}`;
}

export default function PendaftaranRawatJalan({
    variant = 'rawat-jalan',
    q,
    searchResults,
    searchResultsTruncated = false,
    todaysEncounters,
    todaysEncountersPagination,
    clinics,
    sexOptions,
    maritalOptions = [],
    religionOptions,
    educationOptions,
    occupationOptions,
    ethnicityOptions,
    languageOptions,
    payerOptions,
    admissionOptions,
    caseTypeOptions = [],
    accidentTypeOptions = [],
    wilayahProvinces = [],
    canRegister,
    canCancel = false,
}: Props) {
    const isIgd = variant === 'igd';
    const { flash } = usePage().props;
    const lastEncounterPublicId =
        typeof flash?.lastEncounterPublicId === 'string'
            ? flash.lastEncounterPublicId
            : null;
    const indexPath = isIgd ? '/pendaftaran/igd' : '/pendaftaran/rawat-jalan';
    const storePath = indexPath;
    const examPathPrefix = isIgd
        ? '/pemeriksaan/igd'
        : '/pemeriksaan/rawat-jalan';

    const [searchOpen, setSearchOpen] = useState(q !== '');
    const [cityOptions, setCityOptions] = useState<Option[]>([]);
    const [districtOptions, setDistrictOptions] = useState<Option[]>([]);
    const [villageOptions, setVillageOptions] = useState<Option[]>([]);
    const [validationAttempt, setValidationAttempt] = useState(0);
    const [cancelTarget, setCancelTarget] = useState<EncounterRow | null>(null);
    const [cancelDialogOpen, setCancelDialogOpen] = useState(false);
    const [cancellationAnnouncement, setCancellationAnnouncement] =
        useState('');
    const cancelTriggerRef = useRef<HTMLButtonElement | null>(null);
    const [printQueue, setPrintQueue] = useState(true);
    const [printFlags, setPrintFlags] = useState({
        sep: false,
        gelang: false,
        kartu: false,
        consent: false,
        lembarIgd: false,
        tracer: false,
        fastTrack: false,
    });

    const today = useMemo(() => new Date().toISOString().slice(0, 10), []);

    const form = useForm({
        patient_public_id: '',
        full_name: '',
        date_of_birth: '',
        sex: 'LAKI_LAKI',
        medical_record_number: '',
        nik: '',
        place_of_birth: '',
        religion: '',
        marital_status: '',
        education: '',
        occupation: '',
        province_code: '',
        city_code: '',
        district_code: '',
        village_code: '',
        province: '',
        city: '',
        district: '',
        village: '',
        address_line: '',
        domicile: '',
        phone: '',
        email: '',
        ethnicity: '',
        language: '',
        notes: '',
        responsible_party_name: '',
        clinic_public_id: '',
        doctor_public_id: '',
        schedule_public_id: '',
        visit_date: today,
        admission_mode: 'DATANG_SENDIRI',
        payer_type: 'UMUM',
        insurance_number: '',
        booking_code: '',
        chief_complaint: '',
        case_type: 'NON_BEDAH',
        accident_type: 'BUKAN_KECELAKAAN',
        is_synthetic: true,
    });

    const cancelForm = useForm({
        reason_code: '',
        note: '',
        idempotency_key: '',
    });

    useEffect(() => {
        if (!isIgd || clinics.length === 0 || form.data.clinic_public_id) {
            return;
        }

        form.setData('clinic_public_id', clinics[0].public_id);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- seed IGD clinic once
    }, [isIgd, clinics]);

    const selectedClinic = clinics.find(
        (clinic) => clinic.public_id === form.data.clinic_public_id,
    );
    const doctors = selectedClinic?.doctors ?? [];
    const selectedDoctor = doctors.find(
        (doctor) => doctor.public_id === form.data.doctor_public_id,
    );
    const schedules = selectedDoctor?.schedules ?? [];

    const visibleCityOptions = form.data.province_code ? cityOptions : [];
    const visibleDistrictOptions = form.data.city_code ? districtOptions : [];
    const visibleVillageOptions = form.data.district_code ? villageOptions : [];

    useEffect(() => {
        const provinceCode = form.data.province_code;

        if (!provinceCode) {
            return;
        }

        let cancelled = false;
        void fetchWilayahOptions(`/wilayah/regencies/${provinceCode}`).then(
            (options) => {
                if (!cancelled) {
                    setCityOptions(options);
                }
            },
        );

        return () => {
            cancelled = true;
        };
    }, [form.data.province_code]);

    useEffect(() => {
        const cityCode = form.data.city_code;

        if (!cityCode) {
            return;
        }

        let cancelled = false;
        void fetchWilayahOptions(`/wilayah/districts/${cityCode}`).then(
            (options) => {
                if (!cancelled) {
                    setDistrictOptions(options);
                }
            },
        );

        return () => {
            cancelled = true;
        };
    }, [form.data.city_code]);

    useEffect(() => {
        const districtCode = form.data.district_code;

        if (!districtCode) {
            return;
        }

        let cancelled = false;
        void fetchWilayahOptions(`/wilayah/villages/${districtCode}`).then(
            (options) => {
                if (!cancelled) {
                    setVillageOptions(options);
                }
            },
        );

        return () => {
            cancelled = true;
        };
    }, [form.data.district_code]);

    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const query = String(data.get('q') ?? '').trim();
        setSearchOpen(true);
        router.get(indexPath, query ? { q: query } : {}, {
            preserveState: true,
            replace: true,
        });
    };

    const selectPatient = (patient: PatientRow) => {
        form.setData({
            ...form.data,
            patient_public_id: patient.public_id,
            full_name: patient.full_name,
            date_of_birth: patient.date_of_birth ?? '',
            sex: patient.sex,
            medical_record_number: patient.medical_record_number,
            nik: patient.nik ?? '',
            place_of_birth: patient.place_of_birth ?? '',
            religion: patient.religion ?? '',
            marital_status: patient.marital_status ?? '',
            education: patient.education ?? '',
            occupation: patient.occupation ?? '',
            province_code: patient.province_code ?? '',
            city_code: patient.city_code ?? '',
            district_code: patient.district_code ?? '',
            village_code: patient.village_code ?? '',
            province: patient.province ?? '',
            city: patient.city ?? '',
            district: patient.district ?? '',
            village: patient.village ?? '',
            address_line: patient.address_line ?? '',
            domicile: patient.domicile ?? '',
            phone: patient.phone ?? '',
            email: patient.email ?? '',
            ethnicity: patient.ethnicity ?? '',
            language: patient.language ?? '',
            notes: patient.notes ?? '',
            responsible_party_name: patient.responsible_party_name ?? '',
        });
        setSearchOpen(false);
    };

    const clearSelectedPatient = () => {
        form.setData({
            ...form.data,
            patient_public_id: '',
            full_name: '',
            date_of_birth: '',
            sex: 'LAKI_LAKI',
            medical_record_number: '',
            nik: '',
            place_of_birth: '',
            religion: '',
            marital_status: '',
            education: '',
            occupation: '',
            province_code: '',
            city_code: '',
            district_code: '',
            village_code: '',
            province: '',
            city: '',
            district: '',
            village: '',
            address_line: '',
            domicile: '',
            phone: '',
            email: '',
            ethnicity: '',
            language: '',
            notes: '',
            responsible_party_name: '',
        });
        setCityOptions([]);
        setDistrictOptions([]);
        setVillageOptions([]);
    };

    const autoResponsibleParty = () => {
        if (form.data.full_name.trim() !== '') {
            form.setData('responsible_party_name', form.data.full_name);
        }
    };

    const autoDomicile = () => {
        const parts = [
            form.data.address_line,
            form.data.village,
            form.data.district,
            form.data.city,
            form.data.province,
        ].filter((part) => part.trim() !== '');
        form.setData('domicile', parts.join(', '));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(storePath, {
            preserveScroll: true,
            onError: () => {
                setValidationAttempt((attempt) => attempt + 1);
            },
            onSuccess: () => {
                const igdClinicId = isIgd ? (clinics[0]?.public_id ?? '') : '';
                form.reset();
                form.setData({
                    ...form.data,
                    sex: 'LAKI_LAKI',
                    visit_date: today,
                    admission_mode: 'DATANG_SENDIRI',
                    payer_type: 'UMUM',
                    case_type: 'NON_BEDAH',
                    accident_type: 'BUKAN_KECELAKAAN',
                    clinic_public_id: igdClinicId,
                    is_synthetic: true,
                });
            },
        });
    };

    const registrationErrors = registrationErrorEntries(
        form.errors as Record<string, string | undefined>,
        isIgd,
    );
    const returning = form.data.patient_public_id !== '';
    const printTargetId =
        lastEncounterPublicId ?? todaysEncounters[0]?.public_id ?? null;
    const selectedPrintDocs = [
        'bukti',
        ...(printQueue ? ['antrian'] : []),
        ...PRINTABLE_FLAGS.filter((key) => printFlags[key]),
    ];
    const openPrint = (publicId: string, docs = selectedPrintDocs) => {
        window.open(
            encounterPrintUrl(publicId, docs),
            '_blank',
            'noopener,noreferrer',
        );
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
            <Head
                title={isIgd ? 'Pendaftaran IGD' : 'Pendaftaran Rawat Jalan'}
            />

            <p className="sr-only" role="status" aria-live="polite">
                {cancellationAnnouncement}
            </p>

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-4 px-3 py-4 md:px-5 md:py-5">
                <CareSettingSubnav
                    items={[
                        {
                            href: '/pendaftaran/rawat-jalan',
                            label: 'Rawat Jalan',
                            active: !isIgd,
                        },
                        {
                            href: '/pendaftaran/igd',
                            label: 'IGD',
                            active: isIgd,
                        },
                        {
                            href: '/pendaftaran/rawat-inap',
                            label: 'Rawat Inap',
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
                            {isIgd
                                ? 'Data Pasien · Pendaftaran IGD'
                                : 'Data Pasien · Pendaftaran Rawat Jalan'}
                        </h1>
                        <p className="mt-0.5 text-xs text-[#64748b]">
                            {isIgd
                                ? 'Kelola identitas pasien dan pendaftaran kunjungan IGD.'
                                : 'Kelola identitas pasien dan pendaftaran rawat jalan.'}
                        </p>
                    </div>
                    {returning ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={clearSelectedPatient}
                        >
                            Pasien baru
                        </Button>
                    ) : null}
                </header>

                {typeof flash?.error === 'string' && flash.error !== '' ? (
                    <div
                        role="alert"
                        className="rounded-md border border-[#fecaca] bg-[#fef2f2] px-3 py-2 text-sm text-[#991b1b]"
                    >
                        {flash.error}
                    </div>
                ) : null}
                {typeof flash?.success === 'string' && flash.success !== '' ? (
                    <div
                        role="status"
                        className="rounded-md border border-[#a7f3d0] bg-[#ecfdf5] px-3 py-2 text-sm text-[#065f46]"
                    >
                        {flash.success}
                    </div>
                ) : null}

                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] px-3 py-2">
                    <ActionStub label="Riwayat" />
                    <ActionStub label="EMR" />
                    <ActionStub label="Ambil RegOn" />
                    <form
                        onSubmit={search}
                        className="flex min-w-[16rem] flex-1 items-center gap-2"
                    >
                        <Label htmlFor="patient-search" className="sr-only">
                            Cari pasien berdasarkan nama, nomor rekam medis,
                            atau NIK
                        </Label>
                        <Input
                            id="patient-search"
                            name="q"
                            defaultValue={q}
                            placeholder="Cari nama / No. RM / NIK"
                            className="h-8 bg-white"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            className="h-8 bg-[#1b75bc] hover:bg-[#1665a3]"
                        >
                            Cari Pasien
                        </Button>
                    </form>
                    <ActionStub label="Approval SEP" />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8"
                        asChild
                    >
                        <Link href="/pendaftaran/rekap">Data Kunjungan</Link>
                    </Button>
                </div>

                {searchOpen && q !== '' ? (
                    <section className="min-w-0 rounded-lg border border-[#e2e8f0] bg-white p-3">
                        <div className="mb-2 flex items-center justify-between">
                            <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                Hasil pencarian
                            </h2>
                            <button
                                type="button"
                                className="text-xs text-[#64748b] hover:text-[#0f172a]"
                                onClick={() => setSearchOpen(false)}
                            >
                                Tutup
                            </button>
                        </div>
                        <div className="min-w-0 overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-left text-sm">
                                <caption className="sr-only">
                                    Hasil pencarian pasien
                                </caption>
                                <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                    <tr>
                                        <th className="px-2 py-1.5 font-medium">
                                            No. RM
                                        </th>
                                        <th className="px-2 py-1.5 font-medium">
                                            NIK
                                        </th>
                                        <th className="px-2 py-1.5 font-medium">
                                            Nama
                                        </th>
                                        <th className="px-2 py-1.5 font-medium">
                                            Tgl. lahir
                                        </th>
                                        <th className="px-2 py-1.5 font-medium">
                                            JK
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-2 py-1.5 font-medium"
                                        >
                                            <span className="sr-only">
                                                Aksi
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {searchResults.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-2 py-3 text-[#64748b]"
                                            >
                                                Tidak ada hasil.
                                            </td>
                                        </tr>
                                    ) : (
                                        searchResults.map((patient) => (
                                            <tr
                                                key={patient.public_id}
                                                className="border-b border-[#f1f5f9]"
                                            >
                                                <td className="px-2 py-1.5 font-mono text-xs">
                                                    {
                                                        patient.medical_record_number
                                                    }
                                                </td>
                                                <td className="px-2 py-1.5 font-mono text-xs">
                                                    {patient.nik ?? '—'}
                                                </td>
                                                <td className="px-2 py-1.5">
                                                    {patient.full_name}
                                                </td>
                                                <td className="px-2 py-1.5">
                                                    {patient.date_of_birth}
                                                </td>
                                                <td className="px-2 py-1.5">
                                                    {sexLabel[patient.sex] ??
                                                        patient.sex}
                                                </td>
                                                <td className="px-2 py-1.5 text-right">
                                                    {canRegister ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className="h-7"
                                                            onClick={() =>
                                                                selectPatient(
                                                                    patient,
                                                                )
                                                            }
                                                        >
                                                            Pilih
                                                        </Button>
                                                    ) : null}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {searchResultsTruncated ? (
                            <p
                                className="mt-2 text-xs text-[#92400e]"
                                role="status"
                            >
                                Menampilkan 20 hasil pertama. Persempit
                                pencarian untuk menemukan pasien lainnya.
                            </p>
                        ) : null}
                    </section>
                ) : null}

                {canRegister ? (
                    <form
                        onSubmit={submit}
                        noValidate
                        className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4"
                    >
                        <RegistrationErrorSummary
                            entries={registrationErrors}
                            focusAttempt={validationAttempt}
                        />
                        <div className="grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)_13rem]">
                            <DeskSection title="Data pribadi">
                                <Field
                                    id="medical_record_number"
                                    label="No. Rekam Medis"
                                    error={form.errors.medical_record_number}
                                >
                                    <Input
                                        id="medical_record_number"
                                        className={fieldClass}
                                        value={form.data.medical_record_number}
                                        onChange={(e) =>
                                            form.setData(
                                                'medical_record_number',
                                                e.target.value,
                                            )
                                        }
                                        disabled={returning}
                                        placeholder="Otomatis jika kosong"
                                    />
                                </Field>
                                <Field
                                    id="nik"
                                    label="NIK"
                                    error={form.errors.nik}
                                >
                                    <Input
                                        id="nik"
                                        className={fieldClass}
                                        value={form.data.nik}
                                        onChange={(e) =>
                                            form.setData('nik', e.target.value)
                                        }
                                        maxLength={16}
                                        placeholder="16 digit"
                                    />
                                </Field>
                                <Field
                                    id="full_name"
                                    label="Nama pasien"
                                    error={form.errors.full_name}
                                >
                                    <Input
                                        id="full_name"
                                        className={fieldClass}
                                        value={form.data.full_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'full_name',
                                                e.target.value,
                                            )
                                        }
                                        disabled={returning}
                                        required={!returning}
                                    />
                                </Field>
                                <div className="grid gap-2.5 sm:grid-cols-2">
                                    <Field
                                        id="sex"
                                        label="Jenis kelamin"
                                        error={form.errors.sex}
                                    >
                                        <select
                                            id="sex"
                                            className={selectClass}
                                            value={form.data.sex}
                                            onChange={(e) =>
                                                form.setData(
                                                    'sex',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={returning}
                                            required={!returning}
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
                                    </Field>
                                    <Field
                                        id="place_of_birth"
                                        label="Tempat lahir"
                                        error={form.errors.place_of_birth}
                                    >
                                        <Input
                                            id="place_of_birth"
                                            className={fieldClass}
                                            value={form.data.place_of_birth}
                                            onChange={(e) =>
                                                form.setData(
                                                    'place_of_birth',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                                <Field
                                    id="date_of_birth"
                                    label="Tanggal lahir"
                                    error={form.errors.date_of_birth}
                                >
                                    <Input
                                        id="date_of_birth"
                                        type="date"
                                        className={fieldClass}
                                        value={form.data.date_of_birth}
                                        onChange={(e) =>
                                            form.setData(
                                                'date_of_birth',
                                                e.target.value,
                                            )
                                        }
                                        disabled={returning}
                                        required={!returning}
                                    />
                                </Field>
                                <div className="grid gap-2.5 sm:grid-cols-3">
                                    <Field
                                        id="marital_status"
                                        label="Status pernikahan"
                                        error={form.errors.marital_status}
                                    >
                                        <select
                                            id="marital_status"
                                            className={selectClass}
                                            value={form.data.marital_status}
                                            onChange={(e) =>
                                                form.setData(
                                                    'marital_status',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">— Pilih —</option>
                                            {maritalOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field
                                        id="religion"
                                        label="Agama"
                                        error={form.errors.religion}
                                    >
                                        <select
                                            id="religion"
                                            className={selectClass}
                                            value={form.data.religion}
                                            onChange={(e) =>
                                                form.setData(
                                                    'religion',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">— Pilih —</option>
                                            {religionOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field
                                        id="education"
                                        label="Pendidikan"
                                        error={form.errors.education}
                                    >
                                        <select
                                            id="education"
                                            className={selectClass}
                                            value={form.data.education}
                                            onChange={(e) =>
                                                form.setData(
                                                    'education',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">— Pilih —</option>
                                            {educationOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field
                                        id="occupation"
                                        label="Pekerjaan"
                                        error={form.errors.occupation}
                                    >
                                        <select
                                            id="occupation"
                                            className={selectClass}
                                            value={form.data.occupation}
                                            onChange={(e) =>
                                                form.setData(
                                                    'occupation',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">— Pilih —</option>
                                            {occupationOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>
                                <div className="grid gap-2.5 sm:grid-cols-2">
                                    <Field
                                        id="province_code"
                                        label="Provinsi"
                                        error={
                                            form.errors.province_code ??
                                            form.errors.province
                                        }
                                    >
                                        <select
                                            id="province_code"
                                            className={selectClass}
                                            value={form.data.province_code}
                                            onChange={(e) => {
                                                const code = e.target.value;
                                                const selected =
                                                    wilayahProvinces.find(
                                                        (option) =>
                                                            option.value ===
                                                            code,
                                                    );
                                                form.setData({
                                                    ...form.data,
                                                    province_code: code,
                                                    province:
                                                        selected?.label ?? '',
                                                    city_code: '',
                                                    city: '',
                                                    district_code: '',
                                                    district: '',
                                                    village_code: '',
                                                    village: '',
                                                });
                                                setCityOptions([]);
                                                setDistrictOptions([]);
                                                setVillageOptions([]);
                                            }}
                                        >
                                            <option value="">— Pilih —</option>
                                            {wilayahProvinces.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field
                                        id="city_code"
                                        label="Kabupaten/Kota"
                                        error={
                                            form.errors.city_code ??
                                            form.errors.city
                                        }
                                    >
                                        <select
                                            id="city_code"
                                            className={selectClass}
                                            value={form.data.city_code}
                                            onChange={(e) => {
                                                const code = e.target.value;
                                                const selected =
                                                    visibleCityOptions.find(
                                                        (option) =>
                                                            option.value ===
                                                            code,
                                                    );
                                                form.setData({
                                                    ...form.data,
                                                    city_code: code,
                                                    city: selected?.label ?? '',
                                                    district_code: '',
                                                    district: '',
                                                    village_code: '',
                                                    village: '',
                                                });
                                                setDistrictOptions([]);
                                                setVillageOptions([]);
                                            }}
                                            disabled={!form.data.province_code}
                                        >
                                            <option value="">— Pilih —</option>
                                            {visibleCityOptions.map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </Field>
                                    <Field
                                        id="district_code"
                                        label="Kecamatan"
                                        error={
                                            form.errors.district_code ??
                                            form.errors.district
                                        }
                                    >
                                        <select
                                            id="district_code"
                                            className={selectClass}
                                            value={form.data.district_code}
                                            onChange={(e) => {
                                                const code = e.target.value;
                                                const selected =
                                                    visibleDistrictOptions.find(
                                                        (option) =>
                                                            option.value ===
                                                            code,
                                                    );
                                                form.setData({
                                                    ...form.data,
                                                    district_code: code,
                                                    district:
                                                        selected?.label ?? '',
                                                    village_code: '',
                                                    village: '',
                                                });
                                                setVillageOptions([]);
                                            }}
                                            disabled={!form.data.city_code}
                                        >
                                            <option value="">— Pilih —</option>
                                            {visibleDistrictOptions.map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </Field>
                                    <Field
                                        id="village_code"
                                        label="Kelurahan"
                                        error={
                                            form.errors.village_code ??
                                            form.errors.village
                                        }
                                    >
                                        <select
                                            id="village_code"
                                            className={selectClass}
                                            value={form.data.village_code}
                                            onChange={(e) => {
                                                const code = e.target.value;
                                                const selected =
                                                    visibleVillageOptions.find(
                                                        (option) =>
                                                            option.value ===
                                                            code,
                                                    );
                                                form.setData({
                                                    ...form.data,
                                                    village_code: code,
                                                    village:
                                                        selected?.label ?? '',
                                                });
                                            }}
                                            disabled={!form.data.district_code}
                                        >
                                            <option value="">— Pilih —</option>
                                            {visibleVillageOptions.map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </Field>
                                </div>
                                <Field
                                    id="address_line"
                                    label="Dusun/Jalan"
                                    error={form.errors.address_line}
                                >
                                    <Input
                                        id="address_line"
                                        className={fieldClass}
                                        value={form.data.address_line}
                                        onChange={(e) =>
                                            form.setData(
                                                'address_line',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Dusun atau nama jalan"
                                    />
                                </Field>
                                <Field
                                    id="domicile"
                                    label="Domisili"
                                    error={form.errors.domicile}
                                >
                                    {(controlProps) => (
                                        <div className="flex gap-2">
                                            <Input
                                                id="domicile"
                                                {...controlProps}
                                                className={fieldClass}
                                                value={form.data.domicile}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'domicile',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="h-8 shrink-0"
                                                onClick={autoDomicile}
                                            >
                                                Auto
                                            </Button>
                                        </div>
                                    )}
                                </Field>
                                <div className="grid gap-2.5 sm:grid-cols-2">
                                    <Field
                                        id="phone"
                                        label="Telepon"
                                        error={form.errors.phone}
                                    >
                                        <Input
                                            id="phone"
                                            className={fieldClass}
                                            value={form.data.phone}
                                            onChange={(e) =>
                                                form.setData(
                                                    'phone',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="08…"
                                        />
                                    </Field>
                                    <Field
                                        id="email"
                                        label="Email"
                                        error={form.errors.email}
                                    >
                                        <Input
                                            id="email"
                                            type="email"
                                            className={fieldClass}
                                            value={form.data.email}
                                            onChange={(e) =>
                                                form.setData(
                                                    'email',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                                <div className="grid gap-2.5 sm:grid-cols-2">
                                    <Field
                                        id="ethnicity"
                                        label="Suku"
                                        error={form.errors.ethnicity}
                                    >
                                        <select
                                            id="ethnicity"
                                            className={selectClass}
                                            value={form.data.ethnicity}
                                            onChange={(e) =>
                                                form.setData(
                                                    'ethnicity',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">— Suku —</option>
                                            {ethnicityOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field
                                        id="language"
                                        label="Bahasa"
                                        error={form.errors.language}
                                    >
                                        <select
                                            id="language"
                                            className={selectClass}
                                            value={form.data.language}
                                            onChange={(e) =>
                                                form.setData(
                                                    'language',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">— Bahasa —</option>
                                            {languageOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                </div>
                                <Field
                                    id="notes"
                                    label="Catatan pasien"
                                    error={form.errors.notes}
                                >
                                    <textarea
                                        id="notes"
                                        className={cn(
                                            fieldClass,
                                            'min-h-[4rem] py-1.5',
                                        )}
                                        value={form.data.notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </DeskSection>

                            <div className="grid gap-4">
                                <DeskSection title="Penanggung jawab">
                                    <Field
                                        id="responsible_party_name"
                                        label="Nama lengkap"
                                        error={
                                            form.errors.responsible_party_name
                                        }
                                    >
                                        {(controlProps) => (
                                            <div className="flex gap-2">
                                                <Input
                                                    id="responsible_party_name"
                                                    {...controlProps}
                                                    className={fieldClass}
                                                    value={
                                                        form.data
                                                            .responsible_party_name
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'responsible_party_name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-8 shrink-0"
                                                    onClick={
                                                        autoResponsibleParty
                                                    }
                                                >
                                                    Auto
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-8 shrink-0"
                                                    onClick={() => {
                                                        document
                                                            .getElementById(
                                                                'responsible_party_name',
                                                            )
                                                            ?.focus();
                                                    }}
                                                >
                                                    Edit
                                                </Button>
                                            </div>
                                        )}
                                    </Field>
                                </DeskSection>

                                <DeskSection title="Data kunjungan">
                                    {!isIgd ? (
                                        <Field
                                            id="booking_code"
                                            label="Kode booking"
                                            error={form.errors.booking_code}
                                        >
                                            <Input
                                                id="booking_code"
                                                className={fieldClass}
                                                value={form.data.booking_code}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'booking_code',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Opsional"
                                            />
                                        </Field>
                                    ) : null}
                                    <div className="grid gap-2.5 sm:grid-cols-[1fr_auto]">
                                        <Field
                                            id="visit_date"
                                            label="Tgl/Jns kunjungan"
                                            error={form.errors.visit_date}
                                        >
                                            <Input
                                                id="visit_date"
                                                type="date"
                                                className={fieldClass}
                                                value={form.data.visit_date}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'visit_date',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        </Field>
                                        <div className="flex items-end pb-0.5">
                                            <span className="inline-flex h-8 items-center rounded-md bg-[#e8f2fa] px-2.5 text-xs font-semibold text-[#123b63]">
                                                Baru
                                            </span>
                                        </div>
                                    </div>
                                    {!isIgd ? (
                                        <Field
                                            id="clinic_public_id"
                                            label="Poliklinik"
                                            error={form.errors.clinic_public_id}
                                        >
                                            <select
                                                id="clinic_public_id"
                                                className={selectClass}
                                                value={
                                                    form.data.clinic_public_id
                                                }
                                                onChange={(e) =>
                                                    form.setData({
                                                        ...form.data,
                                                        clinic_public_id:
                                                            e.target.value,
                                                        doctor_public_id: '',
                                                        schedule_public_id: '',
                                                    })
                                                }
                                                required
                                            >
                                                <option value="">
                                                    — Pilih poliklinik —
                                                </option>
                                                {clinics.map((clinic) => (
                                                    <option
                                                        key={clinic.public_id}
                                                        value={clinic.public_id}
                                                    >
                                                        {clinic.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                    ) : (
                                        <input
                                            type="hidden"
                                            name="clinic_public_id"
                                            value={form.data.clinic_public_id}
                                        />
                                    )}
                                    <Field
                                        id="doctor_public_id"
                                        label="Dokter"
                                        error={form.errors.doctor_public_id}
                                    >
                                        <select
                                            id="doctor_public_id"
                                            className={selectClass}
                                            value={form.data.doctor_public_id}
                                            onChange={(e) =>
                                                form.setData({
                                                    ...form.data,
                                                    doctor_public_id:
                                                        e.target.value,
                                                    schedule_public_id: '',
                                                })
                                            }
                                            disabled={
                                                !form.data.clinic_public_id
                                            }
                                            required
                                        >
                                            <option value="">
                                                — Pilih dokter —
                                            </option>
                                            {doctors.map((doctor) => (
                                                <option
                                                    key={doctor.public_id}
                                                    value={doctor.public_id}
                                                >
                                                    {doctor.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field
                                        id="schedule_public_id"
                                        label={isIgd ? 'Shift' : 'Jadwal'}
                                        error={form.errors.schedule_public_id}
                                    >
                                        <select
                                            id="schedule_public_id"
                                            className={selectClass}
                                            value={form.data.schedule_public_id}
                                            onChange={(e) =>
                                                form.setData(
                                                    'schedule_public_id',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={
                                                !form.data.doctor_public_id
                                            }
                                            required
                                        >
                                            <option value="">
                                                {isIgd
                                                    ? '— Pilih shift —'
                                                    : '— Pilih jadwal —'}
                                            </option>
                                            {schedules.map((schedule) => (
                                                <option
                                                    key={schedule.public_id}
                                                    value={schedule.public_id}
                                                >
                                                    {schedule.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    {isIgd ? (
                                        <>
                                            <Field
                                                id="case_type"
                                                label="Kasus tindakan"
                                                error={form.errors.case_type}
                                            >
                                                <select
                                                    id="case_type"
                                                    className={selectClass}
                                                    value={form.data.case_type}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'case_type',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    {caseTypeOptions.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </Field>
                                            <Field
                                                id="accident_type"
                                                label="Kecelakaan"
                                                error={
                                                    form.errors.accident_type
                                                }
                                            >
                                                <select
                                                    id="accident_type"
                                                    className={selectClass}
                                                    value={
                                                        form.data.accident_type
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'accident_type',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    {accidentTypeOptions.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </Field>
                                        </>
                                    ) : null}
                                    <Field
                                        id="admission_mode"
                                        label="Cara masuk"
                                        error={form.errors.admission_mode}
                                    >
                                        <select
                                            id="admission_mode"
                                            className={selectClass}
                                            value={form.data.admission_mode}
                                            onChange={(e) =>
                                                form.setData(
                                                    'admission_mode',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        >
                                            {admissionOptions.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <Field
                                        id="payer_type"
                                        label="Cara bayar"
                                        error={form.errors.payer_type}
                                    >
                                        <select
                                            id="payer_type"
                                            className={selectClass}
                                            value={form.data.payer_type}
                                            onChange={(e) =>
                                                form.setData(
                                                    'payer_type',
                                                    e.target.value,
                                                )
                                            }
                                            required
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
                                    </Field>
                                    <Field
                                        id="insurance_number"
                                        label="No. asuransi"
                                        error={form.errors.insurance_number}
                                    >
                                        {(controlProps) => (
                                            <div className="flex gap-1.5">
                                                <Input
                                                    id="insurance_number"
                                                    {...controlProps}
                                                    className={fieldClass}
                                                    value={
                                                        form.data
                                                            .insurance_number
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'insurance_number',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Opsional"
                                                />
                                                <button
                                                    type="button"
                                                    disabled
                                                    title="Integrasi BPJS belum aktif"
                                                    className="h-8 shrink-0 rounded-md border border-[#e2e8f0] px-2 text-xs text-[#94a3b8]"
                                                >
                                                    Cek
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled
                                                    title="Biometrik belum tersedia"
                                                    className="h-8 shrink-0 rounded-md border border-[#e2e8f0] px-2 text-xs text-[#94a3b8]"
                                                >
                                                    FR
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled
                                                    title="Biometrik belum tersedia"
                                                    className="h-8 shrink-0 rounded-md border border-[#e2e8f0] px-2 text-xs text-[#94a3b8]"
                                                >
                                                    FP
                                                </button>
                                            </div>
                                        )}
                                    </Field>
                                    <Field
                                        id="chief_complaint"
                                        label="Catatan kunjungan"
                                        error={form.errors.chief_complaint}
                                    >
                                        <textarea
                                            id="chief_complaint"
                                            className={cn(
                                                fieldClass,
                                                'min-h-[4.5rem] py-1.5',
                                            )}
                                            value={form.data.chief_complaint}
                                            onChange={(e) =>
                                                form.setData(
                                                    'chief_complaint',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </DeskSection>
                            </div>

                            <aside className="flex flex-col gap-3 rounded-md border border-[#e2e8f0] bg-[#f8fafc] p-3">
                                <h2 className="text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                                    Cetak / antrean
                                </h2>
                                <label className="flex items-center gap-2 text-sm text-[#0f172a]">
                                    <input
                                        type="checkbox"
                                        checked={printQueue}
                                        onChange={(e) =>
                                            setPrintQueue(e.target.checked)
                                        }
                                        className="accent-[#1b75bc]"
                                    />
                                    No. Antrian
                                </label>
                                {(isIgd
                                    ? ([
                                          ['lembarIgd', 'Lembar IGD'],
                                          ['gelang', 'Gelang pasien'],
                                          ['kartu', 'Kartu pasien'],
                                          ['tracer', 'Tracer berkas RM'],
                                          ['sep', 'SEP'],
                                          ['consent', 'General consent'],
                                      ] as const)
                                    : ([
                                          ['sep', 'SEP'],
                                          ['gelang', 'Gelang pasien'],
                                          ['kartu', 'Kartu pasien'],
                                          ['consent', 'General consent'],
                                          ['fastTrack', 'Fast track'],
                                      ] as const)
                                ).map(([key, label]) => {
                                    const printable = (
                                        PRINTABLE_FLAGS as readonly string[]
                                    ).includes(key);

                                    return (
                                        <label
                                            key={key}
                                            className="flex items-center gap-2 text-sm text-[#64748b]"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={printFlags[key]}
                                                onChange={(e) =>
                                                    setPrintFlags((prev) => ({
                                                        ...prev,
                                                        [key]: e.target.checked,
                                                    }))
                                                }
                                                className="accent-[#1b75bc]"
                                            />
                                            {label}
                                            {printable ? null : (
                                                <span className="text-[0.65rem] text-[#94a3b8]">
                                                    stub
                                                </span>
                                            )}
                                        </label>
                                    );
                                })}
                                <div className="mt-auto grid gap-2 border-t border-[#e2e8f0] pt-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="w-full"
                                        disabled={!printTargetId}
                                        title={
                                            printTargetId
                                                ? 'Buka bukti/SEP pengajaran (bukan BPJS asli)'
                                                : 'Simpan pendaftaran dulu, atau pilih Cetak di daftar hari ini'
                                        }
                                        onClick={() => {
                                            if (printTargetId) {
                                                openPrint(printTargetId);
                                            }
                                        }}
                                    >
                                        Cetak
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                        className="w-full bg-[#1b75bc] hover:bg-[#1665a3]"
                                    >
                                        Simpan
                                    </Button>
                                </div>
                            </aside>
                        </div>
                    </form>
                ) : (
                    <section className="rounded-lg border border-dashed border-[#e2e8f0] bg-[#f8fafc] p-5">
                        <h2 className="text-sm font-semibold text-[#123b63]">
                            Form pendaftaran tidak tersedia
                        </h2>
                        <p className="mt-2 text-sm text-[#64748b]">
                            Akun ini dapat melihat antrean, tetapi belum punya
                            hak untuk mendaftarkan pasien baru.
                        </p>
                    </section>
                )}

                <section className="min-w-0 rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                    <h2 className="mb-2 text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                        Pendaftaran hari ini
                    </h2>
                    <div className="min-w-0 overflow-x-auto">
                        <table className="w-full min-w-[52rem] text-left text-sm">
                            <caption className="sr-only">
                                Daftar pendaftaran pasien hari ini
                            </caption>
                            <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th className="px-2 py-1.5 font-medium">
                                        Antrian
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Waktu
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        No. RM
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Nama
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        {isIgd ? 'Unit' : 'Poli'}
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Dokter
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Penjamin
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Status
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-2 py-1.5 font-medium"
                                    >
                                        <span className="sr-only">Aksi</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {todaysEncounters.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={9}
                                            className="px-2 py-3 text-[#64748b]"
                                        >
                                            Belum ada pendaftaran hari ini.
                                        </td>
                                    </tr>
                                ) : (
                                    todaysEncounters.map((encounter) => (
                                        <tr
                                            key={encounter.public_id}
                                            className="border-b border-[#f1f5f9]"
                                        >
                                            <td className="px-2 py-1.5 font-mono text-xs">
                                                {encounter.queue_number != null
                                                    ? String(
                                                          encounter.queue_number,
                                                      ).padStart(3, '0')
                                                    : '—'}
                                            </td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">
                                                {encounter.registered_at
                                                    ? new Date(
                                                          encounter.registered_at,
                                                      ).toLocaleTimeString(
                                                          'id-ID',
                                                          {
                                                              hour: '2-digit',
                                                              minute: '2-digit',
                                                          },
                                                      )
                                                    : '—'}
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
                                                {encounter.clinic_name}
                                            </td>
                                            <td className="px-2 py-1.5 text-[#475569]">
                                                {encounter.doctor_name ?? '—'}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {payerLabel[
                                                    encounter.payer_type
                                                ] ?? encounter.payer_type}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                <span
                                                    className={cn(
                                                        'inline-flex rounded-full px-2 py-0.5 text-[0.7rem] font-medium',
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
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="px-2 py-1.5 text-right">
                                                <div className="flex justify-end gap-3">
                                                    {encounter.status ===
                                                    'CANCELLED' ? (
                                                        <span className="text-xs text-[#64748b]">
                                                            Riwayat tersimpan
                                                        </span>
                                                    ) : (
                                                        <>
                                                            <a
                                                                href={encounterPrintUrl(
                                                                    encounter.public_id,
                                                                    [
                                                                        'bukti',
                                                                        'antrian',
                                                                    ],
                                                                )}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                            >
                                                                Cetak
                                                            </a>
                                                            <Link
                                                                href={`${examPathPrefix}/${encounter.public_id}`}
                                                                className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                            >
                                                                Buka
                                                            </Link>
                                                        </>
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
                                                                    ? `cancel-blocked-${encounter.public_id}`
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
                                                            id={`cancel-blocked-${encounter.public_id}`}
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
                    <OperationalPagination
                        pagination={todaysEncountersPagination}
                        itemLabel="pendaftaran hari ini"
                        className="mt-3"
                    />
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
                                Tindakan ini menyimpan pembatalan sebagai
                                riwayat. Kunjungan yang sudah mulai dilayani
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
                                            {isIgd
                                                ? 'Unit dan antrian'
                                                : 'Poli dan antrian'}
                                        </p>
                                        <p className="mt-0.5 font-medium text-[#0f172a]">
                                            {cancelTarget.clinic_name}
                                        </p>
                                        <p className="text-xs text-[#64748b]">
                                            Antrian{' '}
                                            {cancelTarget.queue_number != null
                                                ? String(
                                                      cancelTarget.queue_number,
                                                  ).padStart(3, '0')
                                                : '—'}
                                        </p>
                                    </div>
                                    <p className="border-t border-[#d7e6f3] pt-2 text-xs leading-5 text-[#475569] sm:col-span-2">
                                        Nomor antrian dan data pendaftaran tetap
                                        tersimpan dalam riwayat.
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
                                    <Label htmlFor="cancellation-reason">
                                        Alasan pembatalan
                                    </Label>
                                    <select
                                        id="cancellation-reason"
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
                                                ? 'cancellation-reason-error'
                                                : 'cancellation-reason-help'
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
                                        id="cancellation-reason-help"
                                        className="text-xs text-[#64748b]"
                                    >
                                        Pilih alasan yang paling sesuai dengan
                                        kejadian pendaftaran.
                                    </p>
                                    <InputError
                                        id="cancellation-reason-error"
                                        message={cancelForm.errors.reason_code}
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="cancellation-note">
                                        Catatan pembatalan{' '}
                                        <span className="font-normal text-[#64748b]">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <textarea
                                        id="cancellation-note"
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
                                                ? 'cancellation-note-error'
                                                : 'cancellation-note-help'
                                        }
                                        className="min-h-20 w-full resize-y rounded-md border border-[#cbd5e1] bg-white px-3 py-2 text-sm outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30"
                                    />
                                    <p
                                        id="cancellation-note-help"
                                        className="text-xs text-[#64748b]"
                                    >
                                        Maksimal 500 karakter. Hindari data
                                        pribadi yang tidak diperlukan.
                                    </p>
                                    <InputError
                                        id="cancellation-note-error"
                                        message={cancelForm.errors.note}
                                    />
                                    <InputError
                                        id="cancellation-idempotency-error"
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

PendaftaranRawatJalan.layout = (props: Props) => {
    const isIgd = props.variant === 'igd';

    return {
        breadcrumbs: [
            { title: 'Beranda', href: '/' },
            {
                title: 'Pendaftaran',
                href: isIgd ? '/pendaftaran/igd' : '/pendaftaran/rawat-jalan',
            },
            {
                title: isIgd ? 'IGD' : 'Rawat Jalan',
                href: isIgd ? '/pendaftaran/igd' : '/pendaftaran/rawat-jalan',
            },
        ] satisfies BreadcrumbItem[],
    };
};
