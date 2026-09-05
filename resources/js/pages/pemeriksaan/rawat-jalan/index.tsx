import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import { OperationalPagination } from '@/components/operational-pagination';
import type { OperationalPaginationMeta } from '@/components/operational-pagination';
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
    scope?: 'active' | 'correction';
};

type DeskVariant = 'rawat-jalan' | 'igd' | 'triage' | 'rawat-inap';

type Props = {
    variant?: DeskVariant;
    indexPath?: string;
    showPathPrefix?: string;
    encounters: EncounterRow[];
    pagination?: OperationalPaginationMeta | null;
    clinics: ClinicOption[];
    payerOptions?: Option[];
    continueFromOptions?: Option[];
    filters: Filters;
    canOpen: boolean;
    canAccessCorrections?: boolean;
};

const statusLabel: Record<string, string> = {
    REGISTERED: 'Registered',
    IN_EXAMINATION: 'In examination',
    READY_FOR_RM: 'Documentation complete',
    CLOSED: 'Closed',
};

const statusChip: Record<string, string> = {
    REGISTERED: 'bg-[#e8f2fa] text-[#123b63]',
    IN_EXAMINATION: 'bg-[#fdeee3] text-[#9a3412]',
    READY_FOR_RM: 'bg-[#ecfdf5] text-[#047857]',
    CLOSED: 'bg-[#f1f5f9] text-[#334155]',
};

const payerLabel: Record<string, string> = {
    UMUM: 'Self-pay',
    BPJS: 'BPJS',
    LAINNYA: 'Other',
};

const continueLabel: Record<string, string> = {
    LANGSUNG: 'Direct',
    DARI_IGD: 'From emergency',
    DARI_RJ: 'From outpatient care',
};

const fieldClass =
    'border-input h-8 rounded-md border bg-white px-2.5 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30';

