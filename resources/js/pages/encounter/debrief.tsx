import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    BookOpenCheck,
    CheckCircle2,
    Clock3,
    FileClock,
    FileText,
    Filter,
    History,
    LockKeyhole,
    MessageSquareText,
    PencilLine,
    Route,
    ShieldCheck,
    Sparkles,
    UserRoundCheck,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
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

type DebriefNoteVersion = {
    publicId: string;
    versionNumber: number;
    body: string;
    changeReason: string | null;
    authoredAt: string;
    author: {
        name: string;
        program: string;
        role: string;
        assignmentPublicId: string;
    };
};

type DebriefNote = {
    publicId: string;
    type: { code: string; label: string };
    createdAt: string | null;
    versions: DebriefNoteVersion[];
    revisionUrl: string;
    revisionRequestKey: string;
};

type RubricReference = {
    code: string;
    title: string;
    version: string;
    status: { code: string; label: string };
    sourceLabel: string;
    learningOutcomes: Array<{ number: number; label: string }>;
    nonScoring: boolean;
};

type Props = {
    encounter: EncounterContext;
    patient: PatientContext;
    assignment: {
        publicId: string;
        program: string;
        role: string;
        canViewReports: boolean;
    };
    session: {
        publicId: string;
        code: string;
        status: { code: string; label: string };
        scenarioTitle: string;
        learningOutcomes: string[];
    };
    release: {
        gate: string;
        label: string;
        finalizedAt: string | null;
        ordinaryEditsLocked: boolean;
    };
    teachingEvidence: {
        notes: DebriefNote[];
        rubricReferences: RubricReference[];
        authoring: {
            canAuthorNotes: boolean;
            storeUrl: string;
            requestKey: string;
            bodyMaxCharacters: number;
            noteTypes: Array<{ code: string; label: string }>;
        };
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
        timeline: string;
        self: string;
        workQueue: string;
        outpatientSummaryReport: string;
        debriefEvidenceReport: string;
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

export default function EncounterDebrief({
    encounter,
    patient,
    assignment,
    session,
    release,
    teachingEvidence,
    events,
    summary,
    urls,
}: Props) {
    const [category, setCategory] = useState(() => initialFilter('stage'));
    const [program, setProgram] = useState(() => initialFilter('program'));
    const [evidence, setEvidence] = useState(() => initialFilter('evidence'));
    const visibleEvents = useMemo(
        () =>
            events.filter((event) => {
                const categoryMatches =
                    category === '' || event.category.code === category;
                const programMatches =
                    program === '' || event.actor.program === program;
                const evidenceMatches =
                    evidence === '' ||
                    event.tags.some((tag) => tag.code === evidence);

                return categoryMatches && programMatches && evidenceMatches;
            }),
        [category, evidence, events, program],
    );

    useEffect(() => {
        const query = new URLSearchParams();

        if (category) {
            query.set('stage', category);
        }

        if (program) {
            query.set('program', program);
        }

        if (evidence) {
            query.set('evidence', evidence);
        }

        const suffix = query.size > 0 ? `?${query.toString()}` : '';
        window.history.replaceState({}, '', `${urls.self}${suffix}`);
    }, [category, evidence, program, urls.self]);

    const categories = Array.from(
        new Map(
            events.map((event) => [event.category.code, event.category]),
        ).values(),
    );
    const programs = Array.from(
        new Set(events.map((event) => event.actor.program)),
    );

    return (
        <>
            <Head title={`Debrief ${encounter.number}`} />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Pusat pembelajaran · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Linimasa Rekam & Debrief
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Rekonstruksi sumber lintas profesi dari waktu
                            klinis, versi, handoff, koreksi, supervisi, dan
                            keputusan koding manusia.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {assignment.canViewReports && (
                            <>
                                <Button asChild>
                                    <a href={urls.outpatientSummaryReport}>
                                        <FileText
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Ringkasan cetak
                                    </a>
                                </Button>
                                <Button asChild variant="outline">
                                    <a href={urls.debriefEvidenceReport}>
                                        <BookOpenCheck
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Laporan debrief
                                    </a>
                                </Button>
                            </>
                        )}
                        <Button asChild variant="outline">
                            <Link href={urls.timeline}>
                                <FileClock
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Linimasa rekam
                            </Link>
                        </Button>
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
                    aria-labelledby="release-title"
                    className="clinical-shadow overflow-hidden rounded-lg border border-emerald-200 bg-white"
                >
                    <div className="grid gap-px bg-emerald-200 lg:grid-cols-[minmax(0,1fr)_repeat(3,minmax(11rem,0.35fr))]">
                        <div className="bg-emerald-50 px-5 py-5">
                            <div className="flex items-start gap-3">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-white">
                                    <BookOpenCheck
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div>
                                    <p className="text-xs font-bold tracking-wider text-emerald-800 uppercase">
                                        Release debrief
                                    </p>
                                    <h2
                                        id="release-title"
                                        className="mt-1 text-xl font-semibold text-emerald-950"
                                    >
                                        {release.label}
                                    </h2>
                                    <p className="mt-1 text-sm leading-6 text-emerald-900">
                                        Ini adalah bukti pembelajaran, bukan
                                        rekam medis legal atau persetujuan
                                        klinis dunia nyata.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <SummaryCell
                            icon={History}
                            label="Peristiwa sumber"
                            value={summary.displayedEventCount.toLocaleString(
                                'id-ID',
                            )}
                        />
                        <SummaryCell
                            icon={Route}
                            label="Handoff"
                            value={summary.handoffCount.toLocaleString('id-ID')}
                        />
                        <SummaryCell
                            icon={UserRoundCheck}
                            label="Keputusan supervisor"
                            value={summary.supervisionCount.toLocaleString(
                                'id-ID',
                            )}
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-emerald-200 px-5 py-3 text-xs text-emerald-950">
                        <span className="flex items-center gap-1.5 font-semibold">
                            <LockKeyhole
                                className="size-4"
                                aria-hidden="true"
                            />
                            Perubahan biasa terkunci
                        </span>
                        <span>
                            Gate server: <strong>{release.gate}</strong>
                        </span>
                        {release.finalizedAt && (
                            <span>
                                Finalisasi:{' '}
                                <time dateTime={release.finalizedAt}>
                                    {formatDateTime(release.finalizedAt)} WIB
                                </time>
                            </span>
                        )}
                    </div>
                </section>

                {summary.truncated && (
                    <div
                        role="alert"
                        className="rounded-lg border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-950"
                    >
                        Menampilkan {summary.displayedEventCount} peristiwa
                        terbaru dari {summary.totalAvailableEventCount}. Filter
                        tidak mengubah urutan sumber; fasilitator perlu membagi
                        kasus sebelum ekspor lengkap diaktifkan.
                    </div>
                )}

                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-5">
                        <SharedDebriefNotes evidence={teachingEvidence} />

                        <section
                            aria-labelledby="filters-title"
                            className="clinical-shadow rounded-lg border border-border bg-white p-5"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex items-center gap-2 text-primary">
                                        <Filter
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                        <h2
                                            id="filters-title"
                                            className="text-xl font-semibold"
                                        >
                                            Saring bukti pembelajaran
                                        </h2>
                                    </div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Filter hanya mengubah tampilan. Riwayat
                                        sumber tidak diubah atau diurutkan
                                        ulang.
                                    </p>
                                </div>
                                <Badge variant="outline" aria-live="polite">
                                    {visibleEvents.length} dari {events.length}{' '}
                                    peristiwa
                                </Badge>
                            </div>

                            <div className="mt-5 grid gap-4 md:grid-cols-3">
                                <label className="grid gap-1.5 text-sm font-semibold">
                                    Tahap layanan
                                    <select
                                        value={category}
                                        onChange={(event) =>
                                            setCategory(event.target.value)
                                        }
                                        className="h-10 rounded-md border border-input bg-white px-3 text-sm font-normal"
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
                                <label className="grid gap-1.5 text-sm font-semibold">
                                    Program/peran sumber
                                    <select
                                        value={program}
                                        onChange={(event) =>
                                            setProgram(event.target.value)
                                        }
                                        className="h-10 rounded-md border border-input bg-white px-3 text-sm font-normal"
                                    >
                                        <option value="">Semua program</option>
                                        {programs.map((item) => (
                                            <option key={item} value={item}>
                                                {item}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1.5 text-sm font-semibold">
                                    Jenis bukti
                                    <select
                                        value={evidence}
                                        onChange={(event) =>
                                            setEvidence(event.target.value)
                                        }
                                        className="h-10 rounded-md border border-input bg-white px-3 text-sm font-normal"
                                    >
                                        <option value="">Semua bukti</option>
                                        <option value="HANDOFF">Handoff</option>
                                        <option value="SUPERVISION">
                                            Keputusan supervisor
                                        </option>
                                        <option value="CORRECTION">
                                            Koreksi/versi penerus
                                        </option>
                                        <option value="HUMAN_CODING">
                                            Keputusan koding manusia
                                        </option>
                                    </select>
                                </label>
                            </div>
                        </section>

                        <section
                            aria-labelledby="timeline-title"
                            className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                        >
                            <div className="border-b border-border px-5 py-4">
                                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                    Provenance terkurasi
                                </p>
                                <h2
                                    id="timeline-title"
                                    className="mt-1 text-xl font-semibold"
                                >
                                    Perjalanan lintas profesi
                                </h2>
                            </div>

                            {visibleEvents.length === 0 ? (
                                <div className="px-5 py-12 text-center">
                                    <History
                                        className="mx-auto size-8 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <p className="mt-3 font-semibold">
                                        Tidak ada peristiwa untuk filter ini
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Ubah satu atau lebih filter; sumber asli
                                        tetap tersimpan.
                                    </p>
                                </div>
                            ) : (
                                <ol className="divide-y divide-border">
                                    {visibleEvents.map((event) => (
                                        <TimelineCard
                                            key={event.publicId}
                                            event={event}
                                        />
                                    ))}
                                </ol>
                            )}
                        </section>
                    </div>

                    <aside className="space-y-5">
                        <RubricReferences
                            references={teachingEvidence.rubricReferences}
                        />

                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2 text-primary">
                                <Sparkles
                                    className="size-5"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Tujuan pembelajaran
                                </h2>
                            </div>
                            <ol className="mt-4 space-y-3">
                                {session.learningOutcomes.map(
                                    (outcome, index) => (
                                        <li
                                            key={outcome}
                                            className="flex gap-3 text-sm leading-6"
                                        >
                                            <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">
                                                {index + 1}
                                            </span>
                                            <span>{outcome}</span>
                                        </li>
                                    ),
                                )}
                            </ol>
                        </section>

                        <section className="rounded-lg border border-sky-200 bg-[#eaf4f8] p-5">
                            <div className="flex items-center gap-2 text-primary">
                                <MessageSquareText
                                    className="size-5"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Pertanyaan debrief
                                </h2>
                            </div>
                            <ul className="mt-4 space-y-3 text-sm leading-6 text-[#174c68]">
                                <li>
                                    Di handoff mana konteks pasien, encounter,
                                    dan sumber paling jelas dipertahankan?
                                </li>
                                <li>
                                    Versi atau koreksi mana yang mengubah
                                    keputusan tahap berikutnya?
                                </li>
                                <li>
                                    Bukti apa yang membedakan kandidat koding
                                    dari kode yang diputuskan manusia?
                                </li>
                            </ul>
                        </section>

                        <section className="rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <ShieldCheck
                                    className="size-5 text-success"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Batas data
                                </h2>
                            </div>
                            <ul className="mt-3 space-y-2 text-sm leading-6 text-muted-foreground">
                                <li>
                                    Metadata keamanan, alamat jaringan, dan
                                    identitas permintaan tidak ditampilkan.
                                </li>
                                <li>
                                    Alasan klinis lengkap tetap dibaca dari
                                    sumber berizin, bukan disalin ke audit.
                                </li>
                                <li>
                                    Ekspor debrief belum diaktifkan pada
                                    reference MVP ini.
                                </li>
                            </ul>
                        </section>
                    </aside>
                </div>
            </div>
        </>
    );
}

function SharedDebriefNotes({
    evidence,
}: {
    evidence: Props['teachingEvidence'];
}) {
    const form = useForm({
        request_key: evidence.authoring.requestKey,
        note_type:
            evidence.authoring.noteTypes[0]?.code ?? 'FACILITATOR_SYNTHESIS',
        body: '',
        simulation_attestation: false,
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(evidence.authoring.storeUrl, {
            preserveScroll: true,
            onSuccess: (page) => {
                const nextEvidence = page.props.teachingEvidence as
                    Partial<Props['teachingEvidence']> | undefined;
                const nextRequestKey =
                    nextEvidence?.authoring?.requestKey ??
                    evidence.authoring.requestKey;

                form.setData({
                    request_key: nextRequestKey,
                    note_type:
                        evidence.authoring.noteTypes[0]?.code ??
                        'FACILITATOR_SYNTHESIS',
                    body: '',
                    simulation_attestation: false,
                });
                form.clearErrors();
            },
        });
    }

    return (
        <section
            aria-labelledby="shared-notes-title"
            className="clinical-shadow rounded-lg border border-border bg-white p-5"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2 text-primary">
                        <FileText className="size-5" aria-hidden="true" />
                        <h2
                            id="shared-notes-title"
                            className="text-xl font-semibold"
                        >
                            Catatan debrief bersama
                        </h2>
                    </div>
                    <p className="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">
                        Catatan pembelajaran yang dibaca seluruh peserta
                        berizin. Catatan ini terpisah dari rekam klinis, bukan
                        nilai, dan setiap revisi mempertahankan versi lama.
                    </p>
                </div>
                <Badge variant="outline">{evidence.notes.length} catatan</Badge>
            </div>

            {evidence.notes.length === 0 ? (
                <div className="mt-5 rounded-md border border-dashed border-border bg-muted/30 px-4 py-6 text-center">
                    <p className="font-semibold">Belum ada catatan bersama</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Linimasa sumber tetap tersedia; fasilitator dapat
                        menambahkan sintesis setelah diskusi debrief.
                    </p>
                </div>
            ) : (
                <div className="mt-5 space-y-4">
                    {evidence.notes.map((note) => (
                        <DebriefNoteCard
                            key={note.publicId}
                            note={note}
                            canRevise={evidence.authoring.canAuthorNotes}
                            bodyMaxCharacters={
                                evidence.authoring.bodyMaxCharacters
                            }
                        />
                    ))}
                </div>
            )}

            {evidence.authoring.canAuthorNotes && (
                <form
                    onSubmit={submit}
                    className="mt-5 rounded-lg border border-sky-200 bg-[#eaf4f8] p-4"
                >
                    <div className="flex items-center gap-2 text-primary">
                        <PencilLine className="size-5" aria-hidden="true" />
                        <h3 className="text-lg font-semibold">
                            Tambah catatan bersama
                        </h3>
                    </div>

                    <div className="mt-4 grid gap-4">
                        <div>
                            <Label htmlFor="debrief-note-type">
                                Jenis catatan
                            </Label>
                            <select
                                id="debrief-note-type"
                                value={form.data.note_type}
                                onChange={(event) =>
                                    form.setData(
                                        'note_type',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 h-11 w-full rounded-md border border-input bg-white px-3 text-sm"
                            >
                                {evidence.authoring.noteTypes.map((type) => (
                                    <option key={type.code} value={type.code}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                id="debrief-note-type-error"
                                className="mt-1"
                                message={errors.note_type}
                            />
                        </div>

                        <div>
                            <div className="flex items-center justify-between gap-3">
                                <Label htmlFor="debrief-note-body">
                                    Isi catatan pembelajaran
                                </Label>
                                <span className="text-xs text-muted-foreground">
                                    {form.data.body.length}/
                                    {evidence.authoring.bodyMaxCharacters}
                                </span>
                            </div>
                            <textarea
                                id="debrief-note-body"
                                value={form.data.body}
                                onChange={(event) =>
                                    form.setData('body', event.target.value)
                                }
                                maxLength={evidence.authoring.bodyMaxCharacters}
                                aria-invalid={Boolean(errors.body)}
                                aria-describedby={
                                    errors.body
                                        ? 'debrief-note-body-error'
                                        : undefined
                                }
                                className="mt-1 min-h-32 w-full rounded-md border border-input bg-white px-3 py-2 text-sm leading-6 shadow-xs focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                placeholder="Ringkas pola handoff, alasan koreksi, dan tindak lanjut pembelajaran tanpa menyalin data keamanan atau membuat nilai."
                            />
                            <InputError
                                id="debrief-note-body-error"
                                className="mt-1"
                                message={errors.body}
                            />
                        </div>

                        <div className="rounded-md border border-sky-300 bg-white p-3">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="debrief-note-attestation"
                                    checked={form.data.simulation_attestation}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'simulation_attestation',
                                            checked === true,
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        errors.simulation_attestation,
                                    )}
                                />
                                <Label
                                    htmlFor="debrief-note-attestation"
                                    className="text-sm leading-6 font-normal"
                                >
                                    Saya mengonfirmasi bahwa catatan ini untuk
                                    debrief simulasi bersama, bukan rekam medis,
                                    catatan privat, atau nilai mahasiswa.
                                </Label>
                            </div>
                            <InputError
                                className="mt-2"
                                message={errors.simulation_attestation}
                            />
                        </div>

                        <InputError
                            message={errors.workflow ?? errors.request_key}
                        />
                        <Button
                            type="submit"
                            className="w-full sm:w-fit"
                            disabled={
                                form.processing ||
                                form.data.body.trim().length < 3 ||
                                !form.data.simulation_attestation
                            }
                        >
                            <CheckCircle2
                                className="size-4"
                                aria-hidden="true"
                            />
                            {form.processing
                                ? 'Menyimpan catatan…'
                                : 'Simpan catatan bersama'}
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}

function DebriefNoteCard({
    note,
    canRevise,
    bodyMaxCharacters,
}: {
    note: DebriefNote;
    canRevise: boolean;
    bodyMaxCharacters: number;
}) {
    const [editing, setEditing] = useState(false);
    const latest = note.versions[note.versions.length - 1];
    const priorVersions = note.versions.slice(0, -1).reverse();
    const form = useForm({
        request_key: note.revisionRequestKey,
        body: latest?.body ?? '',
        change_reason: '',
        simulation_attestation: false,
    });
    const errors = form.errors as Record<string, string | undefined>;

    if (!latest) {
        return null;
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(note.revisionUrl, {
            preserveScroll: true,
            onSuccess: (page) => {
                const nextEvidence = page.props.teachingEvidence as
                    Partial<Props['teachingEvidence']> | undefined;
                const nextNote = nextEvidence?.notes?.find(
                    (candidate) => candidate.publicId === note.publicId,
                );
                const nextLatest = nextNote?.versions.at(-1);

                form.setData({
                    request_key:
                        nextNote?.revisionRequestKey ?? note.revisionRequestKey,
                    body: nextLatest?.body ?? form.data.body,
                    change_reason: '',
                    simulation_attestation: false,
                });
                form.clearErrors();
                setEditing(false);
            },
        });
    }

    const fieldPrefix = `debrief-note-${note.publicId}`;

    return (
        <article className="rounded-lg border border-border bg-muted/20 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{note.type.label}</Badge>
                        <span className="font-mono text-xs text-muted-foreground">
                            v{latest.versionNumber}
                        </span>
                    </div>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {latest.author.name} · {latest.author.program} ·{' '}
                        {latest.author.role}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        <time dateTime={latest.authoredAt}>
                            {formatDateTime(latest.authoredAt)} WIB
                        </time>{' '}
                        · Penugasan …
                        {latest.author.assignmentPublicId
                            .slice(-8)
                            .toUpperCase()}
                    </p>
                </div>
                {canRevise && !editing && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        <PencilLine className="size-4" aria-hidden="true" />
                        Buat versi perbaikan
                    </Button>
                )}
            </div>

            <p className="mt-4 text-sm leading-6 whitespace-pre-wrap">
                {latest.body}
            </p>

            {latest.changeReason && (
                <p className="mt-2 text-xs leading-5 text-muted-foreground">
                    <span className="font-semibold">Alasan perubahan:</span>{' '}
                    {latest.changeReason}
                </p>
            )}

            {priorVersions.length > 0 && (
                <details className="mt-4 rounded-md border border-border bg-white p-3">
                    <summary className="cursor-pointer py-3 text-sm font-semibold">
                        Riwayat {priorVersions.length} versi sebelumnya
                    </summary>
                    <ol className="mt-3 space-y-3">
                        {priorVersions.map((version) => (
                            <li
                                key={version.publicId}
                                className="border-t border-border pt-3 first:border-t-0 first:pt-0"
                            >
                                <p className="text-xs font-semibold text-muted-foreground">
                                    v{version.versionNumber} ·{' '}
                                    {version.author.name} ·{' '}
                                    <time dateTime={version.authoredAt}>
                                        {formatDateTime(version.authoredAt)} WIB
                                    </time>
                                </p>
                                <p className="mt-2 text-sm leading-6 whitespace-pre-wrap">
                                    {version.body}
                                </p>
                                {version.changeReason && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Alasan perubahan: {version.changeReason}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                </details>
            )}

            {editing && (
                <form
                    onSubmit={submit}
                    className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4"
                >
                    <h4 className="font-semibold">
                        Versi penerus v{latest.versionNumber + 1}
                    </h4>
                    <p className="mt-1 text-sm text-amber-950">
                        Versi v{latest.versionNumber} tetap tersimpan dan dapat
                        dibaca pada riwayat.
                    </p>

                    <div className="mt-4 grid gap-4">
                        <div>
                            <div className="flex items-center justify-between gap-3">
                                <Label htmlFor={`${fieldPrefix}-body`}>
                                    Isi versi baru
                                </Label>
                                <span className="text-xs text-muted-foreground">
                                    {form.data.body.length}/{bodyMaxCharacters}
                                </span>
                            </div>
                            <textarea
                                id={`${fieldPrefix}-body`}
                                value={form.data.body}
                                onChange={(event) =>
                                    form.setData('body', event.target.value)
                                }
                                maxLength={bodyMaxCharacters}
                                aria-invalid={Boolean(errors.body)}
                                className="mt-1 min-h-32 w-full rounded-md border border-input bg-white px-3 py-2 text-sm leading-6 shadow-xs focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            />
                            <InputError
                                className="mt-1"
                                message={errors.body}
                            />
                        </div>
                        <div>
                            <Label htmlFor={`${fieldPrefix}-reason`}>
                                Alasan perubahan
                            </Label>
                            <textarea
                                id={`${fieldPrefix}-reason`}
                                value={form.data.change_reason}
                                onChange={(event) =>
                                    form.setData(
                                        'change_reason',
                                        event.target.value,
                                    )
                                }
                                maxLength={1000}
                                required
                                aria-invalid={Boolean(errors.change_reason)}
                                className="mt-1 min-h-20 w-full rounded-md border border-input bg-white px-3 py-2 text-sm leading-6 shadow-xs focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            />
                            <InputError
                                className="mt-1"
                                message={errors.change_reason}
                            />
                        </div>
                        <div className="rounded-md border border-amber-300 bg-white p-3">
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id={`${fieldPrefix}-attestation`}
                                    checked={form.data.simulation_attestation}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'simulation_attestation',
                                            checked === true,
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        errors.simulation_attestation,
                                    )}
                                />
                                <Label
                                    htmlFor={`${fieldPrefix}-attestation`}
                                    className="text-sm leading-6 font-normal"
                                >
                                    Saya mengonfirmasi versi ini tetap berupa
                                    catatan debrief bersama, bukan perubahan
                                    rekam klinis atau nilai.
                                </Label>
                            </div>
                            <InputError
                                className="mt-2"
                                message={errors.simulation_attestation}
                            />
                        </div>
                        <InputError
                            message={errors.workflow ?? errors.request_key}
                        />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    form.data.body.trim().length < 3 ||
                                    form.data.change_reason.trim().length < 3 ||
                                    !form.data.simulation_attestation
                                }
                            >
                                <CheckCircle2
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {form.processing
                                    ? 'Menyimpan versi…'
                                    : 'Simpan versi penerus'}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setEditing(false)}
                            >
                                Batal
                            </Button>
                        </div>
                    </div>
                </form>
            )}
        </article>
    );
}

function RubricReferences({ references }: { references: RubricReference[] }) {
    return (
        <section className="rounded-lg border border-amber-200 bg-amber-50 p-5">
            <div className="flex items-center gap-2 text-amber-950">
                <AlertTriangle className="size-5" aria-hidden="true" />
                <h2 className="text-lg font-semibold">Referensi rubrik</h2>
            </div>
            <p className="mt-2 text-sm leading-6 text-amber-950">
                Referensi ini menautkan tujuan pembelajaran. Sistem belum
                membuat skor, nilai, kelulusan, atau klaim kompetensi.
            </p>

            {references.length === 0 ? (
                <p className="mt-4 rounded-md border border-dashed border-amber-300 bg-white p-3 text-sm">
                    Belum ada referensi rubrik pada versi skenario ini.
                </p>
            ) : (
                <div className="mt-4 space-y-3">
                    {references.map((reference) => (
                        <article
                            key={`${reference.code}-${reference.version}`}
                            className="rounded-md border border-amber-300 bg-white p-3"
                        >
                            <Badge
                                variant="outline"
                                className="border-amber-300 bg-amber-50 text-amber-950"
                            >
                                {reference.status.label}
                            </Badge>
                            <h3 className="mt-2 font-semibold">
                                {reference.title}
                            </h3>
                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                {reference.code} · {reference.version}
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Sumber: {reference.sourceLabel}
                            </p>
                            {reference.learningOutcomes.length > 0 && (
                                <ul className="mt-3 space-y-2 text-sm leading-5">
                                    {reference.learningOutcomes.map(
                                        (outcome) => (
                                            <li
                                                key={outcome.number}
                                                className="flex gap-2"
                                            >
                                                <span className="font-bold">
                                                    LO-{outcome.number}
                                                </span>
                                                <span>{outcome.label}</span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            )}
                            {reference.nonScoring && (
                                <p className="mt-3 text-xs font-semibold text-amber-900">
                                    Non-scoring · belum dapat dipakai sebagai
                                    nilai resmi.
                                </p>
                            )}
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
}

function SummaryCell({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof History;
    label: string;
    value: string;
}) {
    return (
        <div className="bg-white px-5 py-5">
            <Icon className="size-5 text-success" aria-hidden="true" />
            <p className="mt-3 text-2xl font-semibold">{value}</p>
            <p className="mt-1 text-xs font-bold tracking-wider text-muted-foreground uppercase">
                {label}
            </p>
        </div>
    );
}

function TimelineCard({ event }: { event: TimelineEvent }) {
    const shortReference = event.source.publicId?.slice(-8).toUpperCase();

    return (
        <li className="grid gap-4 px-5 py-5 md:grid-cols-[3rem_minmax(0,1fr)_12rem]">
            <div
                className={cn(
                    'flex size-10 items-center justify-center rounded-full border font-mono text-xs font-bold',
                    categoryStyles[event.category.code] ??
                        categoryStyles.WORKFLOW,
                )}
                aria-label={`Urutan ${event.sequence}`}
            >
                {event.sequence}
            </div>
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge
                        variant="outline"
                        className={cn(
                            categoryStyles[event.category.code] ??
                                categoryStyles.WORKFLOW,
                        )}
                    >
                        {event.category.label}
                    </Badge>
                    {event.tags.map((tag) => (
                        <Badge key={tag.code} variant="outline">
                            {tag.label}
                        </Badge>
                    ))}
                </div>
                <h3 className="mt-3 text-lg font-semibold">{event.title}</h3>
                {event.detail && (
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                        {event.detail}
                    </p>
                )}
                <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <span className="font-semibold text-foreground">
                        {event.actor.name}
                    </span>
                    <span>
                        {event.actor.program} · {event.actor.role}
                    </span>
                    {event.actor.assignmentPublicId && (
                        <span>
                            Penugasan …
                            {event.actor.assignmentPublicId
                                .slice(-8)
                                .toUpperCase()}
                        </span>
                    )}
                    <span>
                        {event.source.label}
                        {event.source.version
                            ? ` · ${event.source.version}`
                            : ''}
                        {shortReference ? ` · …${shortReference}` : ''}
                    </span>
                </div>
            </div>
            <div className="text-xs leading-5 text-muted-foreground md:text-right">
                <div className="flex items-center gap-1.5 md:justify-end">
                    <Clock3 className="size-4" aria-hidden="true" />
                    <span className="font-semibold text-foreground">
                        Waktu utama
                    </span>
                </div>
                <time dateTime={event.primaryAt}>
                    {formatDateTime(event.primaryAt)} WIB
                </time>
                {event.showsRecordedTimeDifference && (
                    <p className="mt-2 border-t border-border pt-2">
                        Dicatat{' '}
                        <time dateTime={event.recordedAt}>
                            {formatDateTime(event.recordedAt)} WIB
                        </time>
                    </p>
                )}
                {event.outcome === 'SUCCESS' && (
                    <span className="mt-2 inline-flex items-center gap-1 text-success">
                        <CheckCircle2 className="size-3" aria-hidden="true" />
                        Tercatat
                    </span>
                )}
            </div>
        </li>
    );
}

EncounterDebrief.layout = {
    breadcrumbs: [
        { title: 'Antrean kerja', href: '/work' },
        { title: 'Encounter', href: '#' },
        { title: 'Linimasa & debrief', href: '#' },
    ],
};
