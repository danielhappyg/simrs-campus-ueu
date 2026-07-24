import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ClipboardCheck,
    FileCheck2,
    Fingerprint,
    History,
    LockKeyhole,
    Plus,
    RotateCcw,
    ShieldCheck,
    Stethoscope,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, TextareaHTMLAttributes } from 'react';
import { ClinicalDraftFailureAlert } from '@/components/clinical-draft-failure-alert';
import { ClinicalVersionStamp } from '@/components/clinical-version-stamp';
import InputError from '@/components/input-error';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { UnsavedChangesGuard } from '@/components/unsaved-changes-guard';
import {
    dateTimeFormValue,
    dateTimeLocalDisplay,
} from '@/lib/clinical-date-time';
import { clinicalDraftRecoveryOptions } from '@/lib/clinical-draft-recovery';
import type { DraftSaveFailure } from '@/lib/clinical-draft-recovery';
import { cn } from '@/lib/utils';
import type {
    ClinicalFinding,
    EncounterClosureSourceSnapshot,
    EncounterClosureVersion,
    EncounterClosureWorkspaceProps,
} from '@/types';

type ClosureForm = {
    request_key: string;
    intent: 'SAVE_DRAFT' | 'SUBMIT';
    clinical_occurrence_at: string;
    leaving_condition: string;
    disposition: string;
    follow_up_plan: string;
    referral_plan: string;
    education_instructions: string;
    outpatient_summary: string;
    procedure_documentation_state:
        '' | 'NONE_PERFORMED' | 'PROCEDURES_RECORDED';
    procedures: ProcedureForm[];
    change_reason: string;
};

type ProcedureForm = {
    authored_text: string;
    performed_start_at: string;
    performed_end_at: string;
    performer_text: string;
    body_site_text: string;
    outcome_text: string;
    note: string;
    reason_condition_public_id: string;
    based_on_service_request_public_id: string;
};

type ReviewForm = {
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
                'min-h-28 w-full rounded-md border border-input bg-white px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:bg-muted/40 disabled:opacity-70',
                className,
            )}
            {...props}
        />
    );
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Belum tersedia';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

function shortHash(value: string | null): string {
    return value ? `${value.slice(0, 12)}…${value.slice(-8)}` : '—';
}

function emptyProcedure(defaultOccurrenceAt: string): ProcedureForm {
    return {
        authored_text: '',
        performed_start_at: defaultOccurrenceAt,
        performed_end_at: '',
        performer_text: '',
        body_site_text: '',
        outcome_text: '',
        note: '',
        reason_condition_public_id: '',
        based_on_service_request_public_id: '',
    };
}

