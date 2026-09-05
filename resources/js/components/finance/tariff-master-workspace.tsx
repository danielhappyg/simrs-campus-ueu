import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CirclePlus,
    History,
    Landmark,
    Pencil,
    Search,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatRupiah } from './finance-shared';
import type {
    CostComponent,
    CostComponentGroup,
    TariffCatalogue,
    TariffItem,
    TariffMasterKind,
    TariffMasterProps,
} from './tariff-master-types';

type ActionMode = 'create' | 'revise' | 'retire';
type Action = {
    mode: ActionMode;
    kind: TariffMasterKind;
    url: string;
    record?: CostComponentGroup | CostComponent | TariffCatalogue | TariffItem;
};

type FormData = {
    code: string;
    display_name: string;
    group_public_id: string;
    catalogue_public_id: string;
    component_public_id: string;
    description: string;
    terminology_label: string;
    care_setting: string;
    service_domain: string;
    reference_label: string;
    ward_class_label: string;
    amount_rupiah: string;
    effective_from: string;
    expected_version: number | '';
    expected_digest: string;
    reason: string;
    confirm: boolean;
    idempotency_key: string;
};

const careLabels = {
    OUTPATIENT: 'Outpatient',
    EMERGENCY: 'IGD',
    INPATIENT: 'Inpatient',
} as const;

const domainLabels = {
    GENERAL_SERVICE: 'Service general',
    LABORATORY: 'Laboratory',
    RADIOLOGY: 'Radiology',
    ACCOMMODATION: 'Accommodation',
} as const;

function operationKey(): string {
    return `tariff-${Date.now()}-${globalThis.crypto?.randomUUID?.() ?? Math.random().toString(16).slice(2)}`;
}

function initialForm(action: Action | null): FormData {
    const record = action?.record;
    const tariff = action?.kind === 'tariff' ? (record as TariffItem) : null;
    const component =
        action?.kind === 'component' ? (record as CostComponent) : null;

    return {
        code: '',
        display_name: record?.display_name ?? '',
        group_public_id: component?.group.public_id ?? '',
        catalogue_public_id: tariff?.catalogue.public_id ?? '',
        component_public_id: tariff?.component.public_id ?? '',
        description: component?.description ?? '',
        terminology_label: component?.terminology_label ?? '',
        care_setting: tariff?.care_setting ?? 'OUTPATIENT',
        service_domain: tariff?.service_domain ?? 'GENERAL_SERVICE',
        reference_label: tariff?.reference_label ?? '',
        ward_class_label: tariff?.ward_class_label ?? '',
        amount_rupiah: tariff ? String(tariff.amount_rupiah) : '',
        effective_from: '',
        expected_version: tariff?.latest_head_version ?? record?.version ?? '',
        expected_digest:
            tariff?.latest_head_content_digest ?? record?.content_digest ?? '',
        reason: '',
        confirm: false,
        idempotency_key: operationKey(),
    };
}

