import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    Check,
    ChevronDown,
    ChevronUp,
    CircleDotDashed,
    FileJson2,
    Fingerprint,
    FlaskConical,
    LockKeyhole,
    Network,
    Play,
    ReceiptText,
    Route,
    Send,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { EncounterContext, PatientContext } from '@/types';

type ClaimStep = {
    action: string;
    method: string;
    label: string;
    completed: boolean;
    available: boolean;
    requestKey: string | null;
    actionUrl: string;
};

type ClaimEvent = {
    publicId: string;
    sequenceNumber: number;
    action: string;
    label: string;
    method: string;
    request: Record<string, unknown>;
    response: Record<string, unknown>;
    requestHash: string;
    responseHash: string;
    responseCode: number;
    transportState: string;
    actor: string;
    recordedAt: string;
};

type Snapshot = {
    identifiers: {
        nomorKartu: string;
        nomorSep: string;
        nomorRm: string;
        coderNik: string;
        synthetic: true;
    };
    coding: {
        diagnoses: Array<{
            code: string;
            display: string;
            assignmentPublicId: string;
            contentHash: string;
        }>;
        procedures: Array<{
            code: string;
            display: string;
            assignmentPublicId: string;
            contentHash: string;
        }>;
    };
    billing: {
        currency: string;
        hospitalTotal: number;
        educationalPlaceholder: true;
    };
};

type Props = {
    boundary: {
        classification: string;
        mode: string;
        transportState: string;
        externalEndpoint: null;
        outboundEnabled: false;
        certifiedGrouper: false;
        compatibilityProfile: string;
        observedInstallationVersion: string;
    };
    encounter: EncounterContext;
    patient: PatientContext;
    assignment: {
        program: string;
        role: string;
        canAdvance: boolean;
    };
    claimCase: {
        publicId: string;
        status: { code: string; label: string };
        syntheticSep: string;
        sourceSnapshotHash: string;
        grouperCode: string | null;
        grouperDescription: string | null;
        simulatedTariff: number | null;
    } | null;
    snapshot: Snapshot;
    steps: ClaimStep[];
    events: ClaimEvent[];
    urls: {
        back: string;
        timeline: string;
        interoperabilityPreview: string;
    };
};

