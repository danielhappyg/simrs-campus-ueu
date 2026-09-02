import { useForm } from '@inertiajs/react';
import { FilePlus2, Plus, Send, Trash2, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { pharmacyFieldClass, newPharmacyOperationKey } from './operation';
import { PharmacyErrorSummary } from './pharmacy-errors';
import {
    PharmacyControlTotals,
    PharmacyPrescriptionHistory,
    PharmacyPrescriptionItems,
} from './pharmacy-prescription-card';
import { PharmacyEmptyState, PharmacyStatusChip } from './pharmacy-shared';
import type {
    PharmacyEncounterProjection,
    PharmacyMedicineSummary,
    PharmacyPrescriptionProjection,
} from './types';

type PrescriptionItemDraft = {
    medicine_public_id: string;
    dose_text: string;
    route: string;
    frequency_text: string;
    duration_text: string;
    requested_quantity: number;
    instruction: string;
};

function emptyItem(medicine?: PharmacyMedicineSummary): PrescriptionItemDraft {
    return {
        medicine_public_id: medicine?.public_id ?? '',
        dose_text: '',
        route: medicine?.route_choices[0] ?? '',
        frequency_text: '',
        duration_text: '',
        requested_quantity: 1,
        instruction: '',
    };
}

function PrescriptionComposer({
    projection,
    prescription,
    replacement = false,
    onClose,
}: {
    projection: PharmacyEncounterProjection;
    prescription?: PharmacyPrescriptionProjection;
    replacement?: boolean;
    onClose?: () => void;
}) {
    const firstMedicine = projection.medicine_options[0];
    const form = useForm({
        expected_version: prescription?.version ?? 0,
        expected_fingerprint: prescription?.fingerprint ?? '',
        depot_public_id:
            prescription?.depot.public_id ??
            projection.depot_options[0]?.public_id ??
            '',
        clinical_note: prescription?.clinical_note ?? '',
        reason_code: '',
        items: prescription
            ? prescription.items.map((item) => ({
                  medicine_public_id: item.medicine.public_id,
                  dose_text: item.dose_text,
                  route: item.route,
                  frequency_text: item.frequency_text,
                  duration_text: item.duration_text,
                  requested_quantity: item.requested_quantity,
                  instruction: item.clinical_instruction,
              }))
            : [emptyItem(firstMedicine)],
        idempotency_key: newPharmacyOperationKey(
            replacement ? 'prescription-replacement' : 'prescription-draft',
        ),
    });
    const saveUrl = replacement
        ? (prescription?.actions.replace_url ?? null)
        : prescription
          ? prescription.actions.save_draft_url
          : projection.commands.create_draft_url;

    const setItem = (
        index: number,
        key: keyof PrescriptionItemDraft,
        value: string | number,
    ) => {
        const items = [...form.data.items];
        items[index] = { ...items[index], [key]: value };
        form.setData('items', items);
    };

    const setMedicine = (index: number, publicId: string) => {
        const medicine = projection.medicine_options.find(
            (option) => option.public_id === publicId,
        );

        if (!medicine) {
            return;
        }

        const items = [...form.data.items];
        items[index] = {
            ...items[index],
            medicine_public_id: medicine.public_id,
            route: medicine.route_choices.includes(items[index].route)
                ? items[index].route
                : (medicine.route_choices[0] ?? ''),
        };
        form.setData('items', items);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!saveUrl) {
            return;
        }

        form.post(saveUrl, {
            preserveScroll: true,
            errorBag: prescription
                ? `pharmacyPrescriptionDraft.${prescription.public_id}`
                : 'pharmacyPrescriptionCreate',
            onSuccess: onClose,
        });
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-4 rounded-xl border border-primary/25 bg-primary/5 p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold tracking-[0.12em] text-primary uppercase">
                        Resep dokter
                    </p>
                    <h3 className="mt-1 font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold">
                        {replacement
                            ? 'Resep pengganti'
                            : prescription
                              ? 'Ubah Draf'
                              : 'Resep baru'}
                    </h3>
                </div>
                {onClose ? (
                    <Button
                        type="button"
                        variant="ghost"
                        className="min-h-11"
                        onClick={onClose}
                    >
                        Tutup
                    </Button>
                ) : null}
            </div>
            <PharmacyErrorSummary errors={form.errors} />
            {replacement ? (
                <div>
                    <Label
                        htmlFor={`replacement-reason-${prescription?.public_id}`}
                    >
                        Alasan penggantian resep
                    </Label>
                    <select
                        id={`replacement-reason-${prescription?.public_id}`}
                        className={pharmacyFieldClass}
                        value={form.data.reason_code}
                        onChange={(event) =>
                            form.setData('reason_code', event.target.value)
                        }
                        required
                    >
                        <option value="">Pilih alasan</option>
                        <option value="PRESCRIBING_CORRECTION">
                            Koreksi peresepan
                        </option>
                        <option value="THERAPY_CHANGE">Perubahan terapi</option>
                        <option value="MEDICINE_UNAVAILABLE">
                            Obat tidak tersedia
                        </option>
                        <option value="OTHER">Alasan lain</option>
                    </select>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Resep lama dibatalkan dan tetap tersedia pada riwayat.
                    </p>
                </div>
            ) : null}
            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <Label
                        htmlFor={`pharmacy-depot-${prescription?.public_id ?? 'new'}`}
                    >
                        Depo tujuan
                    </Label>
                    <select
                        id={`pharmacy-depot-${prescription?.public_id ?? 'new'}`}
                        className={pharmacyFieldClass}
                        value={form.data.depot_public_id}
                        onChange={(event) =>
                            form.setData('depot_public_id', event.target.value)
                        }
                        required
                    >
                        {projection.depot_options.map((depot) => (
                            <option
                                key={depot.public_id}
                                value={depot.public_id}
                            >
                                {depot.display_name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <Label
                        htmlFor={`pharmacy-note-${prescription?.public_id ?? 'new'}`}
                    >
                        Catatan klinis resep
                    </Label>
                    <textarea
                        id={`pharmacy-note-${prescription?.public_id ?? 'new'}`}
                        className={cn(pharmacyFieldClass, 'min-h-20')}
                        value={form.data.clinical_note}
                        onChange={(event) =>
                            form.setData('clinical_note', event.target.value)
                        }
                        required
                    />
                </div>
            </div>
            <fieldset className="space-y-3">
                <legend className="font-semibold">
                    Obat dan aturan penggunaan
                </legend>
                {form.data.items.map((item, index) => {
                    const medicine = projection.medicine_options.find(
                        (option) =>
                            option.public_id === item.medicine_public_id,
                    );

                    return (
                        <div
                            key={`${index}-${item.medicine_public_id}`}
                            className="rounded-lg border border-border bg-card p-4 shadow-xs"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <h4 className="font-semibold">
                                    Obat {index + 1}
                                </h4>
                                {form.data.items.length > 1 ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-11 text-destructive"
                                        aria-label={`Hapus obat ${index + 1}`}
                                        onClick={() =>
                                            form.setData(
                                                'items',
                                                form.data.items.filter(
                                                    (_, itemIndex) =>
                                                        itemIndex !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                    </Button>
                                ) : null}
                            </div>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="sm:col-span-2">
                                    <Label htmlFor={`medicine-${index}`}>
                                        Nama obat
                                    </Label>
                                    <select
                                        id={`medicine-${index}`}
                                        className={pharmacyFieldClass}
                                        value={item.medicine_public_id}
                                        onChange={(event) =>
                                            setMedicine(
                                                index,
                                                event.target.value,
                                            )
                                        }
                                        required
                                    >
                                        {projection.medicine_options.map(
                                            (option) => (
                                                <option
                                                    key={option.public_id}
                                                    value={option.public_id}
                                                >
                                                    {
                                                        option.generic_display_name
                                                    }{' '}
                                                    {option.strength_text} ·{' '}
                                                    {option.dosage_form}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                                <div>
                                    <Label htmlFor={`dose-${index}`}>
                                        Dosis tertulis
                                    </Label>
                                    <input
                                        id={`dose-${index}`}
                                        className={pharmacyFieldClass}
                                        value={item.dose_text}
                                        onChange={(event) =>
                                            setItem(
                                                index,
                                                'dose_text',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </div>
                                <div>
                                    <Label htmlFor={`route-${index}`}>
                                        Rute
                                    </Label>
                                    <select
                                        id={`route-${index}`}
                                        className={pharmacyFieldClass}
                                        value={item.route}
                                        onChange={(event) =>
                                            setItem(
                                                index,
                                                'route',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    >
                                        {(medicine?.route_choices ?? []).map(
                                            (route) => (
                                                <option
                                                    key={route}
                                                    value={route}
                                                >
                                                    {route}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                                <div>
                                    <Label htmlFor={`frequency-${index}`}>
                                        Frekuensi tertulis
                                    </Label>
                                    <input
                                        id={`frequency-${index}`}
                                        className={pharmacyFieldClass}
                                        value={item.frequency_text}
                                        onChange={(event) =>
                                            setItem(
                                                index,
                                                'frequency_text',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </div>
                                <div>
                                    <Label htmlFor={`duration-${index}`}>
                                        Durasi tertulis
                                    </Label>
                                    <input
                                        id={`duration-${index}`}
                                        className={pharmacyFieldClass}
                                        value={item.duration_text}
                                        onChange={(event) =>
                                            setItem(
                                                index,
                                                'duration_text',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </div>
                                <div>
                                    <Label htmlFor={`quantity-${index}`}>
                                        Jumlah (
                                        {medicine?.base_issue_unit ?? 'unit'})
                                    </Label>
                                    <input
                                        id={`quantity-${index}`}
                                        type="number"
                                        min={1}
                                        step={1}
                                        className={pharmacyFieldClass}
                                        value={item.requested_quantity}
                                        onChange={(event) =>
                                            setItem(
                                                index,
                                                'requested_quantity',
                                                Number(event.target.value),
                                            )
                                        }
                                        required
                                    />
                                </div>
                                <div className="sm:col-span-2 lg:col-span-1">
                                    <Label htmlFor={`instruction-${index}`}>
                                        Instruksi klinis
                                    </Label>
                                    <textarea
                                        id={`instruction-${index}`}
                                        className={cn(
                                            pharmacyFieldClass,
                                            'min-h-20',
                                        )}
                                        value={item.instruction}
                                        onChange={(event) =>
                                            setItem(
                                                index,
                                                'instruction',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </div>
                            </div>
                        </div>
                    );
                })}
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    disabled={!firstMedicine}
                    onClick={() =>
                        form.setData('items', [
                            ...form.data.items,
                            emptyItem(firstMedicine),
                        ])
                    }
                >
                    <Plus aria-hidden="true" className="mr-2 size-4" />
                    Tambah obat
                </Button>
            </fieldset>
            <div className="flex flex-wrap justify-end gap-2">
                <Button
                    type="submit"
                    className="min-h-11"
                    disabled={form.processing || !saveUrl}
                >
                    {replacement ? 'Buat Draf pengganti' : 'Simpan Draf'}
                </Button>
            </div>
        </form>
    );
}

function PhysicianPrescriptionActions({
    prescription,
}: {
    prescription: PharmacyPrescriptionProjection;
}) {
    const [cancelling, setCancelling] = useState(false);
    const orderForm = useForm({
        expected_version: prescription.version,
        expected_fingerprint: prescription.fingerprint,
        idempotency_key: newPharmacyOperationKey('prescription-order'),
    });
    const cancelForm = useForm({
        expected_fingerprint: prescription.fingerprint,
        reason_code: '',
        note: '',
        idempotency_key: newPharmacyOperationKey('prescription-cancel'),
    });

    return (
        <div className="mt-4 border-t border-border pt-4">
            <PharmacyErrorSummary
                errors={{ ...orderForm.errors, ...cancelForm.errors }}
            />
            <div className="flex flex-wrap justify-end gap-2">
                {prescription.actions.order_url ? (
                    <Button
                        type="button"
                        className="min-h-11"
                        disabled={orderForm.processing}
                        onClick={() =>
                            orderForm.post(prescription.actions.order_url!, {
                                preserveScroll: true,
                            })
                        }
                    >
                        <Send aria-hidden="true" className="mr-2 size-4" />
                        Pesan ke Apotek
                    </Button>
                ) : null}
                {prescription.actions.cancel_url ? (
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11 text-destructive"
                        onClick={() => setCancelling((value) => !value)}
                    >
                        <XCircle aria-hidden="true" className="mr-2 size-4" />
                        Batalkan resep
                    </Button>
                ) : null}
            </div>
            {cancelling && prescription.actions.cancel_url ? (
                <form
                    className="mt-3 rounded-md border border-destructive/30 bg-destructive/5 p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        cancelForm.post(prescription.actions.cancel_url!, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Label htmlFor={`cancel-reason-${prescription.public_id}`}>
                        Alasan pembatalan
                    </Label>
                    <select
                        id={`cancel-reason-${prescription.public_id}`}
                        className={pharmacyFieldClass}
                        value={cancelForm.data.reason_code}
                        onChange={(event) =>
                            cancelForm.setData(
                                'reason_code',
                                event.target.value,
                            )
                        }
                        required
                    >
                        <option value="">Pilih alasan</option>
                        <option value="THERAPY_CANCELLED">
                            Terapi dibatalkan
                        </option>
                        <option value="PRESCRIBING_ERROR">
                            Kesalahan peresepan
                        </option>
                        <option value="PATIENT_CONDITION_CHANGED">
                            Kondisi pasien berubah
                        </option>
                        <option value="OTHER">Alasan lain</option>
                    </select>
                    <Button
                        type="submit"
                        variant="destructive"
                        className="mt-3 min-h-11"
                        disabled={
                            cancelForm.processing ||
                            !cancelForm.data.reason_code.trim()
                        }
                    >
                        Konfirmasi pembatalan
                    </Button>
                </form>
            ) : null}
        </div>
    );
}

function EncounterPrescription({
    projection,
    prescription,
}: {
    projection: PharmacyEncounterProjection;
    prescription: PharmacyPrescriptionProjection;
}) {
    const [editing, setEditing] = useState(false);
    const [replacing, setReplacing] = useState(false);

    return (
        <article className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-mono text-xs text-muted-foreground">
                        {prescription.public_id}
                    </p>
                    <h3 className="mt-1 font-semibold">
                        {prescription.depot.display_name} ·{' '}
                        {prescription.items.length} obat
                    </h3>
                </div>
                <PharmacyStatusChip state={prescription.state} />
            </div>
            <p className="mt-3 rounded-md bg-muted/30 p-3 text-sm whitespace-pre-wrap">
                {prescription.clinical_note}
            </p>
            <div className="mt-3">
                <PharmacyPrescriptionItems prescription={prescription} />
            </div>
            {prescription.actions.save_draft_url ? (
                <Button
                    type="button"
                    variant="outline"
                    className="mt-3 min-h-11"
                    onClick={() => setEditing((value) => !value)}
                >
                    Ubah Draf
                </Button>
            ) : null}
            {prescription.actions.replace_url ? (
                <Button
                    type="button"
                    variant="outline"
                    className="mt-3 ml-2 min-h-11"
                    onClick={() => setReplacing((value) => !value)}
                >
                    Buat resep pengganti
                </Button>
            ) : null}
            {editing ? (
                <div className="mt-4">
                    <PrescriptionComposer
                        projection={projection}
                        prescription={prescription}
                        onClose={() => setEditing(false)}
                    />
                </div>
            ) : null}
            {replacing ? (
                <div className="mt-4">
                    <PrescriptionComposer
                        projection={projection}
                        prescription={prescription}
                        replacement
                        onClose={() => setReplacing(false)}
                    />
                </div>
            ) : null}
            <PhysicianPrescriptionActions prescription={prescription} />
            <div className="mt-4 grid gap-3 xl:grid-cols-2">
                <PharmacyControlTotals prescription={prescription} />
                <PharmacyPrescriptionHistory prescription={prescription} />
            </div>
        </article>
    );
}

export function PharmacyEncounterPanel({
    projection,
}: {
    projection: PharmacyEncounterProjection;
}) {
    const [creating, setCreating] = useState(false);
    const canCreate =
        projection.permissions.can_prescribe &&
        projection.commands.create_draft_url !== null;
    const activeCount = useMemo(
        () =>
            projection.prescriptions.filter(
                (prescription) =>
                    ![
                        'REFUSED',
                        'HANDED_OVER',
                        'UNFILLED_CLOSED',
                        'CANCELLED',
                    ].includes(prescription.state),
            ).length,
        [projection.prescriptions],
    );

    return (
        <section
            aria-labelledby="pharmacy-encounter-title"
            className="space-y-4"
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold tracking-[0.12em] text-primary uppercase">
                        Farmakoterapi episode
                    </p>
                    <h2
                        id="pharmacy-encounter-title"
                        className="mt-1 flex items-center gap-2 text-xl font-semibold"
                    >
                        <FilePlus2 aria-hidden="true" className="size-5" />
                        Resep & Obat
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {activeCount} resep aktif ·{' '}
                        {projection.prescriptions.length} resep tercatat
                    </p>
                </div>
                {canCreate ? (
                    <Button
                        type="button"
                        className="min-h-11"
                        onClick={() => setCreating((value) => !value)}
                    >
                        <Plus aria-hidden="true" className="mr-2 size-4" />
                        Buat resep
                    </Button>
                ) : null}
            </header>
            <p className="sr-only" role="status" aria-live="polite">
                {projection.prescriptions.length} resep ditampilkan.
            </p>
            {creating && canCreate ? (
                <PrescriptionComposer
                    projection={projection}
                    onClose={() => setCreating(false)}
                />
            ) : null}
            <div className="space-y-4">
                {projection.prescriptions.length ? (
                    projection.prescriptions.map((prescription) => (
                        <EncounterPrescription
                            key={`${prescription.public_id}:${prescription.version}:${prescription.state}`}
                            projection={projection}
                            prescription={prescription}
                        />
                    ))
                ) : (
                    <PharmacyEmptyState
                        title="Belum ada resep"
                        body={
                            canCreate
                                ? 'Buat Draf resep untuk memulai proses obat pada episode ini.'
                                : 'Resep akan muncul setelah dokter membuatnya.'
                        }
                    />
                )}
            </div>
        </section>
    );
}
