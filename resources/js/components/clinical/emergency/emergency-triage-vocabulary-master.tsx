import { Link, useForm } from '@inertiajs/react';
import { Archive, BookOpenCheck, History, Pencil, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { TriageChip, triagePresentation } from './emergency-shared';
import {
    emergencyFieldClass,
    formatEmergencyDate,
    newEmergencyOperationKey,
} from './operation';
import type {
    EmergencyTriageCategory,
    EmergencyTriageVocabularyMasterProps,
    EmergencyTriageVocabularyMasterRecord,
} from './types';

export type { EmergencyTriageVocabularyMasterProps } from './types';

const fixedCategories: EmergencyTriageCategory[] = [
    {
        code: 'MERAH',
        rank: 1,
        display_name: 'Merah',
        text_cue: 'Prioritas segera',
        colour_token: 'danger',
        guidance_text:
            'Kategori prioritas tertinggi berdasarkan penilaian manual ABCDE.',
    },
    {
        code: 'KUNING',
        rank: 2,
        display_name: 'Kuning',
        text_cue: 'Prioritas mendesak',
        colour_token: 'warning',
        guidance_text:
            'Kategori prioritas mendesak berdasarkan penilaian manual ABCDE.',
    },
    {
        code: 'HIJAU',
        rank: 3,
        display_name: 'Hijau',
        text_cue: 'Prioritas lebih rendah',
        colour_token: 'success',
        guidance_text:
            'Kategori prioritas lebih rendah berdasarkan penilaian manual ABCDE.',
    },
    {
        code: 'HITAM',
        rank: 4,
        display_name: 'Hitam',
        text_cue: 'Kategori hitam',
        colour_token: 'neutral',
        guidance_text:
            'Fakta kategori triase; bukan penetapan kematian atau sebab kematian.',
    },
];

function freshCategories(
    categories = fixedCategories,
): EmergencyTriageCategory[] {
    return categories.map((category) => ({ ...category }));
}

function MasterErrors({ errors }: { errors: Record<string, string> }) {
    const ref = useRef<HTMLDivElement>(null);
    const messages = Object.values(errors);
    const fingerprint = messages.join('|');

    useEffect(() => {
        if (messages.length > 0) {
            ref.current?.focus();
        }
    }, [fingerprint, messages.length]);

    return messages.length ? (
        <div
            ref={ref}
            tabIndex={-1}
            role="alert"
            className="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
            <p className="font-semibold">Kosakata belum tersimpan.</p>
            <ul className="mt-1 list-disc pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}

function CategoryEditor({
    category,
    prefix,
    onChange,
}: {
    category: EmergencyTriageCategory;
    prefix: string;
    onChange: (category: EmergencyTriageCategory) => void;
}) {
    const presentation = triagePresentation[category.code];

    return (
        <fieldset className="relative overflow-hidden rounded-lg border border-border bg-card p-4 shadow-xs">
            <span
                aria-hidden="true"
                className={cn(
                    'absolute inset-y-0 left-0 w-1.5',
                    presentation.rail,
                )}
            />
            <legend className="px-2">
                <TriageChip code={category.code} cue={category.text_cue} />
            </legend>
            <p className="mb-3 text-xs text-muted-foreground">
                Kode dan urutan {category.rank} bersifat tetap.
            </p>
            <div className="grid gap-3 md:grid-cols-2">
                <div>
                    <Label htmlFor={`${prefix}-display-name`}>
                        Label Indonesia
                    </Label>
                    <input
                        id={`${prefix}-display-name`}
                        className={emergencyFieldClass}
                        value={category.display_name}
                        onChange={(event) =>
                            onChange({
                                ...category,
                                display_name: event.target.value,
                            })
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor={`${prefix}-text-cue`}>
                        Petunjuk teks nonwarna
                    </Label>
                    <input
                        id={`${prefix}-text-cue`}
                        className={emergencyFieldClass}
                        value={category.text_cue}
                        onChange={(event) =>
                            onChange({
                                ...category,
                                text_cue: event.target.value,
                            })
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor={`${prefix}-colour-token`}>
                        Token warna
                    </Label>
                    <input
                        id={`${prefix}-colour-token`}
                        className={emergencyFieldClass}
                        pattern="[a-z][a-z0-9-]{1,31}"
                        value={category.colour_token}
                        onChange={(event) =>
                            onChange({
                                ...category,
                                colour_token: event.target.value.toLowerCase(),
                            })
                        }
                        required
                    />
                </div>
                <div>
                    <Label htmlFor={`${prefix}-guidance`}>
                        Panduan lokal (opsional)
                    </Label>
                    <textarea
                        id={`${prefix}-guidance`}
                        className={emergencyFieldClass}
                        rows={3}
                        maxLength={1000}
                        value={category.guidance_text ?? ''}
                        onChange={(event) =>
                            onChange({
                                ...category,
                                guidance_text: event.target.value || null,
                            })
                        }
                    />
                </div>
            </div>
        </fieldset>
    );
}

function VocabularyEditor({
    vocabulary,
}: {
    vocabulary: EmergencyTriageVocabularyMasterRecord;
}) {
    const [editing, setEditing] = useState(false);
    const [retiring, setRetiring] = useState(false);
    const form = useForm({
        expected_version: vocabulary.version,
        display_name: vocabulary.display_name,
        categories: freshCategories(vocabulary.categories),
        retire: false,
        idempotency_key: newEmergencyOperationKey('vocabulary-revise'),
    });

    const setCategory = (index: number, category: EmergencyTriageCategory) => {
        const categories = [...form.data.categories];
        categories[index] = category;
        form.setData('categories', categories);
    };

    const submit = (event: FormEvent, retire: boolean) => {
        event.preventDefault();

        if (!vocabulary.actions.revise_url) {
            return;
        }

        form.transform((data) => ({ ...data, retire }));
        form.post(vocabulary.actions.revise_url, {
            preserveScroll: true,
            errorBag: `triageVocabularyRevise.${vocabulary.public_id}`,
        });
    };

    return (
        <article className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-mono text-xs font-semibold tracking-[0.14em] text-primary">
                        {vocabulary.vocabulary_code}
                    </p>
                    <h2 className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-foreground">
                        {vocabulary.display_name}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Versi {vocabulary.version} ·{' '}
                        {vocabulary.state === 'ACTIVE' ? 'Aktif' : 'Dihentikan'}
                    </p>
                </div>
                <span
                    className={cn(
                        'inline-flex min-h-7 items-center rounded-full px-3 text-xs font-semibold',
                        vocabulary.state === 'ACTIVE'
                            ? 'bg-success/10 text-success'
                            : 'bg-muted text-muted-foreground',
                    )}
                >
                    {vocabulary.state === 'ACTIVE' ? 'Aktif' : 'Dihentikan'}
                </span>
            </div>

            <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                {vocabulary.categories.map((category) => (
                    <div
                        key={category.code}
                        className="relative overflow-hidden rounded-lg border border-border bg-background p-3 pl-4"
                    >
                        <span
                            aria-hidden="true"
                            className={cn(
                                'absolute inset-y-0 left-0 w-1.5',
                                triagePresentation[category.code].rail,
                            )}
                        />
                        <TriageChip
                            code={category.code}
                            cue={category.text_cue}
                        />
                        <p className="mt-2 text-sm font-semibold">
                            {category.display_name}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {category.guidance_text || 'Tanpa panduan lokal.'}
                        </p>
                    </div>
                ))}
            </div>

            {vocabulary.state === 'ACTIVE' && vocabulary.actions.revise_url ? (
                <div className="mt-4 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={() => setEditing((value) => !value)}
                    >
                        <Pencil aria-hidden="true" className="mr-2 size-4" />
                        Buat versi baru
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11 text-destructive"
                        onClick={() => setRetiring((value) => !value)}
                    >
                        <Archive aria-hidden="true" className="mr-2 size-4" />
                        Hentikan kosakata
                    </Button>
                </div>
            ) : null}

            <MasterErrors errors={form.errors} />

            {editing && vocabulary.actions.revise_url ? (
                <form
                    onSubmit={(event) => submit(event, false)}
                    className="mt-4 space-y-4 rounded-lg border border-primary/25 bg-primary/5 p-4"
                >
                    <div>
                        <Label
                            htmlFor={`vocabulary-name-${vocabulary.public_id}`}
                        >
                            Nama kosakata
                        </Label>
                        <input
                            id={`vocabulary-name-${vocabulary.public_id}`}
                            className={emergencyFieldClass}
                            value={form.data.display_name}
                            onChange={(event) =>
                                form.setData('display_name', event.target.value)
                            }
                            required
                        />
                    </div>
                    <div className="grid gap-4 xl:grid-cols-2">
                        {form.data.categories.map((category, index) => (
                            <CategoryEditor
                                key={category.code}
                                prefix={`${vocabulary.public_id}-${category.code}`}
                                category={category}
                                onChange={(next) => setCategory(index, next)}
                            />
                        ))}
                    </div>
                    <Button
                        type="submit"
                        className="min-h-11"
                        disabled={form.processing}
                    >
                        Simpan sebagai versi {vocabulary.version + 1}
                    </Button>
                </form>
            ) : null}

            {retiring && vocabulary.actions.revise_url ? (
                <form
                    onSubmit={(event) => submit(event, true)}
                    className="mt-4 rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm"
                >
                    <p className="font-semibold text-destructive">
                        Hentikan kosakata aktif ini?
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        Versi lama dan semua asesmen tetap tersedia sebagai
                        bukti. Kosakata yang sudah dihentikan tidak dapat
                        diaktifkan kembali.
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            variant="destructive"
                            className="min-h-11"
                            disabled={form.processing}
                        >
                            Ya, hentikan
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setRetiring(false)}
                        >
                            Kembali
                        </Button>
                    </div>
                </form>
            ) : null}

            <details className="mt-4 rounded-lg border border-border bg-muted/25 p-3">
                <summary className="flex min-h-11 cursor-pointer items-center gap-2 font-semibold">
                    <History
                        aria-hidden="true"
                        className="size-4 text-primary"
                    />
                    Riwayat versi ({vocabulary.versions.length})
                </summary>
                <ol className="mt-2 space-y-2">
                    {vocabulary.versions.map((version) => (
                        <li
                            key={version.public_id}
                            className="rounded-md border border-border bg-background p-3 text-sm"
                        >
                            <div className="flex flex-wrap justify-between gap-2">
                                <span className="font-semibold">
                                    Versi {version.version} ·{' '}
                                    {version.display_name}
                                </span>
                                <span className="text-muted-foreground">
                                    {formatEmergencyDate(version.created_at)}
                                </span>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {version.state === 'ACTIVE'
                                    ? 'Aktif'
                                    : 'Dihentikan'}
                                {' · '}
                                {version.actor_name ||
                                    'Pelaksana tidak tersedia'}
                            </p>
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {version.categories.map((category) => (
                                    <TriageChip
                                        key={category.code}
                                        code={category.code}
                                        cue={category.text_cue}
                                    />
                                ))}
                            </div>
                        </li>
                    ))}
                </ol>
            </details>
        </article>
    );
}

export function EmergencyTriageVocabularyMaster(
    props: EmergencyTriageVocabularyMasterProps,
) {
    const [creating, setCreating] = useState(false);
    const createForm = useForm({
        code: '',
        display_name: 'Kategori Triase IGD',
        categories: freshCategories(),
        idempotency_key: newEmergencyOperationKey('vocabulary-create'),
    });
    const canManage =
        props.permissions.can_manage && props.commands.create_url !== null;

    const setCreateCategory = (
        index: number,
        category: EmergencyTriageCategory,
    ) => {
        const categories = [...createForm.data.categories];
        categories[index] = category;
        createForm.setData('categories', categories);
    };

    const create = (event: FormEvent) => {
        event.preventDefault();

        if (!canManage || !props.commands.create_url) {
            return;
        }

        createForm.post(props.commands.create_url, {
            preserveScroll: true,
            errorBag: 'triageVocabularyCreate',
        });
    };

    if (!props.permissions.can_manage) {
        return null;
    }

    return (
        <main className="mx-auto w-full max-w-7xl space-y-5 p-4 sm:p-6">
            <nav
                aria-label="Manajemen data"
                className="flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1"
            >
                <Link
                    href="/manajemen-data/bangsal"
                    className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    Bangsal & Tempat Tidur
                </Link>
                <Link
                    href="/manajemen-data/laboratorium"
                    className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    Pemeriksaan Laboratorium
                </Link>
                <Link
                    href="/manajemen-data/radiologi"
                    className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    Pemeriksaan Radiologi
                </Link>
                <Link
                    href="/manajemen-data/triage"
                    aria-current="page"
                    className="inline-flex min-h-11 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground"
                >
                    Kosakata Triase IGD
                </Link>
            </nav>

            <header className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <span className="grid size-12 shrink-0 place-items-center rounded-lg bg-primary text-primary-foreground">
                        <BookOpenCheck aria-hidden="true" className="size-6" />
                    </span>
                    <div>
                        <p className="font-mono text-xs font-semibold tracking-[0.15em] text-primary">
                            MASTER KLINIS IGD
                        </p>
                        <h1 className="font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold text-foreground">
                            Kosakata Triase IGD
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                            Kelola label, petunjuk teks, token warna, dan
                            panduan lokal untuk empat kategori tetap. Perubahan
                            selalu membentuk versi bukti baru.
                        </p>
                    </div>
                </div>
                {canManage ? (
                    <Button
                        type="button"
                        className="min-h-11"
                        onClick={() => setCreating((value) => !value)}
                    >
                        <Plus aria-hidden="true" className="mr-2 size-4" />
                        Tambah kosakata
                    </Button>
                ) : null}
            </header>

            <p className="sr-only" role="status" aria-live="polite">
                {createForm.processing
                    ? 'Menyimpan kosakata triase.'
                    : `${props.vocabularies.length} kosakata ditampilkan.`}
            </p>

            {props.read_error ? (
                <div
                    role="alert"
                    className="rounded-md border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
                >
                    {props.read_error}
                </div>
            ) : null}

            {creating && canManage ? (
                <form
                    onSubmit={create}
                    className="space-y-4 rounded-xl border border-primary/25 bg-primary/5 p-4 shadow-sm"
                >
                    <h2 className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold text-foreground">
                        Kosakata baru
                    </h2>
                    <MasterErrors errors={createForm.errors} />
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <Label htmlFor="new-vocabulary-code">
                                Kode permanen
                            </Label>
                            <input
                                id="new-vocabulary-code"
                                className={emergencyFieldClass}
                                pattern="[A-Z0-9_]{3,64}"
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
                            <Label htmlFor="new-vocabulary-name">
                                Nama kosakata
                            </Label>
                            <input
                                id="new-vocabulary-name"
                                className={emergencyFieldClass}
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
                    </div>
                    <div className="grid gap-4 xl:grid-cols-2">
                        {createForm.data.categories.map((category, index) => (
                            <CategoryEditor
                                key={category.code}
                                prefix={`new-${category.code}`}
                                category={category}
                                onChange={(next) =>
                                    setCreateCategory(index, next)
                                }
                            />
                        ))}
                    </div>
                    <Button
                        type="submit"
                        className="min-h-11"
                        disabled={createForm.processing}
                    >
                        Simpan kosakata
                    </Button>
                </form>
            ) : null}

            <div className="space-y-4">
                {props.vocabularies.length ? (
                    props.vocabularies.map((vocabulary) => (
                        <VocabularyEditor
                            key={`${vocabulary.public_id}:${vocabulary.version}:${vocabulary.state}`}
                            vocabulary={vocabulary}
                        />
                    ))
                ) : (
                    <p className="rounded-xl border border-dashed border-border bg-card p-10 text-center text-sm text-muted-foreground">
                        Belum ada kosakata triase IGD.
                    </p>
                )}
            </div>
        </main>
    );
}
