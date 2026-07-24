import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ClipboardList,
    FileCheck2,
    Fingerprint,
    History,
    LockKeyhole,
    RotateCcw,
    SearchCheck,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, TextareaHTMLAttributes } from 'react';
import { ClinicalVersionStamp } from '@/components/clinical-version-stamp';
import InputError from '@/components/input-error';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type {
    ClinicalFinding,
    RecordQualityFinding,
    RecordQualityReviewVersion,
    RecordQualityWorkspaceProps,
} from '@/types';

type ReviewForm = {
    request_key: string;
    intent: 'SAVE_DRAFT' | 'SUBMIT';
    findings: Array<{
        code: string;
        severity: 'BLOCKING' | 'NON_BLOCKING' | 'INFORMATIONAL';
        message: string;
        requested_action: string;
    }>;
    resolved_correction_public_ids: string[];
    change_reason: string;
};

type SupervisorForm = {
    request_key: string;
    action: 'APPROVE_SIMULATION' | 'REQUEST_CHANGES';
    comment: string;
    findings: ClinicalFinding[];
};

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

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

function CorrectionRequestButton({
    finding,
}: {
    finding: RecordQualityFinding;
}) {
    const form = useForm({
        request_key: finding.correctionRequestKey,
        reason: finding.requestedAction,
    });
    const errors = form.errors as Record<string, string | undefined>;

    if (!finding.canRequestCorrection) {
        return finding.correctionRequest ? (
            <Badge variant="outline">
                {finding.correctionRequest.status.label}
            </Badge>
        ) : null;
    }

    return (
        <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3">
            <Label htmlFor={`correction-${finding.publicId}`}>
                Alasan permintaan koreksi
            </Label>
            <Textarea
                id={`correction-${finding.publicId}`}
                className="mt-2 min-h-20 bg-white"
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <InputError
                className="mt-2"
                message={errors.reason ?? errors.workflow}
            />
            <Button
                type="button"
                size="sm"
                className="mt-3"
                disabled={form.processing || form.data.reason.trim() === ''}
                onClick={() =>
                    form.post(finding.correctionUrl, { preserveScroll: true })
                }
            >
                <RotateCcw className="size-4" aria-hidden="true" />
                Kirim ke penulis klinis
            </Button>
        </div>
    );
}

function snapshotText(
    snapshot: Record<string, unknown> | null,
    key: string,
): string {
    const value = snapshot?.[key];

    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '—';
}

