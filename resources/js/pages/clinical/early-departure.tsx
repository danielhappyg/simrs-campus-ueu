import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Fingerprint,
    History,
    ListTodo,
    LockKeyhole,
    ShieldAlert,
    UserCheck,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { OutpatientEarlyDepartureWorkspaceProps } from '@/types';

type EarlyDepartureForm = {
    request_key: string;
    confirmed: boolean;
    stated_reason: string;
    communication_summary: string;
};

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'long',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

export default function OutpatientEarlyDepartureWorkspace({
    boundary,
    encounter,
    patient,
    session,
    source,
    authorization,
    departure,
    form: formOptions,
    urls,
}: OutpatientEarlyDepartureWorkspaceProps) {
    const form = useForm<EarlyDepartureForm>({
        request_key: formOptions.requestKey ?? '',
        confirmed: false,
        stated_reason: '',
        communication_summary: '',
    });
    const workflowError = (form.errors as Record<string, string | undefined>)
        .workflow;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(urls.store, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Pulang atas permintaan sendiri" />

            <div className="mx-auto flex w-full max-w-[1380px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-[#a84312] uppercase">
                            Disposisi keluar lebih awal · keputusan manusia
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold text-[#063650] md:text-4xl">
                            Pulang atas permintaan sendiri
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Catat peristiwa setelah pelayanan dimulai tanpa
                            menghapus pekerjaan sebelumnya atau menganggap rekam
                            selesai.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" className="min-h-11">
                            <Link href={urls.timeline}>
                                <History
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Linimasa rekam
                            </Link>
                        </Button>
                        <Button asChild variant="outline" className="min-h-11">
                            <Link href={urls.workQueue}>
                                <ListTodo
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Antrean tugas
                            </Link>
                        </Button>
                        <Button asChild variant="outline" className="min-h-11">
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
                    aria-labelledby="departure-boundary-title"
                    className="clinical-shadow overflow-hidden rounded-lg border border-[#efb08d] bg-[#fff8f3]"
                >
                    <div className="grid lg:grid-cols-[minmax(0,1fr)_23rem]">
                        <div className="p-5 md:p-6">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge className="border-[#a84312] bg-[#d75122] text-white">
                                    {boundary.classification}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className="border-[#d97706] bg-white text-[#8a3b0f]"
                                >
                                    EVENT MANUSIA · IMMUTABLE
                                </Badge>
                            </div>
                            <h2
                                id="departure-boundary-title"
                                className="mt-4 text-2xl font-semibold text-[#733315]"
                            >
                                Bukan pembatalan atau tidak hadir
                            </h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-[#7c4b34]">
                                Pasien sintetis sudah hadir dan pelayanan yang
                                telah dilakukan tetap tersimpan dengan
                                provenance aslinya.
                            </p>
                        </div>
                        <div className="grid gap-px border-t border-[#efb08d] bg-[#efb08d] lg:border-t-0 lg:border-l">
                            <div className="bg-white px-5 py-4">
                                <div className="flex items-center gap-2 text-sm font-semibold text-[#733315]">
                                    <ShieldAlert
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                    Bukan keputusan aman untuk pulang
                                </div>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    Sistem tidak menilai keselamatan, diagnosis,
                                    terapi, atau kelayakan pulang.
                                </p>
                            </div>
                            <div className="bg-white px-5 py-4">
                                <div className="flex items-center gap-2 text-sm font-semibold text-[#733315]">
                                    <LockKeyhole
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                    Tidak memfinalisasi rekam secara otomatis
                                </div>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    Encounter menjadi terminal, tetapi tidak
                                    dianggap closure, coding, atau finalisasi.
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

                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.78fr)]">
                    <section
                        aria-labelledby="departure-source-title"
                        className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                    >
                        <div className="border-b border-border px-5 py-4">
                            <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                Provenance sumber
                            </p>
                            <h2
                                id="departure-source-title"
                                className="mt-1 text-xl font-semibold"
                            >
                                Kondisi rekam saat peristiwa dicatat
                            </h2>
                        </div>
                        <div className="space-y-5 p-5">
                            <div className="rounded-lg border border-sky-200 bg-[#eaf4f8] p-4">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="text-xs font-semibold tracking-wider text-primary uppercase">
                                            Status encounter sumber
                                        </p>
                                        <p className="mt-1 font-semibold">
                                            {source.encounterStatus.label}
                                        </p>
                                    </div>
                                    <Badge
                                        variant="outline"
                                        className="font-mono"
                                    >
                                        {source.encounterStatus.code}
                                    </Badge>
                                </div>
                            </div>

                            {source.clinicalSources.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-border px-4 py-5 text-sm leading-6 text-muted-foreground">
                                    Belum ada versi klinis tersimpan pada titik
                                    ini. Status encounter dan peristiwa keluar
                                    tetap dicatat apa adanya.
                                </p>
                            ) : (
                                <ul className="space-y-3">
                                    {source.clinicalSources.map((item) => (
                                        <li
                                            key={item.versionPublicId}
                                            className="min-w-0 rounded-lg border border-border p-4"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="font-mono text-xs font-semibold text-primary">
                                                    {item.documentType}
                                                </span>
                                                <Badge variant="outline">
                                                    v{item.versionNumber} ·{' '}
                                                    {item.status}
                                                </Badge>
                                            </div>
                                            <div className="mt-3 flex min-w-0 items-start gap-2 text-xs text-muted-foreground">
                                                <Fingerprint
                                                    className="mt-0.5 size-4 shrink-0"
                                                    aria-hidden="true"
                                                />
                                                <span className="min-w-0 font-mono [overflow-wrap:anywhere]">
                                                    {item.contentHash}
                                                </span>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {source.snapshotHash && (
                                <div className="rounded-lg bg-slate-950 p-4 text-slate-100">
                                    <p className="text-xs font-semibold tracking-wider text-sky-200 uppercase">
                                        Hash snapshot sumber
                                    </p>
                                    <p className="mt-2 font-mono text-xs [overflow-wrap:anywhere]">
                                        {source.snapshotHash}
                                    </p>
                                </div>
                            )}
                        </div>
                    </section>

                    {authorization.canRecord && !departure ? (
                        <section
                            aria-labelledby="departure-form-title"
                            className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                        >
                            <div className="border-b border-border px-5 py-4">
                                <p className="text-xs font-bold tracking-wider text-[#a84312] uppercase">
                                    Konfirmasi eksplisit
                                </p>
                                <h2
                                    id="departure-form-title"
                                    className="mt-1 text-xl font-semibold"
                                >
                                    Catat peristiwa manusia
                                </h2>
                            </div>
                            <form className="space-y-5 p-5" onSubmit={submit}>
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                                    <p className="font-semibold text-amber-950">
                                        {formOptions.outcome.label}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-amber-900">
                                        Pemetaan referensi:{' '}
                                        <code>
                                            {
                                                formOptions.outcome
                                                    .interoperabilityCode
                                            }
                                        </code>
                                        . Tidak ada transmisi eksternal.
                                    </p>
                                </div>

                                <div>
                                    <label className="flex min-h-14 cursor-pointer items-start gap-3 rounded-lg border border-border p-4 focus-within:ring-3 focus-within:ring-ring/50">
                                        <input
                                            type="checkbox"
                                            name="confirmed"
                                            required
                                            checked={form.data.confirmed}
                                            onChange={(event) =>
                                                form.setData(
                                                    'confirmed',
                                                    event.target.checked,
                                                )
                                            }
                                            aria-describedby="confirmation-help confirmation-error"
                                            className="mt-1 size-5 shrink-0 accent-[#00639f]"
                                        />
                                        <span className="text-sm leading-6">
                                            Saya menegaskan bahwa pasien
                                            sintetis atau perwakilannya meminta
                                            mengakhiri kunjungan lebih awal dan
                                            bahwa catatan ini dibuat oleh
                                            manusia.
                                        </span>
                                    </label>
                                    <p
                                        id="confirmation-help"
                                        className="mt-2 text-xs text-muted-foreground"
                                    >
                                        Konfirmasi tidak menyatakan bahwa sistem
                                        menilai kepulangan aman.
                                    </p>
                                    <p
                                        id="confirmation-error"
                                        className="mt-1 min-h-5 text-sm text-red-600"
                                        role={
                                            form.errors.confirmed
                                                ? 'alert'
                                                : undefined
                                        }
                                    >
                                        {form.errors.confirmed}
                                    </p>
                                </div>

                                <div>
                                    <label
                                        htmlFor="stated-reason"
                                        className="text-sm font-semibold"
                                    >
                                        Alasan yang dinyatakan pasien atau
                                        perwakilan sintetis
                                    </label>
                                    <p
                                        id="stated-reason-help"
                                        className="mt-1 text-xs leading-5 text-muted-foreground"
                                    >
                                        Catat pernyataan faktual, 10–1000
                                        karakter. Jangan menambahkan verdict
                                        sistem.
                                    </p>
                                    <textarea
                                        id="stated-reason"
                                        name="stated_reason"
                                        required
                                        minLength={10}
                                        maxLength={1000}
                                        value={form.data.stated_reason}
                                        onChange={(event) =>
                                            form.setData(
                                                'stated_reason',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.stated_reason,
                                        )}
                                        aria-describedby="stated-reason-help stated-reason-error"
                                        className="mt-2 min-h-28 w-full resize-y rounded-md border border-input bg-white px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                    />
                                    <p
                                        id="stated-reason-error"
                                        className="mt-1 min-h-5 text-sm text-red-600"
                                        role={
                                            form.errors.stated_reason
                                                ? 'alert'
                                                : undefined
                                        }
                                    >
                                        {form.errors.stated_reason}
                                    </p>
                                </div>

                                <div>
                                    <label
                                        htmlFor="communication-summary"
                                        className="text-sm font-semibold"
                                    >
                                        Ringkasan komunikasi yang dicatat
                                        manusia
                                    </label>
                                    <p
                                        id="communication-summary-help"
                                        className="mt-1 text-xs leading-5 text-muted-foreground"
                                    >
                                        Catat apa yang benar-benar
                                        dikomunikasikan dalam simulasi, 10–2000
                                        karakter.
                                    </p>
                                    <textarea
                                        id="communication-summary"
                                        name="communication_summary"
                                        required
                                        minLength={10}
                                        maxLength={2000}
                                        value={form.data.communication_summary}
                                        onChange={(event) =>
                                            form.setData(
                                                'communication_summary',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            form.errors.communication_summary,
                                        )}
                                        aria-describedby="communication-summary-help communication-summary-error"
                                        className="mt-2 min-h-32 w-full resize-y rounded-md border border-input bg-white px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                    />
                                    <p
                                        id="communication-summary-error"
                                        className="mt-1 min-h-5 text-sm text-red-600"
                                        role={
                                            form.errors.communication_summary
                                                ? 'alert'
                                                : undefined
                                        }
                                    >
                                        {form.errors.communication_summary}
                                    </p>
                                </div>

                                <p
                                    className="min-h-5 text-sm text-red-600"
                                    role={workflowError ? 'alert' : undefined}
                                >
                                    {workflowError}
                                </p>

                                <div className="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                                    <AlertTriangle
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <p>
                                        Setelah dicatat, event tidak dapat
                                        diubah. Tugas yang belum selesai
                                        dihentikan; pekerjaan yang sudah selesai
                                        tetap utuh.
                                    </p>
                                </div>

                                <Button
                                    type="submit"
                                    className="min-h-11 w-full bg-[#a84312] px-5 text-white"
                                    disabled={form.processing}
                                >
                                    <UserCheck
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Catat pulang atas permintaan sendiri
                                </Button>
                            </form>
                        </section>
                    ) : departure ? (
                        <section
                            aria-labelledby="recorded-departure-title"
                            className="clinical-shadow overflow-hidden rounded-lg border border-emerald-200 bg-white"
                        >
                            <div className="border-b border-emerald-200 bg-emerald-50 px-5 py-4">
                                <div className="flex items-center gap-2 text-emerald-900">
                                    <CheckCircle2
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                    <h2
                                        id="recorded-departure-title"
                                        className="text-xl font-semibold"
                                    >
                                        Peristiwa sudah dicatat
                                    </h2>
                                </div>
                            </div>
                            <dl className="grid gap-4 p-5 text-sm">
                                <div>
                                    <dt className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Outcome
                                    </dt>
                                    <dd className="mt-1 font-semibold">
                                        {departure.outcome.label}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Aktor dan waktu
                                    </dt>
                                    <dd className="mt-1">
                                        <strong>{departure.actor}</strong> ·{' '}
                                        {departure.role}
                                        <br />
                                        <time dateTime={departure.occurredAt}>
                                            {formatDateTime(
                                                departure.occurredAt,
                                            )}{' '}
                                            WIB
                                        </time>
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Alasan yang dinyatakan
                                    </dt>
                                    <dd className="mt-1 leading-6 whitespace-pre-wrap">
                                        {departure.statedReason}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Ringkasan komunikasi
                                    </dt>
                                    <dd className="mt-1 leading-6 whitespace-pre-wrap">
                                        {departure.communicationSummary}
                                    </dd>
                                </div>
                            </dl>
                        </section>
                    ) : (
                        <section className="rounded-lg border border-border bg-muted/30 p-5">
                            <h2 className="font-semibold">
                                Tidak tersedia pada tahap ini
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                Tidak ada event tersimpan dan status encounter
                                bukan sumber yang diizinkan untuk aksi ini.
                            </p>
                        </section>
                    )}
                </div>

                <p className="text-center text-xs text-muted-foreground">
                    {session.code} · {session.scenarioTitle}
                </p>
            </div>
        </>
    );
}

OutpatientEarlyDepartureWorkspace.layout = {
    breadcrumbs: [
        { title: 'Antrean kerja', href: '/work' },
        { title: 'Encounter', href: '#' },
        { title: 'Pulang atas permintaan sendiri', href: '#' },
    ],
};
