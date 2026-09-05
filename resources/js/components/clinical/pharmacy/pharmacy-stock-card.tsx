import { router, useForm } from '@inertiajs/react';
import { Filter, ShieldAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    formatPharmacyDate,
    newPharmacyOperationKey,
    pharmacyFieldClass,
} from './operation';
import { PharmacyErrorSummary } from './pharmacy-errors';
import { PharmacyEmptyState, PharmacySubnav } from './pharmacy-shared';
import type { PharmacyStockCardLot, PharmacyStockCardProps } from './types';

function LotActions({ lot }: { lot: PharmacyStockCardLot }) {
    const form = useForm({
        available_delta: 0,
        quarantined_delta: 0,
        reason_code: '',
        idempotency_key: newPharmacyOperationKey('pharmacy-stock'),
    });
    const submitCorrection = (event: FormEvent) => {
        event.preventDefault();

        if (lot.actions.correct_url) {
            form.post(lot.actions.correct_url, {
                preserveScroll: true,
                errorBag: `pharmacyStock.${lot.public_id}`,
            });
        }
    };
    const state = (url: string) => {
        form.post(url, {
            preserveScroll: true,
            errorBag: `pharmacyStockState.${lot.public_id}`,
        });
    };

    if (
        !lot.actions.correct_url &&
        !lot.actions.quarantine_url &&
        !lot.actions.release_url
    ) {
        return null;
    }

    return (
        <details className="mt-3 rounded-lg border bg-background p-3">
            <summary className="flex min-h-11 cursor-pointer items-center font-semibold">
                Stock controls
            </summary>
            <PharmacyErrorSummary errors={form.errors} />
            {lot.actions.correct_url ? (
                <form
                    onSubmit={submitCorrection}
                    className="mt-3 grid gap-3 sm:grid-cols-4 sm:items-end"
                >
                    <div>
                        <Label htmlFor={`available-delta-${lot.public_id}`}>
                            Available adjustment
                        </Label>
                        <input
                            id={`available-delta-${lot.public_id}`}
                            type="number"
                            step={1}
                            className={pharmacyFieldClass}
                            value={form.data.available_delta}
                            onChange={(event) =>
                                form.setData(
                                    'available_delta',
                                    Number(event.target.value),
                                )
                            }
                        />
                    </div>
                    <div>
                        <Label htmlFor={`quarantine-delta-${lot.public_id}`}>
                            Quarantine adjustment
                        </Label>
                        <input
                            id={`quarantine-delta-${lot.public_id}`}
                            type="number"
                            step={1}
                            className={pharmacyFieldClass}
                            value={form.data.quarantined_delta}
                            onChange={(event) =>
                                form.setData(
                                    'quarantined_delta',
                                    Number(event.target.value),
                                )
                            }
                        />
                    </div>
                    <div>
                        <Label htmlFor={`stock-reason-${lot.public_id}`}>
                            Reason for correction or status change
                        </Label>
                        <select
                            id={`stock-reason-${lot.public_id}`}
                            className={pharmacyFieldClass}
                            value={form.data.reason_code}
                            onChange={(event) =>
                                form.setData('reason_code', event.target.value)
                            }
                            required
                        >
                            <option value="">Select reason</option>
                            <option value="INVENTORY_CORRECTION">
                                Inventory correction
                            </option>
                            <option value="DAMAGED_PACKAGE">
                                Damaged packaging
                            </option>
                            <option value="EXPIRED">Expired</option>
                            <option value="QUALITY_HOLD">Quality hold</option>
                            <option value="OTHER">Other reason</option>
                        </select>
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        className="min-h-11"
                        disabled={!form.data.reason_code.trim()}
                    >
                        Record correction
                    </Button>
                </form>
            ) : null}
            <div className="mt-3 flex flex-wrap gap-2">
                {lot.actions.quarantine_url ? (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        disabled={!form.data.reason_code.trim()}
                        onClick={() => state(lot.actions.quarantine_url!)}
                    >
                        <ShieldAlert className="mr-2 size-4" />
                        Quarantine lot
                    </Button>
                ) : null}
                {lot.actions.release_url ? (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        disabled={!form.data.reason_code.trim()}
                        onClick={() => state(lot.actions.release_url!)}
                    >
                        Release from quarantine
                    </Button>
                ) : null}
            </div>
        </details>
    );
}

