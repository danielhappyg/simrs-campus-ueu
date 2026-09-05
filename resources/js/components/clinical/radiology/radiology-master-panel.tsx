import { Link, useForm } from '@inertiajs/react';
import { Archive, ClipboardList, Pencil, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { newRadiologyOperationKey, radiologyFieldClass } from './operation';
import type { RadiologyMasterProjection, RadiologyMasterProps } from './types';

export type { RadiologyMasterProps } from './types';

function MasterErrors({ errors }: { errors: Record<string, string> }) {
    const ref = useRef<HTMLDivElement>(null);
    const messages = Object.values(errors);
    const errorFingerprint = messages.join('|');

    useEffect(() => {
        if (messages.length > 0) {
            ref.current?.focus();
        }
    }, [errorFingerprint, messages.length]);

    return messages.length ? (
        <div
            ref={ref}
            tabIndex={-1}
            role="alert"
            className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 outline-none focus-visible:ring-2 focus-visible:ring-red-600"
        >
            <p className="font-semibold">Data could not be saved.</p>
            <ul className="mt-1 list-disc pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}

function MasterCard({
    examination,
}: {
    examination: RadiologyMasterProjection;
}) {
    const [editing, setEditing] = useState(false);
    const [retiring, setRetiring] = useState(false);
    const updateForm = useForm({
        expected_version: examination.version,
        display_name: examination.display_name,
        preparation_instruction: examination.preparation_instruction ?? '',
        idempotency_key: newRadiologyOperationKey('master-update'),
    });
    const retireForm = useForm({
        expected_version: examination.version,
        idempotency_key: newRadiologyOperationKey('master-retire'),
    });

    const update = (event: FormEvent) => {
        event.preventDefault();

        if (!examination.actions.update_url) {
            return;
        }

        updateForm.patch(examination.actions.update_url, {
            preserveScroll: true,
            errorBag: `radiologyMasterUpdate.${examination.public_id}`,
        });
    };

    const retire = () => {
        if (!examination.actions.retire_url) {
            return;
        }

        retireForm.post(examination.actions.retire_url, {
            preserveScroll: true,
            errorBag: `radiologyMasterRetire.${examination.public_id}`,
        });
    };

    return (
        <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-mono text-xs font-semibold tracking-wide text-[#145a8d]">
                        {examination.code}
                    </p>
                    <h3 className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold text-slate-950">
                        {examination.display_name}
                    </h3>
                    <p className="mt-1 text-sm text-slate-600">
                        Version {examination.version} ·{' '}
                        {examination.state === 'ACTIVE'
                            ? 'Active'
                            : 'Deactivated'}
                    </p>
                </div>
                <span
                    className={`inline-flex min-h-7 items-center rounded-full px-3 text-xs font-semibold ${examination.state === 'ACTIVE' ? 'bg-emerald-50 text-emerald-800' : 'bg-[#e8eef2] text-[#243746]'}`}
                >
                    {examination.state === 'ACTIVE' ? 'Active' : 'Inactive'}
                </span>
            </div>
            <p className="mt-3 rounded-md bg-slate-50 p-3 text-sm text-slate-700">
                {examination.preparation_instruction ||
                    'No special preparation instructions.'}
            </p>
            <MasterErrors
                errors={{ ...updateForm.errors, ...retireForm.errors }}
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
                            <Archive className="mr-2 size-4" /> Deactivate
                        </Button>
                    ) : null}
                </div>
            ) : null}

            {editing && examination.actions.update_url ? (
                <form
                    onSubmit={update}
                    className="mt-4 grid gap-4 rounded-md border border-[#b9d9ed] bg-[#f4f9fc] p-4 sm:grid-cols-2"
                >
                    <div>
                        <Label htmlFor={`master-name-${examination.public_id}`}>
                            Examination name
                        </Label>
                        <input
                            id={`master-name-${examination.public_id}`}
                            className={radiologyFieldClass}
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
                        <Label htmlFor={`master-prep-${examination.public_id}`}>
                            Preparation instructions (optional)
                        </Label>
                        <textarea
                            id={`master-prep-${examination.public_id}`}
                            className={radiologyFieldClass}
                            rows={3}
                            value={updateForm.data.preparation_instruction}
                            onChange={(event) =>
                                updateForm.setData(
                                    'preparation_instruction',
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <Button
                        type="submit"
                        className="min-h-11 sm:col-span-2 sm:w-fit"
                        disabled={updateForm.processing}
                    >
                        Save changes
                    </Button>
                </form>
            ) : null}

            {retiring && examination.actions.retire_url ? (
                <div className="mt-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-950">
                    <p className="font-semibold">
                        Deactivate {examination.display_name}?
                    </p>
                    <p className="mt-1">
                        This examination cannot be selected for new requests.
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

export function RadiologyMasterPanel(props: RadiologyMasterProps) {
    const [creating, setCreating] = useState(false);
    const createForm = useForm({
        code: '',
        display_name: '',
        preparation_instruction: '',
        idempotency_key: newRadiologyOperationKey('master-create'),
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
            errorBag: 'radiologyMasterCreate',
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
                    Wards & Beds
                </Link>
                <Link
                    href="/manajemen-data/laboratorium"
                    className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950"
                >
                    Laboratory Examinations
                </Link>
                <Link
                    href="/manajemen-data/radiologi"
                    aria-current="page"
                    className="inline-flex min-h-11 items-center rounded-md bg-[#123b63] px-3 text-sm font-medium text-white"
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
                            Radiology Examinations
                        </h1>
                        <p className="mt-1 text-sm text-slate-600">
                            Manage examination definitions that physicians can
                            select.
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
                    ? 'Saving examination.'
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
                        New examination
                    </h2>
                    <MasterErrors errors={createForm.errors} />
                    <div>
                        <Label htmlFor="new-radiology-code">
                            Permanent code
                        </Label>
                        <input
                            id="new-radiology-code"
                            className={radiologyFieldClass}
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
                        <Label htmlFor="new-radiology-name">
                            Examination name
                        </Label>
                        <input
                            id="new-radiology-name"
                            className={radiologyFieldClass}
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
                        <Label htmlFor="new-radiology-prep">
                            Preparation instructions (optional)
                        </Label>
                        <textarea
                            id="new-radiology-prep"
                            className={radiologyFieldClass}
                            rows={3}
                            value={createForm.data.preparation_instruction}
                            onChange={(event) =>
                                createForm.setData(
                                    'preparation_instruction',
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <Button
                        type="submit"
                        className="min-h-11 sm:col-span-3 sm:w-fit"
                        disabled={createForm.processing}
                    >
                        Save examination
                    </Button>
                </form>
            ) : null}
            <div className="grid gap-4 lg:grid-cols-2">
                {props.examinations.length ? (
                    props.examinations.map((examination) => (
                        <MasterCard
                            key={`${examination.public_id}:${examination.version}:${examination.state}`}
                            examination={examination}
                        />
                    ))
                ) : (
                    <p className="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-600 lg:col-span-2">
                        No radiology examinations have been configured.
                    </p>
                )}
            </div>
        </main>
    );
}