function StateBadge({ state }: { state: 'ACTIVE' | 'RETIRED' }) {
    return (
        <span
            className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold ${
                state === 'ACTIVE'
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                    : 'border-slate-300 bg-slate-100 text-slate-700'
            }`}
        >
            {state === 'ACTIVE' ? 'Active' : 'Inactive'}
        </span>
    );
}

function ActionButtons({
    record,
    kind,
    canManage,
    onAction,
}: {
    record: CostComponentGroup | CostComponent | TariffCatalogue | TariffItem;
    kind: TariffMasterKind;
    canManage: boolean;
    onAction: (action: Action) => void;
}) {
    return (
        <div className="flex min-w-max gap-1">
            <Link
                href={record.actions.history_url}
                className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                aria-label={`Open history ${record.code}`}
            >
                <History aria-hidden="true" className="size-4" />
            </Link>
            {canManage && record.actions.revise_url ? (
                <button
                    type="button"
                    className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    aria-label={`Add version ${record.code}`}
                    onClick={() =>
                        onAction({
                            mode: 'revise',
                            kind,
                            url: record.actions.revise_url!,
                            record,
                        })
                    }
                >
                    <Pencil aria-hidden="true" className="size-4" />
                </button>
            ) : null}
            {canManage && record.actions.retire_url ? (
                <button
                    type="button"
                    className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md text-red-700 hover:bg-red-50 focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:outline-none"
                    aria-label={`Deactivate ${record.code}`}
                    onClick={() =>
                        onAction({
                            mode: 'retire',
                            kind,
                            url: record.actions.retire_url!,
                            record,
                        })
                    }
                >
                    <X aria-hidden="true" className="size-4" />
                </button>
            ) : null}
        </div>
    );
}

function EmptyState({
    canManage,
    commands,
    onAction,
}: {
    canManage: boolean;
    commands: TariffMasterProps['commands'];
    onAction: (action: Action) => void;
}) {
    return (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center">
            <Landmark
                aria-hidden="true"
                className="mx-auto size-8 text-slate-400"
            />
            <h2 className="mt-3 text-lg font-semibold text-slate-950">
                No tariff or charge components
            </h2>
            <p className="mx-auto mt-2 max-w-2xl text-sm text-slate-600">
                Tariffs must be added deliberately before they can be selected.
                Start with a component group, then add charge components, a
                catalogue, and tariffs. There is no default price.
            </p>
            {!canManage ? (
                <p className="mt-3 text-sm font-medium text-[#0d5275]">
                    You have read-only access.
                </p>
            ) : (
                <div className="mt-5 flex flex-wrap justify-center gap-2">
                    {commands.create_group_url ? (
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={() =>
                                onAction({
                                    mode: 'create',
                                    kind: 'group',
                                    url: commands.create_group_url!,
                                })
                            }
                        >
                            <CirclePlus aria-hidden="true" /> Add group
                        </Button>
                    ) : null}
                    {commands.create_catalogue_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() =>
                                onAction({
                                    mode: 'create',
                                    kind: 'catalogue',
                                    url: commands.create_catalogue_url!,
                                })
                            }
                        >
                            <CirclePlus aria-hidden="true" /> Add catalogue
                        </Button>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function SectionHeading({
    id,
    title,
    description,
    addLabel,
    addUrl,
    kind,
    onAction,
}: {
    id: string;
    title: string;
    description: string;
    addLabel: string;
    addUrl: string | null;
    kind: TariffMasterKind;
    onAction: (action: Action) => void;
}) {
    return (
        <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2
                    id={id}
                    className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-slate-950"
                >
                    {title}
                </h2>
                <p className="mt-1 text-sm text-slate-600">{description}</p>
            </div>
            {addUrl ? (
                <Button
                    type="button"
                    className="min-h-11"
                    onClick={() =>
                        onAction({ mode: 'create', kind, url: addUrl })
                    }
                >
                    <CirclePlus aria-hidden="true" /> {addLabel}
                </Button>
            ) : null}
        </div>
    );
}

function MasterForm({
    action,
    groups,
    components,
    catalogues,
    onClose,
    onStatus,
}: {
    action: Action;
    groups: CostComponentGroup[];
    components: CostComponent[];
    catalogues: TariffCatalogue[];
    onClose: () => void;
    onStatus: (status: string) => void;
}) {
    const form = useForm<FormData>(initialForm(action));
    const errors = Object.entries(form.errors);
    const summaryRef = useRef<HTMLDivElement>(null);
    const [attempted, setAttempted] = useState(false);

    useEffect(() => {
        if (attempted && errors.length) {
            summaryRef.current?.focus();
        }
    }, [attempted, errors.length]);

    const kindLabel = {
        group: 'group charge components',
        component: 'charge components',
        catalogue: 'catalogue tariff',
        tariff: 'tariff',
    }[action.kind];
    const title =
        action.mode === 'create'
            ? `Add ${kindLabel}`
            : action.mode === 'revise'
              ? `Add version ${kindLabel}`
              : `Deactivate ${kindLabel}`;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onError: () => setAttempted(true),
            onSuccess: () => {
                onStatus(
                    action.mode === 'retire'
                        ? 'Record retired successfully.'
                        : 'New version saved successfully.',
                );
                onClose();
            },
        };

        if (action.mode === 'revise') {
            form.patch(action.url, options);
        } else {
            form.post(action.url, options);
        }
    };

    const inputError = (field: keyof FormData) => ({
        'aria-invalid': form.errors[field] ? true : undefined,
        'aria-describedby': form.errors[field]
            ? `tariff-${field}-error`
            : undefined,
    });

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4"
            role="presentation"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="tariff-form-title"
                aria-describedby="tariff-form-description"
                className="max-h-[calc(100vh-2rem)] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl"
                onKeyDown={(event) => {
                    if (event.key === 'Escape' && !form.processing) {
                        onClose();
                    }
                }}
            >
                <header className="border-b border-slate-200 bg-slate-50 px-5 py-4">
                    <h2
                        id="tariff-form-title"
                        className="text-lg font-semibold text-slate-950"
                    >
                        {title}
                    </h2>
                    <p
                        id="tariff-form-description"
                        className="mt-1 text-sm text-slate-600"
                    >
                        {action.mode === 'retire'
                            ? 'Deactivation is final. Previous history remains stored.'
                            : 'Saving creates a new version without changing history.'}
                    </p>
                </header>
                <form onSubmit={submit} noValidate>
                    <div className="grid gap-4 px-5 py-5 sm:grid-cols-2">
                        {errors.length ? (
                            <div
                                ref={summaryRef}
                                role="alert"
                                tabIndex={-1}
                                className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:outline-none sm:col-span-2"
                            >
                                <p className="font-semibold">
                                    The data could not be saved.
                                </p>
                                <ul className="mt-1 list-disc pl-5">
                                    {errors.map(([field, message]) => (
                                        <li key={field}>{message}</li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        {action.mode === 'create' ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor="tariff-code">
                                    Code remains
                                </Label>
                                <Input
                                    id="tariff-code"
                                    autoFocus
                                    {...inputError('code')}
                                    value={form.data.code}
                                    onChange={(event) =>
                                        form.setData(
                                            'code',
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                />
                                <InputError
                                    id="tariff-code-error"
                                    message={form.errors.code}
                                />
                            </div>
                        ) : null}

                        {action.mode !== 'retire' ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor="tariff-display-name">
                                    Name tampilan
                                </Label>
                                <Input
                                    id="tariff-display-name"
                                    autoFocus={action.mode === 'revise'}
                                    {...inputError('display_name')}
                                    value={form.data.display_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'display_name',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    id="tariff-display_name-error"
                                    message={form.errors.display_name}
                                />
                            </div>
                        ) : null}

                        {action.kind === 'component' &&
                        action.mode === 'create' ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor="tariff-group">
                                    Group component
                                </Label>
                                <select
                                    id="tariff-group"
                                    className="min-h-11 rounded-md border border-slate-300 bg-white px-3"
                                    value={form.data.group_public_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'group_public_id',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">
                                        Select group active
                                    </option>
                                    {groups
                                        .filter(
                                            (group) => group.state === 'ACTIVE',
                                        )
                                        .map((group) => (
                                            <option
                                                key={group.public_id}
                                                value={group.public_id}
                                            >
                                                {group.code} ·{' '}
                                                {group.display_name}
                                            </option>
                                        ))}
                                </select>
                                <InputError
                                    message={form.errors.group_public_id}
                                />
                            </div>
                        ) : null}

                        {action.kind === 'component' &&
                        action.mode !== 'retire' ? (
                            <>
                                <div className="grid gap-1.5 sm:col-span-2">
                                    <Label htmlFor="tariff-description">
                                        Description (opsional)
                                    </Label>
                                    <textarea
                                        id="tariff-description"
                                        className="min-h-24 rounded-md border border-slate-300 p-3 text-sm"
                                        value={form.data.description}
                                        onChange={(event) =>
                                            form.setData(
                                                'description',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.description}
                                    />
                                </div>
                                <div className="grid gap-1.5 sm:col-span-2">
                                    <Label htmlFor="tariff-terminology">
                                        Label terminologi manual (opsional)
                                    </Label>
                                    <Input
                                        id="tariff-terminology"
                                        value={form.data.terminology_label}
                                        onChange={(event) =>
                                            form.setData(
                                                'terminology_label',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </>
                        ) : null}

                        {action.kind === 'tariff' &&
                        action.mode === 'create' ? (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="tariff-catalogue">
                                        Catalogue tariff
                                    </Label>
                                    <select
                                        id="tariff-catalogue"
                                        className="min-h-11 rounded-md border border-slate-300 bg-white px-3"
                                        value={form.data.catalogue_public_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'catalogue_public_id',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="">
                                            Select catalogue active
                                        </option>
                                        {catalogues
                                            .filter(
                                                (catalogue) =>
                                                    catalogue.state ===
                                                    'ACTIVE',
                                            )
                                            .map((catalogue) => (
                                                <option
                                                    key={catalogue.public_id}
                                                    value={catalogue.public_id}
                                                >
                                                    {catalogue.code} ·{' '}
                                                    {catalogue.display_name}
                                                </option>
                                            ))}
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="tariff-component">
                                        Component charge
                                    </Label>
                                    <select
                                        id="tariff-component"
                                        className="min-h-11 rounded-md border border-slate-300 bg-white px-3"
                                        value={form.data.component_public_id}
                                        onChange={(event) =>
                                            form.setData(
                                                'component_public_id',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="">
                                            Select component active
                                        </option>
                                        {components
                                            .filter(
                                                (component) =>
                                                    component.state ===
                                                    'ACTIVE',
                                            )
                                            .map((component) => (
                                                <option
                                                    key={component.public_id}
                                                    value={component.public_id}
                                                >
                                                    {component.code} ·{' '}
                                                    {component.display_name}
                                                </option>
                                            ))}
                                    </select>
                                </div>
                            </>
                        ) : null}

                        {action.kind === 'tariff' &&
                        action.mode !== 'retire' ? (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="tariff-care">
                                        Jenis service
                                    </Label>
                                    <select
                                        id="tariff-care"
                                        className="min-h-11 rounded-md border border-slate-300 bg-white px-3"
                                        value={form.data.care_setting}
                                        onChange={(event) =>
                                            form.setData(
                                                'care_setting',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {Object.entries(careLabels).map(
                                            ([value, label]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="tariff-domain">
                                        Domain service
                                    </Label>
                                    <select
                                        id="tariff-domain"
                                        className="min-h-11 rounded-md border border-slate-300 bg-white px-3"
                                        value={form.data.service_domain}
                                        onChange={(event) =>
                                            form.setData(
                                                'service_domain',
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {Object.entries(domainLabels).map(
                                            ([value, label]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="tariff-reference">
                                        Label referensi (opsional)
                                    </Label>
                                    <Input
                                        id="tariff-reference"
                                        value={form.data.reference_label}
                                        onChange={(event) =>
                                            form.setData(
                                                'reference_label',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="tariff-ward-class">
                                        Class ruang (opsional)
                                    </Label>
                                    <Input
                                        id="tariff-ward-class"
                                        value={form.data.ward_class_label}
                                        onChange={(event) =>
                                            form.setData(
                                                'ward_class_label',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="tariff-amount">
                                        Amount in rupiah
                                    </Label>
                                    <Input
                                        id="tariff-amount"
                                        type="number"
                                        min="1"
                                        step="1"
                                        inputMode="numeric"
                                        {...inputError('amount_rupiah')}
                                        value={form.data.amount_rupiah}
                                        onChange={(event) =>
                                            form.setData(
                                                'amount_rupiah',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-slate-500">
                                        Enter bilangan bulat positif, without
                                        desimal.
                                    </p>
                                    <InputError
                                        id="tariff-amount_rupiah-error"
                                        message={form.errors.amount_rupiah}
                                    />
                                </div>
                            </>
                        ) : null}

                        {action.kind === 'tariff' ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor="tariff-effective">
                                    Effective from
                                </Label>
                                <Input
                                    id="tariff-effective"
                                    type="date"
                                    {...inputError('effective_from')}
                                    value={form.data.effective_from}
                                    onChange={(event) =>
                                        form.setData(
                                            'effective_from',
                                            event.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-slate-500">
                                    The version remains effective until one day
                                    before the next version takes effect.
                                </p>
                                <InputError
                                    id="tariff-effective_from-error"
                                    message={form.errors.effective_from}
                                />
                            </div>
                        ) : null}

                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="tariff-reason">Reason</Label>
                            <textarea
                                id="tariff-reason"
                                autoFocus={action.mode === 'retire'}
                                {...inputError('reason')}
                                className="min-h-24 rounded-md border border-slate-300 p-3 text-sm"
                                value={form.data.reason}
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                            />
                            <InputError
                                id="tariff-reason-error"
                                message={form.errors.reason}
                            />
                        </div>

                        {action.mode === 'retire' ? (
                            <label className="flex min-h-11 items-start gap-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 sm:col-span-2">
                                <input
                                    type="checkbox"
                                    className="mt-1 size-4"
                                    checked={form.data.confirm}
                                    onChange={(event) =>
                                        form.setData(
                                            'confirm',
                                            event.target.checked,
                                        )
                                    }
                                />
                                I understand that the code cannot be reactivated
                                or used back.
                            </label>
                        ) : null}
                    </div>
                    <footer className="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={
                                action.mode === 'retire'
                                    ? 'destructive'
                                    : 'default'
                            }
                            className="min-h-11"
                            disabled={form.processing}
                        >
                            {form.processing
                                ? 'Saving…'
                                : action.mode === 'retire'
                                  ? 'Deactivate'
                                  : 'Save version'}
                        </Button>
                    </footer>
                </form>
            </section>
        </div>
    );
}

export function TariffMasterWorkspace(props: TariffMasterProps) {
    const {
        groups,
        components,
        catalogues,
        tariffs,
        permissions,
        commands,
        history,
        read_error,
    } = props;
    const [action, setAction] = useState<Action | null>(null);
    const [query, setQuery] = useState('');
    const [stateFilter, setStateFilter] = useState('');
    const [careFilter, setCareFilter] = useState('');
    const [status, setStatus] = useState('');
    const page = usePage();
    const flashSuccess = (page.props.flash as { success?: string } | undefined)
        ?.success;

    const matches = (record: {
        code: string;
        display_name: string;
        state: string;
    }) => {
        const needle = query.trim().toLocaleLowerCase('en-GB');

        return (
            (!stateFilter || record.state === stateFilter) &&
            (!needle ||
                `${record.code} ${record.display_name} ${record.state}`
                    .toLocaleLowerCase('en-GB')
                    .includes(needle))
        );
    };
    const visibleGroups = groups.filter(matches);
    const visibleComponents = components.filter(matches);
    const visibleCatalogues = catalogues.filter(matches);
    const visibleTariffs = tariffs.filter(
        (record) =>
            matches(record) &&
            (!careFilter || record.care_setting === careFilter),
    );
    const empty =
        !groups.length &&
        !components.length &&
        !catalogues.length &&
        !tariffs.length;

    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <header className="relative overflow-hidden rounded-2xl bg-[#123b5d] p-6 text-white shadow-sm">
                    <span
                        aria-hidden="true"
                        className="absolute inset-y-0 left-0 w-2 bg-[#24a69a]"
                    />
                    <div className="relative flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="flex items-center gap-2 text-xs font-semibold tracking-[0.14em] text-sky-100 uppercase">
                                <ShieldCheck
                                    aria-hidden="true"
                                    className="size-4"
                                />{' '}
                                Data Management
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                Tariffs &amp; Charge Components
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-sky-50">
                                Manage catalogues, component groups, charge
                                components, and date-effective tariff versions.
                            </p>
                        </div>
                        <span className="rounded-md bg-white/10 px-3 py-2 text-sm font-semibold">
                            {permissions.can_manage
                                ? 'Tariff Manager'
                                : 'Read-only access'}
                        </span>
                    </div>
                </header>

                <nav
                    aria-label="Tariff and charge component sections"
                    className="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1 shadow-sm"
                >
                    <Link
                        href="/manajemen-data/tarif-komponen-biaya"
                        aria-current="page"
                        className="inline-flex min-h-11 items-center rounded-md bg-[#123b5d] px-3 text-sm font-semibold text-white"
                    >
                        Tariff Master
                    </Link>
                    <Link
                        href="/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi"
                        className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        Radiology Mapping
                    </Link>
                    <Link
                        href="/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium"
                        className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        Laboratory Mapping
                    </Link>
                    <Link
                        href="/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi"
                        className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-sky-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none"
                    >
                        Accommodation Mapping
                    </Link>
                </nav>

                <div
                    role="status"
                    aria-live="polite"
                    className={
                        status || flashSuccess
                            ? 'rounded-md border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900'
                            : 'sr-only'
                    }
                >
                    {status || flashSuccess || ''}
                </div>
                {read_error ? (
                    <div
                        role="alert"
                        className="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-900"
                    >
                        {read_error}
                    </div>
                ) : null}

                <div className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[1fr_auto_auto_auto]">
                    <label className="relative block">
                        <span className="sr-only">
                            Search code, name, or status
                        </span>
                        <Search
                            aria-hidden="true"
                            className="absolute top-3.5 left-3 size-4 text-slate-500"
                        />
                        <Input
                            className="min-h-11 pl-10"
                            type="search"
                            placeholder="Search code, name, or status"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-medium text-slate-700">
                        <span className="sr-only">Filter status</span>
                        <select
                            className="min-h-11 rounded-md border border-slate-300 bg-white px-3"
                            value={stateFilter}
                            onChange={(event) =>
                                setStateFilter(event.target.value)
                            }
                        >
                            <option value="">All statuses</option>
                            <option value="ACTIVE">Active</option>
                            <option value="RETIRED">Inactive</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-medium text-slate-700">
                        <span className="sr-only">Filter service type</span>
                        <select
                            className="min-h-11 rounded-md border border-slate-300 bg-white px-3"
                            value={careFilter}
                            onChange={(event) =>
                                setCareFilter(event.target.value)
                            }
                        >
                            <option value="">All services</option>
                            {Object.entries(careLabels).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <CalendarClock aria-hidden="true" className="size-4" />{' '}
                        Service date
                        <Input
                            type="date"
                            className="min-h-11 w-auto"
                            value={props.as_of_date}
                            onChange={(event) =>
                                router.get(
                                    '/manajemen-data/tarif-komponen-biaya',
                                    { as_of_date: event.target.value },
                                    { preserveState: true },
                                )
                            }
                        />
                    </label>
                </div>

                {history ? (
                    <section
                        aria-labelledby="tariff-history-heading"
                        className="rounded-xl border border-[#7fbcb6] bg-white shadow-sm"
                    >
                        <div className="flex items-start justify-between gap-3 border-b border-[#7fbcb6] bg-[#e8f5f3] p-4">
                            <div>
                                <h2
                                    id="tariff-history-heading"
                                    className="text-lg font-semibold text-[#0b4147]"
                                >
                                    Immutable history · {history.code}
                                </h2>
                                <p className="mt-1 text-sm text-slate-700">
                                    {history.display_name}
                                </p>
                            </div>
                            <Link
                                href="/manajemen-data/tarif-komponen-biaya"
                                className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-semibold text-[#0d5275] hover:bg-white"
                            >
                                Close history
                            </Link>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[54rem] text-left text-sm">
                                <caption className="sr-only">
                                    Immutable versions for {history.code}
                                </caption>
                                <thead className="border-b border-slate-200 bg-slate-50">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">
                                            Version
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Name
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Period effective
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-3 text-right"
                                        >
                                            Value
                                        </th>
                                        <th scope="col" className="px-4 py-3">
                                            Reason and author
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {history.versions.map((version) => (
                                        <tr key={version.public_id}>
                                            <th
                                                scope="row"
                                                className="px-4 py-4 font-mono"
                                            >
                                                v{version.version}
                                            </th>
                                            <td className="px-4 py-4">
                                                {version.display_name}
                                            </td>
                                            <td className="px-4 py-4">
                                                <StateBadge
                                                    state={version.state}
                                                />
                                            </td>
                                            <td className="px-4 py-4">
                                                {version.effective_from
                                                    ? version.effective_until
                                                        ? `${version.effective_from} until before ${version.effective_until}`
                                                        : `${version.effective_from} and onward`
                                                    : 'No bertanggal'}
                                            </td>
                                            <td className="px-4 py-4 text-right font-mono">
                                                {version.amount_rupiah === null
                                                    ? '—'
                                                    : formatRupiah(
                                                          version.amount_rupiah,
                                                      )}
                                            </td>
                                            <td className="px-4 py-4">
                                                <span className="block">
                                                    {version.reason}
                                                </span>
                                                <span className="mt-1 block text-xs text-slate-500">
                                                    {version.authored_by} ·{' '}
                                                    {version.authored_at}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : null}

                {empty ? (
                    <EmptyState
                        canManage={permissions.can_manage}
                        commands={commands}
                        onAction={setAction}
                    />
                ) : (
                    <>
                        <section aria-labelledby="catalogue-heading">
                            <SectionHeading
                                id="catalogue-heading"
                                title="Tariff Catalogues"
                                description="Stable containers for hospital tariff groups."
                                addLabel="Add catalogue"
                                addUrl={commands.create_catalogue_url}
                                kind="catalogue"
                                onAction={setAction}
                            />
                            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                                <table className="w-full min-w-[48rem] text-left text-sm">
                                    <caption className="sr-only">
                                        List catalogue tariff
                                    </caption>
                                    <thead className="border-b bg-slate-100">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Code and name
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Status
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-right"
                                            >
                                                Tariff
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Version
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                <span className="sr-only">
                                                    Action
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {visibleCatalogues.map((record) => (
                                            <tr key={record.public_id}>
                                                <th
                                                    scope="row"
                                                    className="px-4 py-4"
                                                >
                                                    <span className="block font-mono text-xs">
                                                        {record.code}
                                                    </span>
                                                    <span className="mt-1 block">
                                                        {record.display_name}
                                                    </span>
                                                </th>
                                                <td className="px-4 py-4">
                                                    <StateBadge
                                                        state={record.state}
                                                    />
                                                </td>
                                                <td className="px-4 py-4 text-right">
                                                    {record.tariff_count}
                                                </td>
                                                <td className="px-4 py-4">
                                                    v{record.version}
                                                </td>
                                                <td className="px-4 py-2">
                                                    <ActionButtons
                                                        record={record}
                                                        kind="catalogue"
                                                        canManage={
                                                            permissions.can_manage
                                                        }
                                                        onAction={setAction}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section aria-labelledby="group-heading">
                            <SectionHeading
                                id="group-heading"
                                title="Charge Component Groups"
                                description="Stable grouping for components that make up a charge."
                                addLabel="Add group"
                                addUrl={commands.create_group_url}
                                kind="group"
                                onAction={setAction}
                            />
                            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                                <table className="w-full min-w-[48rem] text-left text-sm">
                                    <caption className="sr-only">
                                        List group charge components
                                    </caption>
                                    <thead className="border-b bg-slate-100">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Code and name
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Status
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-right"
                                            >
                                                Component
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Version
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                <span className="sr-only">
                                                    Action
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {visibleGroups.map((record) => (
                                            <tr key={record.public_id}>
                                                <th
                                                    scope="row"
                                                    className="px-4 py-4"
                                                >
                                                    <span className="block font-mono text-xs">
                                                        {record.code}
                                                    </span>
                                                    <span className="mt-1 block">
                                                        {record.display_name}
                                                    </span>
                                                </th>
                                                <td className="px-4 py-4">
                                                    <StateBadge
                                                        state={record.state}
                                                    />
                                                </td>
                                                <td className="px-4 py-4 text-right">
                                                    {record.component_count}
                                                </td>
                                                <td className="px-4 py-4">
                                                    v{record.version}
                                                </td>
                                                <td className="px-4 py-2">
                                                    <ActionButtons
                                                        record={record}
                                                        kind="group"
                                                        canManage={
                                                            permissions.can_manage
                                                        }
                                                        onAction={setAction}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section aria-labelledby="component-heading">
                            <SectionHeading
                                id="component-heading"
                                title="Charge Components"
                                description="Stable components that remain linked to one group."
                                addLabel="Add component"
                                addUrl={commands.create_component_url}
                                kind="component"
                                onAction={setAction}
                            />
                            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                                <table className="w-full min-w-[64rem] text-left text-sm">
                                    <caption className="sr-only">
                                        List charge components
                                    </caption>
                                    <thead className="border-b bg-slate-100">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Code and name
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Group
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Status
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-right"
                                            >
                                                Tariff
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Version
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                <span className="sr-only">
                                                    Action
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {visibleComponents.map((record) => (
                                            <tr key={record.public_id}>
                                                <th
                                                    scope="row"
                                                    className="px-4 py-4"
                                                >
                                                    <span className="block font-mono text-xs">
                                                        {record.code}
                                                    </span>
                                                    <span className="mt-1 block">
                                                        {record.display_name}
                                                    </span>
                                                </th>
                                                <td className="px-4 py-4">
                                                    {record.group.code} ·{' '}
                                                    {record.group.display_name}
                                                </td>
                                                <td className="px-4 py-4">
                                                    <StateBadge
                                                        state={record.state}
                                                    />
                                                </td>
                                                <td className="px-4 py-4 text-right">
                                                    {record.tariff_count}
                                                </td>
                                                <td className="px-4 py-4">
                                                    v{record.version}
                                                </td>
                                                <td className="px-4 py-2">
                                                    <ActionButtons
                                                        record={record}
                                                        kind="component"
                                                        canManage={
                                                            permissions.can_manage
                                                        }
                                                        onAction={setAction}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section aria-labelledby="tariff-heading">
                            <SectionHeading
                                id="tariff-heading"
                                title="Effective Tariffs"
                                description={`Value for service date ${props.as_of_date}; future versions do not override the version effective today.`}
                                addLabel="Add tariff"
                                addUrl={commands.create_tariff_url}
                                kind="tariff"
                                onAction={setAction}
                            />
                            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                                <table className="w-full min-w-[76rem] text-left text-sm">
                                    <caption className="sr-only">
                                        Tariffs by effective date
                                    </caption>
                                    <thead className="border-b bg-slate-100">
                                        <tr>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Code and name
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Catalogue
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Component
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Service
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Effective
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3 text-right"
                                            >
                                                Value
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                Status
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-3"
                                            >
                                                <span className="sr-only">
                                                    Action
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {visibleTariffs.map((record) => (
                                            <tr key={record.public_id}>
                                                <th
                                                    scope="row"
                                                    className="px-4 py-4"
                                                >
                                                    <span className="block font-mono text-xs">
                                                        {record.code}
                                                    </span>
                                                    <span className="mt-1 block">
                                                        {record.display_name}
                                                    </span>
                                                </th>
                                                <td className="px-4 py-4">
                                                    {record.catalogue.code}
                                                </td>
                                                <td className="px-4 py-4">
                                                    {record.component.code}
                                                </td>
                                                <td className="px-4 py-4">
                                                    {
                                                        careLabels[
                                                            record.care_setting
                                                        ]
                                                    }
                                                    <span className="mt-1 block text-xs text-slate-500">
                                                        {
                                                            domainLabels[
                                                                record
                                                                    .service_domain
                                                            ]
                                                        }
                                                    </span>
                                                </td>
                                                <td className="px-4 py-4">
                                                    {record.effective_from}
                                                    <span className="mt-1 block text-xs text-slate-500">
                                                        {record.next_effective_from
                                                            ? `until before ${record.next_effective_from}`
                                                            : 'and onward'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-4 text-right font-mono font-semibold">
                                                    {formatRupiah(
                                                        record.amount_rupiah,
                                                    )}
                                                </td>
                                                <td className="px-4 py-4">
                                                    <StateBadge
                                                        state={record.state}
                                                    />
                                                    <span className="mt-1 block text-xs text-slate-500">
                                                        {record.is_effective
                                                            ? 'Effective on service date'
                                                            : 'Not yet/not effective'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2">
                                                    <ActionButtons
                                                        record={record}
                                                        kind="tariff"
                                                        canManage={
                                                            permissions.can_manage
                                                        }
                                                        onAction={setAction}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </>
                )}
            </div>
            {action ? (
                <MasterForm
                    action={action}
                    groups={groups}
                    components={components}
                    catalogues={catalogues}
                    onClose={() => setAction(null)}
                    onStatus={setStatus}
                />
            ) : null}
        </main>
    );
}
