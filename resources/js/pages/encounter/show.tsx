import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    BookOpenCheck,
    CheckCircle2,
    Circle,
    Clock3,
    FileText,
    History,
    ListChecks,
    MapPin,
    Network,
    ShieldCheck,
} from 'lucide-react';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { presentRecordedWorkflow } from '@/lib/encounter-workflow';
import { cn } from '@/lib/utils';
import type { EncounterContext, PatientContext } from '@/types';

type Props = {
    encounter: EncounterContext;
    patient: PatientContext;
    assignment: {
        publicId: string;
        program: string;
        role: string;
        canViewDebrief: boolean;
        canViewReports: boolean;
    };
    session: {
        publicId: string;
        code: string;
        scenarioTitle: string;
    };
    queue: Array<{
        publicId: string;
        ticketNumber: string;
        status: { code: string; label: string };
        startedAt: string;
    }>;
    timeline: Array<{
        publicId: string;
        fromStatus: string | null;
        fromStatusCode: string | null;
        toStatus: string;
        toStatusCode: string;
        reason: string | null;
        occurredAt: string;
        actor: string;
        role: string;
    }>;
    workflow: Array<{
        code: string;
        label: string;
        current: boolean;
    }>;
    urls: {
        debrief: string;
        timeline: string | null;
        outpatientSummaryReport: string;
        debriefEvidenceReport: string;
        interoperabilityPreview?: string | null;
    };
};

const visibleWorkflowStates = new Set([
    'PLANNED',
    'ARRIVED',
    'IN_INTAKE',
    'WAITING_CLINICIAN',
    'IN_CONSULTATION',
    'AWAITING_PHARMACY',
    'CLOSURE_PENDING',
    'CLINICALLY_CLOSED',
    'RECORD_REVIEW',
    'FINALIZED',
]);

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

