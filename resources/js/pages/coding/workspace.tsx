import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Bot,
    CheckCircle2,
    ClipboardCheck,
    FileSearch,
    Fingerprint,
    History,
    LockKeyhole,
    Search,
    Send,
    ShieldCheck,
    Stethoscope,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, TextareaHTMLAttributes } from 'react';
import { ClinicalVersionStamp } from '@/components/clinical-version-stamp';
import InputError from '@/components/input-error';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type {
    CodeAssignment,
    CodingCandidate,
    CodingSuggestionRun,
    CodingWorkspaceProps,
} from '@/types';

function Textarea({
    className,
    ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            className={cn(
                'min-h-24 w-full rounded-md border border-input bg-white px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:bg-muted/40 disabled:opacity-70',
                className,
            )}
            {...props}
        />
    );
}

function shortHash(value: string | null): string {
    return value ? `${value.slice(0, 12)}…${value.slice(-8)}` : '—';
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

function confidenceClass(code: string): string {
    return code === 'EXACT'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
        : code === 'STRONG_MATCH'
          ? 'border-sky-200 bg-sky-50 text-sky-900'
          : 'border-amber-200 bg-amber-50 text-amber-950';
}

function statusClass(code: string): string {
    return code === 'APPROVED'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
        : code === 'CHANGES_REQUESTED' || code === 'REVIEW_REQUIRED'
          ? 'border-amber-200 bg-amber-50 text-amber-950'
          : 'border-sky-200 bg-sky-50 text-sky-900';
}

export default function CodingWorkspace({
    encounter,
    patient,
    session,
    assignment,
    task,
    documentationCorrection,
    prerequisites,
    releases,
    sources,
    selectedSourcePublicId,
    manualSearch,
    formOptions,
    urls,
}: CodingWorkspaceProps) {
    const selectedSource =
        sources.find((source) => source.publicId === selectedSourcePublicId) ??
        sources[0] ??
        null;
    const latestRun = selectedSource?.latestRun ?? null;
    const latestAssignment = selectedSource?.assignmentHistory[0] ?? null;
    const suggestionForm = useForm({
        request_key: formOptions.suggestionRequestKey,
    });
    const decisionForm = useForm({
        request_key: formOptions.decisionRequestKey,
        decision: 'REJECTED',
        candidate_public_id: '',
        manual_concept_public_id: '',
        reason: '',
        rationale: '',
        change_reason: '',
    });
    const reviewForm = useForm({
        request_key: formOptions.reviewRequestKey,
        action: 'REQUEST_CHANGES',
        comment: '',
        findings: [] as Array<{
            code: string;
            severity: string;
            message: string;
        }>,
    });
    const [searchQuery, setSearchQuery] = useState(manualSearch.query);
    const searchSystem =
        selectedSource?.terminologySystem ?? manualSearch.system;
    const [reviewFinding, setReviewFinding] = useState('');
    const suggestionErrors = suggestionForm.errors as Record<
        string,
        string | undefined
    >;
    const errors = decisionForm.errors as Record<string, string | undefined>;
    const reviewErrors = reviewForm.errors as Record<
        string,
        string | undefined
    >;

    function generateSuggestions(url: string) {
        suggestionForm.post(url, {
            preserveScroll: true,
            onSuccess: (page) => {
                const options = page.props.formOptions as
                    Partial<CodingWorkspaceProps['formOptions']> | undefined;

                if (typeof options?.suggestionRequestKey === 'string') {
                    suggestionForm.setData(
                        'request_key',
                        options.suggestionRequestKey,
                    );
                    suggestionForm.clearErrors();
                }
            },
        });
    }

    function submitDecision(
        run: CodingSuggestionRun,
        decision: string,
        candidate?: CodingCandidate,
        manualConceptPublicId?: string,
    ) {
        decisionForm.transform((data) => ({
            ...data,
            decision,
            candidate_public_id: candidate?.publicId ?? '',
            manual_concept_public_id: manualConceptPublicId ?? '',
        }));
        decisionForm.post(run.decisionUrl, {
            preserveScroll: true,
            onSuccess: (page) => {
                const options = page.props.formOptions as
                    Partial<CodingWorkspaceProps['formOptions']> | undefined;

                if (typeof options?.decisionRequestKey === 'string') {
                    decisionForm.setData({
                        ...decisionForm.data,
                        request_key: options.decisionRequestKey,
                        decision: 'REJECTED',
                        candidate_public_id: '',
                        manual_concept_public_id: '',
                        reason: '',
                        rationale: '',
                        change_reason: '',
                    });
                    decisionForm.clearErrors();
                }
            },
        });
    }

    function searchTerminology(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(
            urls.self,
            {
                source: selectedSource?.publicId ?? '',
                q: searchQuery,
                system: searchSystem,
            },
            { preserveScroll: true, preserveState: true },
        );
    }

    function submitAssignment(coding: CodeAssignment) {
        router.post(coding.submitUrl, {}, { preserveScroll: true });
    }

    function reviewAssignment(
        coding: CodeAssignment,
        action: 'APPROVE_SIMULATION' | 'REQUEST_CHANGES',
    ) {
        reviewForm.transform((data) => ({
            ...data,
            action,
            findings:
                action === 'REQUEST_CHANGES' && reviewFinding.trim() !== ''
                    ? [
                          {
                              code: 'CODING_REVIEW_FINDING',
                              severity: 'BLOCKING',
                              message: reviewFinding.trim(),
                          },
                      ]
                    : [],
        }));
        reviewForm.post(coding.reviewUrl, {
            preserveScroll: true,
            onSuccess: (page) => {
                const options = page.props.formOptions as
                    Partial<CodingWorkspaceProps['formOptions']> | undefined;

                if (typeof options?.reviewRequestKey === 'string') {
                    reviewForm.setData({
                        request_key: options.reviewRequestKey,
                        action: 'REQUEST_CHANGES',
                        comment: '',
                        findings: [],
                    });
                    reviewForm.clearErrors();
                    setReviewFinding('');
                }
            },
        });
    }

    return (
        <>
            <Head title={`Koding ${encounter.number}`} />

            <div className="mx-auto flex w-full max-w-[1540px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Koding berbantuan RMIK · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Saran Koding Otomatis
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Mesin merangking kandidat ICD-10 dari diagnosis dan
                            ICD-9-CM dari prosedur yang benar-benar dicatat
                            telah dilakukan. Koder memilih atau menolak;
                            supervisor meninjau sumber dan hash yang tepat.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={urls.recordQuality}>
                                <ClipboardCheck
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Kelengkapan RMIK
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
                    className="rounded-lg border-2 border-orange-300 bg-orange-50 px-5 py-4"
                    aria-labelledby="coding-safety-title"
                >
                    <div className="flex gap-3">
                        <ShieldCheck
                            className="mt-0.5 size-6 shrink-0 text-orange-800"
                            aria-hidden="true"
                        />
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h2
                                    id="coding-safety-title"
                                    className="font-semibold text-orange-950"
                                >
                                    Wajib ditinjau koder
                                </h2>
                                <Badge className="bg-orange-900 text-white">
                                    Bukan finalisasi otomatis
                                </Badge>
                            </div>
                            <p className="mt-1 text-sm leading-6 text-orange-950">
                                Skor di bawah adalah skor retrieval, bukan
                                probabilitas klinis atau kepastian kode. Tidak
                                ada tombol terima semua dan tidak ada kode yang
                                menjadi final tanpa keputusan manusia.
                            </p>
                        </div>
                    </div>
                </section>

                {documentationCorrection && (
                    <section
                        role="alert"
                        aria-labelledby="coding-correction-progress-title"
                        className="rounded-lg border border-amber-300 bg-amber-50 px-5 py-4"
                    >
                        <div className="flex gap-3">
                            <AlertTriangle
                                className="mt-0.5 size-5 shrink-0 text-amber-800"
                                aria-hidden="true"
                            />
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2
                                        id="coding-correction-progress-title"
                                        className="font-semibold text-amber-950"
                                    >
                                        Koding diblokir selama koreksi sumber
                                    </h2>
                                    <Badge variant="outline">
                                        {documentationCorrection.status.label}
                                    </Badge>
                                </div>
                                <p className="mt-1 text-sm leading-6 text-amber-950">
                                    “{documentationCorrection.reason}” Koreksi{' '}
                                    {documentationCorrection.sourceType ===
                                    'PROCEDURE'
                                        ? 'prosedur'
                                        : 'diagnosis'}{' '}
                                    berada pada{' '}
                                    {documentationCorrection.responsibleAuthor}{' '}
                                    atau tahap supervisi berikutnya. Kandidat
                                    baru hanya dapat dibuat setelah closure dan
                                    telaah RMIK penerus disetujui.
                                </p>
                            </div>
                        </div>
                    </section>
                )}

                <div className="grid items-start gap-5 xl:grid-cols-[21rem_minmax(0,1fr)_25rem]">
                    <aside
                        aria-label="Sumber klinis dan release terminologi"
                        className="space-y-5 xl:sticky xl:top-6"
                    >
                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <Stethoscope
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Sumber klinis
                                </h2>
                            </div>
                            <div className="mt-4 space-y-2">
                                {sources.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Tidak ada diagnosis atau prosedur yang
                                        dapat dikoding.
                                    </p>
                                ) : (
                                    sources.map((source, index) => (
                                        <Link
                                            key={source.publicId}
                                            href={`${urls.self}?source=${source.publicId}`}
                                            preserveScroll
                                            className={cn(
                                                'block rounded-md border p-3 transition-colors',
                                                source.publicId ===
                                                    selectedSource?.publicId
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-border hover:bg-muted/40',
                                            )}
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-xs font-bold text-primary uppercase">
                                                    {source.sourceType.label} ·{' '}
                                                    {index + 1}
                                                </span>
                                                {source
                                                    .assignmentHistory[0] && (
                                                    <Badge
                                                        variant="outline"
                                                        className={statusClass(
                                                            source
                                                                .assignmentHistory[0]
                                                                .status.code,
                                                        )}
                                                    >
                                                        {
                                                            source
                                                                .assignmentHistory[0]
                                                                .status.label
                                                        }
                                                    </Badge>
                                                )}
                                            </div>
                                            <p className="mt-1 line-clamp-3 text-sm font-medium">
                                                {source.authoredText}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {source.sourceType.code ===
                                                'DIAGNOSIS'
                                                    ? `${source.role?.label ?? 'Diagnosis'} · ${source.certainty?.label ?? 'Status klinis'}`
                                                    : `${source.procedureDetails?.performerText ?? 'Pelaksana tidak tersedia'} · ICD-9-CM`}
                                            </p>
                                        </Link>
                                    ))
                                )}
                            </div>
                        </section>

                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <Fingerprint
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Release terminologi
                                </h2>
                            </div>
                            <div className="mt-4 space-y-3">
                                {releases.map((item) => (
                                    <article
                                        key={item.system.code}
                                        className="rounded-md border border-border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="font-semibold">
                                                {item.system.label}
                                            </p>
                                            <Badge
                                                variant="outline"
                                                className={
                                                    item.active
                                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                                                        : 'border-red-200 bg-red-50 text-red-900'
                                                }
                                            >
                                                {item.active
                                                    ? 'Aktif'
                                                    : 'Belum aktif'}
                                            </Badge>
                                        </div>
                                        {item.release ? (
                                            <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                                                <p>
                                                    {
                                                        item.release
                                                            .logicalVersion
                                                    }{' '}
                                                    ·{' '}
                                                    {item.release.rowCount.toLocaleString(
                                                        'id-ID',
                                                    )}{' '}
                                                    kode
                                                </p>
                                                <p>
                                                    SHA-256{' '}
                                                    {shortHash(
                                                        item.release
                                                            .sourceSha256,
                                                    )}
                                                </p>
                                                <p>{item.release.provenance}</p>
                                            </div>
                                        ) : (
                                            <p className="mt-2 text-xs leading-5 text-red-800">
                                                Import workbook tervalidasi
                                                diperlukan sebelum pencarian.
                                            </p>
                                        )}
                                    </article>
                                ))}
                            </div>
                        </section>
                    </aside>

                    <div className="space-y-5">
                        {!prerequisites.qualityApproved ||
                        !prerequisites.medicalSourceApproved ||
                        (selectedSource?.sourceType.code === 'PROCEDURE' &&
                            !prerequisites.closureSourceApproved) ? (
                            <section className="rounded-lg border border-amber-300 bg-amber-50 p-5">
                                <div className="flex gap-3">
                                    <AlertTriangle
                                        className="mt-0.5 size-5 shrink-0 text-amber-800"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <h2 className="font-semibold text-amber-950">
                                            Prasyarat koding belum lengkap
                                        </h2>
                                        <p className="mt-1 text-sm leading-6 text-amber-950">
                                            Sumber medis, closure untuk
                                            prosedur, dan telaah kelengkapan
                                            RMIK yang relevan harus disetujui
                                            sebelum kandidat dapat dibuat.
                                        </p>
                                    </div>
                                </div>
                            </section>
                        ) : null}

                        {selectedSource ? (
                            <>
                                <section className="clinical-shadow rounded-lg border border-border bg-white p-5 md:p-6">
                                    <div className="flex flex-col justify-between gap-4 md:flex-row md:items-start">
                                        <div>
                                            <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                                {
                                                    selectedSource.sourceType
                                                        .label
                                                }{' '}
                                                · tidak dapat diedit RMIK
                                            </p>
                                            <h2 className="mt-2 text-2xl font-semibold">
                                                {selectedSource.authoredText}
                                            </h2>
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                {selectedSource.sourceType
                                                    .code === 'DIAGNOSIS'
                                                    ? `${selectedSource.role?.label ?? 'Diagnosis'} · ${selectedSource.certainty?.label ?? 'Status klinis'}`
                                                    : `${selectedSource.procedureDetails?.performerText ?? 'Pelaksana tidak tersedia'} · ${formatDateTime(selectedSource.procedureDetails?.performedStartAt ?? null)} WIB`}{' '}
                                                · Penulis{' '}
                                                {
                                                    selectedSource.sourceVersion
                                                        .author
                                                }
                                            </p>
                                        </div>
                                        <Badge variant="outline">
                                            {selectedSource.clinicalStatus}
                                        </Badge>
                                    </div>
                                    {selectedSource.procedureDetails && (
                                        <dl className="mt-5 grid gap-3 rounded-md border border-sky-200 bg-sky-50 p-4 text-sm sm:grid-cols-2">
                                            <div>
                                                <dt className="text-xs font-semibold text-sky-900 uppercase">
                                                    Pelaksana
                                                </dt>
                                                <dd className="mt-1">
                                                    {
                                                        selectedSource
                                                            .procedureDetails
                                                            .performerText
                                                    }
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs font-semibold text-sky-900 uppercase">
                                                    Waktu dilakukan
                                                </dt>
                                                <dd className="mt-1">
                                                    {formatDateTime(
                                                        selectedSource
                                                            .procedureDetails
                                                            .performedStartAt,
                                                    )}{' '}
                                                    WIB
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs font-semibold text-sky-900 uppercase">
                                                    Lokasi tubuh
                                                </dt>
                                                <dd className="mt-1">
                                                    {selectedSource
                                                        .procedureDetails
                                                        .bodySiteText ?? '—'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs font-semibold text-sky-900 uppercase">
                                                    Hasil
                                                </dt>
                                                <dd className="mt-1">
                                                    {selectedSource
                                                        .procedureDetails
                                                        .outcomeText ?? '—'}
                                                </dd>
                                            </div>
                                        </dl>
                                    )}
                                    {selectedSource.sourceVersion
                                        .versionNumber !== null &&
                                    selectedSource.sourceVersion.contentHash !==
                                        null &&
                                    selectedSource.sourceVersion.status !==
                                        null ? (
                                        <div className="mt-5">
                                            <ClinicalVersionStamp
                                                versionNumber={
                                                    selectedSource.sourceVersion
                                                        .versionNumber
                                                }
                                                schemaVersion={
                                                    selectedSource.sourceVersion
                                                        .schemaVersion ??
                                                    'unknown-source.v1'
                                                }
                                                contentHash={
                                                    selectedSource.sourceVersion
                                                        .documentContentHash ??
                                                    selectedSource.sourceVersion
                                                        .contentHash
                                                }
                                                status={
                                                    selectedSource.sourceVersion
                                                        .status
                                                }
                                            />
                                            {selectedSource.sourceType.code ===
                                                'PROCEDURE' && (
                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    Hash rekaman prosedur:{' '}
                                                    {shortHash(
                                                        selectedSource
                                                            .sourceVersion
                                                            .contentHash,
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                    ) : null}
                                    <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-5">
                                        <p className="max-w-xl text-sm leading-6 text-muted-foreground">
                                            Kandidat baru selalu membuat run
                                            versi baru; run lama dan setiap
                                            keputusan tetap tersimpan.
                                        </p>
                                        <Button
                                            type="button"
                                            disabled={
                                                !selectedSource.canGenerate ||
                                                suggestionForm.processing
                                            }
                                            onClick={() =>
                                                generateSuggestions(
                                                    selectedSource.suggestionUrl,
                                                )
                                            }
                                        >
                                            <Bot
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            {latestRun
                                                ? 'Buat run kandidat baru'
                                                : 'Buat kandidat'}
                                        </Button>
                                    </div>
                                    <InputError
                                        className="mt-3"
                                        message={suggestionErrors.workflow}
                                    />
                                </section>

                                <section className="clinical-shadow rounded-lg border border-border bg-white p-5 md:p-6">
                                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                        <div className="flex items-center gap-2">
                                            <Bot
                                                className="size-5 text-primary"
                                                aria-hidden="true"
                                            />
                                            <div>
                                                <h2 className="text-xl font-semibold">
                                                    Kandidat teranking
                                                </h2>
                                                <p className="text-sm text-muted-foreground">
                                                    Kandidat, bukan assignment
                                                    kode.
                                                </p>
                                            </div>
                                        </div>
                                        {latestRun && (
                                            <Badge variant="outline">
                                                {latestRun.engineVersion}
                                            </Badge>
                                        )}
                                    </div>

                                    {!latestRun ? (
                                        <div className="mt-5 rounded-md border border-dashed border-border p-6 text-center">
                                            <FileSearch
                                                className="mx-auto size-8 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <p className="mt-3 font-medium">
                                                Belum ada run kandidat
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Koder dapat langsung memakai
                                                pencarian manual setelah release
                                                aktif.
                                            </p>
                                        </div>
                                    ) : latestRun.candidates.length === 0 ? (
                                        <div className="mt-5 rounded-md border border-amber-200 bg-amber-50 p-5">
                                            <p className="font-semibold text-amber-950">
                                                Tidak ada kandidat andal
                                            </p>
                                            <p className="mt-1 text-sm leading-6 text-amber-950">
                                                Sistem tidak memaksakan
                                                kecocokan rendah. Cari manual,
                                                tolak run, dan catat alasannya.
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="mt-5 space-y-3">
                                            {latestRun.candidates.map(
                                                (candidate) => (
                                                    <article
                                                        key={candidate.publicId}
                                                        className="rounded-lg border border-border p-4"
                                                    >
                                                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                                            <div className="flex gap-3">
                                                                <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">
                                                                    {
                                                                        candidate.rank
                                                                    }
                                                                </div>
                                                                <div>
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        <span className="font-mono text-lg font-semibold">
                                                                            {
                                                                                candidate
                                                                                    .concept
                                                                                    .code
                                                                            }
                                                                        </span>
                                                                        <Badge
                                                                            variant="outline"
                                                                            className={confidenceClass(
                                                                                candidate
                                                                                    .confidence
                                                                                    .code,
                                                                            )}
                                                                        >
                                                                            {
                                                                                candidate
                                                                                    .confidence
                                                                                    .label
                                                                            }
                                                                        </Badge>
                                                                    </div>
                                                                    <p className="mt-1 text-sm font-medium">
                                                                        {
                                                                            candidate
                                                                                .concept
                                                                                .display
                                                                        }
                                                                    </p>
                                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                                        Skor
                                                                        retrieval{' '}
                                                                        {
                                                                            candidate.score
                                                                        }
                                                                        /10000 ·
                                                                        bukan
                                                                        probabilitas
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                disabled={
                                                                    !latestRun.canDecide ||
                                                                    decisionForm.processing
                                                                }
                                                                onClick={() =>
                                                                    submitDecision(
                                                                        latestRun,
                                                                        'ACCEPTED_TO_DRAFT',
                                                                        candidate,
                                                                    )
                                                                }
                                                            >
                                                                <CheckCircle2
                                                                    className="size-4"
                                                                    aria-hidden="true"
                                                                />
                                                                Pilih ke draf
                                                            </Button>
                                                        </div>
                                                        {candidate.specificityWarning && (
                                                            <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-950">
                                                                {
                                                                    candidate.specificityWarning
                                                                }
                                                            </p>
                                                        )}
                                                        <details className="mt-3 text-xs text-muted-foreground">
                                                            <summary className="cursor-pointer font-medium text-foreground">
                                                                Lihat bukti
                                                                pencocokan
                                                            </summary>
                                                            <pre className="mt-2 overflow-x-auto rounded-md bg-muted p-3 font-mono text-[11px] whitespace-pre-wrap">
                                                                {JSON.stringify(
                                                                    candidate.evidence,
                                                                    null,
                                                                    2,
                                                                )}
                                                            </pre>
                                                        </details>
                                                    </article>
                                                ),
                                            )}
                                        </div>
                                    )}

                                    {latestRun?.canDecide && (
                                        <div className="mt-5 grid gap-4 border-t border-border pt-5 md:grid-cols-2">
                                            <div>
                                                <Label htmlFor="decision-rationale">
                                                    Alasan pemilihan / catatan
                                                    koder
                                                </Label>
                                                <Textarea
                                                    id="decision-rationale"
                                                    className="mt-2"
                                                    value={
                                                        decisionForm.data
                                                            .rationale
                                                    }
                                                    onChange={(event) =>
                                                        decisionForm.setData(
                                                            'rationale',
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="Jelaskan indeks, konteks, atau alasan pemilihan."
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="decision-reason">
                                                    {selectedSource.correctionSupported
                                                        ? 'Alasan tolak / permintaan koreksi'
                                                        : 'Alasan penolakan saran'}
                                                </Label>
                                                <Textarea
                                                    id="decision-reason"
                                                    className="mt-2"
                                                    value={
                                                        decisionForm.data.reason
                                                    }
                                                    onChange={(event) =>
                                                        decisionForm.setData(
                                                            'reason',
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder={
                                                        selectedSource.correctionSupported
                                                            ? `Wajib untuk menolak atau meminta koreksi ${selectedSource.sourceType.code === 'PROCEDURE' ? 'dokumentasi prosedur' : 'dokumentasi diagnosis'}.`
                                                            : 'Wajib untuk menolak kandidat.'
                                                    }
                                                />
                                            </div>
                                            {latestAssignment?.status.code ===
                                                'CHANGES_REQUESTED' && (
                                                <div className="md:col-span-2">
                                                    <Label htmlFor="change-reason">
                                                        Alasan versi penerus
                                                    </Label>
                                                    <Input
                                                        id="change-reason"
                                                        className="mt-2"
                                                        value={
                                                            decisionForm.data
                                                                .change_reason
                                                        }
                                                        onChange={(event) =>
                                                            decisionForm.setData(
                                                                'change_reason',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            )}
                                            <div className="flex flex-wrap justify-end gap-2 md:col-span-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    disabled={
                                                        decisionForm.processing ||
                                                        decisionForm.data.reason.trim() ===
                                                            ''
                                                    }
                                                    onClick={() =>
                                                        submitDecision(
                                                            latestRun,
                                                            'REJECTED',
                                                        )
                                                    }
                                                >
                                                    <XCircle
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    Tolak saran
                                                </Button>
                                                {selectedSource.correctionSupported && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        disabled={
                                                            decisionForm.processing ||
                                                            decisionForm.data.reason.trim() ===
                                                                ''
                                                        }
                                                        onClick={() =>
                                                            submitDecision(
                                                                latestRun,
                                                                'CORRECTION_REQUESTED',
                                                            )
                                                        }
                                                    >
                                                        <AlertTriangle
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                        Minta koreksi{' '}
                                                        {selectedSource
                                                            .sourceType.code ===
                                                        'PROCEDURE'
                                                            ? 'prosedur'
                                                            : 'diagnosis'}
                                                    </Button>
                                                )}
                                            </div>
                                            <InputError
                                                className="md:col-span-2"
                                                message={
                                                    errors.workflow ??
                                                    errors.reason ??
                                                    errors.change_reason
                                                }
                                            />
                                        </div>
                                    )}
                                </section>

                                <section className="clinical-shadow rounded-lg border border-border bg-white p-5 md:p-6">
                                    <div className="flex items-center gap-2">
                                        <Search
                                            className="size-5 text-primary"
                                            aria-hidden="true"
                                        />
                                        <div>
                                            <h2 className="text-xl font-semibold">
                                                Cari kode lain
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                Pencarian manual selalu tersedia
                                                dan dicatat sebagai manual.
                                            </p>
                                        </div>
                                    </div>
                                    <form
                                        className="mt-4 grid gap-3 sm:grid-cols-[11rem_minmax(0,1fr)_auto]"
                                        onSubmit={searchTerminology}
                                    >
                                        <div>
                                            <Label
                                                htmlFor="terminology-system"
                                                className="sr-only"
                                            >
                                                Sistem klasifikasi
                                            </Label>
                                            <select
                                                id="terminology-system"
                                                className="h-9 w-full rounded-md border border-input bg-white px-3 text-sm"
                                                value={searchSystem}
                                                disabled
                                            >
                                                <option
                                                    value={
                                                        selectedSource.terminologySystem
                                                    }
                                                >
                                                    {selectedSource.terminologySystem ===
                                                    'ICD_10'
                                                        ? 'ICD-10 diagnosis'
                                                        : 'ICD-9-CM prosedur'}
                                                </option>
                                            </select>
                                        </div>
                                        <div>
                                            <Label
                                                htmlFor="terminology-query"
                                                className="sr-only"
                                            >
                                                Istilah atau kode
                                            </Label>
                                            <Input
                                                id="terminology-query"
                                                value={searchQuery}
                                                onChange={(event) =>
                                                    setSearchQuery(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Masukkan kode atau istilah (minimum 2 karakter)"
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={
                                                searchQuery.trim().length < 2
                                            }
                                        >
                                            <Search
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            Cari
                                        </Button>
                                    </form>
                                    {manualSearch.error && (
                                        <p className="mt-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900">
                                            {manualSearch.error}
                                        </p>
                                    )}
                                    {manualSearch.results.length > 0 && (
                                        <div className="mt-4 divide-y divide-border rounded-md border border-border">
                                            {manualSearch.results.map(
                                                (result) => (
                                                    <article
                                                        key={
                                                            result.concept
                                                                .publicId
                                                        }
                                                        className="flex flex-col justify-between gap-3 p-4 sm:flex-row sm:items-center"
                                                    >
                                                        <div>
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="font-mono font-semibold">
                                                                    {
                                                                        result
                                                                            .concept
                                                                            .code
                                                                    }
                                                                </span>
                                                                <Badge variant="outline">
                                                                    {
                                                                        result
                                                                            .confidence
                                                                            .label
                                                                    }
                                                                </Badge>
                                                            </div>
                                                            <p className="mt-1 text-sm">
                                                                {
                                                                    result
                                                                        .concept
                                                                        .display
                                                                }
                                                            </p>
                                                        </div>
                                                        {manualSearch.system ===
                                                            selectedSource.terminologySystem &&
                                                        latestRun?.canDecide ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={
                                                                    decisionForm.processing
                                                                }
                                                                onClick={() =>
                                                                    submitDecision(
                                                                        latestRun,
                                                                        'MANUAL_ALTERNATIVE',
                                                                        undefined,
                                                                        result
                                                                            .concept
                                                                            .publicId,
                                                                    )
                                                                }
                                                            >
                                                                Pilih manual
                                                            </Button>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                Buat run untuk
                                                                sumber ini
                                                                terlebih dahulu
                                                            </span>
                                                        )}
                                                    </article>
                                                ),
                                            )}
                                        </div>
                                    )}
                                </section>

                                <section className="clinical-shadow rounded-lg border border-border bg-white p-5 md:p-6">
                                    <div className="flex items-center gap-2">
                                        <LockKeyhole
                                            className="size-5 text-primary"
                                            aria-hidden="true"
                                        />
                                        <div>
                                            <h2 className="text-xl font-semibold">
                                                Assignment kode
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                Draf, pengajuan, dan persetujuan
                                                dipisahkan.
                                            </p>
                                        </div>
                                    </div>
                                    {selectedSource.assignmentHistory.length ===
                                    0 ? (
                                        <p className="mt-4 text-sm text-muted-foreground">
                                            Belum ada assignment kode untuk
                                            sumber ini.
                                        </p>
                                    ) : (
                                        <div className="mt-4 space-y-4">
                                            {selectedSource.assignmentHistory.map(
                                                (coding, index) => (
                                                    <article
                                                        key={coding.publicId}
                                                        className="rounded-lg border border-border p-4"
                                                    >
                                                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                                            <div>
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="font-mono text-xl font-semibold">
                                                                        {
                                                                            coding
                                                                                .concept
                                                                                .code
                                                                        }
                                                                    </span>
                                                                    <Badge
                                                                        variant="outline"
                                                                        className={statusClass(
                                                                            coding
                                                                                .status
                                                                                .code,
                                                                        )}
                                                                    >
                                                                        {
                                                                            coding
                                                                                .status
                                                                                .label
                                                                        }
                                                                    </Badge>
                                                                    <Badge variant="secondary">
                                                                        {
                                                                            coding
                                                                                .selectionMethod
                                                                                .label
                                                                        }
                                                                    </Badge>
                                                                </div>
                                                                <p className="mt-1 text-sm font-medium">
                                                                    {
                                                                        coding
                                                                            .concept
                                                                            .display
                                                                    }
                                                                </p>
                                                                <p className="mt-2 text-xs text-muted-foreground">
                                                                    Versi{' '}
                                                                    {selectedSource
                                                                        .assignmentHistory
                                                                        .length -
                                                                        index}{' '}
                                                                    ·{' '}
                                                                    {
                                                                        coding.coder
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {formatDateTime(
                                                                        coding.recordedAt,
                                                                    )}{' '}
                                                                    WIB
                                                                </p>
                                                            </div>
                                                            {coding.canSubmit && (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        submitAssignment(
                                                                            coding,
                                                                        )
                                                                    }
                                                                >
                                                                    <Send
                                                                        className="size-4"
                                                                        aria-hidden="true"
                                                                    />
                                                                    Ajukan ke
                                                                    supervisor
                                                                </Button>
                                                            )}
                                                        </div>
                                                        <div className="mt-4 grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                                                            <p>
                                                                Sumber klinis:{' '}
                                                                {shortHash(
                                                                    coding.sourceClinicalContentHash,
                                                                )}
                                                            </p>
                                                            <p>
                                                                Release:{' '}
                                                                {shortHash(
                                                                    coding.terminologySourceHash,
                                                                )}
                                                            </p>
                                                            <p>
                                                                Assignment:{' '}
                                                                {shortHash(
                                                                    coding.contentHash,
                                                                )}
                                                            </p>
                                                        </div>

                                                        {coding.canReview && (
                                                            <div className="mt-5 space-y-4 border-t border-border pt-5">
                                                                <div>
                                                                    <Label
                                                                        htmlFor={`review-comment-${coding.publicId}`}
                                                                    >
                                                                        Catatan
                                                                        supervisor
                                                                    </Label>
                                                                    <Textarea
                                                                        id={`review-comment-${coding.publicId}`}
                                                                        className="mt-2"
                                                                        value={
                                                                            reviewForm
                                                                                .data
                                                                                .comment
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            reviewForm.setData(
                                                                                'comment',
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <Label
                                                                        htmlFor={`review-finding-${coding.publicId}`}
                                                                    >
                                                                        Temuan
                                                                        perbaikan
                                                                    </Label>
                                                                    <Textarea
                                                                        id={`review-finding-${coding.publicId}`}
                                                                        className="mt-2"
                                                                        value={
                                                                            reviewFinding
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            setReviewFinding(
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        placeholder="Wajib bila meminta perbaikan."
                                                                    />
                                                                </div>
                                                                <div className="flex flex-wrap justify-end gap-2">
                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        disabled={
                                                                            reviewForm.processing ||
                                                                            reviewFinding.trim() ===
                                                                                ''
                                                                        }
                                                                        onClick={() =>
                                                                            reviewAssignment(
                                                                                coding,
                                                                                'REQUEST_CHANGES',
                                                                            )
                                                                        }
                                                                    >
                                                                        Minta
                                                                        perbaikan
                                                                    </Button>
                                                                    <Button
                                                                        type="button"
                                                                        disabled={
                                                                            reviewForm.processing
                                                                        }
                                                                        onClick={() =>
                                                                            reviewAssignment(
                                                                                coding,
                                                                                'APPROVE_SIMULATION',
                                                                            )
                                                                        }
                                                                    >
                                                                        <ShieldCheck
                                                                            className="size-4"
                                                                            aria-hidden="true"
                                                                        />
                                                                        Setujui
                                                                        kode
                                                                    </Button>
                                                                </div>
                                                                <InputError
                                                                    message={
                                                                        reviewErrors.workflow ??
                                                                        reviewErrors.findings
                                                                    }
                                                                />
                                                            </div>
                                                        )}

                                                        {coding.reviews.length >
                                                            0 && (
                                                            <div className="mt-4 border-t border-border pt-4">
                                                                {coding.reviews.map(
                                                                    (
                                                                        review,
                                                                    ) => (
                                                                        <p
                                                                            key={
                                                                                review.publicId
                                                                            }
                                                                            className="text-xs text-muted-foreground"
                                                                        >
                                                                            {
                                                                                review
                                                                                    .action
                                                                                    .label
                                                                            }{' '}
                                                                            oleh{' '}
                                                                            {
                                                                                review.reviewer
                                                                            }{' '}
                                                                            ·
                                                                            hash{' '}
                                                                            {shortHash(
                                                                                review.reviewedContentHash,
                                                                            )}
                                                                        </p>
                                                                    ),
                                                                )}
                                                            </div>
                                                        )}
                                                    </article>
                                                ),
                                            )}
                                        </div>
                                    )}
                                </section>
                            </>
                        ) : (
                            <section className="rounded-lg border border-dashed border-border bg-white p-8 text-center">
                                <Stethoscope
                                    className="mx-auto size-9 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <h2 className="mt-3 text-lg font-semibold">
                                    Tidak ada sumber klinis
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Sistem tidak dapat membuat kandidat tanpa
                                    diagnosis atau catatan prosedur yang
                                    dilakukan.
                                </p>
                            </section>
                        )}
                    </div>

                    <aside
                        aria-label="Prasyarat dan provenance koding"
                        className="space-y-5 xl:sticky xl:top-6"
                    >
                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <ClipboardCheck
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Gerbang provenance
                                </h2>
                            </div>
                            <div className="mt-4 space-y-3">
                                <div className="flex items-start gap-3 rounded-md border border-border p-3">
                                    {prerequisites.medicalSourceApproved ? (
                                        <CheckCircle2
                                            className="mt-0.5 size-4 shrink-0 text-emerald-700"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <XCircle
                                            className="mt-0.5 size-4 shrink-0 text-red-700"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <div>
                                        <p className="text-sm font-medium">
                                            Sumber medis disetujui
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            v
                                            {prerequisites.medicalSourceVersion ??
                                                '—'}{' '}
                                            ·{' '}
                                            {shortHash(
                                                prerequisites.medicalSourceHash,
                                            )}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-start gap-3 rounded-md border border-border p-3">
                                    {prerequisites.qualityApproved ? (
                                        <CheckCircle2
                                            className="mt-0.5 size-4 shrink-0 text-emerald-700"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <XCircle
                                            className="mt-0.5 size-4 shrink-0 text-red-700"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <div>
                                        <p className="text-sm font-medium">
                                            Kelengkapan RMIK disetujui
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            v
                                            {prerequisites.qualityReviewVersion ??
                                                '—'}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-start gap-3 rounded-md border border-border p-3">
                                    {prerequisites.closureSourceApproved ? (
                                        <CheckCircle2
                                            className="mt-0.5 size-4 shrink-0 text-emerald-700"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <XCircle
                                            className="mt-0.5 size-4 shrink-0 text-red-700"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <div>
                                        <p className="text-sm font-medium">
                                            Closure prosedur disetujui
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            v
                                            {prerequisites.closureSourceVersion ??
                                                '—'}{' '}
                                            ·{' '}
                                            {shortHash(
                                                prerequisites.closureSourceHash,
                                            )}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            {task && (
                                <div className="mt-4 flex items-center justify-between rounded-md bg-muted px-3 py-2 text-xs">
                                    <span>{task.type}</span>
                                    <Badge variant="outline">
                                        {task.status.label}
                                    </Badge>
                                </div>
                            )}
                        </section>

                        {latestRun && (
                            <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                                <div className="flex items-center gap-2">
                                    <History
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h2 className="text-lg font-semibold">
                                        Run saat ini
                                    </h2>
                                </div>
                                <dl className="mt-4 space-y-3 text-sm">
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Outcome
                                        </dt>
                                        <dd className="font-medium">
                                            {latestRun.outcome.label}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Engine
                                        </dt>
                                        <dd className="font-medium">
                                            {latestRun.engineVersion}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Release
                                        </dt>
                                        <dd className="font-medium">
                                            {latestRun.release.system} ·{' '}
                                            {latestRun.release.logicalVersion}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Input hash
                                        </dt>
                                        <dd className="font-mono text-xs">
                                            {shortHash(
                                                latestRun.normalizedInputHash,
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Dibuat
                                        </dt>
                                        <dd>
                                            {formatDateTime(
                                                latestRun.generatedAt,
                                            )}{' '}
                                            WIB
                                        </dd>
                                    </div>
                                </dl>
                            </section>
                        )}
                    </aside>
                </div>
            </div>
        </>
    );
}
