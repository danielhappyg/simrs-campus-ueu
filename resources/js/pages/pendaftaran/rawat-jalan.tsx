import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types';

type Option = { value: string; label: string };

type PatientRow = {
    public_id: string;
    medical_record_number: string;
    full_name: string;
    date_of_birth: string | null;
    sex: string;
};

type EncounterRow = {
    public_id: string;
    status: string;
    clinic_name: string;
    payer_type: string;
    registered_at: string | null;
    patient: {
        public_id: string | null;
        medical_record_number: string | null;
        full_name: string | null;
    };
};

type Props = {
    q: string;
    searchResults: PatientRow[];
    todaysEncounters: EncounterRow[];
    sexOptions: Option[];
    payerOptions: Option[];
    canRegister: boolean;
};

const statusLabel: Record<string, string> = {
    REGISTERED: 'Terdaftar',
    IN_EXAMINATION: 'Dalam pemeriksaan',
    READY_FOR_RM: 'Siap RM',
    CLOSED: 'Ditutup',
};

const sexLabel: Record<string, string> = {
    LAKI_LAKI: 'Laki-laki',
    PEREMPUAN: 'Perempuan',
    TIDAK_DIKETAHUI: 'Tidak diketahui',
};

export default function PendaftaranRawatJalan({
    q,
    searchResults,
    todaysEncounters,
    sexOptions,
    payerOptions,
    canRegister,
}: Props) {
    const form = useForm({
        patient_public_id: '',
        full_name: '',
        date_of_birth: '',
        sex: 'LAKI_LAKI',
        medical_record_number: '',
        clinic_name: '',
        payer_type: 'UMUM',
        chief_complaint: '',
        is_synthetic: true,
    });

    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const query = String(data.get('q') ?? '').trim();
        router.get(
            '/pendaftaran/rawat-jalan',
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
        });
    };

    const clearSelectedPatient = () => {
        form.setData({
            ...form.data,
            patient_public_id: '',
            full_name: '',
            date_of_birth: '',
            sex: 'LAKI_LAKI',
            medical_record_number: '',
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/pendaftaran/rawat-jalan', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset(
                    'patient_public_id',
                    'full_name',
                    'date_of_birth',
                    'sex',
                    'medical_record_number',
                    'clinic_name',
                    'payer_type',
                    'chief_complaint',
                );
                form.setData('sex', 'LAKI_LAKI');
                form.setData('payer_type', 'UMUM');
                form.setData('is_synthetic', true);
            },
        });
    };

    const returning = form.data.patient_public_id !== '';

    return (
        <>
            <Head title="Pendaftaran Rawat Jalan" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 px-4 py-8 md:px-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight text-[#0f172a] md:text-3xl">
                        Pendaftaran Rawat Jalan
                    </h1>
                    <p className="text-sm text-[#64748b]">
                        Cari pasien, daftarkan kunjungan, lalu lanjut ke
                        pemeriksaan.
                    </p>
                </header>

                <section className="rounded-xl border border-[#e2e8f0] bg-white p-5">
                    <h2 className="text-sm font-semibold text-[#123b63]">
                        Cari pasien
                    </h2>
                    <form
                        onSubmit={search}
                        className="mt-3 flex flex-col gap-3 sm:flex-row"
                    >
                        <Input
                            name="q"
                            defaultValue={q}
                            placeholder="Nama atau nomor rekam medis"
                            className="sm:max-w-md"
                        />
                        <Button type="submit" className="bg-[#1b75bc] hover:bg-[#1665a3]">
                            Cari
                        </Button>
                    </form>

                    {q !== '' && (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full min-w-[36rem] text-left text-sm">
                                <thead className="border-b border-[#e2e8f0] text-xs tracking-wide text-[#64748b] uppercase">
                                    <tr>
                                        <th className="px-2 py-2 font-medium">
                                            No. RM
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Nama
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Tgl. lahir
                                        </th>
                                        <th className="px-2 py-2 font-medium">
                                            Jenis kelamin
                                        </th>
                                        <th className="px-2 py-2 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {searchResults.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="px-2 py-4 text-[#64748b]"
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
                                                <td className="px-2 py-2 font-mono text-xs">
                                                    {
                                                        patient.medical_record_number
                                                    }
                                                </td>
                                                <td className="px-2 py-2">
                                                    {patient.full_name}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {patient.date_of_birth}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {sexLabel[patient.sex] ??
                                                        patient.sex}
                                                </td>
                                                <td className="px-2 py-2 text-right">
                                                    {canRegister && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                selectPatient(
                                                                    patient,
                                                                )
                                                            }
                                                        >
                                                            Pilih
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {canRegister && (
                    <section className="rounded-xl border border-[#e2e8f0] bg-white p-5">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-sm font-semibold text-[#123b63]">
                                {returning
                                    ? 'Daftarkan kunjungan pasien lama'
                                    : 'Daftarkan pasien baru'}
                            </h2>
                            {returning && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={clearSelectedPatient}
                                >
                                    Buat pasien baru
                                </Button>
                            )}
                        </div>

                        <form
                            onSubmit={submit}
                            className="mt-4 grid gap-4 sm:grid-cols-2"
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="full_name">Nama lengkap</Label>
                                <Input
                                    id="full_name"
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
                                <InputError message={form.errors.full_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="date_of_birth">
                                    Tanggal lahir
                                </Label>
                                <Input
                                    id="date_of_birth"
                                    type="date"
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
                                <InputError
                                    message={form.errors.date_of_birth}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="sex">Jenis kelamin</Label>
                                <select
                                    id="sex"
                                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                                    value={form.data.sex}
                                    onChange={(e) =>
                                        form.setData('sex', e.target.value)
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
                                <InputError message={form.errors.sex} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="medical_record_number">
                                    No. RM (opsional)
                                </Label>
                                <Input
                                    id="medical_record_number"
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
                                <InputError
                                    message={form.errors.medical_record_number}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="clinic_name">Klinik</Label>
                                <Input
                                    id="clinic_name"
                                    value={form.data.clinic_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'clinic_name',
                                            e.target.value,
                                        )
                                    }
                                    required
                                    placeholder="Contoh: Poliklinik Umum"
                                />
                                <InputError message={form.errors.clinic_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="payer_type">
                                    Penjamin
                                </Label>
                                <select
                                    id="payer_type"
                                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
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
                                <InputError message={form.errors.payer_type} />
                            </div>

                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="chief_complaint">
                                    Keluhan utama (opsional)
                                </Label>
                                <Input
                                    id="chief_complaint"
                                    value={form.data.chief_complaint}
                                    onChange={(e) =>
                                        form.setData(
                                            'chief_complaint',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.chief_complaint}
                                />
                            </div>

                            <div className="sm:col-span-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="bg-[#1b75bc] hover:bg-[#1665a3]"
                                >
                                    Simpan pendaftaran
                                </Button>
                            </div>
                        </form>
                    </section>
                )}

                <section className="rounded-xl border border-[#e2e8f0] bg-white p-5">
                    <h2 className="text-sm font-semibold text-[#123b63]">
                        Pendaftaran hari ini
                    </h2>
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-left text-sm">
                            <thead className="border-b border-[#e2e8f0] text-xs tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th className="px-2 py-2 font-medium">
                                        Waktu
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        No. RM
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Nama
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Klinik
                                    </th>
                                    <th className="px-2 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-2 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {todaysEncounters.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-2 py-4 text-[#64748b]"
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
                                            <td className="px-2 py-2 whitespace-nowrap">
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
                                            <td className="px-2 py-2 font-mono text-xs">
                                                {
                                                    encounter.patient
                                                        .medical_record_number
                                                }
                                            </td>
                                            <td className="px-2 py-2">
                                                {encounter.patient.full_name}
                                            </td>
                                            <td className="px-2 py-2">
                                                {encounter.clinic_name}
                                            </td>
                                            <td className="px-2 py-2">
                                                {statusLabel[encounter.status] ??
                                                    encounter.status}
                                            </td>
                                            <td className="px-2 py-2 text-right">
                                                <Link
                                                    href={`/pemeriksaan/rawat-jalan/${encounter.public_id}`}
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

PendaftaranRawatJalan.layout = {
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Pendaftaran', href: '/pendaftaran/rawat-jalan' },
        { title: 'Rawat Jalan', href: '/pendaftaran/rawat-jalan' },
    ] satisfies BreadcrumbItem[],
};
