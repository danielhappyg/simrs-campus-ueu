import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    BookOpenCheck,
    Clock3,
    FileClock,
    Filter,
    GitBranch,
    History,
    Network,
    ReceiptText,
    ShieldCheck,
    UserRoundCheck,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { EncounterContext, PatientContext } from '@/types';

type TimelineTag = {
    code: string;
    label: string;
};

type TimelineEvent = {
    publicId: string;
    sequence: number;
    category: { code: string; label: string };
    title: string;
    detail: string | null;
    actor: {
        name: string;
        program: string;
        role: string;
        assignmentPublicId: string | null;
    };
    source: {
        label: string;
        publicId: string | null;
        version: string | null;
    };
    recordedAt: string;
    clinicalOccurrenceAt: string | null;
    primaryAt: string;
    showsRecordedTimeDifference: boolean;
    outcome: string;
    tags: TimelineTag[];
};

type CountSummary = {
    label: string;
    count: number;
};

type Props = {
    encounter: EncounterContext;
    patient: PatientContext;
    assignment: {
        publicId: string;
        program: string;
        role: string;
    };
    session: {
        publicId: string;
        code: string;
        status: { code: string; label: string };
        scenarioTitle: string;
    };
    release: {
        readOnly: boolean;
        sessionCompleted: boolean;
        encounterStatus: string;
    };
    events: TimelineEvent[];
    summary: {
        displayedEventCount: number;
        totalAvailableEventCount: number;
        truncated: boolean;
        categoryCounts: CountSummary[];
        programCounts: CountSummary[];
        correctionCount: number;
        supervisionCount: number;
        handoffCount: number;
    };
    urls: {
        encounter: string;
        self: string;
        debrief: string | null;
        interoperabilityPreview?: string | null;
        eClaimSimulation?: string | null;
        workQueue: string;
    };
};

const categoryStyles: Record<string, string> = {
    REGISTRATION: 'border-violet-200 bg-violet-50 text-violet-900',
    WORKFLOW: 'border-slate-200 bg-slate-50 text-slate-800',
    NURSING: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    MEDICINE: 'border-sky-200 bg-sky-50 text-sky-900',
    RESULTS: 'border-cyan-200 bg-cyan-50 text-cyan-900',
    PHARMACY: 'border-amber-200 bg-amber-50 text-amber-950',
    CLOSURE: 'border-orange-200 bg-orange-50 text-orange-950',
    RMIK: 'border-indigo-200 bg-indigo-50 text-indigo-950',
    CODING: 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-950',
};

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

function initialFilter(name: string): string {
    if (typeof window === 'undefined') {
        return '';
    }

    return new URLSearchParams(window.location.search).get(name) ?? '';
}