function ProvenancePanel({
    source,
}: {
    source: EncounterClosureSourceSnapshot;
}) {
    return (
        <section
            aria-labelledby="closure-provenance-title"
            className="clinical-shadow rounded-lg border border-border bg-white p-5"
        >
            <div className="flex items-center gap-2">
                <Fingerprint
                    className="size-5 text-primary"
                    aria-hidden="true"
                />
                <div>
                    <p className="text-xs font-bold tracking-wider text-primary uppercase">
                        Sumber tidak dapat diedit
                    </p>
                    <h2
                        id="closure-provenance-title"
                        className="text-lg font-semibold"
                    >
                        Provenance penutupan
                    </h2>
                </div>
            </div>

            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                {[
                    ['Asesmen awal', source.nursing],
                    ['Asesmen medis', source.medical],
                ].map(([label, version]) => (
                    <div
                        key={label as string}
                        className="rounded-md border border-sky-200 bg-sky-50 p-3"
                    >
                        <p className="text-xs font-bold text-primary uppercase">
                            {label as string}
                        </p>
                        {typeof version === 'object' && version ? (
                            <>
                                <p className="mt-1 text-sm font-semibold">
                                    Versi {version.versionNumber} ·{' '}
                                    {version.status}
                                </p>
                                <code className="mt-1 block text-[0.68rem] break-all text-muted-foreground">
                                    {shortHash(version.contentHash)}
                                </code>
                            </>
                        ) : (
                            <p className="mt-1 text-sm text-red-700">
                                Belum tersedia
                            </p>
                        )}
                    </div>
                ))}
            </div>

            <div className="mt-4 space-y-4">
                <div>
                    <h3 className="text-sm font-semibold">
                        Diagnosis sumber klinisi
                    </h3>
                    {source.diagnoses.length === 0 ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            Belum ada diagnosis sumber.
                        </p>
                    ) : (
                        <ol className="mt-2 space-y-2">
                            {source.diagnoses.map((diagnosis) => (
                                <li
                                    key={diagnosis.publicId}
                                    className="rounded-md border border-border p-3 text-sm"
                                >
                                    <p className="font-medium">
                                        {diagnosis.authoredText}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {diagnosis.role} · {diagnosis.certainty}{' '}
                                        · kode belum ditetapkan RMIK
                                    </p>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>

                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="rounded-md border border-border p-3">
                        <h3 className="text-sm font-semibold">
                            Hasil saat ini
                        </h3>
                        {source.results.length === 0 ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Tidak ada order diagnostik.
                            </p>
                        ) : (
                            source.results.map((result) => (
                                <div
                                    key={result.serviceRequestPublicId}
                                    className="mt-3 border-t border-border pt-3 first:border-0 first:pt-0"
                                >
                                    <p className="text-sm font-medium">
                                        {result.authoredService}
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                        Hasil v{result.resultVersion ?? '—'} ·
                                        ack{' '}
                                        {result.acknowledgementOutcome ??
                                            'belum ada'}
                                    </p>
                                    <code className="text-[0.65rem] text-muted-foreground">
                                        {shortHash(result.resultContentHash)}
                                    </code>
                                </div>
                            ))
                        )}
                    </div>

                    <div className="rounded-md border border-border p-3">
                        <h3 className="text-sm font-semibold">Outcome obat</h3>
                        {source.medications.length === 0 ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Tidak ada permintaan obat.
                            </p>
                        ) : (
                            source.medications.map((medication) => (
                                <div
                                    key={medication.medicationRequestPublicId}
                                    className="mt-3 border-t border-border pt-3 first:border-0 first:pt-0"
                                >
                                    <p className="text-sm font-medium">
                                        {medication.authoredMedication}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Revisi {medication.revisionNumber} ·{' '}
                                        {medication.status} ·{' '}
                                        {medication.dispenseOutcome ??
                                            'tanpa dispense'}
                                    </p>
                                    <code className="text-[0.65rem] text-muted-foreground">
                                        {shortHash(
                                            medication.dispenseContentHash,
                                        )}
                                    </code>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}

function ReadOnlyClosureField({
    label,
    value,
}: {
    label: string;
    value: string | null;
}) {
    return (
        <div className="rounded-md border border-border bg-white p-3">
            <dt className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-2 text-sm leading-6 font-medium whitespace-pre-wrap">
                {value?.trim() || 'Tidak dicatat'}
            </dd>
        </div>
    );
}

export function SubmittedClosureContent({
    version,
}: {
    version: EncounterClosureVersion;
}) {
    const content = version.content;
    const procedureDocumentation = content.procedureDocumentation;

    return (
        <section
            aria-labelledby="submitted-closure-content-title"
            className="overflow-hidden rounded-lg border border-sky-200 bg-sky-50/40"
        >
            <div className="border-b border-sky-200 bg-sky-50 px-4 py-3">
                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                    Konten tidak dapat diedit
                </p>
                <h3
                    id="submitted-closure-content-title"
                    className="mt-1 text-lg font-semibold"
                >
                    Isi versi penutupan yang diajukan
                </h3>
                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                    Putuskan hanya setelah isi klinis, waktu kejadian, dan
                    dokumentasi tindakan di bawah ini sesuai dengan versi dan
                    hash yang ditampilkan.
                </p>
            </div>

            <div className="space-y-5 p-4">
                <dl className="grid gap-3 text-sm sm:grid-cols-3">
                    <div className="rounded-md border border-border bg-white p-3">
                        <dt className="text-xs text-muted-foreground">
                            Penulis
                        </dt>
                        <dd className="mt-1 font-semibold">{version.author}</dd>
                        <dd className="text-xs text-muted-foreground">
                            {version.authorRole}
                        </dd>
                    </div>
                    <div className="rounded-md border border-border bg-white p-3">
                        <dt className="text-xs text-muted-foreground">
                            Kejadian klinis
                        </dt>
                        <dd className="mt-1 font-semibold">
                            {formatDateTime(version.clinicalOccurrenceAt)} WIB
                        </dd>
                    </div>
                    <div className="rounded-md border border-border bg-white p-3">
                        <dt className="text-xs text-muted-foreground">
                            Direkam sistem
                        </dt>
                        <dd className="mt-1 font-semibold">
                            {formatDateTime(version.recordedAt)} WIB
                        </dd>
                    </div>
                </dl>

                <dl className="grid gap-3 md:grid-cols-2">
                    <ReadOnlyClosureField
                        label="Kondisi saat meninggalkan layanan"
                        value={content.authored.leavingCondition}
                    />
                    <ReadOnlyClosureField
                        label="Disposisi"
                        value={content.authored.disposition}
                    />
                    <ReadOnlyClosureField
                        label="Rencana tindak lanjut"
                        value={content.authored.followUpPlan}
                    />
                    <ReadOnlyClosureField
                        label="Rencana rujukan"
                        value={content.authored.referralPlan}
                    />
                    <ReadOnlyClosureField
                        label="Edukasi dan instruksi"
                        value={content.authored.educationInstructions}
                    />
                    <ReadOnlyClosureField
                        label="Ringkasan rawat jalan"
                        value={content.authored.outpatientSummary}
                    />
                </dl>

                <section
                    aria-labelledby="submitted-procedure-title"
                    className="rounded-md border border-border bg-white p-4"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                Attestasi eksplisit
                            </p>
                            <h4
                                id="submitted-procedure-title"
                                className="mt-1 font-semibold"
                            >
                                Tindakan atau prosedur
                            </h4>
                        </div>
                        <Badge variant="outline">
                            {procedureDocumentation.state === 'NONE_PERFORMED'
                                ? 'Tidak ada tindakan dilakukan'
                                : procedureDocumentation.state ===
                                    'PROCEDURES_RECORDED'
                                  ? 'Tindakan tercatat'
                                  : 'Belum dinyatakan'}
                        </Badge>
                    </div>

                    {procedureDocumentation.state === 'NONE_PERFORMED' && (
                        <p className="mt-3 text-sm leading-6 text-muted-foreground">
                            Penulis menyatakan tidak ada tindakan atau prosedur
                            yang dilakukan pada encounter simulasi ini.
                        </p>
                    )}

                    {procedureDocumentation.state === 'PROCEDURES_RECORDED' &&
                        procedureDocumentation.procedures.length === 0 && (
                            <p
                                role="alert"
                                className="mt-3 text-sm text-red-700"
                            >
                                Attestasi menyatakan tindakan tercatat, tetapi
                                tidak ada rincian tindakan pada versi ini.
                            </p>
                        )}

                    {procedureDocumentation.procedures.length > 0 && (
                        <ol className="mt-4 space-y-3">
                            {procedureDocumentation.procedures.map(
                                (procedure) => (
                                    <li
                                        key={procedure.publicId}
                                        className="rounded-md border border-border p-3"
                                    >
                                        <p className="font-semibold">
                                            {procedure.sequenceNumber}.{' '}
                                            {procedure.authoredText}
                                        </p>
                                        <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Waktu dilakukan
                                                </dt>
                                                <dd className="mt-1">
                                                    {formatDateTime(
                                                        procedure.performedStartAt,
                                                    )}{' '}
                                                    WIB
                                                    {procedure.performedEndAt
                                                        ? ` – ${formatDateTime(procedure.performedEndAt)} WIB`
                                                        : ''}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Pelaksana
                                                </dt>
                                                <dd className="mt-1">
                                                    {procedure.performerText}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Lokasi tubuh
                                                </dt>
                                                <dd className="mt-1">
                                                    {procedure.bodySiteText ||
                                                        'Tidak dicatat'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Outcome
                                                </dt>
                                                <dd className="mt-1">
                                                    {procedure.outcomeText ||
                                                        'Tidak dicatat'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Diagnosis alasan
                                                </dt>
                                                <dd className="mt-1 break-all">
                                                    {procedure.reasonConditionPublicId ||
                                                        'Tidak ditautkan'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Order sumber
                                                </dt>
                                                <dd className="mt-1 break-all">
                                                    {procedure.basedOnServiceRequestPublicId ||
                                                        'Tidak ditautkan'}
                                                </dd>
                                            </div>
                                        </dl>
                                        {procedure.note && (
                                            <p className="mt-3 text-sm leading-6 text-muted-foreground">
                                                Catatan: {procedure.note}
                                            </p>
                                        )}
                                        <p className="mt-3 font-mono text-[0.68rem] break-all text-muted-foreground">
                                            Hash tindakan:{' '}
                                            {procedure.contentHash}
                                        </p>
                                    </li>
                                ),
                            )}
                        </ol>
                    )}
                </section>
            </div>
        </section>
    );
}

export default function EncounterClosureWorkspace({
    encounter,
    patient,
    session,
    assignment,
    task,
    correctionRequest,
    codingCorrection,
    procedureCodingCorrection,
    readiness,
    sourceSnapshot,
    document,
    formOptions,
    urls,
}: EncounterClosureWorkspaceProps) {
    const prior = document.latestVersion?.content.authored;
    const priorProcedureDocumentation =
        document.latestVersion?.content.procedureDocumentation;
    const preserveExactProcedureInstants =
        codingCorrection !== null || procedureCodingCorrection !== null;
    const form = useForm<ClosureForm>({
        request_key: formOptions.requestKey,
        intent: 'SAVE_DRAFT',
        clinical_occurrence_at:
            procedureCodingCorrection && document.latestVersion
                ? dateTimeFormValue(
                      document.latestVersion.clinicalOccurrenceAt,
                      true,
                  )
                : formOptions.defaultOccurrenceAt,
        leaving_condition: prior?.leavingCondition ?? '',
        disposition: prior?.disposition ?? '',
        follow_up_plan: prior?.followUpPlan ?? '',
        referral_plan: prior?.referralPlan ?? '',
        education_instructions: prior?.educationInstructions ?? '',
        outpatient_summary: prior?.outpatientSummary ?? '',
        procedure_documentation_state: priorProcedureDocumentation?.state ?? '',
        procedures:
            priorProcedureDocumentation?.procedures.map((procedure) => ({
                authored_text: procedure.authoredText,
                performed_start_at: dateTimeFormValue(
                    procedure.performedStartAt,
                    preserveExactProcedureInstants,
                ),
                performed_end_at: procedure.performedEndAt
                    ? dateTimeFormValue(
                          procedure.performedEndAt,
                          preserveExactProcedureInstants,
                      )
                    : '',
                performer_text: procedure.performerText,
                body_site_text: procedure.bodySiteText ?? '',
                outcome_text: procedure.outcomeText ?? '',
                note: procedure.note ?? '',
                reason_condition_public_id:
                    procedure.reasonConditionPublicId ?? '',
                based_on_service_request_public_id:
                    procedure.basedOnServiceRequestPublicId ?? '',
            })) ?? [],
        change_reason: '',
    });
    const reviewForm = useForm<ReviewForm>({
        request_key: formOptions.reviewRequestKey,
        action: 'REQUEST_CHANGES',
        comment: '',
        findings: [],
    });
    const [finding, setFinding] = useState<ClinicalFinding>({
        code: 'CLOSURE_COMPLETENESS',
        severity: 'BLOCKING',
        message: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [manualSaveFailure, setManualSaveFailure] =
        useState<DraftSaveFailure | null>(null);
    const reviewErrors = reviewForm.errors as Record<
        string,
        string | undefined
    >;
    const authoredFieldsDisabled =
        !document.canAuthor || document.authoredFieldsLocked;

    function submitClosure(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const submitter = (event.nativeEvent as SubmitEvent)
            .submitter as HTMLButtonElement | null;
        const intent = submitter?.value === 'SUBMIT' ? 'SUBMIT' : 'SAVE_DRAFT';

        setManualSaveFailure(null);
        form.transform((data) => ({ ...data, intent }));
        form.post(urls.store, {
            preserveScroll: true,
            ...clinicalDraftRecoveryOptions(setManualSaveFailure),
            onSuccess: () => setManualSaveFailure(null),
        });
    }

    function saveDraftAndContinue(
        continueNavigation: () => void,
        reportFailure: (failure?: DraftSaveFailure) => void,
    ) {
        form.transform((data) => ({ ...data, intent: 'SAVE_DRAFT' }));
        form.post(urls.store, {
            preserveScroll: true,
            onSuccess: continueNavigation,
            ...clinicalDraftRecoveryOptions(reportFailure),
        });
    }

    function submitReview(event: FormEvent<HTMLFormElement>) {
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
            action === 'REQUEST_CHANGES' && finding.message.trim() !== ''
                ? [finding]
                : [];
        reviewForm.transform((data) => ({ ...data, action, findings }));
        reviewForm.post(urls.review, { preserveScroll: true });
    }

    function addProcedure() {
        form.setData('procedures', [
            ...form.data.procedures,
            emptyProcedure(formOptions.defaultOccurrenceAt),
        ]);
    }

    function updateProcedure(
        index: number,
        field: keyof ProcedureForm,
        value: string,
    ) {
        form.setData(
            'procedures',
            form.data.procedures.map((procedure, procedureIndex) =>
                procedureIndex === index
                    ? { ...procedure, [field]: value }
                    : procedure,
            ),
        );
    }

    function removeProcedure(index: number) {
        form.setData(
            'procedures',
            form.data.procedures.filter(
                (_, procedureIndex) => procedureIndex !== index,
            ),
        );
    }

    return (
        <>
            <Head title={`Penutupan ${encounter.number}`} />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Penutupan encounter · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Ringkasan dan disposisi rawat jalan
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Readiness dihitung dari status dan provenance. Isi
                            klinis tetap ditulis manusia, disimpan sebagai versi
                            baru, lalu ditinjau supervisor yang terhubung.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={urls.encounter}>
                                <ClipboardCheck
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

                {!authoredFieldsDisabled && form.isDirty && (
                    <UnsavedChangesGuard
                        formLabel="penutupan encounter"
                        processing={form.processing}
                        onSaveDraft={saveDraftAndContinue}
                    />
                )}

                {!authoredFieldsDisabled &&
                    form.isDirty &&
                    manualSaveFailure && (
                        <ClinicalDraftFailureAlert
                            failure={manualSaveFailure}
                            className="clinical-shadow"
                        />
                    )}

                <section
                    className="rounded-lg border border-violet-200 bg-violet-50 px-5 py-4"
                    aria-labelledby="closure-boundary-title"
                >
                    <div className="flex gap-3">
                        <ShieldCheck
                            className="mt-0.5 size-5 shrink-0 text-violet-700"
                            aria-hidden="true"
                        />
                        <div>
                            <h2
                                id="closure-boundary-title"
                                className="font-semibold text-violet-950"
                            >
                                Persetujuan penutupan hanya berlaku untuk
                                simulasi
                            </h2>
                            <p className="mt-1 text-sm leading-6 text-violet-950">
                                Sistem memeriksa kelengkapan deterministik,
                                bukan menentukan kondisi pulang. Penulis dan
                                supervisor tetap bertanggung jawab atas teks,
                                keputusan, dan versi yang dipilih.
                            </p>
                        </div>
                    </div>
                </section>

                {correctionRequest && (
                    <section
                        aria-labelledby="rmik-correction-title"
                        className="rounded-lg border border-amber-300 bg-amber-50 px-5 py-4"
                    >
                        <div className="flex gap-3">
                            <RotateCcw
                                className="mt-0.5 size-5 shrink-0 text-amber-800"
                                aria-hidden="true"
                            />
                            <div>
                                <p className="text-xs font-bold tracking-wider text-amber-800 uppercase">
                                    Temuan RMIK ·{' '}
                                    {correctionRequest.status.label}
                                </p>
                                <h2
                                    id="rmik-correction-title"
                                    className="mt-1 font-semibold text-amber-950"
                                >
                                    {correctionRequest.finding.message}
                                </h2>
                                <p className="mt-1 text-sm leading-6 text-amber-950">
                                    Tindakan diminta:{' '}
                                    {correctionRequest.finding.requestedAction}.
                                    Buat versi penerus dan jelaskan alasan
                                    perubahan; versi yang ditinjau RMIK tetap
                                    utuh.
                                </p>
                                <code className="mt-2 block text-[0.68rem] text-amber-800">
                                    Sumber lama{' '}
                                    {shortHash(
                                        correctionRequest.requestedSourceHash,
                                    )}
                                </code>
                            </div>
                        </div>
                    </section>
                )}

                {codingCorrection && (
                    <section
                        role="alert"
                        aria-labelledby="coding-closure-correction-title"
                        className="rounded-lg border border-sky-300 bg-sky-50 px-5 py-4"
                    >
                        <div className="flex gap-3">
                            <RotateCcw
                                className="mt-0.5 size-5 shrink-0 text-sky-800"
                                aria-hidden="true"
                            />
                            <div>
                                <p className="text-xs font-bold tracking-wider text-sky-800 uppercase">
                                    Koreksi sumber koding ·{' '}
                                    {codingCorrection.status.label}
                                </p>
                                <h2
                                    id="coding-closure-correction-title"
                                    className="mt-1 font-semibold text-sky-950"
                                >
                                    Perbarui penutupan untuk versi medis penerus
                                </h2>
                                <p className="mt-1 text-sm leading-6 text-sky-950">
                                    Amendemen medis v
                                    {
                                        codingCorrection.responseMedicalVersionNumber
                                    }{' '}
                                    telah disetujui. Buat closure penerus agar
                                    ringkasan dan provenance ikut berubah
                                    sebelum telaah ulang RMIK.
                                </p>
                                <code className="mt-2 block text-[0.68rem] text-sky-800">
                                    Hash medis penerus{' '}
                                    {shortHash(
                                        codingCorrection.responseMedicalContentHash ??
                                            '',
                                    )}
                                </code>
                            </div>
                        </div>
                    </section>
                )}

                {procedureCodingCorrection && (
                    <section
                        role="alert"
                        aria-labelledby="procedure-correction-title"
                        className="rounded-lg border border-sky-300 bg-sky-50 px-5 py-4"
                    >
                        <div className="flex gap-3">
                            <RotateCcw
                                className="mt-0.5 size-5 shrink-0 text-sky-800"
                                aria-hidden="true"
                            />
                            <div>
                                <p className="text-xs font-bold tracking-wider text-sky-800 uppercase">
                                    Koreksi sumber prosedur ·{' '}
                                    {procedureCodingCorrection.status.label}
                                </p>
                                <h2
                                    id="procedure-correction-title"
                                    className="mt-1 font-semibold text-sky-950"
                                >
                                    Perbaiki dokumentasi tindakan yang telah
                                    dilakukan
                                </h2>
                                <p className="mt-1 text-sm leading-6 text-sky-950">
                                    Permintaan{' '}
                                    {procedureCodingCorrection.requestedBy}: “
                                    {procedureCodingCorrection.reason}”. Hanya
                                    bagian prosedur yang dapat diubah; ringkasan
                                    penutupan dan waktu klinis tetap terkunci
                                    pada versi sumber.
                                </p>
                                <p className="mt-2 text-sm font-medium text-sky-950">
                                    Sumber:{' '}
                                    {procedureCodingCorrection.sourceStatement}
                                </p>
                                <code className="mt-2 block text-[0.68rem] text-sky-800">
                                    Prosedur{' '}
                                    {shortHash(
                                        procedureCodingCorrection.requestedSourceHash,
                                    )}{' '}
                                    · closure{' '}
                                    {shortHash(
                                        procedureCodingCorrection.requestedClosureHash,
                                    )}
                                </code>
                            </div>
                        </div>
                    </section>
                )}

                <section
                    aria-labelledby="readiness-title"
                    className="clinical-shadow rounded-lg border border-border bg-white p-5"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            {readiness.ready ? (
                                <CheckCircle2
                                    className="size-5 text-emerald-700"
                                    aria-hidden="true"
                                />
                            ) : (
                                <AlertTriangle
                                    className="size-5 text-amber-700"
                                    aria-hidden="true"
                                />
                            )}
                            <div>
                                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                    Gerbang deterministik
                                </p>
                                <h2
                                    id="readiness-title"
                                    className="text-lg font-semibold"
                                >
                                    Readiness penutupan
                                </h2>
                            </div>
                        </div>
                        <Badge
                            className={
                                readiness.ready
                                    ? 'bg-emerald-700 text-white'
                                    : 'bg-amber-700 text-white'
                            }
                        >
                            {readiness.ready
                                ? 'SIAP DIAJUKAN'
                                : 'MASIH DIBLOKIR'}
                        </Badge>
                    </div>
                    <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {readiness.checks.map((check) => (
                            <article
                                key={check.code}
                                className={cn(
                                    'rounded-md border p-4',
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
                                        <h3 className="text-sm font-semibold">
                                            {check.label}
                                        </h3>
                                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                            {check.detail}
                                        </p>
                                        {check.evidence
                                            .filter(
                                                (item) =>
                                                    item.status &&
                                                    item.status !==
                                                        'COMPLETE' &&
                                                    item.status !==
                                                        'APPROVED' &&
                                                    item.status !==
                                                        'RESOLVED' &&
                                                    item.status !== 'COMPLETED',
                                            )
                                            .map((item, index) => (
                                                <p
                                                    key={`${check.code}-${index}`}
                                                    className="mt-1 text-xs font-medium text-red-800"
                                                >
                                                    {item.label ??
                                                        item.publicId ??
                                                        'Bukti'}{' '}
                                                    · {item.status}
                                                </p>
                                            ))}
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                </section>

                <div className="grid items-start gap-5 xl:grid-cols-[24rem_minmax(0,1fr)]">
                    <aside className="space-y-5 xl:sticky xl:top-6">
                        <ProvenancePanel source={sourceSnapshot} />

                        <section
                            className="clinical-shadow rounded-lg border border-border bg-white p-5"
                            aria-labelledby="closure-history-title"
                        >
                            <div className="flex items-center gap-2">
                                <History
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2
                                    id="closure-history-title"
                                    className="text-lg font-semibold"
                                >
                                    Riwayat versi
                                </h2>
                            </div>
                            {document.history.length === 0 ? (
                                <p className="mt-4 rounded-md border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                                    Belum ada versi penutupan.
                                </p>
                            ) : (
                                <ol className="mt-4 space-y-4">
                                    {document.history.map((version) => (
                                        <li key={version.publicId}>
                                            <ClinicalVersionStamp
                                                compact
                                                versionNumber={
                                                    version.versionNumber
                                                }
                                                schemaVersion={
                                                    version.schemaVersion
                                                }
                                                contentHash={
                                                    version.contentHash
                                                }
                                                status={version.status}
                                            />
                                            <p className="mt-2 px-1 text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    version.recordedAt,
                                                )}{' '}
                                                WIB · {version.author}
                                            </p>
                                            {version.changeReason && (
                                                <p className="mt-1 rounded bg-amber-50 px-2 py-1 text-xs text-amber-900">
                                                    Alasan:{' '}
                                                    {version.changeReason}
                                                </p>
                                            )}
                                            {version.reviews.map((review) => (
                                                <div
                                                    key={review.publicId}
                                                    className="mt-2 rounded border border-border p-2 text-xs"
                                                >
                                                    <p className="font-semibold">
                                                        {review.action.label} ·{' '}
                                                        {review.reviewer}
                                                    </p>
                                                    <p className="mt-1 text-muted-foreground">
                                                        {review.comment ??
                                                            'Tanpa komentar'}
                                                    </p>
                                                    <code className="mt-1 block text-[0.62rem] text-muted-foreground">
                                                        {shortHash(
                                                            review.reviewedContentHash,
                                                        )}
                                                    </code>
                                                </div>
                                            ))}
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>
                    </aside>

                    <div className="space-y-5">
                        {document.canAuthor && (
                            <form
                                onSubmit={submitClosure}
                                className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                                    <div>
                                        <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                            Versi penulis berikutnya
                                        </p>
                                        <h2 className="mt-1 text-xl font-semibold">
                                            Dokumentasikan penutupan
                                        </h2>
                                    </div>
                                    {task && (
                                        <Badge variant="outline">
                                            Tugas: {task.status.label}
                                        </Badge>
                                    )}
                                </div>

                                <div className="space-y-5 p-5">
                                    {document.requiresChangeReason && (
                                        <div className="rounded-md border border-amber-200 bg-amber-50 p-4">
                                            <Label htmlFor="change_reason">
                                                Alasan perubahan versi *
                                            </Label>
                                            <Textarea
                                                id="change_reason"
                                                className="mt-2 bg-white"
                                                value={form.data.change_reason}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'change_reason',
                                                        event.target.value,
                                                    )
                                                }
                                                required
                                                aria-invalid={Boolean(
                                                    errors.change_reason,
                                                )}
                                                aria-describedby="closure-change-reason-error"
                                            />
                                            <InputError
                                                id="closure-change-reason-error"
                                                className="mt-2"
                                                message={errors.change_reason}
                                            />
                                        </div>
                                    )}

                                    <div className="grid gap-5 md:grid-cols-2">
                                        <div>
                                            <Label htmlFor="leaving_condition">
                                                Kondisi saat meninggalkan
                                                layanan *
                                            </Label>
                                            <Textarea
                                                id="leaving_condition"
                                                className="mt-2"
                                                value={
                                                    form.data.leaving_condition
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'leaving_condition',
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    authoredFieldsDisabled
                                                }
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={
                                                    errors.leaving_condition
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="disposition">
                                                Disposisi *
                                            </Label>
                                            <Textarea
                                                id="disposition"
                                                className="mt-2"
                                                value={form.data.disposition}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'disposition',
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    authoredFieldsDisabled
                                                }
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={errors.disposition}
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="follow_up_plan">
                                                Rencana tindak lanjut *
                                            </Label>
                                            <Textarea
                                                id="follow_up_plan"
                                                className="mt-2"
                                                value={form.data.follow_up_plan}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'follow_up_plan',
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    authoredFieldsDisabled
                                                }
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={errors.follow_up_plan}
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="referral_plan">
                                                Rencana rujukan bila ada
                                            </Label>
                                            <Textarea
                                                id="referral_plan"
                                                className="mt-2"
                                                value={form.data.referral_plan}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'referral_plan',
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    authoredFieldsDisabled
                                                }
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={errors.referral_plan}
                                            />
                                        </div>
                                        <section
                                            className="space-y-4 rounded-lg border border-sky-200 bg-sky-50 p-4 md:col-span-2"
                                            aria-labelledby="performed-procedure-title"
                                        >
                                            <div>
                                                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                                    Sumber klinis untuk ICD-9-CM
                                                </p>
                                                <h3
                                                    id="performed-procedure-title"
                                                    className="mt-1 text-lg font-semibold"
                                                >
                                                    Tindakan/prosedur yang
                                                    benar-benar telah dilakukan
                                                </h3>
                                                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                                    Jangan menyalin rencana atau
                                                    order sebagai tindakan yang
                                                    sudah dilakukan. Dokter
                                                    menulis pernyataan klinis;
                                                    kode ICD-9-CM tetap dipilih
                                                    dan ditinjau RMIK.
                                                </p>
                                            </div>

                                            {codingCorrection && (
                                                <div className="rounded-md border border-sky-300 bg-white px-3 py-2 text-sm text-sky-950">
                                                    Dokumentasi prosedur dikunci
                                                    pada koreksi sumber
                                                    diagnosis ini. Nilai dari
                                                    versi sebelumnya harus tetap
                                                    sama.
                                                </div>
                                            )}

                                            <div>
                                                <Label htmlFor="procedure_documentation_state">
                                                    Pernyataan tindakan/prosedur
                                                    *
                                                </Label>
                                                <select
                                                    id="procedure_documentation_state"
                                                    className="mt-2 h-10 w-full rounded-md border border-input bg-white px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:bg-muted/40"
                                                    value={
                                                        form.data
                                                            .procedure_documentation_state
                                                    }
                                                    onChange={(event) => {
                                                        const value = event
                                                            .target
                                                            .value as ClosureForm['procedure_documentation_state'];
                                                        form.setData(
                                                            'procedure_documentation_state',
                                                            value,
                                                        );

                                                        if (
                                                            value ===
                                                            'NONE_PERFORMED'
                                                        ) {
                                                            form.setData(
                                                                'procedures',
                                                                [],
                                                            );
                                                        } else if (
                                                            value ===
                                                                'PROCEDURES_RECORDED' &&
                                                            form.data.procedures
                                                                .length === 0
                                                        ) {
                                                            form.setData(
                                                                'procedures',
                                                                [
                                                                    emptyProcedure(
                                                                        formOptions.defaultOccurrenceAt,
                                                                    ),
                                                                ],
                                                            );
                                                        }
                                                    }}
                                                    disabled={
                                                        !document.canAuthor ||
                                                        codingCorrection !==
                                                            null
                                                    }
                                                >
                                                    <option value="">
                                                        Pilih pernyataan
                                                    </option>
                                                    {formOptions.procedureDocumentationStates.map(
                                                        (state) => (
                                                            <option
                                                                key={state.code}
                                                                value={
                                                                    state.code
                                                                }
                                                            >
                                                                {state.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <InputError
                                                    className="mt-2"
                                                    message={
                                                        errors.procedure_documentation_state
                                                    }
                                                />
                                            </div>

                                            {form.data
                                                .procedure_documentation_state ===
                                                'PROCEDURES_RECORDED' && (
                                                <div className="space-y-4">
                                                    {form.data.procedures.map(
                                                        (procedure, index) => (
                                                            <fieldset
                                                                key={index}
                                                                className="space-y-4 rounded-md border border-sky-200 bg-white p-4"
                                                            >
                                                                <div className="flex items-center justify-between gap-3">
                                                                    <legend className="font-semibold">
                                                                        Prosedur{' '}
                                                                        {index +
                                                                            1}
                                                                    </legend>
                                                                    {!codingCorrection && (
                                                                        <Button
                                                                            type="button"
                                                                            variant="ghost"
                                                                            size="sm"
                                                                            onClick={() =>
                                                                                removeProcedure(
                                                                                    index,
                                                                                )
                                                                            }
                                                                        >
                                                                            <Trash2
                                                                                className="size-4"
                                                                                aria-hidden="true"
                                                                            />
                                                                            Hapus
                                                                        </Button>
                                                                    )}
                                                                </div>

                                                                <div>
                                                                    <Label
                                                                        htmlFor={`procedure_${index}_authored_text`}
                                                                    >
                                                                        Pernyataan
                                                                        prosedur
                                                                        yang
                                                                        telah
                                                                        dilakukan
                                                                        *
                                                                    </Label>
                                                                    <Textarea
                                                                        id={`procedure_${index}_authored_text`}
                                                                        className="mt-2"
                                                                        value={
                                                                            procedure.authored_text
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            updateProcedure(
                                                                                index,
                                                                                'authored_text',
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        disabled={
                                                                            codingCorrection !==
                                                                            null
                                                                        }
                                                                    />
                                                                    <InputError
                                                                        className="mt-2"
                                                                        message={
                                                                            errors[
                                                                                `procedures.${index}.authored_text`
                                                                            ]
                                                                        }
                                                                    />
                                                                </div>

                                                                <div className="grid gap-4 md:grid-cols-2">
                                                                    <div>
                                                                        <Label
                                                                            htmlFor={`procedure_${index}_start`}
                                                                        >
                                                                            Mulai
                                                                            dilakukan
                                                                            *
                                                                        </Label>
                                                                        <Input
                                                                            id={`procedure_${index}_start`}
                                                                            type="datetime-local"
                                                                            className="mt-2"
                                                                            value={dateTimeFormValue(
                                                                                procedure.performed_start_at,
                                                                                preserveExactProcedureInstants,
                                                                            )}
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updateProcedure(
                                                                                    index,
                                                                                    'performed_start_at',
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                codingCorrection !==
                                                                                null
                                                                            }
                                                                        />
                                                                        <InputError
                                                                            className="mt-2"
                                                                            message={
                                                                                errors[
                                                                                    `procedures.${index}.performed_start_at`
                                                                                ]
                                                                            }
                                                                        />
                                                                    </div>
                                                                    <div>
                                                                        <Label
                                                                            htmlFor={`procedure_${index}_end`}
                                                                        >
                                                                            Selesai
                                                                            dilakukan
                                                                        </Label>
                                                                        <Input
                                                                            id={`procedure_${index}_end`}
                                                                            type="datetime-local"
                                                                            className="mt-2"
                                                                            value={dateTimeFormValue(
                                                                                procedure.performed_end_at,
                                                                                preserveExactProcedureInstants,
                                                                            )}
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updateProcedure(
                                                                                    index,
                                                                                    'performed_end_at',
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                codingCorrection !==
                                                                                null
                                                                            }
                                                                        />
                                                                        <InputError
                                                                            className="mt-2"
                                                                            message={
                                                                                errors[
                                                                                    `procedures.${index}.performed_end_at`
                                                                                ]
                                                                            }
                                                                        />
                                                                    </div>
                                                                    <div>
                                                                        <Label
                                                                            htmlFor={`procedure_${index}_performer`}
                                                                        >
                                                                            Pelaksana
                                                                            *
                                                                        </Label>
                                                                        <Input
                                                                            id={`procedure_${index}_performer`}
                                                                            className="mt-2"
                                                                            value={
                                                                                procedure.performer_text
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updateProcedure(
                                                                                    index,
                                                                                    'performer_text',
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                codingCorrection !==
                                                                                null
                                                                            }
                                                                        />
                                                                    </div>
                                                                    <div>
                                                                        <Label
                                                                            htmlFor={`procedure_${index}_body_site`}
                                                                        >
                                                                            Lokasi
                                                                            anatomis
                                                                        </Label>
                                                                        <Input
                                                                            id={`procedure_${index}_body_site`}
                                                                            className="mt-2"
                                                                            value={
                                                                                procedure.body_site_text
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updateProcedure(
                                                                                    index,
                                                                                    'body_site_text',
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                codingCorrection !==
                                                                                null
                                                                            }
                                                                        />
                                                                    </div>
                                                                    <div>
                                                                        <Label
                                                                            htmlFor={`procedure_${index}_reason`}
                                                                        >
                                                                            Alasan/diagnosis
                                                                        </Label>
                                                                        <select
                                                                            id={`procedure_${index}_reason`}
                                                                            className="mt-2 h-10 w-full rounded-md border border-input bg-white px-3 text-sm"
                                                                            value={
                                                                                procedure.reason_condition_public_id
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updateProcedure(
                                                                                    index,
                                                                                    'reason_condition_public_id',
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                codingCorrection !==
                                                                                null
                                                                            }
                                                                        >
                                                                            <option value="">
                                                                                Tidak
                                                                                ditautkan
                                                                            </option>
                                                                            {formOptions.diagnosisOptions.map(
                                                                                (
                                                                                    option,
                                                                                ) => (
                                                                                    <option
                                                                                        key={
                                                                                            option.publicId
                                                                                        }
                                                                                        value={
                                                                                            option.publicId
                                                                                        }
                                                                                    >
                                                                                        {
                                                                                            option.label
                                                                                        }
                                                                                    </option>
                                                                                ),
                                                                            )}
                                                                        </select>
                                                                    </div>
                                                                    <div>
                                                                        <Label
                                                                            htmlFor={`procedure_${index}_order`}
                                                                        >
                                                                            Berdasarkan
                                                                            order
                                                                        </Label>
                                                                        <select
                                                                            id={`procedure_${index}_order`}
                                                                            className="mt-2 h-10 w-full rounded-md border border-input bg-white px-3 text-sm"
                                                                            value={
                                                                                procedure.based_on_service_request_public_id
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updateProcedure(
                                                                                    index,
                                                                                    'based_on_service_request_public_id',
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                codingCorrection !==
                                                                                null
                                                                            }
                                                                        >
                                                                            <option value="">
                                                                                Tidak
                                                                                ditautkan
                                                                            </option>
                                                                            {formOptions.serviceRequestOptions.map(
                                                                                (
                                                                                    option,
                                                                                ) => (
                                                                                    <option
                                                                                        key={
                                                                                            option.publicId
                                                                                        }
                                                                                        value={
                                                                                            option.publicId
                                                                                        }
                                                                                    >
                                                                                        {
                                                                                            option.label
                                                                                        }
                                                                                    </option>
                                                                                ),
                                                                            )}
                                                                        </select>
                                                                    </div>
                                                                    <div>
                                                                        <Label
                                                                            htmlFor={`procedure_${index}_outcome`}
                                                                        >
                                                                            Outcome
                                                                        </Label>
                                                                        <Input
                                                                            id={`procedure_${index}_outcome`}
                                                                            className="mt-2"
                                                                            value={
                                                                                procedure.outcome_text
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updateProcedure(
                                                                                    index,
                                                                                    'outcome_text',
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                codingCorrection !==
                                                                                null
                                                                            }
                                                                        />
                                                                    </div>
                                                                </div>
                                                                <div>
                                                                    <Label
                                                                        htmlFor={`procedure_${index}_note`}
                                                                    >
                                                                        Catatan
                                                                        prosedur
                                                                    </Label>
                                                                    <Textarea
                                                                        id={`procedure_${index}_note`}
                                                                        className="mt-2"
                                                                        value={
                                                                            procedure.note
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            updateProcedure(
                                                                                index,
                                                                                'note',
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        disabled={
                                                                            codingCorrection !==
                                                                            null
                                                                        }
                                                                    />
                                                                </div>
                                                            </fieldset>
                                                        ),
                                                    )}

                                                    {!codingCorrection && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={
                                                                addProcedure
                                                            }
                                                        >
                                                            <Plus
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                            Tambah prosedur
                                                        </Button>
                                                    )}
                                                </div>
                                            )}
                                        </section>

                                        <div className="md:col-span-2">
                                            <Label htmlFor="education_instructions">
                                                Edukasi dan instruksi *
                                            </Label>
                                            <Textarea
                                                id="education_instructions"
                                                className="mt-2"
                                                value={
                                                    form.data
                                                        .education_instructions
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'education_instructions',
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    authoredFieldsDisabled
                                                }
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={
                                                    errors.education_instructions
                                                }
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <Label htmlFor="outpatient_summary">
                                                Ringkasan rawat jalan *
                                            </Label>
                                            <Textarea
                                                id="outpatient_summary"
                                                className="mt-2 min-h-40"
                                                value={
                                                    form.data.outpatient_summary
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'outpatient_summary',
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    authoredFieldsDisabled
                                                }
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={
                                                    errors.outpatient_summary
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="clinical_occurrence_at">
                                                Waktu kejadian klinis
                                            </Label>
                                            <Input
                                                id="clinical_occurrence_at"
                                                type="datetime-local"
                                                className="mt-2"
                                                value={dateTimeLocalDisplay(
                                                    form.data
                                                        .clinical_occurrence_at,
                                                )}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'clinical_occurrence_at',
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={
                                                    authoredFieldsDisabled
                                                }
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={
                                                    errors.clinical_occurrence_at
                                                }
                                            />
                                        </div>
                                    </div>

                                    <InputError message={errors.workflow} />
                                    <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-5">
                                        <Button
                                            type="submit"
                                            name="intent"
                                            value="SAVE_DRAFT"
                                            variant="outline"
                                            disabled={form.processing}
                                        >
                                            <FileCheck2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            Simpan versi draf
                                        </Button>
                                        <Button
                                            type="submit"
                                            name="intent"
                                            value="SUBMIT"
                                            disabled={
                                                form.processing ||
                                                !readiness.ready
                                            }
                                        >
                                            <ShieldCheck
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            Ajukan ke supervisor
                                        </Button>
                                    </div>
                                </div>
                            </form>
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
                                            Dokumen terkunci pada tahap ini
                                        </h2>
                                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                            Tunggu tugas hilir selesai,
                                            keputusan supervisor, atau tahap
                                            RMIK berikutnya. Versi lama tetap
                                            dapat ditinjau melalui riwayat.
                                        </p>
                                    </div>
                                </div>
                            </section>
                        )}

                        {document.canReview &&
                            document.latestVersion &&
                            urls.review && (
                                <form
                                    onSubmit={submitReview}
                                    className="clinical-shadow overflow-hidden rounded-lg border border-violet-200 bg-white"
                                >
                                    <div className="border-b border-violet-200 bg-violet-50 px-5 py-4">
                                        <p className="text-xs font-bold tracking-wider text-violet-700 uppercase">
                                            Tinjauan supervisor terhubung
                                        </p>
                                        <h2 className="mt-1 text-xl font-semibold text-violet-950">
                                            Putuskan versi{' '}
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
                                                    .schemaVersion
                                            }
                                            contentHash={
                                                document.latestVersion
                                                    .contentHash
                                            }
                                            status={
                                                document.latestVersion.status
                                            }
                                        />

                                        <SubmittedClosureContent
                                            version={document.latestVersion}
                                        />

                                        <div>
                                            <Label htmlFor="review_comment">
                                                Komentar supervisor
                                            </Label>
                                            <Textarea
                                                id="review_comment"
                                                className="mt-2"
                                                value={reviewForm.data.comment}
                                                onChange={(event) =>
                                                    reviewForm.setData(
                                                        'comment',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={reviewErrors.comment}
                                            />
                                        </div>

                                        <div className="rounded-md border border-amber-200 bg-amber-50 p-4">
                                            <div className="flex items-center gap-2">
                                                <RotateCcw
                                                    className="size-4 text-amber-800"
                                                    aria-hidden="true"
                                                />
                                                <Label htmlFor="finding_message">
                                                    Temuan bila meminta
                                                    perbaikan
                                                </Label>
                                            </div>
                                            <Input
                                                className="mt-3 bg-white"
                                                value={finding.code}
                                                onChange={(event) =>
                                                    setFinding((current) => ({
                                                        ...current,
                                                        code: event.target
                                                            .value,
                                                    }))
                                                }
                                                aria-label="Kode temuan"
                                            />
                                            <Textarea
                                                id="finding_message"
                                                className="mt-2 bg-white"
                                                value={finding.message}
                                                onChange={(event) =>
                                                    setFinding((current) => ({
                                                        ...current,
                                                        message:
                                                            event.target.value,
                                                    }))
                                                }
                                                placeholder="Jelaskan bagian yang harus diperbaiki pada versi penerus."
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={reviewErrors.findings}
                                            />
                                        </div>

                                        <InputError
                                            message={reviewErrors.workflow}
                                        />
                                        <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-5">
                                            <Button
                                                type="submit"
                                                name="action"
                                                value="REQUEST_CHANGES"
                                                variant="outline"
                                                disabled={
                                                    reviewForm.processing ||
                                                    finding.message.trim() ===
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
                                                    reviewForm.processing ||
                                                    !readiness.ready
                                                }
                                            >
                                                <Stethoscope
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                Setujui untuk simulasi
                                            </Button>
                                        </div>
                                    </div>
                                </form>
                            )}
                    </div>
                </div>
            </div>
        </>
    );
}