export function PharmacyStockCard(props: PharmacyStockCardProps) {
    const filter = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(
            '/apotek/kartu-stok',
            Object.fromEntries(new FormData(event.currentTarget)),
            { preserveState: true, replace: true },
        );
    };

    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <PharmacySubnav current="stock" />
                <header className="rounded-2xl bg-[#123b5d] p-6 text-white">
                    <p className="text-xs font-semibold tracking-[0.14em] text-sky-100 uppercase">
                        Traceable inventory
                    </p>
                    <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                        Stock card by lot
                    </h1>
                    <p className="mt-2 text-sm text-sky-50">
                        Balances, quarantine, and every movement are displayed
                        as evidence that cannot be overwritten.
                    </p>
                </header>
                {props.read_error ? (
                    <div
                        role="alert"
                        className="rounded-lg border border-red-300 bg-red-50 p-4 text-red-900"
                    >
                        {props.read_error}
                    </div>
                ) : null}
                <form
                    onSubmit={filter}
                    className="rounded-xl border bg-card p-4"
                >
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Filter className="size-4 text-primary" />
                        Filter stock card
                    </h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="sm:col-span-2">
                            <Label htmlFor="stock-query">
                                Lot or medication code
                            </Label>
                            <input
                                id="stock-query"
                                name="q"
                                defaultValue={props.filters.q}
                                className={pharmacyFieldClass}
                            />
                        </div>
                        <div>
                            <Label htmlFor="stock-medicine">Medication</Label>
                            <select
                                id="stock-medicine"
                                name="medicine"
                                defaultValue={props.filters.medicine}
                                className={pharmacyFieldClass}
                            >
                                <option value="">All medications</option>
                                {props.filter_options.medicines.map(
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
                        <div>
                            <Label htmlFor="stock-depot">Depot</Label>
                            <select
                                id="stock-depot"
                                name="depot"
                                defaultValue={props.filters.depot}
                                className={pharmacyFieldClass}
                            >
                                <option value="">All depots</option>
                                {props.filter_options.depots.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="stock-state">Status</Label>
                            <select
                                id="stock-state"
                                name="state"
                                defaultValue={props.filters.state}
                                className={pharmacyFieldClass}
                            >
                                <option value="">All statuses</option>
                                {props.filter_options.states.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="mt-3 flex justify-end">
                        <Button type="submit" className="min-h-11">
                            Apply filters
                        </Button>
                    </div>
                </form>
                <section aria-labelledby="stock-results">
                    <h2
                        id="stock-results"
                        className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                    >
                        {props.lots.length} lots found
                    </h2>
                    {props.lots.length ? (
                        <div className="mt-3 space-y-4">
                            {props.lots.map((lot) => (
                                <article
                                    key={lot.public_id}
                                    className="rounded-xl border bg-card p-4 shadow-sm"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-mono text-xs font-semibold text-primary">
                                                {lot.medicine.code} · LOT{' '}
                                                {lot.lot_code}
                                            </p>
                                            <h3 className="text-lg font-semibold">
                                                {
                                                    lot.medicine
                                                        .generic_display_name
                                                }{' '}
                                                {lot.medicine.strength_text}
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                {lot.depot.display_name} ·
                                                expires{' '}
                                                {lot.expiry_date ??
                                                    lot.no_expiry_reason ??
                                                    '—'}
                                            </p>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3 text-right">
                                            <div>
                                                <p className="text-xs text-muted-foreground">
                                                    Available
                                                </p>
                                                <p className="font-mono text-xl font-semibold">
                                                    {lot.available_quantity}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-xs text-muted-foreground">
                                                    Quarantined
                                                </p>
                                                <p className="font-mono text-xl font-semibold">
                                                    {lot.quarantined_quantity}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        className={`mt-3 rounded-md px-3 py-2 text-sm font-semibold ${lot.reconciled ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-900'}`}
                                        role="status"
                                        aria-live="polite"
                                    >
                                        {lot.reconciled
                                            ? 'Balance matches all movements'
                                            : 'Reconciliation needed: balance and history do not match'}
                                    </div>
                                    <div className="mt-3 overflow-x-auto rounded-lg border">
                                        <table className="w-full min-w-[850px] text-left text-sm">
                                            <caption className="sr-only">
                                                Stock movement for lot{' '}
                                                {lot.lot_code}
                                            </caption>
                                            <thead className="bg-muted/60">
                                                <tr>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2"
                                                    >
                                                        Time
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2"
                                                    >
                                                        Type
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2"
                                                    >
                                                        Δ available
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2"
                                                    >
                                                        Δ quarantine
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2"
                                                    >
                                                        Closing balance
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        className="px-3 py-2"
                                                    >
                                                        Reference
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {lot.movements.map(
                                                    (movement) => (
                                                        <tr
                                                            key={
                                                                movement.public_id
                                                            }
                                                            className="border-t"
                                                        >
                                                            <td className="px-3 py-2">
                                                                {formatPharmacyDate(
                                                                    movement.occurred_at,
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2 font-semibold">
                                                                {
                                                                    movement.movement_type
                                                                }
                                                            </td>
                                                            <td className="px-3 py-2 font-mono">
                                                                {movement.available_delta >
                                                                0
                                                                    ? '+'
                                                                    : ''}
                                                                {
                                                                    movement.available_delta
                                                                }
                                                            </td>
                                                            <td className="px-3 py-2 font-mono">
                                                                {movement.quarantined_delta >
                                                                0
                                                                    ? '+'
                                                                    : ''}
                                                                {
                                                                    movement.quarantined_delta
                                                                }
                                                            </td>
                                                            <td className="px-3 py-2 font-mono">
                                                                {
                                                                    movement.available_balance
                                                                }{' '}
                                                                /{' '}
                                                                {
                                                                    movement.quarantined_balance
                                                                }
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {movement.source_reference ??
                                                                    movement.reason ??
                                                                    '—'}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                    <LotActions lot={lot} />
                                </article>
                            ))}
                        </div>
                    ) : (
                        <PharmacyEmptyState
                            title="No lots match these filters"
                            body="Change the filters or record an opening balance from Medication & Depot Master."
                        />
                    )}
                </section>
            </div>
        </main>
    );
}