export default function EncounterOverview({
    encounter,
    patient,
    assignment,
    session,
    queue,
    timeline,
    workflow,
    urls,
}: Props) {
    const workflowRail = presentRecordedWorkflow(
        workflow.filter((state) => visibleWorkflowStates.has(state.code)),
        timeline,
    );
    const currentStatusIsOnRail = visibleWorkflowStates.has(
        encounter.status.code,
    );

    return (
        <>
            <Head title={`Encounter ${encounter.number}`} />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Rekam bersama · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Ringkasan Encounter Rawat Jalan
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Satu identitas dan encounter menjadi sumber konteks
                            untuk seluruh handoff registrasi, keperawatan,
                            kedokteran, farmasi, dan RMIK.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {urls.timeline && (
                            <Button asChild variant="outline">
                                <Link href={urls.timeline}>
                                    <History
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Buka linimasa rekam
                                </Link>
                            </Button>
                        )}
                        {assignment.canViewReports && (
                            <>
                                {urls.interoperabilityPreview && (
                                    <Button asChild variant="outline">
                                        <Link
                                            href={urls.interoperabilityPreview}
                                        >
                                            <Network
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            Pratinjau FHIR
                                        </Link>
                                    </Button>
                                )}
                                <Button asChild variant="outline">
                                    <a href={urls.outpatientSummaryReport}>
                                        <FileText
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Ringkasan cetak
                                    </a>
                                </Button>
                            </>
                        )}
                        {assignment.canViewDebrief && (
                            <Button asChild>
                                <Link href={urls.debrief}>
                                    <BookOpenCheck
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Buka debrief
                                </Link>
                            </Button>
                        )}
                        <Button asChild variant="outline">
                            <Link href="/work">
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Kembali ke antrean kerja
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
                    aria-labelledby="workflow-title"
                    className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                        <div>
                            <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                Encounter orbit
                            </p>
                            <h2
                                id="workflow-title"
                                className="mt-1 text-xl font-semibold"
                            >
                                Perjalanan layanan
                            </h2>
                        </div>
                        <Badge variant="outline">
                            Status server: {encounter.status.code}
                        </Badge>
                    </div>

                    {!currentStatusIsOnRail && (
                        <div
                            role="status"
                            className="flex gap-3 border-b border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-950"
                        >
                            <AlertTriangle
                                className="mt-0.5 size-5 shrink-0 text-amber-700"
                                aria-hidden="true"
                            />
                            <p>
                                Cabang workflow aktif:{' '}
                                <strong>{encounter.status.label}</strong>. Rail
                                utama hanya menandai tahap yang benar-benar
                                tercatat dalam linimasa; tahap sesudahnya tidak
                                dianggap selesai otomatis.
                            </p>
                        </div>
                    )}

                    <ol className="grid gap-px bg-border sm:grid-cols-2 lg:grid-cols-5">
                        {workflowRail.map((state) => {
                            return (
                                <li
                                    key={state.code}
                                    aria-current={
                                        state.current ? 'step' : undefined
                                    }
                                    className={cn(
                                        'min-h-24 bg-white px-4 py-4',
                                        state.current &&
                                            'bg-[#eaf4f8] ring-2 ring-primary ring-inset',
                                    )}
                                >
                                    <div className="flex items-center gap-2">
                                        {state.completed ? (
                                            <CheckCircle2
                                                className="size-4 text-success"
                                                aria-hidden="true"
                                            />
                                        ) : state.current ? (
                                            <Clock3
                                                className="size-4 text-primary"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <Circle
                                                className="size-4 text-slate-300"
                                                aria-hidden="true"
                                            />
                                        )}
                                        <span className="font-mono text-[0.65rem] text-muted-foreground">
                                            {state.code}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-sm font-semibold">
                                        {state.label}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {state.current
                                            ? 'Tahap aktif'
                                            : state.completed
                                              ? 'Telah dilewati'
                                              : 'Belum aktif'}
                                    </p>
                                </li>
                            );
                        })}
                    </ol>
                </section>

                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_23rem]">
                    <section className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white">
                        <div className="border-b border-border px-5 py-4">
                            <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                Provenance
                            </p>
                            <h2 className="mt-1 text-xl font-semibold">
                                Linimasa status
                            </h2>
                        </div>

                        <ol className="divide-y divide-border">
                            {timeline.map((event, index) => (
                                <li
                                    key={event.publicId}
                                    className="grid gap-3 px-5 py-5 sm:grid-cols-[2rem_1fr_auto]"
                                >
                                    <div className="flex size-8 items-center justify-center rounded-full border border-sky-200 bg-sky-50 font-mono text-xs font-semibold text-primary">
                                        {index + 1}
                                    </div>
                                    <div className="min-w-0">
                                        <p className="font-semibold">
                                            {event.fromStatus
                                                ? `${event.fromStatus} → `
                                                : ''}
                                            {event.toStatus}
                                        </p>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {event.actor} · {event.role}
                                        </p>
                                        {event.reason && (
                                            <p className="mt-2 rounded border border-border bg-muted/40 px-3 py-2 font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground">
                                                {event.reason}
                                            </p>
                                        )}
                                    </div>
                                    <time
                                        className="text-xs text-muted-foreground"
                                        dateTime={event.occurredAt}
                                    >
                                        {formatDateTime(event.occurredAt)} WIB
                                    </time>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <aside className="space-y-5">
                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <MapPin
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Antrean klinik
                                </h2>
                            </div>
                            {queue.length === 0 ? (
                                <p className="mt-4 text-sm leading-6 text-muted-foreground">
                                    Encounter masih terencana dan belum masuk
                                    antrean.
                                </p>
                            ) : (
                                <div className="mt-4 space-y-3">
                                    {queue.map((entry) => (
                                        <div
                                            key={entry.publicId}
                                            className="rounded-md border border-border p-4"
                                        >
                                            <div className="flex items-center justify-between gap-3">
                                                <span className="font-mono text-lg font-semibold text-primary">
                                                    {entry.ticketNumber}
                                                </span>
                                                <Badge variant="outline">
                                                    {entry.status.label}
                                                </Badge>
                                            </div>
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                Masuk{' '}
                                                {formatDateTime(
                                                    entry.startedAt,
                                                )}{' '}
                                                WIB
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>

                        <section className="rounded-lg border border-sky-200 bg-[#eaf4f8] p-5">
                            <div className="flex items-center gap-2 text-primary">
                                <ShieldCheck
                                    className="size-5"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Batas tahap ini
                                </h2>
                            </div>
                            <ul className="mt-3 space-y-2 text-sm leading-6 text-[#174c68]">
                                <li className="flex gap-2">
                                    <ListChecks
                                        className="mt-1 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Status berasal dari transaksi server, bukan
                                    animasi layar.
                                </li>
                                <li className="flex gap-2">
                                    <ListChecks
                                        className="mt-1 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Tugas profesi berikutnya dilepas oleh
                                    handoff yang sah.
                                </li>
                                <li className="flex gap-2">
                                    <ListChecks
                                        className="mt-1 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Belum ada keputusan diagnosis atau terapi
                                    otomatis.
                                </li>
                            </ul>
                        </section>
                    </aside>
                </div>
            </div>
        </>
    );
}

EncounterOverview.layout = {
    breadcrumbs: [
        { title: 'Antrean kerja', href: '/work' },
        { title: 'Encounter rawat jalan', href: '#' },
    ],
};
