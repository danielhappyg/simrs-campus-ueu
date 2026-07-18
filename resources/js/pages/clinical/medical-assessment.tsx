import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ClipboardPenLine,
    FileClock,
    History,
    Plus,
    Save,
    Send,
    ShieldCheck,
    Stethoscope,
    Trash2,
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
import type { MedicalAssessmentWorkspaceProps } from '@/types';

type DiagnosisForm = {
    authored_text: string;
    certainty: string;
    role: string;
    onset_at: string;
};

type ServiceRequestForm = {
    request_type: string;
    authored_service: string;
    clinical_question: string;
    priority: string;
    source_diagnosis_index: number | null;
};

type MedicationRequestForm = {
    authored_medication: string;
    form: string;
    strength: string;
    dose_value: string;
    dose_unit: string;
    route: string;
    frequency: string;
    duration: string;
    quantity_value: string;
    quantity_unit: string;
    directions: string;
    indication_text: string;
    source_diagnosis_index: number | null;
};

type MedicalForm = {
    request_key: string;
    intent: 'SAVE_DRAFT' | 'SUBMIT';
    clinical_occurrence_at: string;
    history_source: string;
    present_illness: string;
    past_medical_history: string;
    family_history: string;
    social_history: string;
    general_examination: string;
    focused_examination: string;
    assessment_summary: string;
    diagnoses: DiagnosisForm[];
    service_requests: ServiceRequestForm[];
    medication_requests: MedicationRequestForm[];
    care_plan: string;
    education: string;
    follow_up_plan: string;
    intended_disposition: string;
    change_reason: string;
};

