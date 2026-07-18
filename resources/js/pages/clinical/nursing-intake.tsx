import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ClipboardPenLine,
    Clock3,
    FileClock,
    HeartPulse,
    Save,
    Send,
    ShieldAlert,
    ShieldCheck,
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
import { clinicalDraftRecoveryOptions } from '@/lib/clinical-draft-recovery';
import type { DraftSaveFailure } from '@/lib/clinical-draft-recovery';
import { cn } from '@/lib/utils';
import type { NursingIntakeWorkspaceProps, NursingVitalKey } from '@/types';

type NursingForm = {
    request_key: string;
    intent: 'SAVE_DRAFT' | 'SUBMIT';
    clinical_occurrence_at: string;
    history_source: string;
    chief_complaint: string;
    onset_duration: string;
    consciousness: string;
    allergy_state: string;
    allergy_details: string;
    current_medication_state: string;
    current_medication_details: string;
    vitals: Record<NursingVitalKey, string>;
    safety_responses: Array<{
        question_code: string;
        response: 'YES' | 'NO' | 'UNKNOWN';
        note: string;
    }>;
    safety_decision: string;
    note: string;
    handoff_summary: string;
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

function localDateTime(value: string): string {
    return value.slice(0, 16);
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

function initialForm(props: NursingIntakeWorkspaceProps): NursingForm {
    const latest = props.document.latestVersion;
    const content = latest?.content;
    const responseFor = (code: string) =>
        content?.safetyScreenResponses.find(
            (response) => response.questionCode === code,
        );

    return {
        request_key: props.formOptions.requestKey,
        intent: 'SAVE_DRAFT',
        clinical_occurrence_at: latest
            ? localDateTime(latest.clinicalOccurrenceAt)
            : props.formOptions.defaultOccurrenceAt,
        history_source: content?.historySource ?? '',
        chief_complaint: content?.chiefComplaint ?? '',
        onset_duration: content?.onsetDuration ?? '',
        consciousness: content?.consciousness ?? '',
        allergy_state: content?.allergyAssessment.state ?? 'NOT_ASSESSED',
        allergy_details: content?.allergyAssessment.details ?? '',
        current_medication_state: content?.currentMedication.state ?? 'UNKNOWN',
        current_medication_details: content?.currentMedication.details ?? '',
        vitals: {
            temperature: content?.vitalObservations.temperature.value ?? '',
            heart_rate: content?.vitalObservations.heart_rate.value ?? '',
            respiratory_rate:
                content?.vitalObservations.respiratory_rate.value ?? '',
            systolic_blood_pressure:
                content?.vitalObservations.systolic_blood_pressure.value ?? '',
            diastolic_blood_pressure:
                content?.vitalObservations.diastolic_blood_pressure.value ?? '',
            oxygen_saturation:
                content?.vitalObservations.oxygen_saturation.value ?? '',
        },
        safety_responses: props.formOptions.safetyQuestions.map((question) => ({
            question_code: question.code,
            response: responseFor(question.code)?.response ?? 'UNKNOWN',
            note: responseFor(question.code)?.note ?? '',
        })),
        safety_decision: content?.safetyDecision ?? '',
        note: content?.note ?? '',
        handoff_summary: content?.handoffSummary ?? '',
        change_reason: '',
    };
}

export default function NursingIntakeWorkspace(
    props: NursingIntakeWorkspaceProps,
) {
    const {
        encounter,
        patient,
        assignment,
        session,
        task,
        document,
        formOptions,
        urls,
    } = props;
    const form = useForm<NursingForm>(initialForm(props));
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

    function setVital(key: NursingVitalKey, value: string) {
        form.setData('vitals', { ...form.data.vitals, [key]: value });
    }

    function setSafetyResponse(
        index: number,
        field: 'response' | 'note',
        value: string,
    ) {
        const responses = [...form.data.safety_responses];
        responses[index] = { ...responses[index], [field]: value };
        form.setData('safety_responses', responses);
    }

    return (
        <>
            <Head title="Asesmen awal keperawatan" />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Dokumentasi klinis · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Asesmen Awal &amp; Skrining Keselamatan
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Catat data yang benar-benar diamati, nyatakan status
                            alergi dan obat secara eksplisit, lalu serahkan
                            versi yang tidak dapat ditimpa kepada supervisor.
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
                        formLabel="asesmen awal keperawatan"
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
                    aria-labelledby="safety-boundary-title"
                    className="rounded-lg border border-orange-200 bg-orange-50 px-5 py-4"
                >
                    <div className="flex gap-3">
                        <ShieldAlert
                            className="mt-0.5 size-5 shrink-0 text-[#a3471f]"
                            aria-hidden="true"
                        />
                        <div>
                            <h2
                                id="safety-boundary-title"
                                className="font-semibold text-[#743719]"
                            >
                                Keputusan keselamatan tetap dibuat manusia
                            </h2>
                            <p className="mt-1 text-sm leading-6 text-[#743719]">
                                Sistem menyimpan data, provenance, dan pilihan
                                Anda. Sistem tidak menghitung diagnosis, tidak
                                menetapkan triase otomatis, dan tidak boleh
                                menggantikan instruksi supervisor pada sesi.
                            </p>
                        </div>
                    </div>
                </section>

                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_23rem]">
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
                                    Temuan asesmen awal
                                </h2>
                            </div>
                            {task && (
                                <Badge variant="outline">
                                    Tugas: {task.status.label}
                                </Badge>
                            )}
                        </div>

                        {!document.canEdit && (
                            <div
                                role="status"
                                className="border-b border-violet-200 bg-violet-50 px-5 py-4 text-sm text-violet-900"
                            >
                                Versi terakhir sedang atau sudah ditinjau.
                                Konten tersebut tidak dapat diedit melalui form
                                ini.
                            </div>
                        )}

                        {document.requiresChangeReason && (
                            <div
                                role="alert"
                                className="flex gap-3 border-b border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-950"
                            >
                                <AlertTriangle
                                    className="mt-0.5 size-5 shrink-0 text-amber-700"
                                    aria-hidden="true"
                                />
                                <p>
                                    Supervisor meminta perbaikan. Versi lama
                                    tetap tersimpan; jelaskan alasan perubahan
                                    untuk membuat versi pengganti.
                                </p>
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
                                <section aria-labelledby="change-reason-title">
                                    <h3
                                        id="change-reason-title"
                                        className="text-lg font-semibold"
                                    >
                                        Alasan koreksi
                                    </h3>
                                    <Label
                                        htmlFor="change-reason"
                                        className="mt-3"
                                    >
                                        Apa yang diubah dan mengapa? *
                                    </Label>
                                    <Textarea
                                        id="change-reason"
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
                                        aria-describedby="change-reason-error"
                                        className="mt-1"
                                    />
                                    <InputError
                                        id="change-reason-error"
                                        message={errors.change_reason}
                                        className="mt-1"
                                    />
                                </section>
                            )}

                            <section
                                aria-labelledby="provenance-title"
                                className="border-t border-border pt-6 first:border-t-0 first:pt-0"
                            >
                                <div className="flex items-center gap-2">
                                    <Clock3
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h3
                                        id="provenance-title"
                                        className="text-lg font-semibold"
                                    >
                                        Waktu dan sumber riwayat
                                    </h3>
                                </div>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="clinical-occurrence">
                                            Waktu kejadian klinis
                                        </Label>
                                        <Input
                                            id="clinical-occurrence"
                                            type="datetime-local"
                                            value={
                                                form.data.clinical_occurrence_at
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'clinical_occurrence_at',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                errors.clinical_occurrence_at,
                                            )}
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={
                                                errors.clinical_occurrence_at
                                            }
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="history-source">
                                            Sumber riwayat
                                        </Label>
                                        <Input
                                            id="history-source"
                                            value={form.data.history_source}
                                            onChange={(event) =>
                                                form.setData(
                                                    'history_source',
                                                    event.target.value,
                                                )
                                            }
                                            placeholder="Pasien sintetis, pengantar, atau brief skenario"
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.history_source}
                                            className="mt-1"
                                        />
                                    </div>
                                </div>
                            </section>

                            <section
                                aria-labelledby="complaint-title"
                                className="border-t border-border pt-6"
                            >
                                <h3
                                    id="complaint-title"
                                    className="text-lg font-semibold"
                                >
                                    Keluhan dan kondisi saat dikaji
                                </h3>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div className="md:col-span-2">
                                        <Label htmlFor="chief-complaint">
                                            Keluhan utama
                                        </Label>
                                        <Textarea
                                            id="chief-complaint"
                                            value={form.data.chief_complaint}
                                            onChange={(event) =>
                                                form.setData(
                                                    'chief_complaint',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                errors.chief_complaint,
                                            )}
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.chief_complaint}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="onset-duration">
                                            Awitan / durasi
                                        </Label>
                                        <Input
                                            id="onset-duration"
                                            value={form.data.onset_duration}
                                            onChange={(event) =>
                                                form.setData(
                                                    'onset_duration',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.onset_duration}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="consciousness">
                                            Kesadaran / respons
                                        </Label>
                                        <Input
                                            id="consciousness"
                                            value={form.data.consciousness}
                                            onChange={(event) =>
                                                form.setData(
                                                    'consciousness',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.consciousness}
                                            className="mt-1"
                                        />
                                    </div>
                                </div>
                            </section>

                            <section
                                aria-labelledby="safety-history-title"
                                className="border-t border-border pt-6"
                            >
                                <h3
                                    id="safety-history-title"
                                    className="text-lg font-semibold"
                                >
                                    Alergi dan obat saat ini
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    “Belum dinilai” berbeda dengan “tidak ada
                                    yang dilaporkan”. Pilih keadaan yang benar.
                                </p>
                                <div className="mt-4 grid gap-5 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="allergy-state">
                                            Status asesmen alergi
                                        </Label>
                                        <select
                                            id="allergy-state"
                                            value={form.data.allergy_state}
                                            onChange={(event) =>
                                                form.setData(
                                                    'allergy_state',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                        >
                                            {formOptions.allergyStates.map(
                                                (state) => (
                                                    <option
                                                        key={state.code}
                                                        value={state.code}
                                                    >
                                                        {state.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError
                                            message={errors.allergy_state}
                                            className="mt-1"
                                        />
                                        {form.data.allergy_state ===
                                            'KNOWN_ALLERGY' && (
                                            <div className="mt-3">
                                                <Label htmlFor="allergy-details">
                                                    Rincian alergi yang
                                                    dilaporkan
                                                </Label>
                                                <Textarea
                                                    id="allergy-details"
                                                    value={
                                                        form.data
                                                            .allergy_details
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'allergy_details',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="mt-1 min-h-20"
                                                />
                                                <InputError
                                                    message={
                                                        errors.allergy_details
                                                    }
                                                    className="mt-1"
                                                />
                                            </div>
                                        )}
                                    </div>
                                    <div>
                                        <Label htmlFor="medication-state">
                                            Status obat yang digunakan
                                        </Label>
                                        <select
                                            id="medication-state"
                                            value={
                                                form.data
                                                    .current_medication_state
                                            }
                                            onChange={(event) =>
                                                form.setData(
                                                    'current_medication_state',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                        >
                                            {formOptions.medicationStates.map(
                                                (state) => (
                                                    <option
                                                        key={state.code}
                                                        value={state.code}
                                                    >
                                                        {state.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError
                                            message={
                                                errors.current_medication_state
                                            }
                                            className="mt-1"
                                        />
                                        {form.data.current_medication_state ===
                                            'HAS_MEDICATION' && (
                                            <div className="mt-3">
                                                <Label htmlFor="medication-details">
                                                    Nama, dosis, dan keterangan
                                                    yang diketahui
                                                </Label>
                                                <Textarea
                                                    id="medication-details"
                                                    value={
                                                        form.data
                                                            .current_medication_details
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'current_medication_details',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="mt-1 min-h-20"
                                                />
                                                <InputError
                                                    message={
                                                        errors.current_medication_details
                                                    }
                                                    className="mt-1"
                                                />
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </section>

                            <section
                                aria-labelledby="vitals-title"
                                className="border-t border-border pt-6"
                            >
                                <div className="flex items-center gap-2">
                                    <HeartPulse
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <h3
                                        id="vitals-title"
                                        className="text-lg font-semibold"
                                    >
                                        Tanda vital terstruktur
                                    </h3>
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Unit dan pemetaan LOINC/UCUM disimpan
                                    bersama setiap observasi; sistem tidak
                                    menafsirkan angka menjadi diagnosis.
                                </p>
                                <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    {(
                                        Object.entries(
                                            formOptions.vitals,
                                        ) as Array<
                                            [
                                                NursingVitalKey,
                                                (typeof formOptions.vitals)[NursingVitalKey],
                                            ]
                                        >
                                    ).map(([key, vital]) => (
                                        <div
                                            key={key}
                                            className="rounded-md border border-border bg-muted/30 p-3"
                                        >
                                            <Label htmlFor={`vital-${key}`}>
                                                {vital.label}
                                            </Label>
                                            <div className="mt-1 flex items-center gap-2">
                                                <Input
                                                    id={`vital-${key}`}
                                                    type="number"
                                                    step="any"
                                                    min="0"
                                                    max={
                                                        key ===
                                                        'oxygen_saturation'
                                                            ? 100
                                                            : undefined
                                                    }
                                                    value={
                                                        form.data.vitals[key]
                                                    }
                                                    onChange={(event) =>
                                                        setVital(
                                                            key,
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={Boolean(
                                                        errors[`vitals.${key}`],
                                                    )}
                                                />
                                                <span className="min-w-16 text-xs font-semibold text-muted-foreground">
                                                    {vital.unitDisplay}
                                                </span>
                                            </div>
                                            <p className="mt-2 font-mono text-[0.65rem] text-muted-foreground">
                                                LOINC {vital.code} · UCUM{' '}
                                                {vital.unitCode}
                                            </p>
                                            <InputError
                                                message={
                                                    errors[`vitals.${key}`]
                                                }
                                                className="mt-1"
                                            />
                                        </div>
                                    ))}
                                </div>
                            </section>

                            <section
                                aria-labelledby="screen-title"
                                className="border-t border-border pt-6"
                            >
                                <h3
                                    id="screen-title"
                                    className="text-lg font-semibold"
                                >
                                    Skrining dan keputusan keselamatan
                                </h3>
                                <div className="mt-4 space-y-4">
                                    {formOptions.safetyQuestions.map(
                                        (question, index) => (
                                            <div
                                                key={question.code}
                                                className="rounded-md border border-border p-4"
                                            >
                                                <Label
                                                    htmlFor={`safety-${question.code}`}
                                                >
                                                    {question.label}
                                                </Label>
                                                <select
                                                    id={`safety-${question.code}`}
                                                    value={
                                                        form.data
                                                            .safety_responses[
                                                            index
                                                        ]?.response ?? 'UNKNOWN'
                                                    }
                                                    onChange={(event) =>
                                                        setSafetyResponse(
                                                            index,
                                                            'response',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="mt-2 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none sm:max-w-xs"
                                                >
                                                    <option value="UNKNOWN">
                                                        Belum diketahui
                                                    </option>
                                                    <option value="NO">
                                                        Tidak
                                                    </option>
                                                    <option value="YES">
                                                        Ya
                                                    </option>
                                                </select>
                                                <Label
                                                    htmlFor={`safety-note-${question.code}`}
                                                    className="mt-3"
                                                >
                                                    Catatan pendukung
                                                </Label>
                                                <Input
                                                    id={`safety-note-${question.code}`}
                                                    value={
                                                        form.data
                                                            .safety_responses[
                                                            index
                                                        ]?.note ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setSafetyResponse(
                                                            index,
                                                            'note',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="mt-1"
                                                />
                                            </div>
                                        ),
                                    )}
                                </div>

                                <fieldset className="mt-5">
                                    <legend className="font-display text-base font-semibold">
                                        Keputusan mahasiswa setelah asesmen
                                    </legend>
                                    <div className="mt-3 grid gap-3 lg:grid-cols-3">
                                        {formOptions.safetyDecisions.map(
                                            (decision) => (
                                                <label
                                                    key={decision.code}
                                                    className={cn(
                                                        'flex cursor-pointer gap-3 rounded-md border p-4 text-sm',
                                                        form.data
                                                            .safety_decision ===
                                                            decision.code
                                                            ? 'border-primary bg-sky-50 ring-1 ring-primary'
                                                            : 'border-border bg-white',
                                                    )}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="safety-decision"
                                                        value={decision.code}
                                                        checked={
                                                            form.data
                                                                .safety_decision ===
                                                            decision.code
                                                        }
                                                        onChange={(event) =>
                                                            form.setData(
                                                                'safety_decision',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        className="mt-0.5 size-4 accent-primary"
                                                    />
                                                    <span className="font-semibold">
                                                        {decision.label}
                                                    </span>
                                                </label>
                                            ),
                                        )}
                                    </div>
                                    <InputError
                                        message={errors.safety_decision}
                                        className="mt-2"
                                    />
                                </fieldset>
                            </section>

                            <section
                                aria-labelledby="handoff-title"
                                className="border-t border-border pt-6"
                            >
                                <h3
                                    id="handoff-title"
                                    className="text-lg font-semibold"
                                >
                                    Catatan dan handoff
                                </h3>
                                <div className="mt-4 grid gap-4 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="clinical-note">
                                            Catatan asesmen
                                        </Label>
                                        <Textarea
                                            id="clinical-note"
                                            value={form.data.note}
                                            onChange={(event) =>
                                                form.setData(
                                                    'note',
                                                    event.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.note}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="handoff-summary">
                                            Ringkasan handoff ke tahap
                                            berikutnya
                                        </Label>
                                        <Textarea
                                            id="handoff-summary"
                                            value={form.data.handoff_summary}
                                            onChange={(event) =>
                                                form.setData(
                                                    'handoff_summary',
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                errors.handoff_summary,
                                            )}
                                            className="mt-1"
                                        />
                                        <InputError
                                            message={errors.handoff_summary}
                                            className="mt-1"
                                        />
                                    </div>
                                </div>
                            </section>

                            <div className="flex flex-col gap-3 border-t border-border pt-6 sm:flex-row sm:items-center sm:justify-between">
                                <p className="max-w-xl text-xs leading-5 text-muted-foreground">
                                    Simpan draf membuat versi baru. Ajukan untuk
                                    tinjauan mengunci konten dan mengirim hash
                                    versi ini kepada supervisor yang terhubung.
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

                    <aside className="space-y-5">
                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <FileClock
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Riwayat versi
                                </h2>
                            </div>
                            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                Setiap simpan menghasilkan rekam baru. Versi
                                lama tidak ditimpa atau dihapus.
                            </p>

                            {document.history.length === 0 ? (
                                <p className="mt-4 rounded-md border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                                    Belum ada versi asesmen.
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
                                            <dl className="mt-2 space-y-1 px-1 text-xs text-muted-foreground">
                                                <div className="flex justify-between gap-3">
                                                    <dt>Dicatat</dt>
                                                    <dd className="text-right">
                                                        {formatDateTime(
                                                            version.recordedAt,
                                                        )}{' '}
                                                        WIB
                                                    </dd>
                                                </div>
                                                <div className="flex justify-between gap-3">
                                                    <dt>Penulis</dt>
                                                    <dd className="text-right font-medium text-foreground">
                                                        {version.author}
                                                    </dd>
                                                </div>
                                                {version.changeReason && (
                                                    <div className="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-amber-900">
                                                        <dt className="font-semibold">
                                                            Alasan koreksi
                                                        </dt>
                                                        <dd className="mt-1">
                                                            {
                                                                version.changeReason
                                                            }
                                                        </dd>
                                                    </div>
                                                )}
                                            </dl>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>

                        <section className="rounded-lg border border-emerald-200 bg-emerald-50 p-5">
                            <div className="flex items-center gap-2 text-emerald-900">
                                <ShieldCheck
                                    className="size-5"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Arti persetujuan
                                </h2>
                            </div>
                            <p className="mt-2 text-sm leading-6 text-emerald-950">
                                Persetujuan supervisor hanya berlaku untuk versi
                                dan hash yang ditinjau, serta hanya untuk tujuan
                                simulasi pembelajaran. Ini bukan tanda tangan
                                klinis rumah sakit.
                            </p>
                        </section>
                    </aside>
                </div>
            </div>
        </>
    );
}
