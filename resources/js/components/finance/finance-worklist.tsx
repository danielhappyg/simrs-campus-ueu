import { Link, router } from '@inertiajs/react';
import { FileText, Landmark, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    careSettingLabels,
    FinanceCoverageRail,
    FinanceStateBadge,
    formatFinanceDate,
    formatRupiah,
    financeSourceDomainLabels,
} from './finance-shared';
import type { FinanceSourceReadiness, FinanceWorklistProps } from './types';

function ReadinessCounts({ readiness }: { readiness: FinanceSourceReadiness }) {
    return (
        <div className="space-y-1 text-xs">
            <p className="font-semibold text-emerald-800">
                {readiness.resolved_count} resolved
            </p>
            <p
                className={
                    readiness.unresolved_count
                        ? 'font-semibold text-amber-900'
                        : 'text-slate-600'
                }
            >
                {readiness.unresolved_count} not yet resolved
            </p>
            {readiness.issue_blocked ? (
                <p className="font-semibold text-red-800">Issuance blocked</p>
            ) : null}
            {readiness.items.length ? (
                <ul
                    aria-label="Charge-source readiness"
                    className="space-y-1 pt-1 text-slate-700"
                >
                    {readiness.items.map((item) => (
                        <li key={item.public_id}>
                            <span className="font-semibold">
                                {financeSourceDomainLabels[item.source_domain]}
                            </span>{' '}
                            · {item.description} — {item.state_label}
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

function SynchronizeButton({
    url,
    encounterNumber,
}: {
    url: string;
    encounterNumber: string;
}) {
    const [processing, setProcessing] = useState(false);
    const [status, setStatus] = useState('');

    const synchronize = () => {
        setProcessing(true);
        setStatus('Synchronizing valid charge sources…');
        router.post(
            url,
            {
                idempotency_key: `finance-sync-${Date.now()}-${crypto.randomUUID()}`,
            },
            {
                preserveScroll: true,
                onError: () => {
                    setProcessing(false);
                    setStatus('Charge source not yet synchronized.');
                },
                onSuccess: () => setStatus('Charge source synchronized.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div>
            <Button
                type="button"
                variant="outline"
                disabled={processing}
                onClick={synchronize}
                className="min-h-11 border-[#1b75bc] text-[#0d5275]"
                aria-label={`Synchronize valid sources for episode ${encounterNumber}`}
            >
                <RefreshCw
                    aria-hidden="true"
                    className={processing ? 'animate-spin' : ''}
                />
                {processing ? 'Synchronizing…' : 'Synchronize valid sources'}
            </Button>
            <span role="status" aria-live="polite" className="sr-only">
                {status}
            </span>
        </div>
    );
}

export function FinanceWorklist({
    bills,
    synchronization_candidates,
    coverage,
    generated_at,
    read_error,
}: FinanceWorklistProps) {
    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <header className="relative overflow-hidden rounded-2xl bg-[#123b5d] p-6 text-white shadow-sm">
                    <span
                        aria-hidden="true"
                        className="absolute inset-y-0 left-0 w-2 bg-[#24a69a]"
                    />
                    <div className="relative flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="flex items-center gap-2 text-xs font-semibold tracking-[0.14em] text-sky-100 uppercase">
                                <Landmark
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Cross-service cashier
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                Bill List
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-sky-50">
                                Review charge sources by episode and issue a new
                                version without changing prior history.
                            </p>
                        </div>
                        <p className="rounded-md bg-white/10 px-3 py-2 font-['IBM_Plex_Mono'] text-xs">
                            Updated {generated_at}
                        </p>
                    </div>
                </header>

                <FinanceCoverageRail
                    label={coverage.label}
                    excludedLabel={coverage.excluded_label}
                    domains={coverage.domains}
                />

                {read_error ? (
                    <div
                        role="alert"
                        className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-950"
                    >
                        <p className="font-semibold">
                            The bill list could not be loaded.
                        </p>
                        <p className="mt-1">{read_error}</p>
                    </div>
                ) : null}

                {synchronization_candidates.length ? (
                    <section aria-labelledby="finance-sync-heading">
                        <div className="mb-3">
                            <h2
                                id="finance-sync-heading"
                                className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-slate-950"
                            >
                                Charge sources ready to process
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Synchronize only dispensed pharmacy items,
                                completed radiology examinations, valid VERIFIED
                                laboratory results, or closed accommodation days
                                with an exact tariff. Unresolved lines remain
                                visible and are never ignored.
                            </p>
                        </div>
                        <div className="overflow-x-auto rounded-xl border border-[#7fbcb6] bg-white shadow-sm">
                            <table className="w-full min-w-[58rem] border-collapse text-left text-sm">
                                <caption className="sr-only">
                                    Episodes with valid unsynchronized charge
                                    sources
                                </caption>
                                <thead className="border-b border-[#7fbcb6] bg-[#e8f5f3] text-xs tracking-wide text-[#0b4147] uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Episode
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Patient
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Service
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Source
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Net
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Charge Readiness
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            <span className="sr-only">
                                                Action
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {synchronization_candidates.map(
                                        (candidate) => (
                                            <tr
                                                key={
                                                    candidate.encounter_public_id
                                                }
                                                className="align-top"
                                            >
                                                <th
                                                    scope="row"
                                                    className="px-4 py-4 font-['IBM_Plex_Mono'] font-medium text-slate-950"
                                                >
                                                    {
                                                        candidate.encounter_public_id
                                                    }
                                                </th>
                                                <td className="px-4 py-4">
                                                    <span className="block font-semibold text-slate-950">
                                                        {
                                                            candidate.patient
                                                                .full_name
                                                        }
                                                    </span>
                                                    <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs text-slate-500">
                                                        {
                                                            candidate.patient
                                                                .medical_record_number
                                                        }
                                                    </span>
                                                </td>
                                                <td className="px-4 py-4 text-slate-700">
                                                    {
                                                        careSettingLabels[
                                                            candidate
                                                                .care_setting
                                                        ]
                                                    }
                                                </td>
                                                <td className="px-4 py-4 text-right font-['IBM_Plex_Mono'] tabular-nums">
                                                    {
                                                        candidate.source_event_count
                                                    }
                                                </td>
                                                <td className="px-4 py-4 text-right font-['IBM_Plex_Mono'] font-semibold tabular-nums">
                                                    {formatRupiah(
                                                        candidate.net_amount,
                                                    )}
                                                </td>
                                                <td className="px-4 py-4">
                                                    <ReadinessCounts
                                                        readiness={
                                                            candidate.source_readiness
                                                        }
                                                    />
                                                </td>
                                                <td className="px-4 py-3">
                                                    <SynchronizeButton
                                                        url={
                                                            candidate.synchronize_url
                                                        }
                                                        encounterNumber={
                                                            candidate.encounter_public_id
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}

                <section aria-labelledby="finance-worklist-heading">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h2
                            id="finance-worklist-heading"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-slate-950"
                        >
                            {bills.length} episode with charge source
                        </h2>
                    </div>

                    {bills.length ? (
                        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                            <table className="w-full min-w-[70rem] border-collapse text-left text-sm">
                                <caption className="sr-only">
                                    Patient episode bills and reconciliation
                                    status
                                </caption>
                                <thead className="border-b border-slate-300 bg-slate-100 text-xs tracking-wide text-slate-700 uppercase">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Episode
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Patient
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Service
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Version
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Charge
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Returns
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Net
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Reconciliation
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Charge Readiness
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Latest source
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            <span className="sr-only">
                                                Open
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {bills.map((bill) => (
                                        <tr
                                            key={bill.public_id}
                                            className="align-top hover:bg-sky-50/60"
                                        >
                                            <th
                                                scope="row"
                                                className="px-4 py-4 font-semibold text-slate-950"
                                            >
                                                <span className="block">
                                                    {
                                                        bill.encounter
                                                            .encounter_number
                                                    }
                                                </span>
                                                <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs font-normal text-slate-500">
                                                    {bill.bill_number}
                                                </span>
                                            </th>
                                            <td className="px-4 py-4">
                                                <span className="block font-medium text-slate-950">
                                                    {
                                                        bill.encounter.patient
                                                            .full_name
                                                    }
                                                </span>
                                                <span className="mt-1 block font-['IBM_Plex_Mono'] text-xs text-slate-500">
                                                    {
                                                        bill.encounter.patient
                                                            .medical_record_number
                                                    }
                                                </span>
                                            </td>
                                            <td className="px-4 py-4 text-slate-700">
                                                {
                                                    careSettingLabels[
                                                        bill.encounter
                                                            .care_setting
                                                    ]
                                                }
                                            </td>
                                            <td className="px-4 py-4 font-['IBM_Plex_Mono'] text-slate-700">
                                                {bill.current_version > 0
                                                    ? `v${bill.current_version}`
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-4 text-right font-['IBM_Plex_Mono'] text-slate-700 tabular-nums">
                                                {formatRupiah(
                                                    bill.totals.gross_amount,
                                                )}
                                            </td>
                                            <td className="px-4 py-4 text-right font-['IBM_Plex_Mono'] text-slate-700 tabular-nums">
                                                {formatRupiah(
                                                    bill.totals.reversal_amount,
                                                )}
                                            </td>
                                            <td className="px-4 py-4 text-right font-['IBM_Plex_Mono'] font-semibold text-slate-950 tabular-nums">
                                                {formatRupiah(
                                                    bill.totals.net_amount,
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                {bill.synchronization_available ? (
                                                    <span className="inline-flex rounded-full border border-violet-300 bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-950">
                                                        Perlu sinkronisasi ·{' '}
                                                        {
                                                            bill.pending_source_count
                                                        }{' '}
                                                        source
                                                    </span>
                                                ) : (
                                                    <FinanceStateBadge
                                                        state={bill.state}
                                                    />
                                                )}
                                            </td>
                                            <td className="px-4 py-4">
                                                <ReadinessCounts
                                                    readiness={
                                                        bill.source_readiness
                                                    }
                                                />
                                            </td>
                                            <td className="px-4 py-4 text-slate-600">
                                                {formatFinanceDate(
                                                    bill.latest_source_at,
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap gap-2">
                                                    {bill.actions
                                                        .synchronize_url ? (
                                                        <SynchronizeButton
                                                            url={
                                                                bill.actions
                                                                    .synchronize_url
                                                            }
                                                            encounterNumber={
                                                                bill.encounter
                                                                    .encounter_number
                                                            }
                                                        />
                                                    ) : null}
                                                    <Link
                                                        href={
                                                            bill.actions
                                                                .show_url
                                                        }
                                                        className="inline-flex min-h-11 items-center gap-2 rounded-md border border-[#1b75bc] px-3 font-semibold text-[#0d5275] outline-none hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc]"
                                                        aria-label={`Open detail bill ${bill.encounter.encounter_number}`}
                                                    >
                                                        <FileText
                                                            aria-hidden="true"
                                                            className="size-4"
                                                        />
                                                        Detail
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
                            <p className="font-semibold text-slate-950">
                                No billable charge sources
                            </p>
                            <p className="mt-1 text-sm text-slate-600">
                                Episodes appear after a billable pharmacy item,
                                completed radiology examination, valid VERIFIED
                                laboratory result, or reconciled closed
                                accommodation day becomes available.
                            </p>
                        </div>
                    )}
                </section>
            </div>
        </main>
    );
}
