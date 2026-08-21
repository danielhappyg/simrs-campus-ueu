import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    useEffect,
    useMemo,
    useState,
    type FormEvent,
    type ReactNode,
} from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
    province: string | null;
    city: string | null;
    district: string | null;
    village: string | null;
    address_line: string | null;
    domicile: string | null;
    phone: string | null;
    email: string | null;
    ethnicity: string | null;
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
    patient: {
        public_id: string | null;
        medical_record_number: string | null;
        full_name: string | null;
    };
};

type WilayahOptions = {
    provinces: Option[];
    cities: Record<string, Option[]>;
    districts: Record<string, Option[]>;
    villages: Record<string, Option[]>;
};

type DeskVariant = 'rawat-jalan' | 'igd';

type Props = {
    variant?: DeskVariant;
    q: string;
    searchResults: PatientRow[];
    todaysEncounters: EncounterRow[];
    clinics: ClinicOption[];
    sexOptions: Option[];
    religionOptions: Option[];
    educationOptions: Option[];
    occupationOptions: Option[];
    ethnicityOptions: Option[];
    languageOptions: Option[];
    payerOptions: Option[];
    admissionOptions: Option[];
    caseTypeOptions?: Option[];
    accidentTypeOptions?: Option[];
    wilayahOptions: WilayahOptions;
    canRegister: boolean;
};

const statusLabel: Record<string, string> = {
    REGISTERED: 'Terdaftar',
    IN_EXAMINATION: 'Pemeriksaan',
    READY_FOR_RM: 'Siap RM',
    CLOSED: 'Ditutup',
};

const statusChipClass: Record<string, string> = {
    REGISTERED: 'bg-[#e8f2fa] text-[#123b63]',
    IN_EXAMINATION: 'bg-[#fff4eb] text-[#c2410c]',
    READY_FOR_RM: 'bg-[#ecfdf5] text-[#047857]',
    CLOSED: 'bg-[#f1f5f9] text-[#64748b]',
};

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

function Field({
    id,
    label,
    children,
    error,
    className,
}: {
    id: string;
    label: string;
    children: ReactNode;
    error?: string;
    className?: string;
}) {
    return (
        <div className={cn('grid gap-1', className)}>
            <Label htmlFor={id} className="text-xs font-medium text-[#475569]">
                {label}
            </Label>
            {children}
            <InputError message={error} />
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
            title="Belum tersedia di demo pengajaran"
            className="inline-flex h-8 items-center rounded-md border border-[#c5d9eb] bg-[#f8fbfe] px-2.5 text-xs font-medium text-[#64748b] opacity-70"
        >
            {label}
            <span className="ml-1.5 text-[0.65rem] text-[#94a3b8]">· stub</span>
        </button>
    );
}