function formatCurrency(value: number | null): string {
    if (value === null) {
        return 'Belum tersedia';
    }

    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(value);
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

function shortHash(value: string): string {
    return `${value.slice(0, 12)}…${value.slice(-8)}`;
}

export default function EClaimSimulation({
    boundary,
    encounter,
    patient,
    assignment,
    claimCase,
    snapshot,
    steps,
    events,
    urls,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [processingAction, setProcessingAction] = useState<string | null>(
        null,
    );
    const [expandedEvent, setExpandedEvent] = useState<string | null>(null);

    function advance(step: ClaimStep) {
        if (!step.available || !step.requestKey) {
            return;
        }

        router.post(
            step.actionUrl,
            { action: step.action, request_key: step.requestKey },
            {
                preserveScroll: true,
                onStart: () => setProcessingAction(step.action),
                onFinish: () => setProcessingAction(null),
            },
        );
    }

    return (
        <>
            <Head title={`Simulasi E-Klaim ${encounter.number}`} />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Laboratorium klaim RMIK · BPJS/E-Klaim
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Simulasi Alur E-Klaim
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Latihan urutan bridging dari data SIMRS yang sudah
                            difinalisasi. Request dan response disusun lokal;
                            tidak ada koneksi ke E-Klaim, BPJS, atau data center
                            Kemenkes.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={urls.interoperabilityPreview}>
                                <Network
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Pratinjau FHIR
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={urls.back}>
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Kembali ke debrief
                            </Link>
                        </Button>
                    </div>
                </header>

                <PatientContextBanner
                    patient={patient}
                    encounter={encounter}
                    actingAs={`${assignment.program} · ${assignment.role}`}
                />

                <section
                    aria-labelledby="eclaim-boundary-title"
                    className="clinical-shadow overflow-hidden rounded-lg border-2 border-orange-300 bg-white"
                >
                    <div className="grid lg:grid-cols-[minmax(0,1fr)_21rem]">
                        <div className="p-5 md:p-6">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge className="bg-orange-700 text-white">
                                    {boundary.classification}
                                </Badge>
                                <Badge variant="outline">
                                    E-Klaim teramati{' '}
                                    {boundary.observedInstallationVersion}
                                </Badge>
                                <Badge variant="outline">{boundary.mode}</Badge>
                            </div>
                            <h2
                                id="eclaim-boundary-title"
                                className="mt-5 text-2xl font-semibold text-[#7c2d12]"
                            >
                                Tidak ada jalur keluar dari kampus
                            </h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-[#7c2d12]">
                                Adapter ini hanya meniru kontrak JSON untuk
                                pembelajaran. Grouper, tarif, nomor kartu, SEP,
                                NIK koder, dan tanda terima semuanya sintetis;
                                tidak boleh dipakai sebagai bukti klaim nyata.
                            </p>
                        </div>
                        <aside className="flex items-center justify-center border-t-2 border-orange-300 bg-orange-50 p-6 lg:border-t-0 lg:border-l-2">
                            <div className="rounded-md border-2 border-orange-700 bg-white px-6 py-5 text-center shadow-[5px_5px_0_#fed7aa]">
                                <LockKeyhole
                                    className="mx-auto size-7 text-orange-800"
                                    aria-hidden="true"
                                />
                                <p className="mt-3 font-mono text-lg font-semibold tracking-[0.12em] text-orange-900">
                                    {boundary.transportState}
                                </p>
                                <p className="mt-1 text-xs text-orange-800">
                                    Endpoint eksternal: tidak dikonfigurasi
                                </p>
                            </div>
                        </aside>
                    </div>
                </section>

                {errors.workflow && (
                    <div
                        role="alert"
                        className="rounded-lg border border-red-300 bg-red-50 px-5 py-4 text-sm text-red-950"
                    >
                        <div className="flex gap-3">
                            <ShieldAlert
                                className="mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <p>{errors.workflow}</p>
                        </div>
                    </div>
                )}

                <section
                    aria-labelledby="claim-flow-title"
                    className="clinical-shadow rounded-lg border bg-white p-5 md:p-6"
                >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                Contoh proses klaim
                            </p>
                            <h2
                                id="claim-flow-title"
                                className="mt-1 text-2xl font-semibold"
                            >
                                Lima checkpoint yang dapat diaudit
                            </h2>
                        </div>
                        {claimCase && (
                            <Badge className="bg-primary text-white">
                                {claimCase.status.label}
                            </Badge>
                        )}
                    </div>

                    <ol className="mt-6 grid gap-3 lg:grid-cols-5">
                        {steps.map((step, index) => (
                            <li
                                key={step.action}
                                className={cn(
                                    'relative rounded-lg border p-4',
                                    step.completed
                                        ? 'border-emerald-300 bg-emerald-50'
                                        : step.available
                                          ? 'border-primary bg-sky-50 ring-2 ring-sky-100'
                                          : 'border-slate-200 bg-slate-50',
                                )}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="flex size-7 items-center justify-center rounded-full bg-white font-mono text-xs font-bold shadow-sm">
                                        {index + 1}
                                    </span>
                                    {step.completed ? (
                                        <Check
                                            className="size-5 text-emerald-700"
                                            aria-label="Selesai"
                                        />
                                    ) : (
                                        <CircleDotDashed
                                            className="size-5 text-slate-500"
                                            aria-hidden="true"
                                        />
                                    )}
                                </div>
                                <p className="mt-4 font-semibold">
                                    {step.label}
                                </p>
                                <code className="mt-1 block text-xs text-muted-foreground">
                                    {step.method}
                                </code>
                                {step.available && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        className="mt-4 w-full"
                                        disabled={processingAction !== null}
                                        onClick={() => advance(step)}
                                    >
                                        <Play
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Jalankan
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ol>

                    {!assignment.canAdvance && (
                        <p className="mt-4 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                            Mode observasi: hanya assignment koder RMIK dengan
                            kapabilitas klaim yang dapat menjalankan checkpoint.
                        </p>
                    )}
                </section>

                <section className="grid gap-4 xl:grid-cols-3">
                    <article className="clinical-shadow rounded-lg border bg-white p-5">
                        <Fingerprint
                            className="size-5 text-primary"
                            aria-hidden="true"
                        />
                        <p className="mt-3 text-xs font-bold tracking-wider text-primary uppercase">
                            Identitas sintetis
                        </p>
                        <dl className="mt-3 space-y-3 text-sm">
                            <div>
                                <dt className="text-muted-foreground">
                                    Nomor SEP
                                </dt>
                                <dd className="mt-1 font-mono font-semibold">
                                    {snapshot.identifiers.nomorSep}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Nomor kartu
                                </dt>
                                <dd className="mt-1 font-mono">
                                    {snapshot.identifiers.nomorKartu}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Nomor rekam medis
                                </dt>
                                <dd className="mt-1 font-mono">
                                    {snapshot.identifiers.nomorRm}
                                </dd>
                            </div>
                        </dl>
                    </article>

                    <article className="clinical-shadow rounded-lg border bg-white p-5">
                        <Route
                            className="size-5 text-primary"
                            aria-hidden="true"
                        />
                        <p className="mt-3 text-xs font-bold tracking-wider text-primary uppercase">
                            Sumber koding disetujui
                        </p>
                        <div className="mt-3 space-y-3 text-sm">
                            {[
                                ...snapshot.coding.diagnoses,
                                ...snapshot.coding.procedures,
                            ].map((code) => (
                                <div key={code.assignmentPublicId}>
                                    <p className="font-mono font-semibold">
                                        {code.code}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {code.display}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </article>

                    <article className="clinical-shadow rounded-lg border bg-white p-5">
                        <ReceiptText
                            className="size-5 text-primary"
                            aria-hidden="true"
                        />
                        <p className="mt-3 text-xs font-bold tracking-wider text-primary uppercase">
                            Hasil pendidikan
                        </p>
                        <dl className="mt-3 space-y-3 text-sm">
                            <div>
                                <dt className="text-muted-foreground">
                                    Total billing SIMRS
                                </dt>
                                <dd className="mt-1 text-lg font-semibold">
                                    {formatCurrency(
                                        snapshot.billing.hospitalTotal,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Kode kelompok
                                </dt>
                                <dd className="mt-1 font-mono">
                                    {claimCase?.grouperCode ??
                                        'Belum dikelompokkan'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Tarif grouper simulasi
                                </dt>
                                <dd className="mt-1 font-semibold">
                                    {formatCurrency(
                                        claimCase?.simulatedTariff ?? null,
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </article>
                </section>

                <section
                    aria-labelledby="exchange-log-title"
                    className="clinical-shadow rounded-lg border bg-white p-5 md:p-6"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                Jejak pertukaran lokal
                            </p>
                            <h2
                                id="exchange-log-title"
                                className="mt-1 text-2xl font-semibold"
                            >
                                Request / response per checkpoint
                            </h2>
                        </div>
                        <Badge variant="outline">
                            {events.length} event immutable
                        </Badge>
                    </div>

                    {events.length === 0 ? (
                        <div className="mt-5 rounded-lg border border-dashed p-8 text-center">
                            <FlaskConical
                                className="mx-auto size-7 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <p className="mt-3 font-semibold">
                                Belum ada pertukaran
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Jalankan checkpoint pertama untuk melihat
                                payload JSON sintetis.
                            </p>
                        </div>
                    ) : (
                        <div className="mt-5 space-y-3">
                            {events.map((event) => {
                                const expanded =
                                    expandedEvent === event.publicId;

                                return (
                                    <article
                                        key={event.publicId}
                                        className="overflow-hidden rounded-lg border"
                                    >
                                        <button
                                            type="button"
                                            className="flex min-h-14 w-full items-center justify-between gap-4 px-4 py-3 text-left hover:bg-slate-50"
                                            aria-expanded={expanded}
                                            onClick={() =>
                                                setExpandedEvent(
                                                    expanded
                                                        ? null
                                                        : event.publicId,
                                                )
                                            }
                                        >
                                            <span className="flex min-w-0 items-center gap-3">
                                                <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-800">
                                                    {event.sequenceNumber}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block font-semibold">
                                                        {event.label}
                                                    </span>
                                                    <span className="block truncate font-mono text-xs text-muted-foreground">
                                                        {event.method} ·{' '}
                                                        {formatDateTime(
                                                            event.recordedAt,
                                                        )}
                                                    </span>
                                                </span>
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <Badge className="bg-emerald-700 text-white">
                                                    {event.responseCode}
                                                </Badge>
                                                {expanded ? (
                                                    <ChevronUp
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                ) : (
                                                    <ChevronDown
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                            </span>
                                        </button>

                                        {expanded && (
                                            <div className="border-t bg-slate-950 p-4 text-slate-100">
                                                <div className="grid gap-4 xl:grid-cols-2">
                                                    <div>
                                                        <p className="mb-2 flex items-center gap-2 text-xs font-bold tracking-wider text-sky-300 uppercase">
                                                            <FileJson2
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                            Request ·{' '}
                                                            {shortHash(
                                                                event.requestHash,
                                                            )}
                                                        </p>
                                                        <pre className="max-h-96 overflow-auto rounded bg-black/30 p-3 text-xs leading-5">
                                                            {JSON.stringify(
                                                                event.request,
                                                                null,
                                                                2,
                                                            )}
                                                        </pre>
                                                    </div>
                                                    <div>
                                                        <p className="mb-2 flex items-center gap-2 text-xs font-bold tracking-wider text-emerald-300 uppercase">
                                                            <Send
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                            Response ·{' '}
                                                            {shortHash(
                                                                event.responseHash,
                                                            )}
                                                        </p>
                                                        <pre className="max-h-96 overflow-auto rounded bg-black/30 p-3 text-xs leading-5">
                                                            {JSON.stringify(
                                                                event.response,
                                                                null,
                                                                2,
                                                            )}
                                                        </pre>
                                                    </div>
                                                </div>
                                                <p className="mt-3 flex items-center gap-2 text-xs text-orange-200">
                                                    <Ban
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    Transport{' '}
                                                    {event.transportState};
                                                    dicatat oleh {event.actor}.
                                                </p>
                                            </div>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                    )}
                </section>

                <footer className="flex flex-wrap justify-between gap-3 border-t pt-5 text-sm text-muted-foreground">
                    <p>
                        Profil: <code>{boundary.compatibilityProfile}</code>
                    </p>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={urls.timeline}>
                            <Route className="size-4" aria-hidden="true" />
                            Lihat sumber rekam
                        </Link>
                    </Button>
                </footer>
            </div>
        </>
    );
}
