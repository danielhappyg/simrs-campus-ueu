import { Link, useForm } from '@inertiajs/react';
import { Archive, ClipboardList, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { LaboratoryErrors } from './laboratory-shared';
import { laboratoryFieldClass, newLaboratoryOperationKey } from './operation';
import type {
    LaboratoryComponentDefinition,
    LaboratoryMasterProjection,
    LaboratoryMasterProps,
    LaboratoryValueKind,
} from './types';

export type { LaboratoryMasterProps } from './types';

type ComponentDraft = {
    code: string;
    display_name: string;
    value_kind: LaboratoryValueKind;
    unit_text: string;
    reference_text: string;
    critical_allowed: boolean;
};

const emptyComponent = (): ComponentDraft => ({
    code: '',
    display_name: '',
    value_kind: 'TEXT',
    unit_text: '',
    reference_text: '',
    critical_allowed: false,
});

const componentDrafts = (
    components: LaboratoryComponentDefinition[],
): ComponentDraft[] =>
    components.map((component) => ({
        ...component,
        unit_text: component.unit_text ?? '',
        reference_text: component.reference_text ?? '',
    }));

function ComponentEditor({
    components,
    valueKindOptions,
    idPrefix,
    onChange,
}: {
    components: ComponentDraft[];
    valueKindOptions: LaboratoryMasterProps['value_kind_options'];
    idPrefix: string;
    onChange: (components: ComponentDraft[]) => void;
}) {
    const update = (index: number, patch: Partial<ComponentDraft>) =>
        onChange(
            components.map((component, currentIndex) =>
                currentIndex === index ? { ...component, ...patch } : component,
            ),
        );

    return (
        <fieldset className="space-y-3 sm:col-span-3">
            <legend className="font-semibold text-slate-950">
                Result components ({components.length}/12)
            </legend>
            {components.map((component, index) => (
                <div
                    key={`${idPrefix}-${index}`}
                    className="grid gap-3 rounded-md border border-slate-200 bg-white p-3 md:grid-cols-2 lg:grid-cols-6"
                >
                    <div>
                        <Label htmlFor={`${idPrefix}-code-${index}`}>
                            Code
                        </Label>
                        <input
                            id={`${idPrefix}-code-${index}`}
                            className={laboratoryFieldClass}
                            value={component.code}
                            onChange={(event) =>
                                update(index, {
                                    code: event.target.value.toUpperCase(),
                                })
                            }
                            required
                        />
                    </div>
                    <div>
                        <Label htmlFor={`${idPrefix}-name-${index}`}>
                            Component name
                        </Label>
                        <input
                            id={`${idPrefix}-name-${index}`}
                            className={laboratoryFieldClass}
                            value={component.display_name}
                            onChange={(event) =>
                                update(index, {
                                    display_name: event.target.value,
                                })
                            }
                            required
                        />
                    </div>
                    <div>
                        <Label htmlFor={`${idPrefix}-kind-${index}`}>
                            Value type
                        </Label>
                        <select
                            id={`${idPrefix}-kind-${index}`}
                            className={laboratoryFieldClass}
                            value={component.value_kind}
                            onChange={(event) =>
                                update(index, {
                                    value_kind: event.target
                                        .value as LaboratoryValueKind,
                                })
                            }
                        >
                            {valueKindOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor={`${idPrefix}-unit-${index}`}>
                            Unit (optional)
                        </Label>
                        <input
                            id={`${idPrefix}-unit-${index}`}
                            className={laboratoryFieldClass}
                            value={component.unit_text}
                            onChange={(event) =>
                                update(index, { unit_text: event.target.value })
                            }
                        />
                    </div>
                    <div>
                        <Label htmlFor={`${idPrefix}-reference-${index}`}>
                            Reference range (optional)
                        </Label>
                        <input
                            id={`${idPrefix}-reference-${index}`}
                            className={laboratoryFieldClass}
                            value={component.reference_text}
                            onChange={(event) =>
                                update(index, {
                                    reference_text: event.target.value,
                                })
                            }
                        />
                    </div>
                    <div className="flex min-h-11 items-end gap-2">
                        <label className="flex min-h-11 flex-1 items-center gap-2 rounded-md border border-slate-300 px-3 text-sm font-medium text-slate-800">
                            <input
                                type="checkbox"
                                checked={component.critical_allowed}
                                onChange={(event) =>
                                    update(index, {
                                        critical_allowed: event.target.checked,
                                    })
                                }
                            />
                            Critical
                        </label>
                        {components.length > 1 ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11 min-w-11 px-3 text-red-700"
                                aria-label={`Remove component ${index + 1}`}
                                onClick={() =>
                                    onChange(
                                        components.filter(
                                            (_, currentIndex) =>
                                                currentIndex !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        ) : null}
                    </div>
                </div>
            ))}
            {components.length < 12 ? (
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={() => onChange([...components, emptyComponent()])}
                >
                    <Plus className="mr-2 size-4" /> Add component
                </Button>
            ) : null}
        </fieldset>
    );
}

function MasterCard({
    examination,
    valueKindOptions,
}: {
    examination: LaboratoryMasterProjection;
    valueKindOptions: LaboratoryMasterProps['value_kind_options'];
}) {
    const [editing, setEditing] = useState(false);
    const [retiring, setRetiring] = useState(false);
    const updateForm = useForm({
        expected_version: examination.version,
        display_name: examination.display_name,
        specimen_type: examination.specimen_type,
        collection_instruction: examination.collection_instruction ?? '',
        components: componentDrafts(examination.components),
        idempotency_key: newLaboratoryOperationKey('master-update'),
    });
    const retireForm = useForm({
        expected_version: examination.version,
        idempotency_key: newLaboratoryOperationKey('master-retire'),
    });

    const update = (event: FormEvent) => {
        event.preventDefault();

        if (!examination.actions.update_url) {
            return;
        }

        updateForm.patch(examination.actions.update_url, {
            preserveScroll: true,
            errorBag: `laboratoryMasterUpdate.${examination.public_id}`,
        });
    };
    const retire = () => {
        if (!examination.actions.retire_url) {
            return;
        }

        retireForm.post(examination.actions.retire_url, {
            preserveScroll: true,
            errorBag: `laboratoryMasterRetire.${examination.public_id}`,
        });
    };

    return (
        <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-mono text-xs font-semibold tracking-wide text-[#145a8d]">
                        {examination.code}
                    </p>
                    <h3 className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold text-slate-950">
                        {examination.display_name}
                    </h3>
                    <p className="mt-1 text-sm text-slate-600">
                        {examination.specimen_type} · Versi{' '}
                        {examination.version}
                    </p>
                </div>
                <span
                    className={`inline-flex min-h-7 items-center rounded-full px-3 text-xs font-semibold ${examination.state === 'ACTIVE' ? 'bg-emerald-50 text-emerald-800' : 'bg-[#e8eef2] text-[#243746]'}`}
                >
                    {examination.state === 'ACTIVE' ? 'Active' : 'Inactive'}
                </span>
            </div>
            <p className="mt-3 rounded-md bg-slate-50 p-3 text-sm text-slate-700">
                {examination.collection_instruction ||
                    'No special collection instructions.'}
            </p>
            <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {examination.components.map((component) => (
                    <li
                        key={component.code}
                        className="rounded-md border border-slate-200 px-3 py-2 text-sm"
                    >
                        <p className="font-semibold text-slate-900">
                            {component.display_name}
                        </p>
                        <p className="font-mono text-xs text-slate-500">
                            {component.code} · {component.value_kind}
                            {component.unit_text
                                ? ` · ${component.unit_text}`
                                : ''}
                        </p>
                    </li>
                ))}
            </ul>
            <LaboratoryErrors
                errors={{ ...updateForm.errors, ...retireForm.errors }}
                title="Unsaved data."
            />

            {examination.state === 'ACTIVE' ? (
                <div className="mt-4 flex flex-wrap gap-2">
                    {examination.actions.update_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setEditing((value) => !value)}
                        >
                            <Pencil className="mr-2 size-4" /> Edit
                        </Button>
                    ) : null}
                    {examination.actions.retire_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11 text-red-700"
                            onClick={() => setRetiring((value) => !value)}
                        >
                            <Archive className="mr-2 size-4" /> Retire
                        </Button>
                    ) : null}
                </div>
            ) : null}

            {editing && examination.actions.update_url ? (
                <form
                    onSubmit={update}
                    className="mt-4 grid gap-4 rounded-md border border-[#b9d9ed] bg-[#f4f9fc] p-4 sm:grid-cols-3"
                >
                    <div>
                        <Label htmlFor={`master-name-${examination.public_id}`}>
                            Examination name
                        </Label>
                        <input
                            id={`master-name-${examination.public_id}`}
                            className={laboratoryFieldClass}
                            value={updateForm.data.display_name}
                            onChange={(event) =>
                                updateForm.setData(
                                    'display_name',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </div>
                    <div>
                        <Label
                            htmlFor={`master-specimen-${examination.public_id}`}
                        >
                            Specimen type
                        </Label>
                        <input
                            id={`master-specimen-${examination.public_id}`}
                            className={laboratoryFieldClass}
                            value={updateForm.data.specimen_type}
                            onChange={(event) =>
                                updateForm.setData(
                                    'specimen_type',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </div>
                    <div>
                        <Label htmlFor={`master-note-${examination.public_id}`}>
                            Collection instructions (optional)
                        </Label>
                        <textarea
                            id={`master-note-${examination.public_id}`}
                            rows={3}
                            className={laboratoryFieldClass}
                            value={updateForm.data.collection_instruction}
                            onChange={(event) =>
                                updateForm.setData(
                                    'collection_instruction',
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <ComponentEditor
                        components={updateForm.data.components}
                        valueKindOptions={valueKindOptions}
                        idPrefix={`update-${examination.public_id}`}
                        onChange={(components) =>
                            updateForm.setData('components', components)
                        }
                    />
                    <Button
                        type="submit"
                        className="min-h-11 sm:col-span-3 sm:w-fit"
                        disabled={updateForm.processing}
                    >
                        Save Changes
                    </Button>
                </form>
            ) : null}

            {retiring && examination.actions.retire_url ? (
                <div className="mt-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    <p>
                        This examination cannot be selected for new orders.
                        Existing history remains available.
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="destructive"
                            className="min-h-11"
                            onClick={retire}
                            disabled={retireForm.processing}
                        >
                            Yes, retire
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setRetiring(false)}
                        >
                            Back
                        </Button>
                    </div>
                </div>
            ) : null}
        </article>
    );
}

export function LaboratoryMasterPanel(props: LaboratoryMasterProps) {
    const [creating, setCreating] = useState(false);
    const createForm = useForm({
        code: '',
        display_name: '',
        specimen_type: '',
        collection_instruction: '',
        components: [emptyComponent()],
        idempotency_key: newLaboratoryOperationKey('master-create'),
    });
    const canCreate =
        props.permissions.can_manage && props.commands.create_url !== null;

    const create = (event: FormEvent) => {
        event.preventDefault();

        if (!canCreate || !props.commands.create_url) {
            return;
        }

        createForm.post(props.commands.create_url, {
            preserveScroll: true,
            errorBag: 'laboratoryMasterCreate',
        });
    };

    return (
        <main className="mx-auto w-full max-w-6xl space-y-5 p-4 sm:p-6">
            <nav
                aria-label="Data management"
                className="flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1"
            >
                <Link
                    href="/manajemen-data/bangsal"
                    className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950"
                >
                    Wards &amp; Beds
                </Link>
                <Link
                    href="/manajemen-data/laboratorium"
                    aria-current="page"
                    className="inline-flex min-h-11 items-center rounded-md bg-[#123b63] px-3 text-sm font-medium text-white"
                >
                    Laboratory Examinations
                </Link>
                <Link
                    href="/manajemen-data/radiologi"
                    className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950"
                >
                    Radiology Examinations
                </Link>
            </nav>
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <span className="grid size-12 shrink-0 place-items-center rounded-lg bg-[#1b75bc] text-white">
                        <ClipboardList className="size-6" />
                    </span>
                    <div>
                        <p className="font-mono text-xs font-semibold tracking-[0.15em] text-[#145a8d]">
                            CLINICAL MASTER
                        </p>
                        <h1 className="font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold text-slate-950">
                            Laboratory Examinations
                        </h1>
                        <p className="mt-1 text-sm text-slate-600">
                            Manage specimens and result components available to
                            physicians.
                        </p>
                    </div>
                </div>
                {canCreate ? (
                    <Button
                        type="button"
                        className="min-h-11"
                        onClick={() => setCreating((value) => !value)}
                    >
                        <Plus className="mr-2 size-4" /> Add examination
                    </Button>
                ) : null}
            </header>
            <p className="sr-only" role="status" aria-live="polite">
                {createForm.processing
                    ? 'Saving laboratory examination.'
                    : `${props.examinations.length} examinations displayed.`}
            </p>
            {props.read_error ? (
                <div
                    role="alert"
                    className="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-900"
                >
                    {props.read_error}
                </div>
            ) : null}
            {creating && canCreate ? (
                <form
                    onSubmit={create}
                    className="grid gap-4 rounded-lg border border-[#b9d9ed] bg-[#f4f9fc] p-4 shadow-sm sm:grid-cols-3"
                >
                    <h2 className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold text-slate-950 sm:col-span-3">
                        New Examination
                    </h2>
                    <div className="sm:col-span-3">
                        <LaboratoryErrors
                            errors={createForm.errors}
                            title="Unsaved data."
                        />
                    </div>
                    <div>
                        <Label htmlFor="new-laboratory-code">
                            Permanent code
                        </Label>
                        <input
                            id="new-laboratory-code"
                            className={laboratoryFieldClass}
                            value={createForm.data.code}
                            onChange={(event) =>
                                createForm.setData(
                                    'code',
                                    event.target.value.toUpperCase(),
                                )
                            }
                            required
                        />
                    </div>
                    <div>
                        <Label htmlFor="new-laboratory-name">
                            Examination name
                        </Label>
                        <input
                            id="new-laboratory-name"
                            className={laboratoryFieldClass}
                            value={createForm.data.display_name}
                            onChange={(event) =>
                                createForm.setData(
                                    'display_name',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </div>
                    <div>
                        <Label htmlFor="new-laboratory-specimen">
                            Specimen type
                        </Label>
                        <input
                            id="new-laboratory-specimen"
                            className={laboratoryFieldClass}
                            value={createForm.data.specimen_type}
                            onChange={(event) =>
                                createForm.setData(
                                    'specimen_type',
                                    event.target.value,
                                )
                            }
                            required
                        />
                    </div>
                    <div className="sm:col-span-3">
                        <Label htmlFor="new-laboratory-instruction">
                            Collection instructions (optional)
                        </Label>
                        <textarea
                            id="new-laboratory-instruction"
                            rows={3}
                            className={laboratoryFieldClass}
                            value={createForm.data.collection_instruction}
                            onChange={(event) =>
                                createForm.setData(
                                    'collection_instruction',
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <ComponentEditor
                        components={createForm.data.components}
                        valueKindOptions={props.value_kind_options}
                        idPrefix="create-laboratory"
                        onChange={(components) =>
                            createForm.setData('components', components)
                        }
                    />
                    <Button
                        type="submit"
                        className="min-h-11 sm:col-span-3 sm:w-fit"
                        disabled={createForm.processing}
                    >
                        Save Examination
                    </Button>
                </form>
            ) : null}
            <div className="grid gap-4 lg:grid-cols-2">
                {props.examinations.length ? (
                    props.examinations.map((examination) => (
                        <MasterCard
                            key={`${examination.public_id}:${examination.version}:${examination.state}`}
                            examination={examination}
                            valueKindOptions={props.value_kind_options}
                        />
                    ))
                ) : (
                    <p className="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-600 lg:col-span-2">
                        No laboratory examination master records yet.
                    </p>
                )}
            </div>
        </main>
    );
}