export default function EncounterRecordTimeline({
    encounter,
    patient,
    assignment,
    session,
    release,
    events,
    summary,
    urls,
}: Props) {
    const [category, setCategory] = useState(() => initialFilter('stage'));
    const [program, setProgram] = useState(() => initialFilter('program'));
    const visibleEvents = useMemo(
        () =>
            events.filter((event) => {
                const categoryMatches =
                    category === '' || event.category.code === category;
                const programMatches =
                    program === '' || event.actor.program === program;

                return categoryMatches && programMatches;
            }),
        [category, events, program],
    );
    const categories = Array.from(
        new Map(
            events.map((event) => [event.category.code, event.category]),
        ).values(),
    );
    const programs = Array.from(
        new Set(events.map((event) => event.actor.program)),
    );

    useEffect(() => {
        const query = new URLSearchParams();

        if (category) {
            query.set('stage', category);
        }

        if (program) {
            query.set('program', program);
        }

        const suffix = query.size > 0 ? `?${query.toString()}` : '';
        window.history.replaceState({}, '', `${urls.self}${suffix}`);
    }, [category, program, urls.self]);

    return (
        <>
            <Head title={`Linimasa Rekam ${encounter.number}`} />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Rekam bersama · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Linimasa Rekam Rawat Jalan
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Urutan deterministik sumber registrasi, klinis,
                            farmasi, penutupan, mutu rekam, dan keputusan koding
                            manusia untuk satu encounter sintetis.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {urls.interoperabilityPreview && (
                            <Button asChild variant="outline">
                                <Link href={urls.interoperabilityPreview}>
                                    <Network
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Pratinjau FHIR
                                </Link>
                            </Button>
                        )}
                        {urls.eClaimSimulation && (
                            <Button asChild variant="outline">
                                <Link href={urls.eClaimSimulation}>
                                    <ReceiptText
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Simulasi E-Klaim
                                </Link>
                            </Button>
                        )}
                        {urls.debrief && (
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
                            <Link href={urls.encounter}>
                                <History
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Ringkasan encounter
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={urls.workQueue}>
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Antrean kerja
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
                    aria-labelledby="record-boundary-title"
                    className="rounded-lg border border-sky-200 bg-[#eaf4f8] p-5"
                >
                    <div className="flex items-start gap-3">
                        <ShieldCheck
                            className="mt-0.5 size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <div>
                            <h2
                                id="record-boundary-title"
                                className="font-semibold text-[#174c68]"
                            >
                                Indeks provenance sumber — hanya baca
                            </h2>
                            <p className="mt-1 max-w-5xl text-sm leading-6 text-[#174c68]">
                                Setiap kartu menunjuk ke peristiwa dan versi
                                sumber yang tercatat. Linimasa ini tidak
                                menggantikan dokumen sumber, bukan rekam legal,
                                tidak membuat keputusan klinis, dan tidak
                                mengirim data ke layanan eksternal.
                            </p>
                            {release.sessionCompleted && (
                                <p className="mt-2 text-sm font-semibold text-[#174c68]">
                                    Sesi telah selesai; rekam tetap dapat dibaca
                                    tanpa membuka kembali perubahan biasa.
                                </p>
                            )}
                        </div>
                    </div>
                </section>

                <section aria-label="Ringkasan linimasa rekam">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {[
                            {
                                label: 'Peristiwa material',
                                value: summary.displayedEventCount,
                                icon: FileClock,
                            },
                            {
                                label: 'Koreksi / versi penerus',
                                value: summary.correctionCount,
                                icon: GitBranch,
                            },
                            {
                                label: 'Keputusan supervisi',
                                value: summary.supervisionCount,
                                icon: UserRoundCheck,
                            },
                            {
                                label: 'Handoff',
                                value: summary.handoffCount,
                                icon: Clock3,
                            },
                        ].map(({ label, value, icon: Icon }) => (
                            <div
                                key={label}
                                className="clinical-shadow rounded-lg border border-border bg-white p-4"
                            >
                                <div className="flex items-center gap-2 text-primary">
                                    <Icon
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    <p className="text-xs font-bold tracking-wider uppercase">
                                        {label}
                                    </p>
                                </div>
                                <p className="mt-3 text-2xl font-semibold">
                                    {value}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                {summary.truncated && (
                    <div
                        role="status"
                        className="rounded-lg border border-amber-300 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-950"
                    >
                        <strong>Proyeksi dibatasi.</strong> Menampilkan{' '}
                        {summary.displayedEventCount} dari{' '}
                        {summary.totalAvailableEventCount} peristiwa material
                        terbaru; urutan sumber yang ditampilkan tetap
                        deterministik.
                    </div>
                )}

                <section
                    aria-labelledby="record-filters-title"
                    className="clinical-shadow rounded-lg border border-border bg-white p-5"
                >
                    <div className="flex items-start gap-3">
                        <Filter
                            className="mt-0.5 size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <div className="min-w-0 flex-1">
                            <h2
                                id="record-filters-title"
                                className="text-xl font-semibold"
                            >
                                Saring linimasa rekam
                            </h2>
                            <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                Filter hanya mengubah tampilan; tidak mengubah
                                urutan, sumber, atau isi rekam.
                            </p>
                            <div className="mt-4 flex flex-wrap gap-4">
                                <label className="grid min-w-56 gap-1 text-sm font-semibold">
                                    Tahap layanan
                                    <select
                                        className="h-11 rounded-md border border-input bg-white px-3 text-sm font-normal"
                                        value={category}
                                        onChange={(event) =>
                                            setCategory(event.target.value)
                                        }
                                    >
                                        <option value="">Semua tahap</option>
                                        {categories.map((item) => (
                                            <option
                                                key={item.code}
                                                value={item.code}
                                            >
                                                {item.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid min-w-56 gap-1 text-sm font-semibold">
                                    Program aktor
                                    <select
                                        className="h-11 rounded-md border border-input bg-white px-3 text-sm font-normal"
                                        value={program}
                                        onChange={(event) =>
                                            setProgram(event.target.value)
                                        }
                                    >
                                        <option value="">Semua program</option>
                                        {programs.map((item) => (
                                            <option key={item} value={item}>
                                                {item}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <p
                                className="mt-4 text-sm font-semibold text-primary"
                                aria-live="polite"
                            >
                                {visibleEvents.length} dari {events.length}{' '}
                                peristiwa
                            </p>
                        </div>
                    </div>
                </section>

                <section
                    aria-labelledby="record-events-title"
                    className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                >
                    <div className="border-b border-border px-5 py-4">
                        <p className="text-xs font-bold tracking-wider text-primary uppercase">
                            Sumber kronologis
                        </p>
                        <h2
                            id="record-events-title"
                            className="mt-1 text-xl font-semibold"
                        >
                            Peristiwa lintas profesi
                        </h2>
                    </div>

                    {visibleEvents.length === 0 ? (
                        <p className="px-5 py-8 text-sm leading-6 text-muted-foreground">
                            {events.length === 0
                                ? 'Belum ada peristiwa material yang dapat diproyeksikan.'
                                : 'Tidak ada peristiwa yang cocok dengan filter.'}
                        </p>
                    ) : (
                        <ol className="divide-y divide-border">
                            {visibleEvents.map((event) => (
                                <li
                                    key={event.publicId}
                                    className="grid gap-4 px-5 py-5 md:grid-cols-[3rem_minmax(0,1fr)_12rem]"
                                >
                                    <div className="flex size-10 items-center justify-center rounded-full border border-sky-200 bg-sky-50 font-mono text-sm font-semibold text-primary">
                                        {event.sequence}
                                    </div>
                                    <div className="min-w-0">
                                        <Badge
                                            variant="outline"
                                            className={cn(
                                                categoryStyles[
                                                    event.category.code
                                                ] ?? categoryStyles.WORKFLOW,
                                            )}
                                        >
                                            {event.category.label}
                                        </Badge>
                                        <h3 className="mt-3 text-lg font-semibold">
                                            {event.title}
                                        </h3>
                                        {event.detail && (
                                            <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                                {event.detail}
                                            </p>
                                        )}
                                        <p className="mt-3 text-sm font-semibold">
                                            {event.actor.name} ·{' '}
                                            {event.actor.program} /{' '}
                                            {event.actor.role}
                                        </p>
                                        <p className="mt-1 font-mono text-xs [overflow-wrap:anywhere] text-muted-foreground">
                                            <span>
                                                {event.source.label}
                                                {event.source.version
                                                    ? ` · ${event.source.version}`
                                                    : ''}
                                            </span>
                                            {event.source.publicId && (
                                                <span>{` · …${event.source.publicId.slice(-8)}`}</span>
                                            )}
                                        </p>
                                        {event.tags.length > 0 && (
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {event.tags.map((tag) => (
                                                    <Badge
                                                        key={tag.code}
                                                        variant="outline"
                                                    >
                                                        {tag.label}
                                                    </Badge>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                    <div className="text-sm text-muted-foreground md:text-right">
                                        <time dateTime={event.primaryAt}>
                                            {formatDateTime(event.primaryAt)}{' '}
                                            WIB
                                        </time>
                                        {event.showsRecordedTimeDifference && (
                                            <p className="mt-2 text-xs leading-5">
                                                Dicatat{' '}
                                                <time
                                                    dateTime={event.recordedAt}
                                                >
                                                    {formatDateTime(
                                                        event.recordedAt,
                                                    )}{' '}
                                                    WIB
                                                </time>
                                            </p>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>
            </div>
        </>
    );
}