export default function PendaftaranRawatJalan({
    variant = 'rawat-jalan',
    q,
    searchResults,
    todaysEncounters,
    clinics,
    sexOptions,
    religionOptions,
    educationOptions,
    occupationOptions,
    ethnicityOptions,
    languageOptions,
    payerOptions,
    admissionOptions,
    caseTypeOptions = [],
    accidentTypeOptions = [],
    wilayahOptions,
    canRegister,
}: Props) {
    const isIgd = variant === 'igd';
    const indexPath = isIgd ? '/pendaftaran/igd' : '/pendaftaran/rawat-jalan';
    const storePath = indexPath;
    const examPathPrefix = isIgd
        ? '/pemeriksaan/igd'
        : '/pemeriksaan/rawat-jalan';

    const [searchOpen, setSearchOpen] = useState(q !== '');
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
        education: '',
        occupation: '',
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

    const cityOptions = form.data.province
        ? (wilayahOptions.cities[form.data.province] ?? [])
        : [];
    const districtOptions = form.data.city
        ? (wilayahOptions.districts[form.data.city] ?? [])
        : [];
    const villageOptions = form.data.district
        ? (wilayahOptions.villages[form.data.district] ?? [])
        : [];

    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const query = String(data.get('q') ?? '').trim();
        setSearchOpen(true);
        router.get(
            indexPath,
            query ? { q: query } : {},
            { preserveState: true, replace: true },
        );
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
            education: patient.education ?? '',
            occupation: patient.occupation ?? '',
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
            education: '',
            occupation: '',
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

    const returning = form.data.patient_public_id !== '';

    return (
        <>
            <Head
                title={
                    isIgd
                        ? 'Pendaftaran IGD'
                        : 'Pendaftaran Rawat Jalan'
                }
            />

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
                            Meja pendaftaran pengajaran (sintetis). Referensi
                            densitas: SIMRS SAHABAT / CAP-REG-
                            {isIgd ? '002' : '003'}.
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

                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] px-3 py-2">
                    <ActionStub label="Riwayat" />
                    <ActionStub label="EMR" />
                    <ActionStub label="Ambil RegOn" />
                    <form
                        onSubmit={search}
                        className="flex min-w-[16rem] flex-1 items-center gap-2"
                    >
                        <Input
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
                    <ActionStub label="Data Kunjungan" />
                </div>

                {searchOpen && q !== '' ? (
                    <section className="rounded-lg border border-[#e2e8f0] bg-white p-3">
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
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-left text-sm">
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
                                        <th className="px-2 py-1.5 font-medium" />
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
                    </section>
                ) : null}

                {canRegister ? (
                    <form
                        onSubmit={submit}
                        className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4"
                    >
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
                                        placeholder="16 digit (sintetis)"
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
                                        id="province"
                                        label="Provinsi"
                                        error={form.errors.province}
                                    >
                                        <select
                                            id="province"
                                            className={selectClass}
                                            value={form.data.province}
                                            onChange={(e) =>
                                                form.setData({
                                                    ...form.data,
                                                    province: e.target.value,
                                                    city: '',
                                                    district: '',
                                                    village: '',
                                                })
                                            }
                                        >
                                            <option value="">— Pilih —</option>
                                            {wilayahOptions.provinces.map(
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
                                        id="city"
                                        label="Kabupaten/Kota"
                                        error={form.errors.city}
                                    >
                                        <select
                                            id="city"
                                            className={selectClass}
                                            value={form.data.city}
                                            onChange={(e) =>
                                                form.setData({
                                                    ...form.data,
                                                    city: e.target.value,
                                                    district: '',
                                                    village: '',
                                                })
                                            }
                                            disabled={!form.data.province}
                                        >
                                            <option value="">— Pilih —</option>
                                            {cityOptions.map((option) => (
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
                                        id="district"
                                        label="Kecamatan"
                                        error={form.errors.district}
                                    >
                                        <select
                                            id="district"
                                            className={selectClass}
                                            value={form.data.district}
                                            onChange={(e) =>
                                                form.setData({
                                                    ...form.data,
                                                    district: e.target.value,
                                                    village: '',
                                                })
                                            }
                                            disabled={!form.data.city}
                                        >
                                            <option value="">— Pilih —</option>
                                            {districtOptions.map((option) => (
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
                                        id="village"
                                        label="Kelurahan"
                                        error={form.errors.village}
                                    >
                                        <select
                                            id="village"
                                            className={selectClass}
                                            value={form.data.village}
                                            onChange={(e) =>
                                                form.setData(
                                                    'village',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={!form.data.district}
                                        >
                                            <option value="">— Pilih —</option>
                                            {villageOptions.map((option) => (
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
                                    <div className="flex gap-2">
                                        <Input
                                            id="domicile"
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
                                        <div className="flex gap-2">
                                            <Input
                                                id="responsible_party_name"
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
                                                onClick={autoResponsibleParty}
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
                                                value={form.data.clinic_public_id}
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
                                            disabled={!form.data.clinic_public_id}
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
                                            disabled={!form.data.doctor_public_id}
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
                                        <div className="flex gap-1.5">
                                            <Input
                                                id="insurance_number"
                                                className={fieldClass}
                                                value={
                                                    form.data.insurance_number
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'insurance_number',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Sintetis / opsional"
                                            />
                                            <button
                                                type="button"
                                                disabled
                                                title="Tidak mengirim BPJS nyata"
                                                className="h-8 shrink-0 rounded-md border border-[#e2e8f0] px-2 text-xs text-[#94a3b8]"
                                            >
                                                Cek
                                            </button>
                                            <button
                                                type="button"
                                                disabled
                                                title="Biometrik tidak aktif di demo"
                                                className="h-8 shrink-0 rounded-md border border-[#e2e8f0] px-2 text-xs text-[#94a3b8]"
                                            >
                                                FR
                                            </button>
                                            <button
                                                type="button"
                                                disabled
                                                title="Biometrik tidak aktif di demo"
                                                className="h-8 shrink-0 rounded-md border border-[#e2e8f0] px-2 text-xs text-[#94a3b8]"
                                            >
                                                FP
                                            </button>
                                        </div>
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
                                {(
                                    (isIgd
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
                                    )
                                ).map(([key, label]) => (
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
                                        <span className="text-[0.65rem] text-[#94a3b8]">
                                            stub
                                        </span>
                                    </label>
                                ))}
                                <div className="mt-auto grid gap-2 border-t border-[#e2e8f0] pt-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="w-full"
                                        disabled
                                        title="Cetak belum diaktifkan — nomor antrean tetap tersimpan"
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

                <section className="rounded-lg border border-[#e2e8f0] bg-white p-3 md:p-4">
                    <h2 className="mb-2 text-xs font-semibold tracking-wide text-[#123b63] uppercase">
                        Pendaftaran hari ini
                    </h2>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[52rem] text-left text-sm">
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
                                    <th className="px-2 py-1.5 font-medium" />
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
                                            </td>
                                            <td className="px-2 py-1.5 text-right">
                                                <Link
                                                    href={`${examPathPrefix}/${encounter.public_id}`}
                                                    className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                >
                                                    Buka
                                                </Link>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
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
