import { Head, Link, router } from '@inertiajs/react';
import { useState  } from 'react';
import type {FormEvent} from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';

type ClinicOption = { value: string; label: string };
type Option = { value: string; label: string };

type EncounterRow = {
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
    };
};

type Filters = {
    q: string;
    clinic: string;
    date_from: string;
    date_to: string;
    payer?: string;
    continue_from?: string;
};

type DeskVariant = 'rawat-jalan' | 'igd' | 'triage' | 'rawat-inap';

type Props = {
    variant?: DeskVariant;
    indexPath?: string;
    showPathPrefix?: string;
    encounters: EncounterRow[];
    clinics: ClinicOption[];
    payerOptions?: Option[];
    continueFromOptions?: Option[];
    filters: Filters;
    canOpen: boolean;
};

const statusLabel: Record<string, string> = {
    REGISTERED: 'Terdaftar',
    IN_EXAMINATION: 'Dalam pemeriksaan',
};

const statusChip: Record<string, string> = {
    REGISTERED: 'bg-[#e8f2fa] text-[#123b63]',
    IN_EXAMINATION: 'bg-[#fdeee3] text-[#9a3412]',
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

const fieldClass =
    'border-input h-8 rounded-md border bg-white px-2.5 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30';

export default function PemeriksaanRawatJalanIndex({
    variant = 'rawat-jalan',
    indexPath = '/pemeriksaan/rawat-jalan',
    showPathPrefix = '/pemeriksaan/rawat-jalan',
    encounters,
    clinics,
    payerOptions = [],
    continueFromOptions = [],
    filters,
    canOpen,
}: Props) {
    const isIgd = variant === 'igd';
    const isTriage = variant === 'triage';
    const isInpatient = variant === 'rawat-inap';
    const title = isTriage
        ? 'Pemeriksaan · Triage'
        : isIgd
          ? 'Pemeriksaan · IGD'
          : isInpatient
            ? 'Pemeriksaan · Rawat Inap'
            : 'Pemeriksaan · Rawat Jalan';

    const [q, setQ] = useState(filters.q);
    const [clinic, setClinic] = useState(filters.clinic);
    const [payer, setPayer] = useState(filters.payer ?? '');
    const [continueFrom, setContinueFrom] = useState(
        filters.continue_from ?? '',
    );
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [showIter, setShowIter] = useState(false);
    const [showKonsul, setShowKonsul] = useState(true);
    const [showBatal, setShowBatal] = useState(false);

    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            indexPath,
            {
                q: q || undefined,
                clinic: !isTriage && clinic ? clinic : undefined,
                payer: (isTriage || isInpatient) && payer ? payer : undefined,
                continue_from:
                    isInpatient && continueFrom ? continueFrom : undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title={title} />

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5 md:py-5">
                <CareSettingSubnav
                    items={[
                        {
                            href: '/pemeriksaan/rawat-jalan',
                            label: 'Rawat Jalan',
                            active: variant === 'rawat-jalan',
                        },
                        {
                            href: '/pemeriksaan/igd',
                            label: 'IGD',
                            active: isIgd,
                        },
                        {
                            href: '/pemeriksaan/rawat-inap',
                            label: 'Rawat Inap',
                            active: isInpatient,
                        },
                        {
                            href: '/pemeriksaan/triage',
                            label: 'Triage',
                            active: isTriage,
                        },
                    ]}
                />

                <header className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                            {title}
                        </h1>
                        <p className="mt-0.5 text-xs text-[#64748b]">
                            Worklist pengajaran (sintetis). Densitas filter
                            mengikuti meja SAHABAT / CAP-CLN-
                            {isTriage
                                ? '002'
                                : isIgd
                                  ? '003'
                                  : isInpatient
                                    ? '005'
                                    : '004'}
                            .
                            {isTriage
                                ? ' Skala triage tetap stub sampai SME confirm.'
                                : ''}
                        </p>
                    </div>
                </header>

                <form
                    onSubmit={applyFilters}
                    className="rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] p-3"
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-[12rem] flex-1 gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                No. RM / Nama
                            </label>
                            <Input
                                className={cn(fieldClass, 'bg-white')}
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="No.RM / Nama"
                            />
                        </div>
                        {isTriage ? (
                            <div className="grid min-w-[10rem] gap-1">
                                <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                    Cara bayar
                                </label>
                                <select
                                    className={fieldClass}
                                    value={payer}
                                    onChange={(e) => setPayer(e.target.value)}
                                >
                                    <option value="">
                                        — Semua cara bayar —
                                    </option>
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
                        ) : (
                            <>
                                <div className="grid min-w-[10rem] gap-1">
                                    <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                        {isInpatient
                                            ? 'Bangsal'
                                            : isIgd
                                              ? 'Unit'
                                              : 'Klinik'}
                                    </label>
                                    <select
                                        className={fieldClass}
                                        value={clinic}
                                        onChange={(e) =>
                                            setClinic(e.target.value)
                                        }
                                    >
                                        <option value="">
                                            {isInpatient
                                                ? 'Semua bangsal'
                                                : isIgd
                                                  ? 'Semua unit IGD'
                                                  : 'Semua klinik'}
                                        </option>
                                        {clinics.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                {isInpatient ? (
                                    <>
                                        <div className="grid min-w-[8rem] gap-1">
                                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                                Cara bayar
                                            </label>
                                            <select
                                                className={fieldClass}
                                                value={payer}
                                                onChange={(e) =>
                                                    setPayer(e.target.value)
                                                }
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
                                                value={continueFrom}
                                                onChange={(e) =>
                                                    setContinueFrom(
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">Semua</option>
                                                {continueFromOptions.map(
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
                                        </div>
                                    </>
                                ) : null}
                            </>
                        )}
                        <div className="grid min-w-[9rem] gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Dari
                            </label>
                            <Input
                                type="date"
                                className={fieldClass}
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                            />
                        </div>
                        <div className="grid min-w-[9rem] gap-1">
                            <label className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase">
                                Sampai
                            </label>
                            <Input
                                type="date"
                                className={fieldClass}
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                            />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            className="h-8 bg-[#1b75bc] hover:bg-[#1665a3]"
                        >
                            Tampilkan
                        </Button>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-4 text-xs text-[#64748b]">
                        {!isTriage ? (
                            <>
                                <label className="inline-flex items-center gap-1.5">
                                    <input
                                        type="checkbox"
                                        checked={showIter}
                                        onChange={(e) =>
                                            setShowIter(e.target.checked)
                                        }
                                        className="accent-[#1b75bc]"
                                    />
                                    Tampilkan pasien iterasi
                                    <span className="text-[0.65rem] text-[#94a3b8]">
                                        · stub
                                    </span>
                                </label>
                                <label className="inline-flex items-center gap-1.5">
                                    <input
                                        type="checkbox"
                                        checked={showKonsul}
                                        onChange={(e) =>
                                            setShowKonsul(e.target.checked)
                                        }
                                        className="accent-[#1b75bc]"
                                    />
                                    Tampilkan pasien konsul internal
                                    <span className="text-[0.65rem] text-[#94a3b8]">
                                        · stub
                                    </span>
                                </label>
                            </>
                        ) : null}
                        <label className="inline-flex items-center gap-1.5">
                            <input
                                type="checkbox"
                                checked={showBatal}
                                onChange={(e) => setShowBatal(e.target.checked)}
                                className="accent-[#1b75bc]"
                            />
                            Tampilkan pasien batal
                            <span className="text-[0.65rem] text-[#94a3b8]">
                                · stub
                            </span>
                        </label>
                    </div>
                </form>

                <section className="rounded-lg border border-[#e2e8f0] bg-white p-3">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[56rem] text-left text-sm">
                            <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th className="px-2 py-1.5 font-medium">
                                        Antrian
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        No. RM
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Nama
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        {isInpatient
                                            ? 'Bangsal'
                                            : isIgd || isTriage
                                              ? 'Unit'
                                              : 'Klinik'}
                                    </th>
                                    {isInpatient ? (
                                        <>
                                            <th className="px-2 py-1.5 font-medium">
                                                Kelas
                                            </th>
                                            <th className="px-2 py-1.5 font-medium">
                                                TT
                                            </th>
                                            <th className="px-2 py-1.5 font-medium">
                                                Asal
                                            </th>
                                        </>
                                    ) : (
                                        <>
                                            <th className="px-2 py-1.5 font-medium">
                                                Dokter
                                            </th>
                                            <th className="px-2 py-1.5 font-medium">
                                                {isIgd || isTriage
                                                    ? 'Shift'
                                                    : 'Jadwal'}
                                            </th>
                                        </>
                                    )}
                                    {(isIgd || isTriage) && !isInpatient && (
                                        <th className="px-2 py-1.5 font-medium">
                                            Kasus
                                        </th>
                                    )}
                                    <th className="px-2 py-1.5 font-medium">
                                        Penjamin
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Status
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Keluhan
                                    </th>
                                    <th className="px-2 py-1.5 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {encounters.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={
                                                isInpatient
                                                    ? 11
                                                    : isIgd || isTriage
                                                      ? 11
                                                      : 10
                                            }
                                            className="px-2 py-6 text-[#64748b]"
                                        >
                                            Tidak ada kunjungan aktif untuk
                                            filter ini.
                                        </td>
                                    </tr>
                                ) : (
                                    encounters.map((encounter) => (
                                        <tr
                                            key={encounter.public_id}
                                            className="border-b border-[#f1f5f9]"
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
                                            <td className="px-2 py-1.5 font-medium text-[#0f172a]">
                                                {encounter.patient.full_name}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                {isInpatient
                                                    ? (encounter.ward_name ??
                                                      encounter.clinic_name)
                                                    : encounter.clinic_name}
                                            </td>
                                            {isInpatient ? (
                                                <>
                                                    <td className="px-2 py-1.5">
                                                        {encounter.ward_class ??
                                                            '—'}
                                                    </td>
                                                    <td className="px-2 py-1.5 font-mono text-xs">
                                                        {encounter.bed_code ??
                                                            encounter.schedule_label ??
                                                            '—'}
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
                                                </>
                                            ) : (
                                                <>
                                                    <td className="px-2 py-1.5">
                                                        {encounter.doctor_name ??
                                                            '—'}
                                                    </td>
                                                    <td className="px-2 py-1.5 text-[#64748b]">
                                                        {encounter.schedule_label ??
                                                            '—'}
                                                    </td>
                                                </>
                                            )}
                                            {(isIgd || isTriage) &&
                                                !isInpatient && (
                                                    <td className="px-2 py-1.5 text-[#64748b]">
                                                        {encounter.case_type ??
                                                            '—'}
                                                    </td>
                                                )}
                                            <td className="px-2 py-1.5">
                                                {payerLabel[
                                                    encounter.payer_type
                                                ] ?? encounter.payer_type}
                                            </td>
                                            <td className="px-2 py-1.5">
                                                <span
                                                    className={cn(
                                                        'inline-flex rounded-md px-2 py-0.5 text-[0.7rem] font-semibold',
                                                        statusChip[
                                                            encounter.status
                                                        ] ??
                                                            'bg-[#f1f5f9] text-[#123b63]',
                                                    )}
                                                >
                                                    {statusLabel[
                                                        encounter.status
                                                    ] ?? encounter.status}
                                                </span>
                                            </td>
                                            <td className="max-w-[10rem] truncate px-2 py-1.5 text-[#64748b]">
                                                {encounter.chief_complaint ||
                                                    '—'}
                                            </td>
                                            <td className="px-2 py-1.5 text-right">
                                                {canOpen ? (
                                                    <Link
                                                        href={`${showPathPrefix}/${encounter.public_id}`}
                                                        className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                    >
                                                        {isTriage
                                                            ? 'Ke IGD'
                                                            : 'Buka'}
                                                    </Link>
                                                ) : null}
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

PemeriksaanRawatJalanIndex.layout = (props: Props) => {
    const variant = props.variant ?? 'rawat-jalan';
    const indexPath =
        props.indexPath ??
        (variant === 'triage'
            ? '/pemeriksaan/triage'
            : variant === 'igd'
              ? '/pemeriksaan/igd'
              : variant === 'rawat-inap'
                ? '/pemeriksaan/rawat-inap'
                : '/pemeriksaan/rawat-jalan');
    const label =
        variant === 'triage'
            ? 'Triage'
            : variant === 'igd'
              ? 'IGD'
              : variant === 'rawat-inap'
                ? 'Rawat Inap'
                : 'Rawat Jalan';

    return {
        breadcrumbs: [
            { title: 'Beranda', href: '/' },
            { title: 'Pemeriksaan', href: indexPath },
            { title: label, href: indexPath },
        ] satisfies BreadcrumbItem[],
    };
};
