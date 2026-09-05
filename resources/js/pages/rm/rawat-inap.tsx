import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem } from '@/types';
import type { InpatientRmIndex } from './rawat-inap/types';
import { reviewStatusLabel } from './rawat-inap/types';

type Props = { inpatient_rm: InpatientRmIndex };

const fieldClass =
    'h-9 rounded-md border border-input bg-background px-2.5 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/20';

export default function RmRawatInap({ inpatient_rm: data }: Props) {
    const [filters, setFilters] = useState(data.filters);
    const setFilter = (key: keyof typeof filters, value: string) =>
        setFilters((current) => ({ ...current, [key]: value }));
    const applyFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            '/rm/rawat-inap',
            Object.fromEntries(
                Object.entries(filters).filter(([, value]) => Boolean(value)),
            ),
            { preserveState: true, replace: true },
        );
    };
    const oldestDischargedFirst = [...data.encounters].sort((left, right) =>
        (left.discharged_at ?? '').localeCompare(right.discharged_at ?? ''),
    );

    return (
        <>
            <Head title="Inpatient Medical Records" />
            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-3 px-3 py-4 md:px-5 md:py-5">
                <CareSettingSubnav
                    items={[
                        { href: '/rm/rawat-jalan', label: 'Outpatient Care' },
                        {
                            href: '/rm/rawat-inap',
                            label: 'Inpatient Care',
                            active: true,
                        },
                    ]}
                />
                <header>
                    <h1 className="text-xl font-semibold tracking-tight text-foreground md:text-2xl">
                        Medical Records · Inpatient Care
                    </h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Discharged episodes are shown from oldest to newest for
                        medical-record review and coding.
                    </p>
                </header>

                <form
                    onSubmit={applyFilters}
                    className="rounded-lg border border-primary/15 bg-muted/30 p-3"
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <label className="grid min-w-[13rem] flex-1 gap-1 text-xs font-medium text-muted-foreground">
                            Medical record no. / Name
                            <Input
                                value={filters.q}
                                onChange={(event) =>
                                    setFilter('q', event.target.value)
                                }
                                className={fieldClass}
                                placeholder="Medical record no. or name"
                            />
                        </label>
                        <SelectFilter
                            label="Last ward"
                            value={filters.ward}
                            options={data.filter_options.wards}
                            onChange={(value) => setFilter('ward', value)}
                            empty="All wards"
                        />
                        <SelectFilter
                            label="Payer"
                            value={filters.payer}
                            options={data.filter_options.payers}
                            onChange={(value) => setFilter('payer', value)}
                            empty="All payers"
                        />
                        <SelectFilter
                            label="Review status"
                            value={filters.review_state}
                            options={data.filter_options.review_states}
                            onChange={(value) =>
                                setFilter('review_state', value)
                            }
                            empty="Semua status"
                        />
                        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                            Discharge date from
                            <Input
                                type="date"
                                value={filters.discharged_from}
                                onChange={(event) =>
                                    setFilter(
                                        'discharged_from',
                                        event.target.value,
                                    )
                                }
                                className={fieldClass}
                            />
                        </label>
                        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                            Discharge date to
                            <Input
                                type="date"
                                value={filters.discharged_to}
                                onChange={(event) =>
                                    setFilter(
                                        'discharged_to',
                                        event.target.value,
                                    )
                                }
                                className={fieldClass}
                            />
                        </label>
                        <Button type="submit" className="h-9">
                            Tampilkan
                        </Button>
                    </div>
                </form>

                <section
                    aria-label="Inpatient episode worklist"
                    className="rounded-lg border border-border bg-card p-3"
                >
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[52rem] text-left text-sm">
                            <caption className="sr-only">
                                Discharged inpatient episodes, ordered from the
                                earliest discharge date.
                            </caption>
                            <thead className="border-b border-border text-xs tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-2 py-2">
                                        Discharge date
                                    </th>
                                    <th className="px-2 py-2">MRN</th>
                                    <th className="px-2 py-2">Name</th>
                                    <th className="px-2 py-2">Last ward</th>
                                    <th className="px-2 py-2">Payer</th>
                                    <th className="px-2 py-2">Review</th>
                                    <th className="px-2 py-2">
                                        <span className="sr-only">Open</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {oldestDischargedFirst.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-2 py-8 text-muted-foreground"
                                        >
                                            No discharged episodes for filter
                                            ini.
                                        </td>
                                    </tr>
                                ) : (
                                    oldestDischargedFirst.map((encounter) => (
                                        <tr
                                            key={encounter.public_id}
                                            className="border-b border-border/60"
                                        >
                                            <td className="px-2 py-2 font-mono text-xs">
                                                {encounter.discharged_at ?? '—'}
                                            </td>
                                            <td className="px-2 py-2 font-mono text-xs">
                                                {encounter.patient
                                                    .medical_record_number ??
                                                    '—'}
                                            </td>
                                            <td className="px-2 py-2 font-medium">
                                                {encounter.patient.full_name ??
                                                    'Patient name unavailable'}
                                            </td>
                                            <td className="px-2 py-2">
                                                {encounter.last_ward_name ??
                                                    '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                {encounter.payer_type ?? '—'}
                                            </td>
                                            <td className="px-2 py-2">
                                                <span className="text-xs font-semibold">
                                                    {
                                                        reviewStatusLabel[
                                                            encounter
                                                                .review_status
                                                        ]
                                                    }
                                                </span>
                                                <p className="font-mono text-xs text-muted-foreground">
                                                    v{encounter.review_version}
                                                </p>
                                            </td>
                                            <td className="px-2 py-2 text-right">
                                                <Link
                                                    href={`${data.actions.show_url}/${encounter.public_id}`}
                                                    className="inline-flex min-h-11 items-center rounded-md border border-primary/30 bg-primary/5 px-3 text-xs font-semibold text-primary hover:bg-primary/10"
                                                >
                                                    {encounter.review_status ===
                                                    'SIGNED_OFF'
                                                        ? 'View archive'
                                                        : 'Review medical record'}
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

function SelectFilter({
    label,
    value,
    options,
    onChange,
    empty,
}: {
    label: string;
    value: string;
    options: InpatientRmIndex['filter_options']['wards'];
    onChange: (value: string) => void;
    empty: string;
}) {
    return (
        <label className="grid min-w-[10rem] gap-1 text-xs font-medium text-muted-foreground">
            {label}
            <select
                className={fieldClass}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">{empty}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

RmRawatInap.layout = {
    breadcrumbs: [
        { title: 'Home', href: '/' },
        { title: 'Medical Records', href: '/rm/rawat-jalan' },
        { title: 'Inpatient Care', href: '/rm/rawat-inap' },
    ] satisfies BreadcrumbItem[],
};
