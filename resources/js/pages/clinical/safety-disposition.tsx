import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    Clock3,
    Fingerprint,
    ListTodo,
    LockKeyhole,
    PauseCircle,
    Route,
    ShieldAlert,
    UserCheck,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type {
    OutpatientSafetyDispositionOutcomeCode,
    OutpatientSafetyDispositionWorkspaceProps,
} from '@/types';

type DispositionForm = {
    request_key: string;
    outcome: '' | OutpatientSafetyDispositionOutcomeCode;
    rationale: string;
};

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Waktu persetujuan tidak tersedia';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'long',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

const flowSteps = [
    {
        label: 'Asesmen awal',
        detail: 'Versi disetujui',
        state: 'complete',
    },
    {
        label: 'ALUR RUTIN DIHENTIKAN',
        detail: 'Encounter tereskalasi',
        state: 'paused',
    },
    {
        label: 'Keputusan manusia',
        detail: 'Supervisor / fasilitator',
        state: 'current',
    },
    {
        label: 'Hasil alur',
        detail: 'Lanjut rutin / transfer simulasi',
        state: 'future',
    },
] as const;

export default function SafetyDispositionWorkspace({
    boundary,
    encounter,
    patient,
    session,
    source,
    authorization,
    disposition,
    formOptions,
    urls,
}: OutpatientSafetyDispositionWorkspaceProps) {
    const form = useForm<DispositionForm>({
        request_key: formOptions.requestKey ?? '',
        outcome: '',
        rationale: '',
    });
    const workflowError = (form.errors as Record<string, string | undefined>)
        .workflow;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(urls.store, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Keputusan Eskalasi Simulasi" />

            <div className="mx-auto flex w-full max-w-[1380px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-[#b54708] uppercase">
                            Keselamatan alur rawat jalan · keputusan manusia
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold text-[#063650] md:text-4xl">
                            Keputusan Eskalasi Simulasi
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Catat satu keputusan alur yang dapat ditelusuri
                            setelah eskalasi asesmen awal. Sistem tidak memilih
                            atau menyarankan hasil.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={urls.workQueue}>
                                <ListTodo
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Antrean tugas
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={urls.encounter}>
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Kembali ke encounter
                            </Link>
                        </Button>
                    </div>
                </header>

                <section
                    aria-labelledby="safety-boundary-title"
                    className="clinical-shadow overflow-hidden rounded-lg border border-[#efb08d] bg-[#fff8f3]"
                >
                    <div className="grid gap-0 lg:grid-cols-[minmax(0,1fr)_21rem]">
                        <div className="p-5 md:p-6">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge className="border-[#b53b13] bg-[#f05828] text-white">
                                    {boundary.classification}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className="border-[#d97706] bg-white text-[#8a3b0f]"
                                >
                                    ALUR RUTIN DIHENTIKAN
                                </Badge>
                            </div>
                            <h2
                                id="safety-boundary-title"
                                className="mt-4 text-2xl font-semibold text-[#733315]"
                            >
                                Keputusan harus dibuat oleh manusia yang
                                berwenang
                            </h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-[#7c4b34]">
                                Halaman ini mengelola status alur pada skenario
                                sintetis. Ia tidak menilai kegawatan dan tidak
                                menentukan tindakan klinis.
                            </p>
                        </div>
                        <div className="grid gap-px border-t border-[#efb08d] bg-[#efb08d] lg:border-t-0 lg:border-l">
                            <div className="bg-white px-5 py-4">
                                <div className="flex items-center gap-2 text-sm font-semibold text-[#733315]">
                                    <ShieldAlert
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                    Bukan triase IGD
                                </div>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    Tidak ada kategori acuity atau protokol
                                    gawat darurat.
                                </p>
                            </div>
                            <div className="bg-white px-5 py-4">
                                <div className="flex items-center gap-2 text-sm font-semibold text-[#733315]">
                                    <LockKeyhole
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                    Bukan rekomendasi diagnosis atau terapi
                                </div>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    Pilihan dan rasional seluruhnya ditulis
                                    manusia.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <PatientContextBanner
                    patient={patient}
                    encounter={encounter}
                    actingAs={authorization.role}
                />

                <section aria-labelledby="flow-title">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                Jalur keputusan
                            </p>
                            <h2
                                id="flow-title"
                                className="mt-1 text-xl font-semibold"
                            >
                                Titik henti yang dapat ditelusuri
                            </h2>
                        </div>
                        <span className="font-mono text-xs text-muted-foreground">
                            {session.code}
                        </span>
                    </div>
                    <ol
                        data-testid="paused-flow-rail"
                        className="clinical-shadow grid overflow-hidden rounded-lg border border-sky-200 bg-white sm:grid-cols-2 xl:grid-cols-4"
                    >
                        {flowSteps.map((step, index) => (
                            <li
                                key={step.label}
                                className={cn(
                                    'relative min-w-0 border-b border-sky-100 p-4 sm:nth-[2]:border-b-0 xl:border-r xl:border-b-0 xl:last:border-r-0',
                                    step.state === 'paused' && 'bg-[#fff0e7]',
                                    step.state === 'current' && 'bg-[#eaf4f8]',
                                )}
                            >
                                <div className="flex items-start gap-3">
                                    <span
                                        className={cn(
                                            'flex size-8 shrink-0 items-center justify-center rounded-full border text-xs font-bold',
                                            step.state === 'complete' &&
                                                'border-emerald-300 bg-emerald-50 text-emerald-800',
                                            step.state === 'paused' &&
                                                'border-orange-400 bg-[#f05828] text-white',
                                            step.state === 'current' &&
                                                'border-primary bg-primary text-white',
                                            step.state === 'future' &&
                                                'border-slate-300 bg-slate-50 text-slate-600',
                                        )}
                                    >
                                        {step.state === 'paused' ? (
                                            <PauseCircle
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            index + 1
                                        )}
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-sm font-bold break-words">
                                            {step.label}
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                            {step.detail}
                                        </p>
                                    </div>
                                </div>
                                {index < flowSteps.length - 1 && (
                                    <ArrowRight
                                        className="absolute top-1/2 -right-2 z-10 hidden size-4 -translate-y-1/2 rounded-full bg-white text-sky-400 xl:block"
                                        aria-hidden="true"
                                    />
                                )}
                            </li>
                        ))}
                    </ol>
                </section>

                <div className="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,0.92fr)_minmax(0,1.08fr)]">
                    <section
                        aria-labelledby="source-title"
                        className="clinical-shadow min-w-0 rounded-lg border border-sky-200 bg-white p-5 md:p-6"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                    Sumber keputusan
                                </p>
                                <h2
                                    id="source-title"
                                    className="mt-1 text-xl font-semibold"
                                >
                                    Eskalasi asesmen awal yang disetujui
                                </h2>
                            </div>
                            <Badge variant="outline">
                                Versi {source.versionNumber}
                            </Badge>
                        </div>

                        <dl className="mt-5 grid gap-3 sm:grid-cols-2">
                            <div className="rounded-md border border-sky-100 bg-[#f7fbfd] p-4">
                                <dt className="text-[0.68rem] font-bold tracking-wider text-muted-foreground uppercase">
                                    Penulis
                                </dt>
                                <dd className="mt-1 text-sm font-semibold">
                                    {source.author}
                                </dd>
                                <dd className="text-xs text-muted-foreground">
                                    {source.authorRole}
                                </dd>
                            </div>
                            <div className="rounded-md border border-sky-100 bg-[#f7fbfd] p-4">
                                <dt className="text-[0.68rem] font-bold tracking-wider text-muted-foreground uppercase">
                                    Disetujui
                                </dt>
                                <dd className="mt-1 text-sm font-semibold">
                                    {formatDateTime(source.approvedAt)}
                                </dd>
                            </div>
                        </dl>

                        <div className="mt-3 rounded-md border border-orange-200 bg-orange-50 p-4">
                            <p className="text-xs font-bold tracking-wider text-[#8a3b0f] uppercase">
                                Keputusan penulis
                            </p>
                            <p className="mt-1 font-mono text-sm font-semibold break-all text-[#733315]">
                                {source.safetyDecision}
                            </p>
                        </div>

                        <div className="mt-3 space-y-2">
                            {source.safetyResponses.map((response) => (
                                <div
                                    key={`${response.questionCode}-${response.response}`}
                                    className="rounded-md border border-border p-4"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <code className="font-mono text-xs font-semibold text-primary">
                                            {response.questionCode}
                                        </code>
                                        <Badge variant="outline">
                                            {response.response}
                                        </Badge>
                                    </div>
                                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                        {response.note ??
                                            'Tidak ada catatan tambahan.'}
                                    </p>
                                </div>
                            ))}
                        </div>

                        <div className="mt-3 rounded-md border border-border bg-muted/30 p-4">
                            <p className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                                Ringkasan handoff
                            </p>
                            <p className="mt-1 text-sm leading-6">
                                {source.handoffSummary ??
                                    'Tidak didokumentasikan'}
                            </p>
                        </div>

                        <div className="mt-3 rounded-md border border-sky-200 bg-[#eaf4f8] p-4">
                            <div className="flex items-center gap-2 text-primary">
                                <Fingerprint
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                <span className="text-xs font-bold tracking-wider uppercase">
                                    SHA-256 versi sumber
                                </span>
                            </div>
                            <code className="mt-2 block font-mono text-[0.7rem] leading-5 break-all text-[#174c68]">
                                {source.contentHash}
                            </code>
                            <p className="mt-2 font-mono text-[0.68rem] break-all text-muted-foreground">
                                {source.versionPublicId}
                            </p>
                        </div>
                    </section>

                    {authorization.canRecord && !disposition ? (
                        <section
                            aria-labelledby="decision-title"
                            className="clinical-shadow min-w-0 rounded-lg border border-[#9ecddd] bg-white p-5 md:p-6"
                        >
                            <div className="flex items-start gap-3">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-white">
                                    <UserCheck
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                        Tindakan berwenang
                                    </p>
                                    <h2
                                        id="decision-title"
                                        className="mt-1 text-xl font-semibold"
                                    >
                                        Catat satu disposisi alur
                                    </h2>
                                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                        Tidak ada pilihan awal. Tinjau
                                        konsekuensi operasional, pilih sendiri,
                                        lalu tulis alasan keputusan.
                                    </p>
                                </div>
                            </div>

                            <form onSubmit={submit} className="mt-6 space-y-5">
                                <input
                                    type="hidden"
                                    name="request_key"
                                    value={form.data.request_key}
                                />
                                <fieldset>
                                    <legend className="text-sm font-semibold">
                                        Hasil alur simulasi
                                    </legend>
                                    <p
                                        id="outcome-help"
                                        className="mt-1 text-xs leading-5 text-muted-foreground"
                                    >
                                        Pilih tanpa bantuan rekomendasi sistem.
                                    </p>
                                    <div className="mt-3 grid gap-3">
                                        {formOptions.outcomes.map((option) => {
                                            const selected =
                                                form.data.outcome ===
                                                option.code;

                                            return (
                                                <label
                                                    key={option.code}
                                                    htmlFor={`outcome-${option.code}`}
                                                    className={cn(
                                                        'flex min-h-14 cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors focus-within:ring-3 focus-within:ring-ring/50',
                                                        selected
                                                            ? 'border-primary bg-[#eaf4f8]'
                                                            : 'border-border bg-white hover:border-sky-300',
                                                    )}
                                                >
                                                    <input
                                                        id={`outcome-${option.code}`}
                                                        type="radio"
                                                        name="outcome"
                                                        value={option.code}
                                                        required
                                                        checked={selected}
                                                        onChange={() =>
                                                            form.setData(
                                                                'outcome',
                                                                option.code,
                                                            )
                                                        }
                                                        aria-describedby="outcome-help outcome-error"
                                                        className="mt-1 size-5 shrink-0 accent-[#00639f]"
                                                    />
                                                    <span className="min-w-0">
                                                        <span className="block text-sm font-semibold">
                                                            {option.label}
                                                        </span>
                                                        <span className="mt-1 block text-xs leading-5 text-muted-foreground">
                                                            {option.consequence}
                                                        </span>
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                    <p
                                        id="outcome-error"
                                        className="mt-2 min-h-5 text-sm text-red-600"
                                        role={
                                            form.errors.outcome
                                                ? 'alert'
                                                : undefined
                                        }
                                    >
                                        {form.errors.outcome}
                                    </p>
                                </fieldset>

                                <div>
                                    <label
                                        htmlFor="rationale"
                                        className="text-sm font-semibold"
                                    >
                                        Rasional keputusan manusia
                                    </label>
                                    <p
                                        id="rationale-help"
                                        className="mt-1 text-xs leading-5 text-muted-foreground"
                                    >
                                        Wajib 10–2000 karakter. Disimpan sebagai
                                        bukti terlindungi, bukan rekomendasi
                                        sistem.
                                    </p>
                                    <textarea
                                        id="rationale"
                                        name="rationale"
                                        required
                                        minLength={10}
                                        maxLength={2000}
                                        value={form.data.rationale}
                                        onChange={(event) =>
                                            form.setData(
                                                'rationale',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.rationale,
                                        )}
                                        aria-describedby="rationale-help rationale-error"
                                        className="mt-2 min-h-32 w-full resize-y rounded-md border border-input bg-white px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                        placeholder="Tuliskan dasar keputusan alur yang Anda buat setelah peninjauan manusia."
                                    />
                                    <p
                                        id="rationale-error"
                                        className="mt-2 min-h-5 text-sm text-red-600"
                                        role={
                                            form.errors.rationale
                                                ? 'alert'
                                                : undefined
                                        }
                                    >
                                        {form.errors.rationale}
                                    </p>
                                </div>

                                <p
                                    id="workflow-error"
                                    className="min-h-5 text-sm text-red-600"
                                    role={workflowError ? 'alert' : undefined}
                                >
                                    {workflowError}
                                </p>

                                <div className="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                                    <div className="flex items-start gap-2">
                                        <AlertTriangle
                                            className="mt-0.5 size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <p>
                                            Setelah dicatat, keputusan tidak
                                            dapat diubah. Permintaan bersaing
                                            akan ditolak.
                                        </p>
                                    </div>
                                </div>

                                <Button
                                    type="submit"
                                    className="min-h-11 w-full bg-[#00639f] px-5 text-white sm:w-auto"
                                    disabled={form.processing}
                                >
                                    <UserCheck
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Catat keputusan manusia
                                </Button>
                            </form>
                        </section>
                    ) : disposition ? (
                        <section
                            aria-labelledby="recorded-title"
                            className="clinical-shadow min-w-0 rounded-lg border border-emerald-200 bg-white p-5 md:p-6"
                        >
                            <div className="flex items-start gap-3">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white">
                                    <CheckCircle2
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <p className="text-xs font-bold tracking-wider text-emerald-800 uppercase">
                                        Bukti append-only
                                    </p>
                                    <h2
                                        id="recorded-title"
                                        className="mt-1 text-xl font-semibold"
                                    >
                                        Keputusan sudah dicatat
                                    </h2>
                                </div>
                            </div>

                            <div className="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-5">
                                <p className="text-xs font-bold tracking-wider text-emerald-800 uppercase">
                                    Disposisi
                                </p>
                                <p className="mt-1 text-lg font-semibold text-emerald-950">
                                    {disposition.outcome.label}
                                </p>
                            </div>

                            <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                                <div className="rounded-md border border-border p-4">
                                    <dt className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                                        Aktor
                                    </dt>
                                    <dd className="mt-1 text-sm font-semibold">
                                        {disposition.actor}
                                    </dd>
                                    <dd className="text-xs text-muted-foreground">
                                        {disposition.role}
                                    </dd>
                                </div>
                                <div className="rounded-md border border-border p-4">
                                    <dt className="flex items-center gap-1.5 text-xs font-bold tracking-wider text-muted-foreground uppercase">
                                        <Clock3
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Waktu
                                    </dt>
                                    <dd className="mt-1 text-sm font-semibold">
                                        {formatDateTime(disposition.occurredAt)}
                                    </dd>
                                </div>
                            </dl>

                            <div className="mt-3 rounded-md border border-border bg-muted/30 p-4">
                                <p className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                                    Rasional manusia
                                </p>
                                <p className="mt-1 text-sm leading-6 whitespace-pre-wrap">
                                    {disposition.rationale}
                                </p>
                            </div>

                            <p className="mt-4 font-mono text-[0.68rem] break-all text-muted-foreground">
                                {disposition.publicId}
                            </p>
                        </section>
                    ) : (
                        <section
                            aria-labelledby="unavailable-title"
                            className="clinical-shadow rounded-lg border border-slate-200 bg-white p-6"
                        >
                            <Route
                                className="size-8 text-slate-500"
                                aria-hidden="true"
                            />
                            <h2
                                id="unavailable-title"
                                className="mt-3 text-xl font-semibold"
                            >
                                Keputusan tidak tersedia
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                Status encounter atau konteks tugas tidak lagi
                                menerima disposisi baru.
                            </p>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}
