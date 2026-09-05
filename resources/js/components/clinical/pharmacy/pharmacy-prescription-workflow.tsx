import { useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    PackageCheck,
    RotateCcw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { newPharmacyOperationKey, pharmacyFieldClass } from './operation';
import { PharmacyErrorSummary } from './pharmacy-errors';
import {
    PharmacyControlTotals,
    PharmacyPrescriptionCard,
    PharmacyPrescriptionHistory,
    PharmacyPrescriptionItems,
} from './pharmacy-prescription-card';
import { PharmacyEvidenceTime } from './pharmacy-shared';
import type {
    PharmacyPrescriptionProjection,
    PharmacyReturnCondition,
    PharmacyWorklistProps,
} from './types';

function DecisionPanel({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    const [decision, setDecision] = useState<'verify' | 'refuse'>('verify');
    const form = useForm({
        expected_fingerprint: prescription.fingerprint,
        manual_allergy_review: '',
        checklist: {
            identity_confirmed: false,
            context_confirmed: false,
            medicine_readable: false,
            instruction_readable: false,
        },
        item_decisions: prescription.items.map((item) => ({
            item_public_id: item.public_id,
            verified_quantity: item.requested_quantity,
            reason_code: '',
        })),
        reason_code: '',
        note: '',
        idempotency_key: newPharmacyOperationKey('pharmacy-verification'),
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const url =
            decision === 'verify'
                ? prescription.actions.verify_url
                : prescription.actions.refuse_url;

        if (!url) {
            return;
        }

        form.post(url, {
            preserveScroll: true,
            errorBag: `pharmacyVerification.${prescription.public_id}`,
        });
    };
    const setItem = (
        index: number,
        key: 'verified_quantity' | 'reason_code',
        value: number | string,
    ) => {
        const next = [...form.data.item_decisions];
        next[index] = { ...next[index], [key]: value };
        form.setData('item_decisions', next);
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-4 rounded-xl border border-[#24a69a]/40 bg-[#24a69a]/5 p-4"
        >
            <div>
                <p className="text-xs font-semibold tracking-[0.12em] text-[#13766f] uppercase">
                    Pharmacist responsibility
                </p>
                <h2 className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold">
                    Manual prescription verification
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    The system records your decision; it does not recommend a
                    dose, allergy assessment, interaction assessment, or
                    clinical suitability.
                </p>
            </div>
            <PharmacyErrorSummary errors={form.errors} />
            <fieldset className="space-y-2">
                <legend className="font-semibold">Decision</legend>
                <div className="grid gap-2 sm:grid-cols-2">
                    {(['verify', 'refuse'] as const).map((value) => (
                        <label
                            key={value}
                            className={cn(
                                'flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border bg-card px-3 py-2',
                                decision === value &&
                                    'border-primary ring-2 ring-primary/20',
                            )}
                        >
                            <input
                                type="radio"
                                name="verification-decision"
                                checked={decision === value}
                                onChange={() => setDecision(value)}
                            />
                            <span className="font-semibold">
                                {value === 'verify'
                                    ? 'Verify'
                                    : 'Refuse prescription'}
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>
            <div>
                <Label htmlFor="allergy-review">
                    Manual allergy-review result
                </Label>
                <select
                    id="allergy-review"
                    className={pharmacyFieldClass}
                    value={form.data.manual_allergy_review}
                    onChange={(event) =>
                        form.setData(
                            'manual_allergy_review',
                            event.target.value,
                        )
                    }
                    required
                >
                    <option value="">Select review result</option>
                    <option value="REVIEWED_NO_CONFLICT">
                        Reviewed; no recorded conflict found
                    </option>
                    <option value="REVIEWED_WITH_NOTE">
                        Reviewed with note
                    </option>
                    <option value="UNKNOWN_BLOCKED">
                        Information unknown — verification blocked
                    </option>
                </select>
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                <label className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 py-2">
                    <input
                        type="checkbox"
                        checked={form.data.checklist.identity_confirmed}
                        onChange={(event) =>
                            form.setData('checklist', {
                                ...form.data.checklist,
                                identity_confirmed: event.target.checked,
                            })
                        }
                    />
                    Patient identity confirmed
                </label>
                <label className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 py-2">
                    <input
                        type="checkbox"
                        checked={form.data.checklist.context_confirmed}
                        onChange={(event) =>
                            form.setData('checklist', {
                                ...form.data.checklist,
                                context_confirmed: event.target.checked,
                            })
                        }
                    />
                    Care context confirmed
                </label>
                <label className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 py-2">
                    <input
                        type="checkbox"
                        checked={form.data.checklist.medicine_readable}
                        onChange={(event) =>
                            form.setData('checklist', {
                                ...form.data.checklist,
                                medicine_readable: event.target.checked,
                            })
                        }
                    />
                    Medication is legible
                </label>
                <label className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 py-2">
                    <input
                        type="checkbox"
                        checked={form.data.checklist.instruction_readable}
                        onChange={(event) =>
                            form.setData('checklist', {
                                ...form.data.checklist,
                                instruction_readable: event.target.checked,
                            })
                        }
                    />
                    Directions are legible
                </label>
            </div>
            {decision === 'verify' ? (
                <fieldset className="space-y-3">
                    <legend className="font-semibold">
                        Verified quantity by medication
                    </legend>
                    {prescription.items.map((item, index) => (
                        <div
                            key={item.public_id}
                            className="grid gap-3 rounded-lg border bg-card p-3 sm:grid-cols-[1fr_160px_1fr] sm:items-end"
                        >
                            <div>
                                <p className="font-semibold">
                                    {item.medicine.generic_display_name}{' '}
                                    {item.medicine.strength_text}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Requested {item.requested_quantity}{' '}
                                    {item.medicine.base_issue_unit}
                                </p>
                            </div>
                            <div>
                                <Label
                                    htmlFor={`verified-quantity-${item.public_id}`}
                                >
                                    Approved quantity
                                </Label>
                                <input
                                    id={`verified-quantity-${item.public_id}`}
                                    type="number"
                                    min={0}
                                    max={item.requested_quantity}
                                    step={1}
                                    className={pharmacyFieldClass}
                                    value={
                                        form.data.item_decisions[index]
                                            .verified_quantity
                                    }
                                    onChange={(event) =>
                                        setItem(
                                            index,
                                            'verified_quantity',
                                            Number(event.target.value),
                                        )
                                    }
                                />
                            </div>
                            <div>
                                <Label htmlFor={`reduction-${item.public_id}`}>
                                    Reduction reason
                                </Label>
                                <select
                                    id={`reduction-${item.public_id}`}
                                    className={pharmacyFieldClass}
                                    value={
                                        form.data.item_decisions[index]
                                            .reason_code
                                    }
                                    onChange={(event) =>
                                        setItem(
                                            index,
                                            'reason_code',
                                            event.target.value,
                                        )
                                    }
                                    required={
                                        form.data.item_decisions[index]
                                            .verified_quantity <
                                        item.requested_quantity
                                    }
                                >
                                    <option value="">No reduction</option>
                                    <option value="STOCK_LIMIT">
                                        Stock limitation
                                    </option>
                                    <option value="DOSAGE_ADJUSTMENT">
                                        Therapy quantity adjustment
                                    </option>
                                    <option value="SAFETY_REVIEW">
                                        Safety review result
                                    </option>
                                    <option value="OTHER">Other reason</option>
                                </select>
                            </div>
                        </div>
                    ))}
                </fieldset>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="refusal-reason">Refusal reason</Label>
                        <select
                            id="refusal-reason"
                            className={pharmacyFieldClass}
                            value={form.data.reason_code}
                            onChange={(event) =>
                                form.setData('reason_code', event.target.value)
                            }
                            required
                        >
                            <option value="">Select reason</option>
                            <option value="ALLERGY_RISK">Allergy risk</option>
                            <option value="INTERACTION_RISK">
                                Interaction risk
                            </option>
                            <option value="INCOMPLETE_INSTRUCTION">
                                Incomplete instructions
                            </option>
                            <option value="OTHER">Other reason</option>
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="refusal-note">Note</Label>
                        <textarea
                            id="refusal-note"
                            className={cn(pharmacyFieldClass, 'min-h-20')}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </div>
                </div>
            )}
            <div className="flex justify-end">
                <Button
                    type="submit"
                    className="min-h-11"
                    disabled={form.processing}
                >
                    <ShieldCheck className="mr-2 size-4" />
                    {decision === 'verify'
                        ? 'Save verification'
                        : 'Save refusal'}
                </Button>
            </div>
        </form>
    );
}

function PreparationPanel({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    const form = useForm({
        expected_fingerprint: prescription.fingerprint,
        item_quantities: Object.fromEntries(
            prescription.items.map((item) => [
                item.public_id,
                item.remaining_quantity,
            ]),
        ) as Record<string, number>,
        idempotency_key: newPharmacyOperationKey('pharmacy-prepare'),
    });
    const prepare = () =>
        prescription.actions.prepare_url &&
        form.post(prescription.actions.prepare_url, {
            preserveScroll: true,
            errorBag: `pharmacyPreparation.${prescription.public_id}`,
        });

    return (
        <section className="rounded-xl border border-amber-300 bg-amber-50 p-4">
            <h2 className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold">
                Medication preparation
            </h2>
            <p className="mt-1 text-sm text-amber-950">
                The system creates FEFO lot allocations. Expired or quarantined
                lots are not used.
            </p>
            <PharmacyErrorSummary errors={form.errors} />
            <fieldset className="mt-4 space-y-3">
                <legend className="font-semibold">
                    Quantity to prepare now
                </legend>
                {prescription.items
                    .filter((item) => item.remaining_quantity > 0)
                    .map((item) => (
                        <div
                            key={item.public_id}
                            className="grid gap-3 rounded-lg border border-amber-300 bg-white p-3 sm:grid-cols-[1fr_180px] sm:items-end"
                        >
                            <div>
                                <p className="font-semibold">
                                    {item.medicine.generic_display_name}{' '}
                                    {item.medicine.strength_text}
                                </p>
                                <p className="text-xs text-amber-950">
                                    Verified remainder {item.remaining_quantity}{' '}
                                    {item.medicine.base_issue_unit}
                                </p>
                            </div>
                            <div>
                                <Label
                                    htmlFor={`prepare-quantity-${item.public_id}`}
                                >
                                    Quantity
                                </Label>
                                <input
                                    id={`prepare-quantity-${item.public_id}`}
                                    type="number"
                                    min={0}
                                    max={item.remaining_quantity}
                                    step={1}
                                    className={pharmacyFieldClass}
                                    value={
                                        form.data.item_quantities[
                                            item.public_id
                                        ] ?? 0
                                    }
                                    onChange={(event) =>
                                        form.setData('item_quantities', {
                                            ...form.data.item_quantities,
                                            [item.public_id]: Number(
                                                event.target.value,
                                            ),
                                        })
                                    }
                                />
                            </div>
                        </div>
                    ))}
            </fieldset>
            <Button
                type="button"
                className="mt-4 min-h-11"
                onClick={prepare}
                disabled={form.processing}
            >
                <PackageCheck className="mr-2 size-4" />
                Prepare with FEFO
            </Button>
        </section>
    );
}

function HandoverPanel({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    const preparation = prescription.preparation;
    const form = useForm({
        expected_preparation_fingerprint: preparation?.fingerprint ?? '',
        partial_reason: '',
        confirm_handover: false,
        idempotency_key: newPharmacyOperationKey('pharmacy-handover'),
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (prescription.actions.handover_url) {
            form.post(prescription.actions.handover_url, {
                preserveScroll: true,
                errorBag: `pharmacyHandover.${prescription.public_id}`,
            });
        }
    };

    if (!preparation) {
        return null;
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-4 rounded-xl border border-primary/30 bg-primary/5 p-4"
        >
            <h2 className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold">
                Final handover
            </h2>
            <p className="text-sm text-muted-foreground">
                Lots and stock are recalculated at handover. If stock changes,
                processing stops so the pharmacist can review it again.
            </p>
            <PharmacyErrorSummary errors={form.errors} />
            <div className="overflow-x-auto rounded-lg border bg-card">
                <table className="w-full min-w-[620px] text-left text-sm">
                    <caption className="sr-only">
                        Medication-lot allocations to hand over
                    </caption>
                    <thead className="bg-muted/60">
                        <tr>
                            <th scope="col" className="px-3 py-2">
                                Medication
                            </th>
                            <th scope="col" className="px-3 py-2">
                                Lot
                            </th>
                            <th scope="col" className="px-3 py-2">
                                Expiry
                            </th>
                            <th scope="col" className="px-3 py-2">
                                Quantity
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {preparation.allocations.map((allocation) => (
                            <tr key={allocation.public_id} className="border-t">
                                <td className="px-3 py-2 font-semibold">
                                    {allocation.medicine_label}
                                </td>
                                <td className="px-3 py-2 font-mono">
                                    {allocation.lot_code}
                                </td>
                                <td className="px-3 py-2">
                                    {allocation.expiry_date ??
                                        allocation.no_expiry_reason ??
                                        '—'}
                                </td>
                                <td className="px-3 py-2 font-mono">
                                    {allocation.quantity}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div>
                <Label htmlFor="partial-reason">
                    Reason for partial handover
                </Label>
                <textarea
                    id="partial-reason"
                    className={cn(pharmacyFieldClass, 'min-h-20')}
                    value={form.data.partial_reason}
                    onChange={(event) =>
                        form.setData('partial_reason', event.target.value)
                    }
                />
            </div>
            <label className="flex min-h-11 items-start gap-2 rounded-lg border border-primary/30 bg-card px-3 py-3">
                <input
                    type="checkbox"
                    className="mt-1"
                    checked={form.data.confirm_handover}
                    onChange={(event) =>
                        form.setData('confirm_handover', event.target.checked)
                    }
                    required
                />
                <span>
                    <span className="block font-semibold">
                        I confirm the final handover
                    </span>
                    <span className="text-xs text-muted-foreground">
                        This action reduces stock and records the cost-value
                        source.
                    </span>
                </span>
            </label>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    className="min-h-11"
                    disabled={form.processing || !form.data.confirm_handover}
                >
                    <CheckCircle2 className="mr-2 size-4" />
                    Hand over medication
                </Button>
            </div>
        </form>
    );
}

function ReturnPanel({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    const handover = [...prescription.handovers]
        .reverse()
        .find((entry) =>
            entry.items.some((item) => item.returnable_quantity > 0),
        );
    const [open, setOpen] = useState(false);
    const returnable =
        handover?.items.filter((item) => item.returnable_quantity > 0) ?? [];
    const form = useForm({
        handover_public_id: handover?.public_id ?? '',
        expected_handover_fingerprint: handover?.fingerprint ?? '',
        reason_code: '',
        note: '',
        confirm_return: false,
        items: returnable.map((item) => ({
            handover_item_public_id: item.public_id,
            condition: 'RETURN_TO_STOCK' as PharmacyReturnCondition,
            quantity: 0,
        })),
        idempotency_key: newPharmacyOperationKey('pharmacy-return'),
    });

    if (!handover || !prescription.actions.return_url) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(prescription.actions.return_url!, {
            preserveScroll: true,
            errorBag: `pharmacyReturn.${prescription.public_id}`,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <section className="rounded-xl border border-border bg-card p-4">
            {!open ? (
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={() => setOpen(true)}
                >
                    <RotateCcw className="mr-2 size-4" />
                    Record return
                </Button>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    <h2 className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold">
                        Medication return
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        The pharmacist assesses medication condition manually. A
                        return does not alter the original handover evidence.
                    </p>
                    <PharmacyErrorSummary errors={form.errors} />
                    {returnable.map((item, index) => (
                        <div
                            key={item.public_id}
                            className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_150px_1fr]"
                        >
                            <div>
                                <p className="font-semibold">
                                    {item.medicine_label}
                                </p>
                                <p className="font-mono text-xs">
                                    Lot {item.lot_code} · maximum{' '}
                                    {item.returnable_quantity}
                                </p>
                            </div>
                            <div>
                                <Label
                                    htmlFor={`return-quantity-${item.public_id}`}
                                >
                                    Quantity
                                </Label>
                                <input
                                    id={`return-quantity-${item.public_id}`}
                                    type="number"
                                    min={0}
                                    max={item.returnable_quantity}
                                    step={1}
                                    className={pharmacyFieldClass}
                                    value={form.data.items[index].quantity}
                                    onChange={(event) => {
                                        const items = [...form.data.items];
                                        items[index] = {
                                            ...items[index],
                                            quantity: Number(
                                                event.target.value,
                                            ),
                                        };
                                        form.setData('items', items);
                                    }}
                                />
                            </div>
                            <div>
                                <Label
                                    htmlFor={`return-condition-${item.public_id}`}
                                >
                                    Condition
                                </Label>
                                <select
                                    id={`return-condition-${item.public_id}`}
                                    className={pharmacyFieldClass}
                                    value={form.data.items[index].condition}
                                    onChange={(event) => {
                                        const items = [...form.data.items];
                                        items[index] = {
                                            ...items[index],
                                            condition: event.target
                                                .value as PharmacyReturnCondition,
                                        };
                                        form.setData('items', items);
                                    }}
                                >
                                    <option value="RETURN_TO_STOCK">
                                        Return to stock
                                    </option>
                                    <option value="QUARANTINE">
                                        Quarantine
                                    </option>
                                    <option value="DESTROYED_OR_NOT_RETURNABLE">
                                        Destroyed or not returnable
                                    </option>
                                </select>
                            </div>
                        </div>
                    ))}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label htmlFor="return-reason">Return reason</Label>
                            <select
                                id="return-reason"
                                className={pharmacyFieldClass}
                                value={form.data.reason_code}
                                onChange={(event) =>
                                    form.setData(
                                        'reason_code',
                                        event.target.value,
                                    )
                                }
                                required
                            >
                                <option value="">Select reason</option>
                                <option value="PATIENT_RETURN">
                                    Returned by patient
                                </option>
                                <option value="DAMAGED_PACKAGE">
                                    Damaged packaging
                                </option>
                                <option value="DISPENSING_ERROR">
                                    Handover correction
                                </option>
                                <option value="OTHER">Other reason</option>
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="return-note">Note</Label>
                            <textarea
                                id="return-note"
                                className={cn(pharmacyFieldClass, 'min-h-20')}
                                value={form.data.note}
                                onChange={(event) =>
                                    form.setData('note', event.target.value)
                                }
                            />
                        </div>
                    </div>
                    <label className="flex min-h-11 items-center gap-2 rounded-lg border px-3 py-2">
                        <input
                            type="checkbox"
                            checked={form.data.confirm_return}
                            onChange={(event) =>
                                form.setData(
                                    'confirm_return',
                                    event.target.checked,
                                )
                            }
                            required
                        />
                        I confirm the return quantity and condition
                    </label>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            className="min-h-11"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={
                                form.processing || !form.data.confirm_return
                            }
                        >
                            Save return
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}

function CloseUnfilledPanel({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    const form = useForm({
        expected_fingerprint: prescription.fingerprint,
        reason_code: '',
        idempotency_key: newPharmacyOperationKey('pharmacy-close-unfilled'),
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (prescription.actions.close_unfilled_url) {
            form.post(prescription.actions.close_unfilled_url, {
                preserveScroll: true,
                errorBag: `pharmacyCloseUnfilled.${prescription.public_id}`,
            });
        }
    };

    if (!prescription.actions.close_unfilled_url) {
        return null;
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4"
        >
            <h2 className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold">
                Close unfilled remainder
            </h2>
            <p className="text-sm text-amber-950">
                Use only when the remaining prescription will not be prepared.
                This terminal decision remains visible in reconciliation.
            </p>
            <PharmacyErrorSummary errors={form.errors} />
            <div>
                <Label htmlFor="close-unfilled-reason">Closure reason</Label>
                <select
                    id="close-unfilled-reason"
                    className={pharmacyFieldClass}
                    value={form.data.reason_code}
                    onChange={(event) =>
                        form.setData('reason_code', event.target.value)
                    }
                    required
                >
                    <option value="">Select reason</option>
                    <option value="STOCK_UNAVAILABLE">Stock unavailable</option>
                    <option value="THERAPY_COMPLETED">Therapy completed</option>
                    <option value="PATIENT_DECLINED">
                        Patient declined remaining medication
                    </option>
                    <option value="OTHER">Other reason</option>
                </select>
            </div>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    variant="outline"
                    className="min-h-11 border-amber-500"
                    disabled={form.processing}
                >
                    Close prescription remainder
                </Button>
            </div>
        </form>
    );
}

function EvidencePanels({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <section className="rounded-xl border bg-card p-4">
                <h2 className="font-semibold">
                    Verification and preparation evidence
                </h2>
                {prescription.verification ? (
                    <div className="mt-3 text-sm">
                        <p className="font-semibold">
                            {prescription.verification.state} ·{' '}
                            {prescription.verification.pharmacist_name ?? '—'}
                        </p>
                        <p>
                            Allergy review:{' '}
                            {
                                prescription.verification
                                    .manual_allergy_review_status
                            }
                        </p>
                        <PharmacyEvidenceTime
                            value={prescription.verification.recorded_at}
                        />
                    </div>
                ) : (
                    <p className="mt-2 text-sm text-muted-foreground">
                        Not verified yet.
                    </p>
                )}
                {prescription.preparation ? (
                    <div className="mt-3 border-t pt-3 text-sm">
                        <p className="font-semibold">
                            Prepared by{' '}
                            {prescription.preparation.technician_name ?? '—'}
                        </p>
                        <p>
                            {prescription.preparation.allocations.length} lot
                            allocations
                        </p>
                        <PharmacyEvidenceTime
                            value={prescription.preparation.prepared_at}
                        />
                    </div>
                ) : null}
            </section>
            <section className="rounded-xl border bg-card p-4">
                <h2 className="font-semibold">Handovers and returns</h2>
                {prescription.handovers.length ? (
                    <ol className="mt-3 space-y-2 text-sm">
                        {prescription.handovers.map((handover) => (
                            <li
                                key={handover.public_id}
                                className="rounded-lg bg-muted/40 p-3"
                            >
                                <p className="font-semibold">
                                    Handover #{handover.sequence} ·{' '}
                                    {handover.pharmacist_name ?? '—'}
                                </p>
                                <p>
                                    {handover.items.reduce(
                                        (sum, item) => sum + item.quantity,
                                        0,
                                    )}{' '}
                                    units · remaining{' '}
                                    {handover.unfilled_quantity}
                                </p>
                                <PharmacyEvidenceTime
                                    value={handover.handed_over_at}
                                />
                            </li>
                        ))}
                    </ol>
                ) : (
                    <p className="mt-2 text-sm text-muted-foreground">
                        No handovers yet.
                    </p>
                )}
                {prescription.returns.length ? (
                    <p className="mt-3 text-sm font-semibold">
                        {prescription.returns.length} recorded returns
                    </p>
                ) : null}
            </section>
        </div>
    );
}

export function PharmacyPrescriptionWorkflow({
    prescription,
    permissions,
}: {
    prescription: PharmacyPrescriptionProjection;
    permissions: PharmacyWorklistProps['permissions'];
}) {
    return (
        <div className="space-y-5">
            <PharmacyPrescriptionCard
                prescription={prescription}
                showLink={false}
            />
            <PharmacyPrescriptionItems prescription={prescription} />
            {permissions.can_verify &&
            prescription.state === 'ORDERED' &&
            prescription.actions.verify_url ? (
                <DecisionPanel prescription={prescription} />
            ) : null}
            {permissions.can_prepare &&
            ['VERIFIED', 'PARTIALLY_HANDED_OVER'].includes(
                prescription.state,
            ) &&
            prescription.actions.prepare_url ? (
                <PreparationPanel prescription={prescription} />
            ) : null}
            {permissions.can_handover &&
            prescription.state === 'PREPARED' &&
            prescription.actions.handover_url ? (
                <HandoverPanel prescription={prescription} />
            ) : null}
            {permissions.can_return ? (
                <ReturnPanel prescription={prescription} />
            ) : null}
            {permissions.can_handover &&
            prescription.state === 'PARTIALLY_HANDED_OVER' ? (
                <CloseUnfilledPanel prescription={prescription} />
            ) : null}
            <EvidencePanels prescription={prescription} />
            <PharmacyControlTotals prescription={prescription} />
            <PharmacyPrescriptionHistory prescription={prescription} />
        </div>
    );
}