function Textarea({
    className,
    ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            className={cn(
                'min-h-24 w-full rounded-md border border-input bg-white px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

function initialForm(props: MedicalAssessmentWorkspaceProps): MedicalForm {
    const latest = props.document.latestVersion;
    const content = latest?.content;
    const diagnoses =
        content?.diagnoses.map((diagnosis) => ({
            authored_text: diagnosis.authoredText,
            certainty: diagnosis.certainty ?? 'WORKING',
            role: diagnosis.role ?? 'SECONDARY',
            onset_at: diagnosis.onsetAt?.slice(0, 10) ?? '',
        })) ?? [];

    return {
        request_key: props.formOptions.requestKey,
        intent: 'SAVE_DRAFT',
        clinical_occurrence_at: latest
            ? dateTimeFormValue(
                  latest.clinicalOccurrenceAt,
                  props.document.amendmentMode,
              )
            : props.formOptions.defaultOccurrenceAt,
        history_source: content?.history.source ?? 'Pasien sintetis',
        present_illness: content?.history.presentIllness ?? '',
        past_medical_history: content?.history.pastMedical ?? '',
        family_history: content?.history.family ?? '',
        social_history: content?.history.social ?? '',
        general_examination: content?.examination.general ?? '',
        focused_examination: content?.examination.focused ?? '',
        assessment_summary: content?.assessmentSummary ?? '',
        diagnoses:
            diagnoses.length > 0
                ? diagnoses
                : [
                      {
                          authored_text: '',
                          certainty: 'WORKING',
                          role: 'PRIMARY',
                          onset_at: '',
                      },
                  ],
        service_requests:
            content?.serviceRequests.map((request) => ({
                request_type: request.requestType ?? 'LABORATORY',
                authored_service: request.authoredService,
                clinical_question: request.clinicalQuestion ?? '',
                priority: request.priority ?? 'ROUTINE',
                source_diagnosis_index: request.sourceDiagnosisIndex,
            })) ?? [],
        medication_requests:
            content?.medicationRequests.map((request) => ({
                authored_medication: request.authoredMedication,
                form: request.form ?? '',
                strength: request.strength ?? '',
                dose_value: request.doseValue ?? '',
                dose_unit: request.doseUnit ?? '',
                route: request.route ?? '',
                frequency: request.frequency ?? '',
                duration: request.duration ?? '',
                quantity_value: request.quantityValue ?? '',
                quantity_unit: request.quantityUnit ?? '',
                directions: request.directions ?? '',
                indication_text: request.indicationText ?? '',
                source_diagnosis_index: request.sourceDiagnosisIndex,
            })) ?? [],
        care_plan: content?.plan.carePlan ?? '',
        education: content?.plan.education ?? '',
        follow_up_plan: content?.plan.followUp ?? '',
        intended_disposition: content?.plan.intendedDisposition ?? '',
        change_reason: '',
    };
}

export default function MedicalAssessmentWorkspace(
    props: MedicalAssessmentWorkspaceProps,
) {
    const {
        encounter,
        patient,
        assignment,
        session,
        task,
        codingCorrection,
        nursingSource,
        document,
        formOptions,
        urls,
    } = props;
    const form = useForm<MedicalForm>(initialForm(props));
    const errors = form.errors as Record<string, string | undefined>;
    const [manualSaveFailure, setManualSaveFailure] =
        useState<DraftSaveFailure | null>(null);

    function submit(event: FormEvent<HTMLFormElement>) {
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

    function updateDiagnosis(
        index: number,
        field: keyof DiagnosisForm,
        value: string,
    ) {
        const diagnoses = [...form.data.diagnoses];
        diagnoses[index] = { ...diagnoses[index], [field]: value };
        form.setData('diagnoses', diagnoses);
    }

    function addDiagnosis() {
        form.setData('diagnoses', [
            ...form.data.diagnoses,
            {
                authored_text: '',
                certainty: 'DIFFERENTIAL',
                role: 'SECONDARY',
                onset_at: '',
            },
        ]);
    }

    function removeDiagnosis(index: number) {
        if (form.data.diagnoses.length === 1) {
            return;
        }

        form.setData(
            'diagnoses',
            form.data.diagnoses.filter((_, itemIndex) => itemIndex !== index),
        );
    }

    function addServiceRequest() {
        form.setData('service_requests', [
            ...form.data.service_requests,
            {
                request_type: 'LABORATORY',
                authored_service: '',
                clinical_question: '',
                priority: 'ROUTINE',
                source_diagnosis_index: 0,
            },
        ]);
    }

    function updateServiceRequest(
        index: number,
        field: keyof ServiceRequestForm,
        value: string | number | null,
    ) {
        const requests = [...form.data.service_requests];
        requests[index] = { ...requests[index], [field]: value };
        form.setData('service_requests', requests);
    }

    function removeServiceRequest(index: number) {
        form.setData(
            'service_requests',
            form.data.service_requests.filter(
                (_, itemIndex) => itemIndex !== index,
            ),
        );
    }

    function addMedicationRequest() {
        form.setData('medication_requests', [
            ...form.data.medication_requests,
            {
                authored_medication: '',
                form: '',
                strength: '',
                dose_value: '',
                dose_unit: '',
                route: '',
                frequency: '',
                duration: '',
                quantity_value: '',
                quantity_unit: '',
                directions: '',
                indication_text: '',
                source_diagnosis_index: 0,
            },
        ]);
    }

    function updateMedicationRequest(
        index: number,
        field: keyof MedicationRequestForm,
        value: string | number | null,
    ) {
        const requests = [...form.data.medication_requests];
        requests[index] = { ...requests[index], [field]: value };
        form.setData('medication_requests', requests);
    }

    function removeMedicationRequest(index: number) {
        form.setData(
            'medication_requests',
            form.data.medication_requests.filter(
                (_, itemIndex) => itemIndex !== index,
            ),
        );
    }

    return (
        <>
            <Head title="Asesmen medis rawat jalan" />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Dokumentasi klinisi · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Asesmen Medis Rawat Jalan
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Tinjau handoff keperawatan tanpa mengubahnya, lalu
                            tulis anamnesis, pemeriksaan, pernyataan diagnosis,
                            dan rencana sebagai versi medis yang terpisah.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={urls.encounter}>
                                <ClipboardPenLine
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

                {document.canEdit && form.isDirty && (
                    <UnsavedChangesGuard
                        formLabel="asesmen medis"
                        processing={form.processing}
                        onSaveDraft={saveDraftAndContinue}
                    />
                )}

                {document.canEdit && form.isDirty && manualSaveFailure && (
                    <ClinicalDraftFailureAlert
                        failure={manualSaveFailure}
                        className="clinical-shadow"
                    />
                )}

                <section
                    aria-labelledby="diagnosis-boundary-title"
                    className="rounded-lg border border-orange-200 bg-orange-50 px-5 py-4"
                >
                    <div className="flex gap-3">
                        <ShieldCheck
                            className="mt-0.5 size-5 shrink-0 text-[#a3471f]"
                            aria-hidden="true"
                        />
                        <div>
                            <h2
                                id="diagnosis-boundary-title"
                                className="font-semibold text-[#743719]"
                            >
                                Diagnosis ditulis klinisi; kode ditetapkan RMIK
                            </h2>
                            <p className="mt-1 text-sm leading-6 text-[#743719]">
                                Sistem tidak menyimpulkan diagnosis dari keluhan
                                atau tanda vital. Teks diagnosis yang Anda tulis
                                menjadi sumber untuk saran ICD di tahap RMIK;
                                setiap kode tetap membutuhkan keputusan manusia.
                            </p>
                        </div>
                    </div>
                </section>

                {codingCorrection && (
                    <section
                        role="alert"
                        aria-labelledby="coding-correction-title"
                        className="rounded-lg border border-amber-300 bg-amber-50 px-5 py-4"
                    >
                        <div className="flex gap-3">
                            <AlertTriangle
                                className="mt-0.5 size-5 shrink-0 text-amber-700"
                                aria-hidden="true"
                            />
                            <div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2
                                        id="coding-correction-title"
                                        className="font-semibold text-amber-950"
                                    >
                                        Klarifikasi dokumentasi diminta koder
                                    </h2>
                                    <Badge variant="outline">
                                        {codingCorrection.status.label}
                                    </Badge>
                                </div>
                                <p className="mt-2 text-sm leading-6 text-amber-950">
                                    “{codingCorrection.reason}” Sumber yang
                                    dipersoalkan: “
                                    {codingCorrection.sourceStatement}” pada
                                    versi medis{' '}
                                    {
                                        codingCorrection.sourceMedicalVersionNumber
                                    }
                                    .
                                </p>
                                <p className="mt-1 font-mono text-[0.68rem] break-all text-amber-800">
                                    Hash sumber:{' '}
                                    {codingCorrection.requestedSourceHash}
                                </p>
                            </div>
                        </div>
                    </section>
                )}

                <div className="grid items-start gap-5 xl:grid-cols-[23rem_minmax(0,1fr)]">
                    <aside className="space-y-5 xl:sticky xl:top-6">
                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <History
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Handoff keperawatan
                                </h2>
                            </div>

                            {!nursingSource ? (
                                <div
                                    role="status"
                                    className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950"
                                >
                                    Asesmen awal belum disetujui. Dokumentasi
                                    medis tetap terkunci sampai handoff
                                    tersedia.
                                </div>
                            ) : (
                                <div className="mt-4 space-y-4">
                                    <ClinicalVersionStamp
                                        compact
                                        versionNumber={
                                            nursingSource.versionNumber
                                        }
                                        schemaVersion="nursing-intake.v1"
                                        contentHash={nursingSource.contentHash}
                                        status={nursingSource.status}
                                    />
                                    <dl className="space-y-3 text-sm">
                                        <div>
                                            <dt className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                                                Keluhan utama
                                            </dt>
                                            <dd className="mt-1 leading-6 font-medium">
                                                {nursingSource.content
                                                    .chiefComplaint ??
                                                    'Tidak didokumentasikan'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                                                Handoff
                                            </dt>
                                            <dd className="mt-1 leading-6 font-medium">
                                                {nursingSource.content
                                                    .handoffSummary ??
                                                    'Tidak didokumentasikan'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs font-bold tracking-wider text-muted-foreground uppercase">
                                                Keputusan keselamatan
                                            </dt>
                                            <dd className="mt-1 font-mono text-xs font-semibold text-primary">
                                                {nursingSource.content
                                                    .safetyDecision ??
                                                    'BELUM ADA'}
                                            </dd>
                                        </div>
                                    </dl>
                                    <div className="grid grid-cols-2 gap-2">
                                        {nursingSource.observations.map(
                                            (observation) => (
                                                <div
                                                    key={observation.code}
                                                    className="rounded border border-border bg-muted/30 p-2"
                                                >
                                                    <p className="truncate text-[0.65rem] text-muted-foreground">
                                                        {observation.display}
                                                    </p>
                                                    <p className="mt-1 text-sm font-semibold">
                                                        {observation.value}{' '}
                                                        {
                                                            observation.unitDisplay
                                                        }
                                                    </p>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>
                            )}
                        </section>

                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <FileClock
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Riwayat versi medis
                                </h2>
                            </div>
                            {document.history.length === 0 ? (
                                <p className="mt-4 rounded-md border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                                    Belum ada versi asesmen medis.
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
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>
                    </aside>

                    <form
                        onSubmit={submit}
                        className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                            <div>
                                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                    Form versi berikutnya
                                </p>
                                <h2 className="mt-1 text-xl font-semibold">
                                    Temuan dan rencana klinisi
                                </h2>
                            </div>
                            {task && (
                                <Badge variant="outline">
                                    Tugas: {task.status.label}
                                </Badge>
                            )}
                        </div>

                        {!document.workflowEnabled && (
                            <div
                                role="status"
                                className="flex gap-3 border-b border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-950"
                            >
                                <AlertTriangle
                                    className="mt-0.5 size-5 shrink-0 text-amber-700"
                                    aria-hidden="true"
                                />
                                <p>
                                    {codingCorrection
                                        ? `Amendemen medis tidak dapat diedit pada tahap “${codingCorrection.status.label}”. Lanjutkan tugas penutupan atau telaah yang tersedia.`
                                        : 'Tahap medis belum dilepas. Tunggu asesmen awal disetujui dan encounter berstatus “Menunggu klinisi”.'}
                                </p>
                            </div>
                        )}

                        {document.workflowEnabled && !document.canEdit && (
                            <div
                                role="status"
                                className="border-b border-violet-200 bg-violet-50 px-5 py-4 text-sm text-violet-900"
                            >
                                Versi terakhir sedang atau sudah ditinjau dan
                                tidak dapat diedit.
                            </div>
                        )}

                        {document.requiresChangeReason && (
                            <div
                                role="alert"
                                className="border-b border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-950"
                            >
                                {document.amendmentMode
                                    ? 'Buat versi medis penerus untuk menjawab klarifikasi koder dan jelaskan perubahan. Versi lama serta seluruh keputusan coding tetap utuh sebagai riwayat.'
                                    : 'Buat versi pengganti dan jelaskan perubahan yang diminta supervisor. Versi lama tetap utuh.'}
                            </div>
                        )}

                        <InputError
                            message={errors.workflow ?? errors.request_key}
                            className="mx-5 mt-5"
                        />

                        <fieldset
                            disabled={!document.canEdit || form.processing}
                            className="m-0 min-w-0 space-y-7 border-0 p-5 disabled:opacity-70"
                        >
                            {document.requiresChangeReason && (
                                <section aria-labelledby="medical-change-title">
                                    <h3
                                        id="medical-change-title"
                                        className="text-lg font-semibold"
                                    >
                                        Alasan koreksi
                                    </h3>
                                    <Label
                                        htmlFor="medical-change-reason"
                                        className="mt-3"
                                    >
                                        Apa yang diubah dan mengapa? *
                                    </Label>
                                    <Textarea
                                        id="medical-change-reason"
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
                                        aria-describedby="medical-change-reason-error"
                                        className="mt-1"
                                    />
                                    <InputError
                                        id="medical-change-reason-error"
                                        message={errors.change_reason}
                                        className="mt-1"
                                    />
                                </section>
                            )}

                            <section aria-labelledby="anamnesis-title">
                                <div className="flex items-center gap-2">
                                    <History
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h3
                                        id="anamnesis-title"
                                        className="text-lg font-semibold"
                                    >
                                        Anamnesis
                                    </h3>
                                </div>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="medical-occurrence">
                                            Waktu kejadian klinis
                                        </Label>
                                        <Input
                                            id="medical-occurrence"
                                            type="datetime-local"
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
                                            className="mt-1"
                                            disabled={document.amendmentMode}
                                        />
                                        <InputError
                                            message={
                                                errors.clinical_occurrence_at
                                            }
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="medical-history-source">
                                            Sumber riwayat
                                        </Label>
                                        <Input
                                            id="medical-history-source"
                                            value={form.data.history_source}
                                            onChange={(event) =>
                                                form.setData(
                                                    'history_source',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.history_source}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div className="md:col-span-2">
                                        <Label htmlFor="present-illness">
                                            Riwayat penyakit sekarang
                                        </Label>
                                        <Textarea
                                            id="present-illness"
                                            value={form.data.present_illness}
                                            onChange={(event) =>
                                                form.setData(
                                                    'present_illness',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                errors.present_illness,
                                            )}
                                            className="mt-1 min-h-32"
                                        />
                                        <InputError
                                            message={errors.present_illness}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="past-medical-history">
                                            Riwayat medis dahulu
                                        </Label>
                                        <Textarea
                                            id="past-medical-history"
                                            value={
                                                form.data.past_medical_history
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'past_medical_history',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="family-history">
                                            Riwayat keluarga
                                        </Label>
                                        <Textarea
                                            id="family-history"
                                            value={form.data.family_history}
                                            onChange={(event) =>
                                                form.setData(
                                                    'family_history',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                    </div>
                                    <div className="md:col-span-2">
                                        <Label htmlFor="social-history">
                                            Riwayat sosial relevan
                                        </Label>
                                        <Textarea
                                            id="social-history"
                                            value={form.data.social_history}
                                            onChange={(event) =>
                                                form.setData(
                                                    'social_history',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                    </div>
                                </div>
                            </section>

                            <section
                                aria-labelledby="examination-title"
                                className="border-t border-border pt-6"
                            >
                                <div className="flex items-center gap-2">
                                    <Stethoscope
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h3
                                        id="examination-title"
                                        className="text-lg font-semibold"
                                    >
                                        Pemeriksaan
                                    </h3>
                                </div>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="general-examination">
                                            Pemeriksaan umum
                                        </Label>
                                        <Textarea
                                            id="general-examination"
                                            value={
                                                form.data.general_examination
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'general_examination',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                errors.general_examination,
                                            )}
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.general_examination}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="focused-examination">
                                            Pemeriksaan terfokus
                                        </Label>
                                        <Textarea
                                            id="focused-examination"
                                            value={
                                                form.data.focused_examination
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'focused_examination',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                    </div>
                                </div>
                            </section>

                            <section
                                aria-labelledby="medical-assessment-title"
                                className="border-t border-border pt-6"
                            >
                                <h3
                                    id="medical-assessment-title"
                                    className="text-lg font-semibold"
                                >
                                    Asesmen dan pernyataan diagnosis
                                </h3>
                                <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                    Gunakan teks klinis yang Anda
                                    pertanggungjawabkan. Jangan memasukkan kode
                                    ICD di sini; RMIK akan menautkan kode ke
                                    versi diagnosis ini.
                                </p>
                                <Label
                                    htmlFor="assessment-summary"
                                    className="mt-4"
                                >
                                    Ringkasan asesmen
                                </Label>
                                <Textarea
                                    id="assessment-summary"
                                    value={form.data.assessment_summary}
                                    onChange={(event) =>
                                        form.setData(
                                            'assessment_summary',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        errors.assessment_summary,
                                    )}
                                    className="mt-1"
                                />
                                <InputError
                                    message={errors.assessment_summary}
                                    className="mt-1"
                                />

                                <div className="mt-5 space-y-4">
                                    {form.data.diagnoses.map(
                                        (diagnosis, index) => (
                                            <fieldset
                                                key={index}
                                                className="rounded-md border border-border bg-muted/20 p-4"
                                            >
                                                <legend className="px-1 font-display font-semibold">
                                                    Diagnosis {index + 1}
                                                </legend>
                                                <div className="grid gap-4 md:grid-cols-2">
                                                    <div className="md:col-span-2">
                                                        <Label
                                                            htmlFor={`diagnosis-text-${index}`}
                                                        >
                                                            Pernyataan diagnosis
                                                            oleh klinisi
                                                        </Label>
                                                        <Textarea
                                                            id={`diagnosis-text-${index}`}
                                                            value={
                                                                diagnosis.authored_text
                                                            }
                                                            onChange={(event) =>
                                                                updateDiagnosis(
                                                                    index,
                                                                    'authored_text',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="mt-1 min-h-20 bg-white"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors[
                                                                    `diagnoses.${index}.authored_text`
                                                                ]
                                                            }
                                                            className="mt-1"
                                                        />
                                                    </div>
                                                    <div>
                                                        <Label
                                                            htmlFor={`diagnosis-certainty-${index}`}
                                                        >
                                                            Kepastian
                                                        </Label>
                                                        <select
                                                            id={`diagnosis-certainty-${index}`}
                                                            value={
                                                                diagnosis.certainty
                                                            }
                                                            onChange={(event) =>
                                                                updateDiagnosis(
                                                                    index,
                                                                    'certainty',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                        >
                                                            {formOptions.diagnosisCertainties.map(
                                                                (option) => (
                                                                    <option
                                                                        key={
                                                                            option.code
                                                                        }
                                                                        value={
                                                                            option.code
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
                                                            htmlFor={`diagnosis-role-${index}`}
                                                        >
                                                            Peran diagnosis
                                                        </Label>
                                                        <select
                                                            id={`diagnosis-role-${index}`}
                                                            value={
                                                                diagnosis.role
                                                            }
                                                            onChange={(event) =>
                                                                updateDiagnosis(
                                                                    index,
                                                                    'role',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                        >
                                                            {formOptions.diagnosisRoles.map(
                                                                (option) => (
                                                                    <option
                                                                        key={
                                                                            option.code
                                                                        }
                                                                        value={
                                                                            option.code
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
                                                            htmlFor={`diagnosis-onset-${index}`}
                                                        >
                                                            Tanggal awitan
                                                            (opsional)
                                                        </Label>
                                                        <Input
                                                            id={`diagnosis-onset-${index}`}
                                                            type="date"
                                                            value={
                                                                diagnosis.onset_at
                                                            }
                                                            onChange={(event) =>
                                                                updateDiagnosis(
                                                                    index,
                                                                    'onset_at',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="mt-1 bg-white"
                                                        />
                                                    </div>
                                                    <div className="flex items-end justify-end">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            disabled={
                                                                form.data
                                                                    .diagnoses
                                                                    .length ===
                                                                1
                                                            }
                                                            onClick={() =>
                                                                removeDiagnosis(
                                                                    index,
                                                                )
                                                            }
                                                            className="text-red-700"
                                                        >
                                                            <Trash2
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                            Hapus diagnosis
                                                        </Button>
                                                    </div>
                                                </div>
                                            </fieldset>
                                        ),
                                    )}
                                </div>
                                <InputError
                                    message={errors.diagnoses}
                                    className="mt-2"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addDiagnosis}
                                    className="mt-3"
                                >
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Tambah diagnosis
                                </Button>
                            </section>

                            <fieldset
                                disabled={document.ordersLocked}
                                className="contents"
                            >
                                <legend className="sr-only">
                                    Order dan resep operasional
                                </legend>
                                {document.ordersLocked && (
                                    <div
                                        role="note"
                                        className="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm leading-6 text-sky-950"
                                    >
                                        Order pemeriksaan dan resep dikunci pada
                                        amendemen dokumentasi untuk koding. Jika
                                        terapi atau layanan harus diubah,
                                        gunakan alur klinis terpisah yang
                                        ditinjau manusia.
                                    </div>
                                )}

                                <section
                                    aria-labelledby="service-request-title"
                                    className="border-t border-border pt-6"
                                >
                                    <h3
                                        id="service-request-title"
                                        className="text-lg font-semibold"
                                    >
                                        Pesanan pemeriksaan simulasi
                                    </h3>
                                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                        Pilih pemeriksaan dan tulis pertanyaan
                                        klinis sendiri. Sistem tidak
                                        merekomendasikan tes berdasarkan
                                        diagnosis atau tanda vital.
                                    </p>

                                    {form.data.service_requests.length === 0 ? (
                                        <p className="mt-4 rounded-md border border-dashed border-border p-5 text-center text-sm text-muted-foreground">
                                            Tidak ada pemeriksaan yang dipesan
                                            pada versi ini.
                                        </p>
                                    ) : (
                                        <div className="mt-4 space-y-4">
                                            {form.data.service_requests.map(
                                                (request, index) => (
                                                    <fieldset
                                                        key={index}
                                                        className="rounded-md border border-border bg-muted/20 p-4"
                                                    >
                                                        <legend className="px-1 font-display font-semibold">
                                                            Pesanan {index + 1}
                                                        </legend>
                                                        <div className="grid gap-4 md:grid-cols-2">
                                                            <div>
                                                                <Label
                                                                    htmlFor={`service-type-${index}`}
                                                                >
                                                                    Jenis
                                                                    layanan
                                                                </Label>
                                                                <select
                                                                    id={`service-type-${index}`}
                                                                    value={
                                                                        request.request_type
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateServiceRequest(
                                                                            index,
                                                                            'request_type',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                                >
                                                                    <option value="LABORATORY">
                                                                        Laboratorium
                                                                        sintetis
                                                                    </option>
                                                                    <option value="IMAGING">
                                                                        Pencitraan
                                                                        sintetis
                                                                    </option>
                                                                    <option value="OTHER">
                                                                        Layanan
                                                                        lain
                                                                    </option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <Label
                                                                    htmlFor={`service-priority-${index}`}
                                                                >
                                                                    Prioritas
                                                                    skenario
                                                                </Label>
                                                                <select
                                                                    id={`service-priority-${index}`}
                                                                    value={
                                                                        request.priority
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateServiceRequest(
                                                                            index,
                                                                            'priority',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                                >
                                                                    <option value="ROUTINE">
                                                                        Rutin
                                                                    </option>
                                                                    <option value="URGENT_SIMULATION">
                                                                        Mendesak
                                                                        dalam
                                                                        simulasi
                                                                    </option>
                                                                </select>
                                                            </div>
                                                            <div className="md:col-span-2">
                                                                <Label
                                                                    htmlFor={`authored-service-${index}`}
                                                                >
                                                                    Pemeriksaan
                                                                    yang dipesan
                                                                </Label>
                                                                <Input
                                                                    id={`authored-service-${index}`}
                                                                    value={
                                                                        request.authored_service
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateServiceRequest(
                                                                            index,
                                                                            'authored_service',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    placeholder="Nama pemeriksaan sesuai fixture skenario"
                                                                    className="mt-1 bg-white"
                                                                />
                                                                <InputError
                                                                    message={
                                                                        errors[
                                                                            `service_requests.${index}.authored_service`
                                                                        ]
                                                                    }
                                                                    className="mt-1"
                                                                />
                                                            </div>
                                                            <div className="md:col-span-2">
                                                                <Label
                                                                    htmlFor={`clinical-question-${index}`}
                                                                >
                                                                    Pertanyaan /
                                                                    alasan
                                                                    klinis
                                                                </Label>
                                                                <Textarea
                                                                    id={`clinical-question-${index}`}
                                                                    value={
                                                                        request.clinical_question
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateServiceRequest(
                                                                            index,
                                                                            'clinical_question',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className="mt-1 min-h-20 bg-white"
                                                                />
                                                                <InputError
                                                                    message={
                                                                        errors[
                                                                            `service_requests.${index}.clinical_question`
                                                                        ]
                                                                    }
                                                                    className="mt-1"
                                                                />
                                                            </div>
                                                            <div>
                                                                <Label
                                                                    htmlFor={`service-source-${index}`}
                                                                >
                                                                    Diagnosis
                                                                    sumber
                                                                </Label>
                                                                <select
                                                                    id={`service-source-${index}`}
                                                                    value={
                                                                        request.source_diagnosis_index ??
                                                                        ''
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateServiceRequest(
                                                                            index,
                                                                            'source_diagnosis_index',
                                                                            event
                                                                                .target
                                                                                .value ===
                                                                                ''
                                                                                ? null
                                                                                : Number(
                                                                                      event
                                                                                          .target
                                                                                          .value,
                                                                                  ),
                                                                        )
                                                                    }
                                                                    className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                                >
                                                                    <option value="">
                                                                        Tidak
                                                                        ditautkan
                                                                    </option>
                                                                    {form.data.diagnoses.map(
                                                                        (
                                                                            diagnosis,
                                                                            diagnosisIndex,
                                                                        ) => (
                                                                            <option
                                                                                key={
                                                                                    diagnosisIndex
                                                                                }
                                                                                value={
                                                                                    diagnosisIndex
                                                                                }
                                                                            >
                                                                                {diagnosis.authored_text ||
                                                                                    `Diagnosis ${diagnosisIndex + 1}`}
                                                                            </option>
                                                                        ),
                                                                    )}
                                                                </select>
                                                            </div>
                                                            <div className="flex items-end justify-end">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        removeServiceRequest(
                                                                            index,
                                                                        )
                                                                    }
                                                                    className="text-red-700"
                                                                >
                                                                    <Trash2
                                                                        className="size-4"
                                                                        aria-hidden="true"
                                                                    />
                                                                    Hapus
                                                                    pesanan
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    </fieldset>
                                                ),
                                            )}
                                        </div>
                                    )}

                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addServiceRequest}
                                        className="mt-3"
                                    >
                                        <Plus
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Tambah pesanan pemeriksaan
                                    </Button>
                                </section>

                                <section
                                    aria-labelledby="prescription-title"
                                    className="border-t border-border pt-6"
                                >
                                    <h3
                                        id="prescription-title"
                                        className="text-lg font-semibold"
                                    >
                                        Resep simulasi
                                    </h3>
                                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                        Semua rincian merupakan instruksi yang
                                        ditulis mahasiswa dan akan ditelaah
                                        manusia oleh farmasi. Sistem tidak
                                        menyatakan resep aman secara otomatis.
                                    </p>

                                    {form.data.medication_requests.length ===
                                    0 ? (
                                        <p className="mt-4 rounded-md border border-dashed border-border p-5 text-center text-sm text-muted-foreground">
                                            Tidak ada obat yang diresepkan pada
                                            versi ini.
                                        </p>
                                    ) : (
                                        <div className="mt-4 space-y-4">
                                            {form.data.medication_requests.map(
                                                (request, index) => (
                                                    <fieldset
                                                        key={index}
                                                        className="rounded-md border border-border bg-muted/20 p-4"
                                                    >
                                                        <legend className="px-1 font-display font-semibold">
                                                            Item resep{' '}
                                                            {index + 1}
                                                        </legend>
                                                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                                            <div className="md:col-span-2 lg:col-span-3">
                                                                <Label
                                                                    htmlFor={`medication-name-${index}`}
                                                                >
                                                                    Nama obat
                                                                    yang ditulis
                                                                </Label>
                                                                <Input
                                                                    id={`medication-name-${index}`}
                                                                    value={
                                                                        request.authored_medication
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateMedicationRequest(
                                                                            index,
                                                                            'authored_medication',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className="mt-1 bg-white"
                                                                />
                                                                <InputError
                                                                    message={
                                                                        errors[
                                                                            `medication_requests.${index}.authored_medication`
                                                                        ]
                                                                    }
                                                                    className="mt-1"
                                                                />
                                                            </div>
                                                            {[
                                                                [
                                                                    'form',
                                                                    'Bentuk sediaan',
                                                                ],
                                                                [
                                                                    'strength',
                                                                    'Kekuatan',
                                                                ],
                                                                [
                                                                    'route',
                                                                    'Rute',
                                                                ],
                                                                [
                                                                    'frequency',
                                                                    'Frekuensi',
                                                                ],
                                                                [
                                                                    'duration',
                                                                    'Durasi',
                                                                ],
                                                            ].map(
                                                                ([
                                                                    field,
                                                                    label,
                                                                ]) => (
                                                                    <div
                                                                        key={
                                                                            field
                                                                        }
                                                                    >
                                                                        <Label
                                                                            htmlFor={`medication-${field}-${index}`}
                                                                        >
                                                                            {
                                                                                label
                                                                            }
                                                                        </Label>
                                                                        <Input
                                                                            id={`medication-${field}-${index}`}
                                                                            value={
                                                                                request[
                                                                                    field as keyof MedicationRequestForm
                                                                                ] ??
                                                                                ''
                                                                            }
                                                                            onChange={(
                                                                                event,
                                                                            ) =>
                                                                                updateMedicationRequest(
                                                                                    index,
                                                                                    field as keyof MedicationRequestForm,
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            className="mt-1 bg-white"
                                                                        />
                                                                    </div>
                                                                ),
                                                            )}
                                                            <div>
                                                                <Label
                                                                    htmlFor={`dose-value-${index}`}
                                                                >
                                                                    Dosis
                                                                </Label>
                                                                <div className="mt-1 flex gap-2">
                                                                    <Input
                                                                        id={`dose-value-${index}`}
                                                                        type="number"
                                                                        min="0"
                                                                        step="any"
                                                                        value={
                                                                            request.dose_value
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            updateMedicationRequest(
                                                                                index,
                                                                                'dose_value',
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="bg-white"
                                                                    />
                                                                    <Input
                                                                        aria-label={`Unit dosis item ${index + 1}`}
                                                                        value={
                                                                            request.dose_unit
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            updateMedicationRequest(
                                                                                index,
                                                                                'dose_unit',
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        placeholder="unit"
                                                                        className="bg-white"
                                                                    />
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <Label
                                                                    htmlFor={`quantity-value-${index}`}
                                                                >
                                                                    Jumlah
                                                                </Label>
                                                                <div className="mt-1 flex gap-2">
                                                                    <Input
                                                                        id={`quantity-value-${index}`}
                                                                        type="number"
                                                                        min="0"
                                                                        step="any"
                                                                        value={
                                                                            request.quantity_value
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            updateMedicationRequest(
                                                                                index,
                                                                                'quantity_value',
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        className="bg-white"
                                                                    />
                                                                    <Input
                                                                        aria-label={`Unit jumlah item ${index + 1}`}
                                                                        value={
                                                                            request.quantity_unit
                                                                        }
                                                                        onChange={(
                                                                            event,
                                                                        ) =>
                                                                            updateMedicationRequest(
                                                                                index,
                                                                                'quantity_unit',
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                            )
                                                                        }
                                                                        placeholder="unit"
                                                                        className="bg-white"
                                                                    />
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <Label
                                                                    htmlFor={`medication-source-${index}`}
                                                                >
                                                                    Diagnosis
                                                                    sumber
                                                                </Label>
                                                                <select
                                                                    id={`medication-source-${index}`}
                                                                    value={
                                                                        request.source_diagnosis_index ??
                                                                        ''
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateMedicationRequest(
                                                                            index,
                                                                            'source_diagnosis_index',
                                                                            event
                                                                                .target
                                                                                .value ===
                                                                                ''
                                                                                ? null
                                                                                : Number(
                                                                                      event
                                                                                          .target
                                                                                          .value,
                                                                                  ),
                                                                        )
                                                                    }
                                                                    className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                                >
                                                                    <option value="">
                                                                        Tidak
                                                                        ditautkan
                                                                    </option>
                                                                    {form.data.diagnoses.map(
                                                                        (
                                                                            diagnosis,
                                                                            diagnosisIndex,
                                                                        ) => (
                                                                            <option
                                                                                key={
                                                                                    diagnosisIndex
                                                                                }
                                                                                value={
                                                                                    diagnosisIndex
                                                                                }
                                                                            >
                                                                                {diagnosis.authored_text ||
                                                                                    `Diagnosis ${diagnosisIndex + 1}`}
                                                                            </option>
                                                                        ),
                                                                    )}
                                                                </select>
                                                            </div>
                                                            <div className="md:col-span-2 lg:col-span-3">
                                                                <Label
                                                                    htmlFor={`directions-${index}`}
                                                                >
                                                                    Aturan pakai
                                                                </Label>
                                                                <Textarea
                                                                    id={`directions-${index}`}
                                                                    value={
                                                                        request.directions
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateMedicationRequest(
                                                                            index,
                                                                            'directions',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className="mt-1 min-h-20 bg-white"
                                                                />
                                                            </div>
                                                            <div className="md:col-span-2 lg:col-span-3">
                                                                <Label
                                                                    htmlFor={`indication-${index}`}
                                                                >
                                                                    Indikasi
                                                                    yang ditulis
                                                                    (opsional)
                                                                </Label>
                                                                <Input
                                                                    id={`indication-${index}`}
                                                                    value={
                                                                        request.indication_text
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        updateMedicationRequest(
                                                                            index,
                                                                            'indication_text',
                                                                            event
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className="mt-1 bg-white"
                                                                />
                                                            </div>
                                                            <div className="flex justify-end md:col-span-2 lg:col-span-3">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        removeMedicationRequest(
                                                                            index,
                                                                        )
                                                                    }
                                                                    className="text-red-700"
                                                                >
                                                                    <Trash2
                                                                        className="size-4"
                                                                        aria-hidden="true"
                                                                    />
                                                                    Hapus item
                                                                    resep
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    </fieldset>
                                                ),
                                            )}
                                        </div>
                                    )}

                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addMedicationRequest}
                                        className="mt-3"
                                    >
                                        <Plus
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Tambah item resep
                                    </Button>
                                </section>
                            </fieldset>

                            <section
                                aria-labelledby="plan-title"
                                className="border-t border-border pt-6"
                            >
                                <h3
                                    id="plan-title"
                                    className="text-lg font-semibold"
                                >
                                    Rencana dan disposisi
                                </h3>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="care-plan">
                                            Rencana layanan
                                        </Label>
                                        <Textarea
                                            id="care-plan"
                                            value={form.data.care_plan}
                                            onChange={(event) =>
                                                form.setData(
                                                    'care_plan',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.care_plan}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="education">
                                            Edukasi untuk skenario
                                        </Label>
                                        <Textarea
                                            id="education"
                                            value={form.data.education}
                                            onChange={(event) =>
                                                form.setData(
                                                    'education',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.education}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="follow-up-plan">
                                            Rencana tindak lanjut
                                        </Label>
                                        <Textarea
                                            id="follow-up-plan"
                                            value={form.data.follow_up_plan}
                                            onChange={(event) =>
                                                form.setData(
                                                    'follow_up_plan',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.follow_up_plan}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="intended-disposition">
                                            Disposisi yang direncanakan
                                        </Label>
                                        <Textarea
                                            id="intended-disposition"
                                            value={
                                                form.data.intended_disposition
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'intended_disposition',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={
                                                errors.intended_disposition
                                            }
                                            className="mt-1"
                                        />
                                    </div>
                                </div>
                            </section>

                            <div className="flex flex-col gap-3 border-t border-border pt-6 sm:flex-row sm:items-center sm:justify-between">
                                <p className="max-w-xl text-xs leading-5 text-muted-foreground">
                                    Pengajuan mengunci versi ini dan mengirim
                                    hash, pernyataan diagnosis, serta provenance
                                    kepada supervisor kedokteran yang terhubung.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="submit"
                                        name="intent"
                                        value="SAVE_DRAFT"
                                        variant="outline"
                                    >
                                        <Save
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Simpan draf
                                    </Button>
                                    <Button
                                        type="submit"
                                        name="intent"
                                        value="SUBMIT"
                                    >
                                        <Send
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Ajukan untuk tinjauan
                                    </Button>
                                </div>
                            </div>
                        </fieldset>
                    </form>
                </div>
            </div>
        </>
    );
}
