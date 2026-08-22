import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type Option = { value: string; label: string };

type WardCatalogue = {
    name: string;
    class: string;
    beds: string[];
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
}: Props) {
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

    const form = useForm({
        patient_public_id: '',
        full_name: '',
        date_of_birth: '',
        sex: sexOptions[0]?.value ?? 'LAKI_LAKI',
        nik: '',
        phone: '',
        ward_name: wards[0]?.name ?? '',
        ward_class: wards[0]?.class ?? '',
        bed_code: wards[0]?.beds[0] ?? '',
        payer_type: payerOptions[0]?.value ?? 'UMUM',
        insurance_number: '',
        continue_from: continueFromOptions[0]?.value ?? 'LANGSUNG',
        chief_complaint: '',
        is_synthetic: true,
    });

    const selectedWard = useMemo(
        () =>
            wards.find((ward) => ward.name === form.data.ward_name) ?? wards[0],
        [form.data.ward_name, wards],
    );

    const availableBeds = selectedWard?.beds ?? [];

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

    const onWardChange = (wardName: string) => {
        const ward = wards.find((item) => item.name === wardName);

        if (!ward) {
            return;
        }

        form.setData({
            ...form.data,
            ward_name: ward.name,
            ward_class: ward.class,
            bed_code: ward.beds[0] ?? '',
        });
    };

    const submitAdmission = (event: FormEvent) => {
        event.preventDefault();
        form.post('/pendaftaran/rawat-inap', {
            preserveScroll: true,
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

    return (
        <>
            <Head title="Pendaftaran Rawat Inap" />

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
                            Meja pendaftaran pengajaran (sintetis). Referensi
                            densitas: SIMRS SAHABAT / CAP-REG-001.
                        </p>
                    </div>
                    {selectedPatient ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={clearPatient}
                        >
                            Pasien baru
                        </Button>
                    ) : null}
                </header>

                <form
                    onSubmit={applySearch}
                    className="rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] p-3"
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-[14rem] flex-1 gap-1">
                            <Label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Cari pasien (No.RM / NIK / Nama)
                            </Label>
                            <Input
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
                    <section className="rounded-lg border border-[#e2e8f0] bg-white p-3">
                        <h2 className="text-sm font-semibold text-[#0f172a]">
                            Hasil pencarian pasien
                        </h2>
                        <div className="mt-2 overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-left text-sm">
                                <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                    <tr>
                                        <th className="px-2 py-1.5">No. RM</th>
                                        <th className="px-2 py-1.5">Nama</th>
                                        <th className="px-2 py-1.5">
                                            Tgl lahir
                                        </th>
                                        <th className="px-2 py-1.5">JK</th>
                                        <th className="px-2 py-1.5" />
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

                    <div className="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        {!selectedPatient ? (
                            <>
                                <div className="grid gap-1">
                                    <Label htmlFor="full_name">
                                        Nama lengkap
                                    </Label>
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
                                        disabled={!canRegister}
                                    />
                                    <InputError
                                        message={form.errors.full_name}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="date_of_birth">
                                        Tanggal lahir
                                    </Label>
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
                                        disabled={!canRegister}
                                    />
                                    <InputError
                                        message={form.errors.date_of_birth}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="sex">Jenis kelamin</Label>
                                    <select
                                        id="sex"
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
                                    <InputError message={form.errors.sex} />
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
                                className={fieldClass}
                                value={form.data.nik}
                                onChange={(e) =>
                                    form.setData('nik', e.target.value)
                                }
                                disabled={!canRegister}
                            />
                            <InputError message={form.errors.nik} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="phone">Telepon</Label>
                            <Input
                                id="phone"
                                className={fieldClass}
                                value={form.data.phone}
                                onChange={(e) =>
                                    form.setData('phone', e.target.value)
                                }
                                disabled={!canRegister}
                            />
                            <InputError message={form.errors.phone} />
                        </div>

                        <div className="grid gap-1">
                            <Label htmlFor="ward_name">Bangsal</Label>
                            <select
                                id="ward_name"
                                className={fieldClass}
                                value={form.data.ward_name}
                                onChange={(e) => onWardChange(e.target.value)}
                                disabled={!canRegister}
                            >
                                {wards.map((ward) => (
                                    <option key={ward.name} value={ward.name}>
                                        {ward.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.ward_name} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="ward_class">Kelas</Label>
                            <Input
                                id="ward_class"
                                className={fieldClass}
                                value={form.data.ward_class}
                                readOnly
                            />
                            <InputError message={form.errors.ward_class} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="bed_code">Tempat tidur</Label>
                            <select
                                id="bed_code"
                                className={fieldClass}
                                value={form.data.bed_code}
                                onChange={(e) =>
                                    form.setData('bed_code', e.target.value)
                                }
                                disabled={!canRegister}
                            >
                                {availableBeds.map((bed) => (
                                    <option key={bed} value={bed}>
                                        {bed}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.bed_code} />
                        </div>

                        <div className="grid gap-1">
                            <Label htmlFor="payer_type">Cara bayar</Label>
                            <select
                                id="payer_type"
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
                            <InputError message={form.errors.payer_type} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="insurance_number">
                                No. penjamin
                            </Label>
                            <Input
                                id="insurance_number"
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
                                message={form.errors.insurance_number}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="continue_from">
                                Asal / kelanjutan
                            </Label>
                            <select
                                id="continue_from"
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
                            <InputError message={form.errors.continue_from} />
                        </div>

                        <div className="grid gap-1 md:col-span-2 lg:col-span-3">
                            <Label htmlFor="chief_complaint">
                                Keluhan utama
                            </Label>
                            <textarea
                                id="chief_complaint"
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
                            <InputError message={form.errors.chief_complaint} />
                        </div>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            disabled={!canRegister || form.processing}
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
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                No. RM / Nama
                            </label>
                            <Input
                                className={cn(fieldClass, 'bg-white')}
                                value={filterQ}
                                onChange={(e) => setFilterQ(e.target.value)}
                                placeholder="No.RM / Nama"
                            />
                        </div>
                        <div className="grid min-w-[10rem] gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Bangsal
                            </label>
                            <select
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
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Cara bayar
                            </label>
                            <select
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
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Asal
                            </label>
                            <select
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
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Dari tgl
                            </label>
                            <Input
                                type="date"
                                className={cn(fieldClass, 'bg-white')}
                                value={filterDateFrom}
                                onChange={(e) =>
                                    setFilterDateFrom(e.target.value)
                                }
                            />
                        </div>
                        <div className="grid min-w-[8rem] gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Sampai tgl
                            </label>
                            <Input
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

                <section className="rounded-lg border border-[#e2e8f0] bg-white p-3">
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
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[56rem] text-left text-sm">
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
                                    <th className="px-2 py-1.5" />
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
                                            </td>
                                            <td className="max-w-[12rem] truncate px-2 py-1.5 text-[#64748b]">
                                                {encounter.chief_complaint ??
                                                    '—'}
                                            </td>
                                            <td className="px-2 py-1.5 text-right">
                                                <a
                                                    href={`/pendaftaran/kunjungan/${encounter.public_id}/cetak?docs=bukti,antrian`}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                >
                                                    Cetak
                                                </a>
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

PendaftaranRawatInap.layout = () => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Pendaftaran', href: '/pendaftaran/rawat-inap' },
        { title: 'Rawat Inap', href: '/pendaftaran/rawat-inap' },
    ] satisfies BreadcrumbItem[],
});