export function SubmittedRecordQualityContent({
    version,
}: {
    version: RecordQualityReviewVersion;
}) {
    const closureValue = version.content.assemblySnapshot.closure;
    const closureSnapshot =
        typeof closureValue === 'object' && closureValue !== null
            ? (closureValue as Record<string, unknown>)
            : null;
    const submittedCompleteness = version.content.completeness;

    return (
        <section
            aria-labelledby="submitted-record-quality-title"
            className="overflow-hidden rounded-lg border border-sky-200 bg-sky-50/40"
        >
            <div className="border-b border-sky-200 bg-sky-50 px-4 py-3">
                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                    Snapshot tidak dapat diedit
                </p>
                <h3
                    id="submitted-record-quality-title"
                    className="mt-1 text-lg font-semibold"
                >
                    Isi telaah RMIK yang diajukan
                </h3>
                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                    Keputusan supervisor terikat pada checklist, sumber, temuan,
                    dan hash versi ini. Checklist di bawah adalah snapshot saat
                    koder mengajukan telaah, bukan hasil hitung ulang layar.
                </p>
            </div>

            <div className="space-y-5 p-4">
                <dl className="grid gap-3 text-sm sm:grid-cols-3">
                    <div className="rounded-md border border-border bg-white p-3">
                        <dt className="text-xs text-muted-foreground">
                            Penelaah RMIK
                        </dt>
                        <dd className="mt-1 font-semibold">
                            {version.reviewer}
                        </dd>
                        <dd className="text-xs text-muted-foreground">
                            {version.reviewerRole}
                        </dd>
                    </div>
                    <div className="rounded-md border border-border bg-white p-3">
                        <dt className="text-xs text-muted-foreground">
                            Direkam
                        </dt>
                        <dd className="mt-1 font-semibold">
                            {formatDateTime(version.recordedAt)} WIB
                        </dd>
                    </div>
                    <div className="rounded-md border border-border bg-white p-3">
                        <dt className="text-xs text-muted-foreground">
                            Diajukan
                        </dt>
                        <dd className="mt-1 font-semibold">
                            {version.submittedAt
                                ? `${formatDateTime(version.submittedAt)} WIB`
                                : 'Belum diajukan'}
                        </dd>
                    </div>
                </dl>

                <section
                    aria-labelledby="submitted-checklist-title"
                    className="rounded-md border border-border bg-white p-4"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h4
                            id="submitted-checklist-title"
                            className="font-semibold"
                        >
                            Checklist tersimpan
                        </h4>
                        <div className="flex flex-wrap gap-2">
                            <Badge variant="outline">
                                {submittedCompleteness.checklistVersion}
                            </Badge>
                            <Badge
                                variant="outline"
                                className={cn(
                                    submittedCompleteness.ready
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                                        : 'border-red-200 bg-red-50 text-red-900',
                                )}
                            >
                                {submittedCompleteness.ready
                                    ? 'Siap saat diajukan'
                                    : 'Tidak siap saat diajukan'}
                            </Badge>
                        </div>
                    </div>
                    <ol className="mt-3 space-y-2">
                        {submittedCompleteness.checks.map((check) => (
                            <li
                                key={check.code}
                                className={cn(
                                    'rounded-md border p-3',
                                    check.passed
                                        ? 'border-emerald-200 bg-emerald-50'
                                        : 'border-red-200 bg-red-50',
                                )}
                            >
                                <div className="flex items-start gap-2">
                                    {check.passed ? (
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
                                        <p className="text-sm font-semibold">
                                            {check.label}
                                        </p>
                                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                            {check.detail}
                                        </p>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ol>
                </section>

                <section
                    aria-labelledby="submitted-source-binding-title"
                    className="rounded-md border border-border bg-white p-4"
                >
                    <div className="flex items-center gap-2">
                        <Fingerprint
                            className="size-4 text-primary"
                            aria-hidden="true"
                        />
                        <h4
                            id="submitted-source-binding-title"
                            className="font-semibold"
                        >
                            Ikatan sumber saat diajukan
                        </h4>
                    </div>
                    {closureSnapshot ? (
                        <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Closure
                                </dt>
                                <dd className="mt-1 font-semibold">
                                    v
                                    {snapshotText(
                                        closureSnapshot,
                                        'versionNumber',
                                    )}{' '}
                                    · {snapshotText(closureSnapshot, 'status')}
                                </dd>
                                <dd className="mt-1 font-mono text-[0.68rem] break-all text-muted-foreground">
                                    {snapshotText(closureSnapshot, 'publicId')}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    SHA-256 closure
                                </dt>
                                <dd className="mt-1 font-mono text-[0.68rem] break-all">
                                    {snapshotText(
                                        closureSnapshot,
                                        'contentHash',
                                    )}
                                </dd>
                            </div>
                        </dl>
                    ) : (
                        <p role="alert" className="mt-3 text-sm text-red-700">
                            Snapshot closure tidak tersedia pada versi ini.
                        </p>
                    )}
                </section>

                <section
                    aria-labelledby="submitted-findings-title"
                    className="rounded-md border border-border bg-white p-4"
                >
                    <h4 id="submitted-findings-title" className="font-semibold">
                        Temuan manual tersimpan
                    </h4>
                    {version.findings.length === 0 ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            Tidak ada temuan manual pada versi yang diajukan.
                        </p>
                    ) : (
                        <ol className="mt-3 space-y-2">
                            {version.findings.map((finding) => (
                                <li
                                    key={finding.publicId}
                                    className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="outline">
                                            {finding.code}
                                        </Badge>
                                        <Badge variant="outline">
                                            {finding.severity.label}
                                        </Badge>
                                    </div>
                                    <p className="mt-2 font-medium">
                                        {finding.message}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Tindakan: {finding.requestedAction}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>

                {version.content.resolvedCorrections.length > 0 && (
                    <section
                        aria-labelledby="submitted-corrections-title"
                        className="rounded-md border border-border bg-white p-4"
                    >
                        <h4
                            id="submitted-corrections-title"
                            className="font-semibold"
                        >
                            Koreksi yang diverifikasi
                        </h4>
                        <ol className="mt-3 space-y-2">
                            {version.content.resolvedCorrections.map(
                                (correction) => (
                                    <li
                                        key={
                                            correction.correctionRequestPublicId
                                        }
                                        className="rounded-md border border-border p-3 text-sm"
                                    >
                                        <p className="font-semibold">
                                            {correction.status}
                                        </p>
                                        <p className="mt-1 font-mono text-[0.68rem] break-all text-muted-foreground">
                                            {correction.priorSourceHash} →{' '}
                                            {
                                                correction.responseClosureContentHash
                                            }
                                        </p>
                                    </li>
                                ),
                            )}
                        </ol>
                    </section>
                )}
            </div>
        </section>
    );
}

export default function RecordQualityWorkspace({
    encounter,
    patient,
    session,
    assignment,
    task,
    completeness,
    assembly,
    currentClosure,
    document,
    codingCorrection,
    corrections,
    formOptions,
    urls,
}: RecordQualityWorkspaceProps) {
    const form = useForm<ReviewForm>({
        request_key: formOptions.requestKey,
        intent: 'SAVE_DRAFT',
        findings: [],
        resolved_correction_public_ids: corrections
            .filter((correction) => correction.resolvable)
            .map((correction) => correction.publicId),
        change_reason: '',
    });
    const supervisorForm = useForm<SupervisorForm>({
        request_key: formOptions.reviewRequestKey,
        action: 'REQUEST_CHANGES',
        comment: '',
        findings: [],
    });
    const [finding, setFinding] = useState<ReviewForm['findings'][number]>({
        code: 'DOCUMENTATION_CLARITY',
        severity: 'BLOCKING',
        message: '',
        requested_action: '',
    });
    const [supervisorFinding, setSupervisorFinding] = useState<ClinicalFinding>(
        {
            code: 'RMIK_REVIEW_QUALITY',
            severity: 'BLOCKING',
            message: '',
        },
    );
    const errors = form.errors as Record<string, string | undefined>;
    const supervisorErrors = supervisorForm.errors as Record<
        string,
        string | undefined
    >;

    function submitReview(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const submitter = (event.nativeEvent as SubmitEvent)
            .submitter as HTMLButtonElement | null;
        const intent = submitter?.value === 'SUBMIT' ? 'SUBMIT' : 'SAVE_DRAFT';
        const hasFinding =
            finding.message.trim() !== '' ||
            finding.requested_action.trim() !== '';

        form.transform((data) => ({
            ...data,
            intent,
            findings: hasFinding ? [finding] : [],
        }));
        form.post(urls.store, { preserveScroll: true });
    }

    function submitSupervisorDecision(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!urls.review) {
            return;
        }

        const submitter = (event.nativeEvent as SubmitEvent)
            .submitter as HTMLButtonElement | null;
        const action =
            submitter?.value === 'APPROVE_SIMULATION'
                ? 'APPROVE_SIMULATION'
                : 'REQUEST_CHANGES';
        const findings =
            action === 'REQUEST_CHANGES' &&
            supervisorFinding.message.trim() !== ''
                ? [supervisorFinding]
                : [];

        supervisorForm.transform((data) => ({ ...data, action, findings }));
        supervisorForm.post(urls.review, { preserveScroll: true });
    }

    return (
        <>
            <Head title={`Kelengkapan RMIK ${encounter.number}`} />

            <div className="mx-auto flex w-full max-w-[1540px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Kelengkapan & koding RMIK · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Workbench mutu rekam medis
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Checklist dapat direproduksi dari versi dan hash.
                            Temuan merutekan koreksi kepada penulis; RMIK tidak
                            dapat menimpa dokumentasi klinis.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={urls.closure}>
                                <FileCheck2
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Penutupan klinis
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
                    className="rounded-lg border border-orange-200 bg-orange-50 px-5 py-4"
                    aria-labelledby="rmik-boundary-title"
                >
                    <div className="flex gap-3">
                        <ShieldCheck
                            className="mt-0.5 size-5 shrink-0 text-orange-800"
                            aria-hidden="true"
                        />
                        <div>
                            <h2
                                id="rmik-boundary-title"
                                className="font-semibold text-orange-950"
                            >
                                Pemisahan sumber klinis, mutu rekam, dan kode
                            </h2>
                            <p className="mt-1 text-sm leading-6 text-orange-950">
                                Diagnosis tetap berasal dari klinisi. Checklist
                                menemukan kelengkapan deterministik; temuan
                                manual harus dapat ditelusuri. Kandidat ICD baru
                                dapat dibuka setelah telaah RMIK disetujui dan
                                tetap selalu memerlukan keputusan koder.
                            </p>
                        </div>
                    </div>
                </section>

                <div className="grid items-start gap-5 xl:grid-cols-[22rem_minmax(0,1fr)_25rem]">
                    <aside className="space-y-5 xl:sticky xl:top-6">
                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <ClipboardList
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h2 className="text-lg font-semibold">
                                        Susunan rekam
                                    </h2>
                                </div>
                                <Badge variant="outline">
                                    {completeness.checklistVersion}
                                </Badge>
                            </div>
                            <div className="mt-4 space-y-2">
                                {completeness.checks.map((check) => (
                                    <div
                                        key={check.code}
                                        className={cn(
                                            'rounded-md border p-3',
                                            check.passed
                                                ? 'border-emerald-200 bg-emerald-50'
                                                : 'border-red-200 bg-red-50',
                                        )}
                                    >
                                        <div className="flex items-start gap-2">
                                            {check.passed ? (
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
                                                <p className="text-sm font-semibold">
                                                    {check.label}
                                                </p>
                                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                                    {check.detail}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <History
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Riwayat telaah
                                </h2>
                            </div>
                            {document.history.length === 0 ? (
                                <p className="mt-4 rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">
                                    Belum ada telaah RMIK.
                                </p>
                            ) : (
                                <ol className="mt-4 space-y-4">
                                    {document.history.map((review) => (
                                        <li key={review.publicId}>
                                            <ClinicalVersionStamp
                                                compact
                                                versionNumber={
                                                    review.versionNumber
                                                }
                                                schemaVersion={
                                                    review.checklistVersion
                                                }
                                                contentHash={review.contentHash}
                                                status={review.status}
                                            />
                                            <p className="mt-2 px-1 text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    review.recordedAt,
                                                )}{' '}
                                                WIB · {review.reviewer}
                                            </p>
                                            {review.changeReason && (
                                                <p className="mt-1 rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">
                                                    {review.changeReason}
                                                </p>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>
                    </aside>

                    <div className="space-y-5">
                        <section
                            className="clinical-shadow rounded-lg border border-border bg-white p-5"
                            aria-labelledby="selected-source-title"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                        Sumber terpilih · tidak dapat diedit
                                    </p>
                                    <h2
                                        id="selected-source-title"
                                        className="mt-1 text-xl font-semibold"
                                    >
                                        Penutupan dan diagnosis sumber
                                    </h2>
                                </div>
                                {currentClosure && (
                                    <Badge variant="outline">
                                        Closure v{currentClosure.versionNumber}
                                    </Badge>
                                )}
                            </div>

                            {assembly.closure && currentClosure ? (
                                <div className="mt-4 space-y-4">
                                    <ClinicalVersionStamp
                                        versionNumber={
                                            assembly.closure.versionNumber
                                        }
                                        schemaVersion={
                                            assembly.closure.schemaVersion
                                        }
                                        contentHash={
                                            assembly.closure.contentHash
                                        }
                                        status={{
                                            code: currentClosure.status.code as
                                                | 'DRAFT'
                                                | 'SUBMITTED'
                                                | 'CHANGES_REQUESTED'
                                                | 'APPROVED',
                                            label: currentClosure.status.label,
                                        }}
                                    />
                                    <div className="rounded-md border border-border p-4">
                                        <p className="text-sm font-semibold">
                                            Pernyataan diagnosis klinisi
                                        </p>
                                        <ol className="mt-3 space-y-2">
                                            {assembly.clinicalSources.diagnoses.map(
                                                (diagnosis) => (
                                                    <li
                                                        key={diagnosis.publicId}
                                                        className="rounded border border-border bg-muted/20 p-3"
                                                    >
                                                        <p className="text-sm font-medium">
                                                            {
                                                                diagnosis.authoredText
                                                            }
                                                        </p>
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {diagnosis.role} ·{' '}
                                                            {
                                                                diagnosis.certainty
                                                            }{' '}
                                                            · belum menjadi
                                                            assignment kode
                                                        </p>
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    </div>
                                </div>
                            ) : (
                                <p className="mt-4 text-sm text-red-700">
                                    Versi penutupan saat ini belum tersedia.
                                </p>
                            )}
                        </section>

                        {document.canAuthor && (
                            <form
                                onSubmit={submitReview}
                                className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                                    <div>
                                        <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                            Telaah versi berikutnya
                                        </p>
                                        <h2 className="mt-1 text-xl font-semibold">
                                            Temuan dan resolusi RMIK
                                        </h2>
                                    </div>
                                    {task && (
                                        <Badge variant="outline">
                                            Tugas: {task.status.label}
                                        </Badge>
                                    )}
                                </div>
                                {codingCorrection && (
                                    <div
                                        role="alert"
                                        className="border-b border-sky-200 bg-sky-50 px-5 py-4 text-sm leading-6 text-sky-950"
                                    >
                                        <p className="font-semibold">
                                            Telaah ulang wajib setelah koreksi
                                            dokumentasi{' '}
                                            {codingCorrection.sourceType ===
                                            'PROCEDURE'
                                                ? 'prosedur'
                                                : 'diagnosis'}{' '}
                                            untuk koding
                                        </p>
                                        <p className="mt-1">
                                            Permintaan: “
                                            {codingCorrection.reason}”.
                                            Verifikasi closure dan sumber{' '}
                                            {codingCorrection.sourceType ===
                                            'PROCEDURE'
                                                ? 'tindakan'
                                                : 'medis'}{' '}
                                            penerus sebelum mengajukan versi
                                            RMIK baru; persetujuan lama tidak
                                            lagi dianggap current.
                                        </p>
                                    </div>
                                )}
                                <div className="space-y-5 p-5">
                                    {document.requiresChangeReason && (
                                        <div className="rounded-md border border-amber-200 bg-amber-50 p-4">
                                            <Label htmlFor="quality-change-reason">
                                                Alasan perubahan telaah *
                                            </Label>
                                            <Textarea
                                                id="quality-change-reason"
                                                className="mt-2 bg-white"
                                                value={form.data.change_reason}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'change_reason',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={errors.change_reason}
                                            />
                                        </div>
                                    )}

                                    <div className="rounded-md border border-border p-4">
                                        <h3 className="font-semibold">
                                            Temuan manual opsional
                                        </h3>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Temuan selalu menunjuk closure
                                            versi/hash saat ini dan penulis yang
                                            bertanggung jawab.
                                        </p>
                                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <Label htmlFor="finding-code">
                                                    Kode temuan
                                                </Label>
                                                <Input
                                                    id="finding-code"
                                                    className="mt-2"
                                                    value={finding.code}
                                                    onChange={(event) =>
                                                        setFinding(
                                                            (current) => ({
                                                                ...current,
                                                                code: event
                                                                    .target
                                                                    .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="finding-severity">
                                                    Severity
                                                </Label>
                                                <select
                                                    id="finding-severity"
                                                    className="mt-2 h-9 w-full rounded-md border border-input bg-white px-3 text-sm"
                                                    value={finding.severity}
                                                    onChange={(event) =>
                                                        setFinding(
                                                            (current) => ({
                                                                ...current,
                                                                severity: event
                                                                    .target
                                                                    .value as ReviewForm['findings'][number]['severity'],
                                                            }),
                                                        )
                                                    }
                                                >
                                                    {formOptions.findingSeverities.map(
                                                        (severity) => (
                                                            <option
                                                                key={
                                                                    severity.code
                                                                }
                                                                value={
                                                                    severity.code
                                                                }
                                                            >
                                                                {severity.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <div className="sm:col-span-2">
                                                <Label htmlFor="finding-message">
                                                    Uraian temuan
                                                </Label>
                                                <Textarea
                                                    id="finding-message"
                                                    className="mt-2"
                                                    value={finding.message}
                                                    onChange={(event) =>
                                                        setFinding(
                                                            (current) => ({
                                                                ...current,
                                                                message:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="sm:col-span-2">
                                                <Label htmlFor="requested-action">
                                                    Tindakan yang diminta
                                                </Label>
                                                <Textarea
                                                    id="requested-action"
                                                    className="mt-2"
                                                    value={
                                                        finding.requested_action
                                                    }
                                                    onChange={(event) =>
                                                        setFinding(
                                                            (current) => ({
                                                                ...current,
                                                                requested_action:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    {corrections.filter(
                                        (correction) => correction.resolvable,
                                    ).length > 0 && (
                                        <div className="rounded-md border border-emerald-200 bg-emerald-50 p-4">
                                            <h3 className="font-semibold text-emerald-950">
                                                Verifikasi koreksi yang
                                                disetujui
                                            </h3>
                                            <div className="mt-3 space-y-3">
                                                {corrections
                                                    .filter(
                                                        (correction) =>
                                                            correction.resolvable,
                                                    )
                                                    .map((correction) => (
                                                        <label
                                                            key={
                                                                correction.publicId
                                                            }
                                                            className="flex items-start gap-3 rounded border border-emerald-200 bg-white p-3 text-sm"
                                                        >
                                                            <Checkbox
                                                                checked={form.data.resolved_correction_public_ids.includes(
                                                                    correction.publicId,
                                                                )}
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    form.setData(
                                                                        'resolved_correction_public_ids',
                                                                        checked
                                                                            ? [
                                                                                  ...form
                                                                                      .data
                                                                                      .resolved_correction_public_ids,
                                                                                  correction.publicId,
                                                                              ]
                                                                            : form.data.resolved_correction_public_ids.filter(
                                                                                  (
                                                                                      id,
                                                                                  ) =>
                                                                                      id !==
                                                                                      correction.publicId,
                                                                              ),
                                                                    )
                                                                }
                                                            />
                                                            <span>
                                                                <strong>
                                                                    {
                                                                        correction.findingCode
                                                                    }
                                                                </strong>{' '}
                                                                · closure v
                                                                {
                                                                    correction.responseClosureVersion
                                                                }
                                                                <br />
                                                                <span className="text-xs text-muted-foreground">
                                                                    {shortHash(
                                                                        correction.requestedSourceHash,
                                                                    )}{' '}
                                                                    →{' '}
                                                                    {shortHash(
                                                                        correction.responseContentHash,
                                                                    )}
                                                                </span>
                                                            </span>
                                                        </label>
                                                    ))}
                                            </div>
                                        </div>
                                    )}

                                    <InputError
                                        message={
                                            errors.findings ??
                                            errors.resolved_correction_public_ids ??
                                            errors.workflow
                                        }
                                    />
                                    <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-5">
                                        <Button
                                            type="submit"
                                            name="intent"
                                            value="SAVE_DRAFT"
                                            variant="outline"
                                            disabled={form.processing}
                                        >
                                            Simpan telaah
                                        </Button>
                                        <Button
                                            type="submit"
                                            name="intent"
                                            value="SUBMIT"
                                            disabled={
                                                form.processing ||
                                                !completeness.ready ||
                                                (finding.severity ===
                                                    'BLOCKING' &&
                                                    finding.message.trim() !==
                                                        '')
                                            }
                                        >
                                            Ajukan telaah RMIK
                                        </Button>
                                    </div>
                                </div>
                            </form>
                        )}

                        {document.latestVersion &&
                            document.latestVersion.findings.length > 0 && (
                                <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                                    <h2 className="text-lg font-semibold">
                                        Temuan versi{' '}
                                        {document.latestVersion.versionNumber}
                                    </h2>
                                    <div className="mt-4 space-y-4">
                                        {document.latestVersion.findings.map(
                                            (item) => (
                                                <article
                                                    key={item.publicId}
                                                    className="rounded-md border border-amber-200 bg-amber-50 p-4"
                                                >
                                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                                        <div>
                                                            <p className="text-xs font-bold text-amber-800 uppercase">
                                                                {item.code} ·{' '}
                                                                {
                                                                    item
                                                                        .severity
                                                                        .label
                                                                }
                                                            </p>
                                                            <h3 className="mt-1 font-semibold text-amber-950">
                                                                {item.message}
                                                            </h3>
                                                            <p className="mt-1 text-sm text-amber-950">
                                                                Diminta:{' '}
                                                                {
                                                                    item.requestedAction
                                                                }
                                                            </p>
                                                            <p className="mt-2 text-xs text-amber-800">
                                                                Penulis:{' '}
                                                                {
                                                                    item.responsibleAuthor
                                                                }{' '}
                                                                · closure v
                                                                {
                                                                    item.affectedVersionNumber
                                                                }{' '}
                                                                ·{' '}
                                                                {shortHash(
                                                                    item.affectedContentHash,
                                                                )}
                                                            </p>
                                                        </div>
                                                        <CorrectionRequestButton
                                                            finding={item}
                                                        />
                                                    </div>
                                                </article>
                                            ),
                                        )}
                                    </div>
                                </section>
                            )}

                        {!document.canAuthor && !document.canReview && (
                            <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                                <div className="flex gap-3">
                                    <LockKeyhole
                                        className="mt-0.5 size-5 shrink-0 text-primary"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <h2 className="font-semibold">
                                            Workbench terkunci pada tahap ini
                                        </h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Tunggu koreksi klinis, keputusan
                                            supervisor, atau tugas RMIK
                                            berikutnya.
                                        </p>
                                    </div>
                                </div>
                            </section>
                        )}

                        {document.canReview &&
                            document.latestVersion &&
                            urls.review && (
                                <form
                                    onSubmit={submitSupervisorDecision}
                                    className="clinical-shadow overflow-hidden rounded-lg border border-violet-200 bg-white"
                                >
                                    <div className="border-b border-violet-200 bg-violet-50 px-5 py-4">
                                        <p className="text-xs font-bold tracking-wider text-violet-700 uppercase">
                                            Supervisor RMIK terhubung
                                        </p>
                                        <h2 className="mt-1 text-xl font-semibold text-violet-950">
                                            Tinjau telaah v
                                            {
                                                document.latestVersion
                                                    .versionNumber
                                            }
                                        </h2>
                                    </div>
                                    <div className="space-y-5 p-5">
                                        <ClinicalVersionStamp
                                            versionNumber={
                                                document.latestVersion
                                                    .versionNumber
                                            }
                                            schemaVersion={
                                                document.latestVersion
                                                    .checklistVersion
                                            }
                                            contentHash={
                                                document.latestVersion
                                                    .contentHash
                                            }
                                            status={
                                                document.latestVersion.status
                                            }
                                        />
                                        <SubmittedRecordQualityContent
                                            version={document.latestVersion}
                                        />
                                        <div>
                                            <Label htmlFor="supervisor-comment">
                                                Komentar
                                            </Label>
                                            <Textarea
                                                id="supervisor-comment"
                                                className="mt-2"
                                                value={
                                                    supervisorForm.data.comment
                                                }
                                                onChange={(event) =>
                                                    supervisorForm.setData(
                                                        'comment',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="rounded-md border border-amber-200 bg-amber-50 p-4">
                                            <Label htmlFor="supervisor-finding">
                                                Temuan bila meminta perbaikan
                                            </Label>
                                            <Textarea
                                                id="supervisor-finding"
                                                className="mt-2 bg-white"
                                                value={
                                                    supervisorFinding.message
                                                }
                                                onChange={(event) =>
                                                    setSupervisorFinding(
                                                        (current) => ({
                                                            ...current,
                                                            message:
                                                                event.target
                                                                    .value,
                                                        }),
                                                    )
                                                }
                                            />
                                        </div>
                                        <InputError
                                            message={
                                                supervisorErrors.findings ??
                                                supervisorErrors.workflow
                                            }
                                        />
                                        <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-5">
                                            <Button
                                                type="submit"
                                                name="action"
                                                value="REQUEST_CHANGES"
                                                variant="outline"
                                                disabled={
                                                    supervisorForm.processing ||
                                                    supervisorFinding.message.trim() ===
                                                        ''
                                                }
                                            >
                                                <RotateCcw
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                Minta perbaikan
                                            </Button>
                                            <Button
                                                type="submit"
                                                name="action"
                                                value="APPROVE_SIMULATION"
                                                disabled={
                                                    supervisorForm.processing ||
                                                    !completeness.ready ||
                                                    !document.latestVersion
                                                        .content.completeness
                                                        .ready
                                                }
                                            >
                                                <ShieldCheck
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                Setujui kelengkapan
                                            </Button>
                                        </div>
                                    </div>
                                </form>
                            )}
                    </div>

                    <aside className="space-y-5 xl:sticky xl:top-6">
                        <section className="clinical-shadow rounded-lg border border-sky-200 bg-white p-5">
                            <div className="flex items-center gap-2">
                                <SearchCheck
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <div>
                                    <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                        Koding diagnosis
                                    </p>
                                    <h2 className="text-lg font-semibold">
                                        Koding berbantuan
                                    </h2>
                                </div>
                            </div>
                            <div className="mt-4 rounded-md border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-950">
                                Release tervalidasi menampilkan kandidat dari
                                diagnosis yang ditulis klinisi. Kandidat tidak
                                pernah menjadi kode final tanpa keputusan koder.
                            </div>
                            {assignment.canCode ? (
                                <Button asChild className="mt-3 w-full">
                                    <Link href={urls.coding}>
                                        <Fingerprint
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Buka workbench koding
                                    </Link>
                                </Button>
                            ) : (
                                <Button className="mt-3 w-full" disabled>
                                    <Fingerprint
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Memerlukan peran koder
                                </Button>
                            )}
                        </section>

                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <RotateCcw
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Riwayat koreksi
                                </h2>
                            </div>
                            {corrections.length === 0 ? (
                                <p className="mt-4 text-sm text-muted-foreground">
                                    Belum ada permintaan koreksi.
                                </p>
                            ) : (
                                <div className="mt-4 space-y-3">
                                    {corrections.map((correction) => (
                                        <article
                                            key={correction.publicId}
                                            className="rounded-md border border-border p-3"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="text-xs font-bold text-primary uppercase">
                                                    {correction.findingCode}
                                                </p>
                                                <Badge variant="outline">
                                                    {correction.status.label}
                                                </Badge>
                                            </div>
                                            <p className="mt-2 text-sm font-medium">
                                                {correction.findingMessage}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {correction.responsibleAuthor} ·{' '}
                                                {formatDateTime(
                                                    correction.requestedAt,
                                                )}{' '}
                                                WIB
                                            </p>
                                            {correction.responseClosureVersion && (
                                                <p className="mt-2 text-xs text-emerald-800">
                                                    Respons closure v
                                                    {
                                                        correction.responseClosureVersion
                                                    }{' '}
                                                    ·{' '}
                                                    {shortHash(
                                                        correction.responseContentHash,
                                                    )}
                                                </p>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            )}
                        </section>

                        <section className="rounded-lg border border-violet-200 bg-violet-50 p-5 text-sm leading-6 text-violet-950">
                            <div className="flex gap-2">
                                <AlertTriangle
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <p>
                                    Checklist yang lulus tidak menyatakan mutu
                                    klinis. Ia hanya membuktikan aturan
                                    kelengkapan terkonfigurasi terhadap versi
                                    yang ditampilkan.
                                </p>
                            </div>
                        </section>
                    </aside>
                </div>
            </div>
        </>
    );
}
