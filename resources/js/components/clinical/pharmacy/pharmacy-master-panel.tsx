import { useForm } from '@inertiajs/react';
import { Archive, Building2, PackagePlus, Pill } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import {
    formatRupiah,
    newPharmacyOperationKey,
    pharmacyFieldClass,
} from './operation';
import { PharmacyErrorSummary } from './pharmacy-errors';
import { PharmacyEmptyState, PharmacySubnav } from './pharmacy-shared';
import type {
    PharmacyDepotMaster,
    PharmacyMasterProps,
    PharmacyMedicineMaster,
} from './types';

function MedicineForm({
    commandUrl,
    medicine,
    onClose,
}: {
    commandUrl: string;
    medicine?: PharmacyMedicineMaster;
    onClose: () => void;
}) {
    const form = useForm({
        expected_version: medicine?.version ?? 0,
        medicine_code: medicine?.code ?? '',
        generic_name: medicine?.generic_display_name ?? '',
        brand_name: medicine?.brand_display_name ?? '',
        strength_text: medicine?.strength_text ?? '',
        dosage_form: medicine?.dosage_form ?? '',
        base_unit: medicine?.base_issue_unit ?? '',
        route_choices: medicine?.route_choices.join(', ') ?? '',
        acquisition_value: medicine?.standard_acquisition_value_rupiah ?? 0,
        teaching_sale_value: medicine?.teaching_sale_value_rupiah ?? 0,
        idempotency_key: newPharmacyOperationKey('medicine-master'),
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(commandUrl, {
            preserveScroll: true,
            errorBag: `pharmacyMedicine.${medicine?.public_id ?? 'create'}`,
            onSuccess: onClose,
        });
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-4 rounded-xl border border-primary/30 bg-primary/5 p-4"
        >
            <h3 className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold">
                {medicine ? 'Create new medication version' : 'Add medication'}
            </h3>
            <PharmacyErrorSummary errors={form.errors} />
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <Label htmlFor="medicine-code">Medication code</Label>
                    <input
                        id="medicine-code"
                        className={pharmacyFieldClass}
                        value={form.data.medicine_code}
                        onChange={(event) =>
                            form.setData('medicine_code', event.target.value)
                        }
                        required
                        disabled={Boolean(medicine)}
                    />
                </div>
                <div>
                    <Label htmlFor="medicine-generic">Generic name</Label>
                    <input
                        id="medicine-generic"
                        className={pharmacyFieldClass}
                        value={form.data.generic_name}
                        onChange={(event) =>
                            form.setData('generic_name', event.target.value)
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor="medicine-brand">Brand name</Label>
                    <input
                        id="medicine-brand"
                        className={pharmacyFieldClass}
                        value={form.data.brand_name}
                        onChange={(event) =>
                            form.setData('brand_name', event.target.value)
                        }
                    />
                </div>
                <div>
                    <Label htmlFor="medicine-strength">Strength</Label>
                    <input
                        id="medicine-strength"
                        className={pharmacyFieldClass}
                        value={form.data.strength_text}
                        onChange={(event) =>
                            form.setData('strength_text', event.target.value)
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor="medicine-form">Dosage form</Label>
                    <input
                        id="medicine-form"
                        className={pharmacyFieldClass}
                        value={form.data.dosage_form}
                        onChange={(event) =>
                            form.setData('dosage_form', event.target.value)
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor="medicine-unit">Issue unit</Label>
                    <input
                        id="medicine-unit"
                        className={pharmacyFieldClass}
                        value={form.data.base_unit}
                        onChange={(event) =>
                            form.setData('base_unit', event.target.value)
                        }
                        required
                    />
                </div>
                <div className="sm:col-span-2 lg:col-span-3">
                    <Label htmlFor="medicine-routes">Available routes</Label>
                    <input
                        id="medicine-routes"
                        className={pharmacyFieldClass}
                        value={form.data.route_choices}
                        onChange={(event) =>
                            form.setData('route_choices', event.target.value)
                        }
                        required
                        placeholder="ORAL, TOPIKAL"
                    />
                    <p className="mt-1 text-xs text-muted-foreground">
                        Separate each route with a comma.
                    </p>
                </div>
                <div>
                    <Label htmlFor="medicine-cost">Acquisition value</Label>
                    <input
                        id="medicine-cost"
                        type="number"
                        min={0}
                        step={1}
                        className={pharmacyFieldClass}
                        value={form.data.acquisition_value}
                        onChange={(event) =>
                            form.setData(
                                'acquisition_value',
                                Number(event.target.value),
                            )
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor="medicine-sale">Charge-source value</Label>
                    <input
                        id="medicine-sale"
                        type="number"
                        min={0}
                        step={1}
                        className={pharmacyFieldClass}
                        value={form.data.teaching_sale_value}
                        onChange={(event) =>
                            form.setData(
                                'teaching_sale_value',
                                Number(event.target.value),
                            )
                        }
                        required
                    />
                </div>
            </div>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    className="min-h-11"
                    onClick={onClose}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    className="min-h-11"
                    disabled={form.processing}
                >
                    Save medication
                </Button>
            </div>
        </form>
    );
}

function DepotForm({
    commandUrl,
    depot,
    onClose,
}: {
    commandUrl: string;
    depot?: PharmacyDepotMaster;
    onClose: () => void;
}) {
    const form = useForm({
        expected_version: depot?.version ?? 0,
        depot_code: depot?.code ?? '',
        display_name: depot?.display_name ?? '',
        eligible_care_settings: depot?.eligible_care_settings ?? [],
        idempotency_key: newPharmacyOperationKey('depot-master'),
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(commandUrl, {
            preserveScroll: true,
            errorBag: `pharmacyDepot.${depot?.public_id ?? 'create'}`,
            onSuccess: onClose,
        });
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-4 rounded-xl border border-primary/30 bg-primary/5 p-4"
        >
            <h3 className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold">
                {depot ? 'Create new depot version' : 'Add depot'}
            </h3>
            <PharmacyErrorSummary errors={form.errors} />
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label htmlFor="depot-code">Depot code</Label>
                    <input
                        id="depot-code"
                        className={pharmacyFieldClass}
                        value={form.data.depot_code}
                        onChange={(event) =>
                            form.setData('depot_code', event.target.value)
                        }
                        required
                        disabled={Boolean(depot)}
                    />
                </div>
                <div>
                    <Label htmlFor="depot-name">Depot name</Label>
                    <input
                        id="depot-name"
                        className={pharmacyFieldClass}
                        value={form.data.display_name}
                        onChange={(event) =>
                            form.setData('display_name', event.target.value)
                        }
                        required
                    />
                </div>
            </div>
            <fieldset>
                <legend className="font-semibold">
                    Supported care settings
                </legend>
                <div className="mt-2 flex flex-wrap gap-2">
                    {(['OUTPATIENT', 'EMERGENCY', 'INPATIENT'] as const).map(
                        (setting) => (
                            <label
                                key={setting}
                                className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3"
                            >
                                <input
                                    type="checkbox"
                                    checked={form.data.eligible_care_settings.includes(
                                        setting,
                                    )}
                                    onChange={(event) =>
                                        form.setData(
                                            'eligible_care_settings',
                                            event.target.checked
                                                ? [
                                                      ...form.data
                                                          .eligible_care_settings,
                                                      setting,
                                                  ]
                                                : form.data.eligible_care_settings.filter(
                                                      (item) =>
                                                          item !== setting,
                                                  ),
                                        )
                                    }
                                />
                                {setting === 'OUTPATIENT'
                                    ? 'Outpatient'
                                    : setting === 'EMERGENCY'
                                      ? 'IGD'
                                      : 'Inpatient'}
                            </label>
                        ),
                    )}
                </div>
            </fieldset>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    className="min-h-11"
                    onClick={onClose}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    className="min-h-11"
                    disabled={form.processing}
                >
                    Save depot
                </Button>
            </div>
        </form>
    );
}

function OpenLotForm({
    props,
    onClose,
}: {
    props: PharmacyMasterProps;
    onClose: () => void;
}) {
    const form = useForm({
        medicine_public_id: props.medicines[0]?.public_id ?? '',
        depot_public_id: props.depots[0]?.public_id ?? '',
        lot_code: '',
        received_at: '',
        expiry_date: '',
        no_expiry_reason: '',
        opening_quantity: 0,
        source_reference: '',
        idempotency_key: newPharmacyOperationKey('stock-opening'),
    });
    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (props.commands.open_lot_url) {
            form.post(props.commands.open_lot_url, {
                preserveScroll: true,
                errorBag: 'pharmacyLot.open',
                onSuccess: onClose,
            });
        }
    };

    return (
        <form
            onSubmit={submit}
            className="space-y-4 rounded-xl border border-amber-300 bg-amber-50 p-4"
        >
            <h3 className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold">
                Record opening lot balance
            </h3>
            <PharmacyErrorSummary errors={form.errors} />
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <Label htmlFor="lot-medicine">Medication</Label>
                    <select
                        id="lot-medicine"
                        className={pharmacyFieldClass}
                        value={form.data.medicine_public_id}
                        onChange={(event) =>
                            form.setData(
                                'medicine_public_id',
                                event.target.value,
                            )
                        }
                    >
                        {props.medicines
                            .filter((item) => item.state === 'ACTIVE')
                            .map((item) => (
                                <option
                                    key={item.public_id}
                                    value={item.public_id}
                                >
                                    {item.code} · {item.generic_display_name}
                                </option>
                            ))}
                    </select>
                </div>
                <div>
                    <Label htmlFor="lot-depot">Depot</Label>
                    <select
                        id="lot-depot"
                        className={pharmacyFieldClass}
                        value={form.data.depot_public_id}
                        onChange={(event) =>
                            form.setData('depot_public_id', event.target.value)
                        }
                    >
                        {props.depots
                            .filter((item) => item.state === 'ACTIVE')
                            .map((item) => (
                                <option
                                    key={item.public_id}
                                    value={item.public_id}
                                >
                                    {item.display_name}
                                </option>
                            ))}
                    </select>
                </div>
                <div>
                    <Label htmlFor="lot-code">Lot code</Label>
                    <input
                        id="lot-code"
                        className={pharmacyFieldClass}
                        value={form.data.lot_code}
                        onChange={(event) =>
                            form.setData('lot_code', event.target.value)
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor="lot-opened">Received date</Label>
                    <input
                        id="lot-opened"
                        type="datetime-local"
                        className={pharmacyFieldClass}
                        value={form.data.received_at}
                        onChange={(event) =>
                            form.setData('received_at', event.target.value)
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor="lot-expiry">Expiry date</Label>
                    <input
                        id="lot-expiry"
                        type="date"
                        className={pharmacyFieldClass}
                        value={form.data.expiry_date}
                        onChange={(event) =>
                            form.setData('expiry_date', event.target.value)
                        }
                    />
                </div>
                <div>
                    <Label htmlFor="lot-no-expiry">
                        Reason no expiry applies
                    </Label>
                    <input
                        id="lot-no-expiry"
                        className={pharmacyFieldClass}
                        value={form.data.no_expiry_reason}
                        onChange={(event) =>
                            form.setData('no_expiry_reason', event.target.value)
                        }
                        required={!form.data.expiry_date}
                        placeholder="NO_EXPIRY_ASSIGNED"
                    />
                </div>
                <div>
                    <Label htmlFor="lot-available">Available quantity</Label>
                    <input
                        id="lot-available"
                        type="number"
                        min={0}
                        step={1}
                        className={pharmacyFieldClass}
                        value={form.data.opening_quantity}
                        onChange={(event) =>
                            form.setData(
                                'opening_quantity',
                                Number(event.target.value),
                            )
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor="lot-source">Source reference</Label>
                    <input
                        id="lot-source"
                        className={pharmacyFieldClass}
                        value={form.data.source_reference}
                        onChange={(event) =>
                            form.setData('source_reference', event.target.value)
                        }
                        required
                    />
                </div>
            </div>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    className="min-h-11"
                    onClick={onClose}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    className="min-h-11"
                    disabled={form.processing}
                >
                    Save opening balance
                </Button>
            </div>
        </form>
    );
}

function RetireMasterButton({
    url,
    version,
    label,
    payload,
}: {
    url: string;
    version: number;
    label: string;
    payload: Record<string, unknown>;
}) {
    const [confirming, setConfirming] = useState(false);
    const form = useForm({
        ...payload,
        expected_version: version,
        idempotency_key: newPharmacyOperationKey('pharmacy-master-retire'),
    });

    if (!confirming) {
        return (
            <Button
                type="button"
                variant="ghost"
                className="min-h-11 text-destructive"
                onClick={() => setConfirming(true)}
            >
                Retire
            </Button>
        );
    }

    return (
        <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-left">
            <p className="text-sm font-semibold">Retire {label}?</p>
            <p className="mt-1 text-xs text-muted-foreground">
                Earlier evidence remains available, but this master record
                cannot be used for new transactions.
            </p>
            <PharmacyErrorSummary errors={form.errors} />
            <div className="mt-2 flex gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    className="min-h-11"
                    onClick={() => setConfirming(false)}
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    className="min-h-11"
                    disabled={form.processing}
                    onClick={() => form.post(url, { preserveScroll: true })}
                >
                    Confirm retirement
                </Button>
            </div>
        </div>
    );
}

export function PharmacyMasterPanel(props: PharmacyMasterProps) {
    const [editor, setEditor] = useState<'medicine' | 'depot' | 'lot' | null>(
        null,
    );
    const [selectedMedicine, setSelectedMedicine] =
        useState<PharmacyMedicineMaster>();
    const [selectedDepot, setSelectedDepot] = useState<PharmacyDepotMaster>();
    const close = () => {
        setEditor(null);
        setSelectedMedicine(undefined);
        setSelectedDepot(undefined);
    };

    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <PharmacySubnav current="master" canManage />
                <header className="rounded-2xl bg-[#123b5d] p-6 text-white">
                    <p className="text-xs font-semibold tracking-[0.14em] text-sky-100 uppercase">
                        Pharmacy master data
                    </p>
                    <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                        Medication & depot master data
                    </h1>
                    <p className="mt-2 text-sm text-sky-50">
                        Changes are saved as a new version; earlier evidence
                        remains traceable.
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
                <div className="flex flex-wrap gap-2">
                    {props.permissions.can_manage_medicines &&
                    props.commands.create_medicine_url ? (
                        <Button
                            className="min-h-11"
                            onClick={() => setEditor('medicine')}
                        >
                            <Pill className="mr-2 size-4" />
                            Add medication
                        </Button>
                    ) : null}
                    {props.permissions.can_manage_depots &&
                    props.commands.create_depot_url ? (
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setEditor('depot')}
                        >
                            <Building2 className="mr-2 size-4" />
                            Add depot
                        </Button>
                    ) : null}
                    {props.permissions.can_manage_inventory &&
                    props.commands.open_lot_url ? (
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setEditor('lot')}
                        >
                            <PackagePlus className="mr-2 size-4" />
                            Opening lot balance
                        </Button>
                    ) : null}
                </div>
                {editor === 'medicine' &&
                (selectedMedicine?.actions.update_url ??
                    props.commands.create_medicine_url) ? (
                    <MedicineForm
                        commandUrl={
                            (selectedMedicine?.actions.update_url ??
                                props.commands.create_medicine_url)!
                        }
                        medicine={selectedMedicine}
                        onClose={close}
                    />
                ) : null}
                {editor === 'depot' &&
                (selectedDepot?.actions.update_url ??
                    props.commands.create_depot_url) ? (
                    <DepotForm
                        commandUrl={
                            (selectedDepot?.actions.update_url ??
                                props.commands.create_depot_url)!
                        }
                        depot={selectedDepot}
                        onClose={close}
                    />
                ) : null}
                {editor === 'lot' ? (
                    <OpenLotForm props={props} onClose={close} />
                ) : null}
                <section aria-labelledby="medicine-heading">
                    <h2
                        id="medicine-heading"
                        className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                    >
                        Medication list
                    </h2>
                    {props.medicines.length ? (
                        <div className="mt-3 overflow-x-auto rounded-xl border bg-card">
                            <table className="w-full min-w-[850px] text-left text-sm">
                                <caption className="sr-only">
                                    Medication master list and active versions
                                </caption>
                                <thead className="bg-muted/60">
                                    <tr>
                                        <th scope="col" className="px-3 py-2">
                                            Code and medication
                                        </th>
                                        <th scope="col" className="px-3 py-2">
                                            Dosage form
                                        </th>
                                        <th scope="col" className="px-3 py-2">
                                            Acquisition value
                                        </th>
                                        <th scope="col" className="px-3 py-2">
                                            Charge source
                                        </th>
                                        <th scope="col" className="px-3 py-2">
                                            Status
                                        </th>
                                        <th scope="col" className="px-3 py-2">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {props.medicines.map((medicine) => (
                                        <tr
                                            key={medicine.public_id}
                                            className="border-t"
                                        >
                                            <td className="px-3 py-3">
                                                <p className="font-mono text-xs text-primary">
                                                    {medicine.code} · v
                                                    {medicine.version}
                                                </p>
                                                <p className="font-semibold">
                                                    {
                                                        medicine.generic_display_name
                                                    }{' '}
                                                    {medicine.strength_text}
                                                </p>
                                            </td>
                                            <td className="px-3 py-3">
                                                {medicine.dosage_form} ·{' '}
                                                {medicine.base_issue_unit}
                                            </td>
                                            <td className="px-3 py-3 font-mono">
                                                {formatRupiah(
                                                    medicine.standard_acquisition_value_rupiah,
                                                )}
                                            </td>
                                            <td className="px-3 py-3 font-mono">
                                                {formatRupiah(
                                                    medicine.teaching_sale_value_rupiah,
                                                )}
                                            </td>
                                            <td className="px-3 py-3">
                                                {medicine.state === 'ACTIVE'
                                                    ? 'Active'
                                                    : 'Retired'}
                                            </td>
                                            <td className="space-y-2 px-3 py-3 text-right">
                                                {medicine.actions.update_url ? (
                                                    <Button
                                                        variant="ghost"
                                                        className="min-h-11"
                                                        onClick={() => {
                                                            setSelectedMedicine(
                                                                medicine,
                                                            );
                                                            setEditor(
                                                                'medicine',
                                                            );
                                                        }}
                                                    >
                                                        Create new version
                                                    </Button>
                                                ) : null}
                                                {medicine.actions.retire_url ? (
                                                    <RetireMasterButton
                                                        url={
                                                            medicine.actions
                                                                .retire_url
                                                        }
                                                        version={
                                                            medicine.version
                                                        }
                                                        label={
                                                            medicine.generic_display_name
                                                        }
                                                        payload={{
                                                            generic_name:
                                                                medicine.generic_display_name,
                                                            brand_name:
                                                                medicine.brand_display_name,
                                                            strength_text:
                                                                medicine.strength_text,
                                                            dosage_form:
                                                                medicine.dosage_form,
                                                            base_unit:
                                                                medicine.base_issue_unit,
                                                            route_choices:
                                                                medicine.route_choices,
                                                            acquisition_value:
                                                                medicine.standard_acquisition_value_rupiah,
                                                            teaching_sale_value:
                                                                medicine.teaching_sale_value_rupiah,
                                                        }}
                                                    />
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <PharmacyEmptyState
                            title="No medication master records yet"
                            body="Add a medication to begin the prescription workflow."
                        />
                    )}
                </section>
                <section aria-labelledby="depot-heading">
                    <h2
                        id="depot-heading"
                        className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                    >
                        Depot list
                    </h2>
                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                        {props.depots.map((depot) => (
                            <article
                                key={depot.public_id}
                                className="rounded-xl border bg-card p-4"
                            >
                                <div className="flex justify-between gap-3">
                                    <div>
                                        <p className="font-mono text-xs text-primary">
                                            {depot.code} · v{depot.version}
                                        </p>
                                        <h3 className="text-lg font-semibold">
                                            {depot.display_name}
                                        </h3>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {depot.eligible_care_settings.join(
                                                ' · ',
                                            )}
                                        </p>
                                    </div>
                                    <span
                                        className={cn(
                                            'text-xs font-semibold',
                                            depot.state === 'ACTIVE'
                                                ? 'text-emerald-700'
                                                : 'text-slate-500',
                                        )}
                                    >
                                        <Archive className="mr-1 inline size-4" />
                                        {depot.state === 'ACTIVE'
                                            ? 'Active'
                                            : 'Retired'}
                                    </span>
                                </div>
                                {depot.actions.update_url ? (
                                    <Button
                                        variant="ghost"
                                        className="mt-3 min-h-11"
                                        onClick={() => {
                                            setSelectedDepot(depot);
                                            setEditor('depot');
                                        }}
                                    >
                                        Create new version
                                    </Button>
                                ) : null}
                                {depot.actions.retire_url ? (
                                    <RetireMasterButton
                                        url={depot.actions.retire_url}
                                        version={depot.version}
                                        label={depot.display_name}
                                        payload={{
                                            display_name: depot.display_name,
                                            eligible_care_settings:
                                                depot.eligible_care_settings,
                                        }}
                                    />
                                ) : null}
                            </article>
                        ))}
                    </div>
                </section>
            </div>
        </main>
    );
}