export default function PemeriksaanRawatJalanIndex({
    variant = 'rawat-jalan',
    indexPath = '/pemeriksaan/rawat-jalan',
    showPathPrefix = '/pemeriksaan/rawat-jalan',
    encounters,
    pagination,
    clinics,
    payerOptions = [],
    continueFromOptions = [],
    filters,
    canOpen,
    canAccessCorrections = false,
}: Props) {
    const canViewBedCensus =
        (
            usePage().props.auth as { capabilities?: string[] } | undefined
        )?.capabilities?.includes('inpatient.occupancy.view') ?? false;
    const isIgd = variant === 'igd';
    const isTriage = variant === 'triage';
    const isInpatient = variant === 'rawat-inap';
    const correctionMode = isInpatient && filters.scope === 'correction';
    const title = isTriage
        ? 'Clinical Care · Triage'
        : isIgd
          ? 'Clinical Care · Emergency Department'
          : isInpatient
            ? 'Clinical Care · Inpatient Care'
            : 'Clinical Care · Outpatient Care';

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
                scope: correctionMode ? 'correction' : undefined,
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
                            label: 'Outpatient Care',
                            active: variant === 'rawat-jalan',
                        },
                        {
                            href: '/pemeriksaan/igd',
                            label: 'Emergency Department',
                            active: isIgd,
                        },
                        {
                            href: '/pemeriksaan/rawat-inap',
                            label: 'Inpatient Care',
                            active: isInpatient,
                        },
                        {
                            href: '/pemeriksaan/triage',
                            label: 'Triage',
                            active: isTriage,
                        },
                        {
                            href: '/pemeriksaan/laboratorium',
                            label: 'Laboratory',
                        },
                        {
                            href: '/pemeriksaan/radiologi',
                            label: 'Radiology',
                        },
                    ]}
                />

                <header className="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                            {title}
                        </h1>
                        <p className="mt-0.5 text-xs text-[#64748b]">
                            {correctionMode
                                ? 'Completed episodes eligible for controlled discharge-summary correction.'
                                : 'Active-encounter worklist for clinical examination and documentation.'}
                            {isTriage
                                ? ' Manual categories and reassessment are available in the triage details.'
                                : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        {isInpatient && canAccessCorrections ? (
                            <nav
                                aria-label="Inpatient worklist mode"
                                className="flex flex-wrap gap-2"
                            >
                                <Button
                                    asChild
                                    type="button"
                                    variant={
                                        correctionMode ? 'outline' : 'default'
                                    }
                                    size="sm"
                                    className="min-h-11"
                                >
                                    <Link href="/pemeriksaan/rawat-inap">
                                        Inpatient
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    type="button"
                                    variant={
                                        correctionMode ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    className="min-h-11"
                                >
                                    <Link href="/pemeriksaan/rawat-inap?scope=correction">
                                        Correct discharge summary
                                    </Link>
                                </Button>
                            </nav>
                        ) : null}
                        {isInpatient && canViewBedCensus ? (
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
                    </div>
                </header>

                <form
                    onSubmit={applyFilters}
                    className="rounded-lg border border-[#d7e6f3] bg-[#f5f9fc] p-3"
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-[12rem] flex-1 gap-1">
                            <label
                                htmlFor="worklist-q"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Medical record no. / Name
                            </label>
                            <Input
                                id="worklist-q"
                                className={cn(fieldClass, 'bg-white')}
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Medical record no. / Name"
                            />
                        </div>
                        {isTriage ? (
                            <div className="grid min-w-[10rem] gap-1">
                                <label
                                    htmlFor="worklist-payer"
                                    className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                                >
                                    Payer
                                </label>
                                <select
                                    id="worklist-payer"
                                    className={fieldClass}
                                    value={payer}
                                    onChange={(e) => setPayer(e.target.value)}
                                >
                                    <option value="">— All payers —</option>
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
                                    <label
                                        htmlFor="worklist-clinic"
                                        className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                                    >
                                        {isInpatient
                                            ? 'Ward'
                                            : isIgd
                                              ? 'Unit'
                                              : 'Clinic'}
                                    </label>
                                    <select
                                        id="worklist-clinic"
                                        className={fieldClass}
                                        value={clinic}
                                        onChange={(e) =>
                                            setClinic(e.target.value)
                                        }
                                    >
                                        <option value="">
                                            {isInpatient
                                                ? 'All wards'
                                                : isIgd
                                                  ? 'All emergency units'
                                                  : 'All clinics'}
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
                                            <label
                                                htmlFor="worklist-payer"
                                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                                            >
                                                Payer
                                            </label>
                                            <select
                                                id="worklist-payer"
                                                className={fieldClass}
                                                value={payer}
                                                onChange={(e) =>
                                                    setPayer(e.target.value)
                                                }
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
                                                htmlFor="worklist-continue-from"
                                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                                            >
                                                Origin
                                            </label>
                                            <select
                                                id="worklist-continue-from"
                                                className={fieldClass}
                                                value={continueFrom}
                                                onChange={(e) =>
                                                    setContinueFrom(
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                <option value="">All</option>
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
                            <label
                                htmlFor="worklist-date-from"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                From
                            </label>
                            <Input
                                id="worklist-date-from"
                                type="date"
                                className={fieldClass}
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                            />
                        </div>
                        <div className="grid min-w-[9rem] gap-1">
                            <label
                                htmlFor="worklist-date-to"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                To
                            </label>
                            <Input
                                id="worklist-date-to"
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
                            Apply
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
                                    Show repeat-visit patients
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
                                    Show internal-consultation patients
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
                            Show cancelled patients
                        </label>
                    </div>
                </form>

                <section className="min-w-0 rounded-lg border border-[#e2e8f0] bg-white p-3">
                    <div className="min-w-0 overflow-x-auto">
                        <table className="w-full min-w-[56rem] text-left text-sm">
                            <caption className="sr-only">
                                {correctionMode
                                    ? 'Episodes available for discharge-summary correction'
                                    : 'Patients in the examination worklist'}
                            </caption>
                            <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th
                                        scope="col"
                                        className="px-2 py-1.5 font-medium"
                                    >
                                        Queue
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-2 py-1.5 font-medium"
                                    >
                                        Medical record no.
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-2 py-1.5 font-medium"
                                    >
                                        Name
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-2 py-1.5 font-medium"
                                    >
                                        {isInpatient
                                            ? 'Ward'
                                            : isIgd || isTriage
                                              ? 'Unit'
                                              : 'Clinic'}
                                    </th>
                                    {isInpatient ? (
                                        <>
                                            <th className="px-2 py-1.5 font-medium">
                                                Class
                                            </th>
                                            <th className="px-2 py-1.5 font-medium">
                                                TT
                                            </th>
                                            <th className="px-2 py-1.5 font-medium">
                                                Origin
                                            </th>
                                        </>
                                    ) : (
                                        <>
                                            <th className="px-2 py-1.5 font-medium">
                                                Physician
                                            </th>
                                            <th className="px-2 py-1.5 font-medium">
                                                {isIgd || isTriage
                                                    ? 'Shift'
                                                    : 'Schedule'}
                                            </th>
                                        </>
                                    )}
                                    {(isIgd || isTriage) && !isInpatient && (
                                        <th className="px-2 py-1.5 font-medium">
                                            Case
                                        </th>
                                    )}
                                    <th className="px-2 py-1.5 font-medium">
                                        Payer
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Status
                                    </th>
                                    <th className="px-2 py-1.5 font-medium">
                                        Complaint
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-2 py-1.5 font-medium"
                                    >
                                        <span className="sr-only">Action</span>
                                    </th>
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
                                            {correctionMode
                                                ? 'No completed episodes match the correction filter.'
                                                : 'No active encounters match these filters.'}
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
                                                        aria-label={`${isTriage ? 'Open emergency episode' : 'Open'} for ${encounter.patient.full_name}`}
                                                        className="text-sm font-medium text-[#1b75bc] hover:underline"
                                                    >
                                                        {isTriage
                                                            ? 'Open emergency episode'
                                                            : 'Open'}
                                                    </Link>
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <OperationalPagination
                        pagination={pagination}
                        itemLabel={
                            correctionMode
                                ? 'correction episode'
                                : 'active encounter'
                        }
                        className="mt-3"
                    />
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
                ? 'Inpatient Care'
                : 'Outpatient Care';

    return {
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Clinical Care', href: indexPath },
            { title: label, href: indexPath },
        ] satisfies BreadcrumbItem[],
    };
};
