import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    FileSearch,
    HeartPulse,
    RotateCcw,
    ShieldCheck,
    UserRound,
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
    ClinicalFinding,
    ClinicalReviewWorkspaceProps,
    MedicalAssessmentContent,
    NursingIntakeContent,
} from '@/types';

type ReviewForm = {
    request_key: string;
    action: 'REQUEST_CHANGES' | 'APPROVE_SIMULATION';
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

function displayValue(value: string | null): string {
    return value && value.trim() !== '' ? value : 'Tidak didokumentasikan';
}

function ContentField({
    label,
    value,
}: {
    label: string;
    value: string | null;
}) {
    return (
        <div className="rounded-md border border-border bg-muted/30 p-4">
            <dt className="text-[0.68rem] font-bold tracking-wider text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-1 text-sm leading-6 font-medium whitespace-pre-wrap">
                {displayValue(value)}
            </dd>
        </div>
    );
}

function NursingContent({ content }: { content: NursingIntakeContent }) {
    return (
        <div className="space-y-6">
            <section aria-labelledby="review-complaint-title">
                <h3
                    id="review-complaint-title"
                    className="text-lg font-semibold"
                >
                    Keluhan dan riwayat
                </h3>
                <dl className="mt-3 grid gap-3 md:grid-cols-2">
                    <ContentField
                        label="Sumber riwayat"
                        value={content.historySource}
                    />
                    <ContentField
                        label="Awitan / durasi"
                        value={content.onsetDuration}
                    />
                    <div className="md:col-span-2">
                        <ContentField
                            label="Keluhan utama"
                            value={content.chiefComplaint}
                        />
                    </div>
                    <ContentField
                        label="Kesadaran / respons"
                        value={content.consciousness}
                    />
                    <ContentField
                        label="Catatan asesmen"
                        value={content.note}
                    />
                </dl>
            </section>

            <section
                aria-labelledby="review-history-title"
                className="border-t border-border pt-6"
            >
                <h3 id="review-history-title" className="text-lg font-semibold">
                    Alergi dan obat saat ini
                </h3>
                <dl className="mt-3 grid gap-3 md:grid-cols-2">
                    <ContentField
                        label={`Alergi · ${content.allergyAssessment.state ?? 'BELUM DINILAI'}`}
                        value={content.allergyAssessment.details}
                    />
                    <ContentField
                        label={`Obat · ${content.currentMedication.state ?? 'BELUM DIKETAHUI'}`}
                        value={content.currentMedication.details}
                    />
                </dl>
            </section>

            <section
                aria-labelledby="review-screen-title"
                className="border-t border-border pt-6"
            >
                <h3 id="review-screen-title" className="text-lg font-semibold">
                    Skrining dan handoff
                </h3>
                <dl className="mt-3 grid gap-3 md:grid-cols-2">
                    {content.safetyScreenResponses.map((response) => (
                        <ContentField
                            key={response.questionCode}
                            label={`${response.questionCode} · ${response.response}`}
                            value={response.note}
                        />
                    ))}
                    <ContentField
                        label="Keputusan keselamatan oleh penulis"
                        value={content.safetyDecision}
                    />
                    <div className="md:col-span-2">
                        <ContentField
                            label="Ringkasan handoff"
                            value={content.handoffSummary}
                        />
                    </div>
                </dl>
            </section>
        </div>
    );
}

function MedicalContent({ content }: { content: MedicalAssessmentContent }) {
    return (
        <div className="space-y-6">
            <section aria-labelledby="review-medical-history-title">
                <h3
                    id="review-medical-history-title"
                    className="text-lg font-semibold"
                >
                    Anamnesis medis
                </h3>
                <dl className="mt-3 grid gap-3 md:grid-cols-2">
                    <ContentField
                        label="Sumber riwayat"
                        value={content.history.source}
                    />
                    <ContentField
                        label="Riwayat penyakit sekarang"
                        value={content.history.presentIllness}
                    />
                    <ContentField
                        label="Riwayat medis dahulu"
                        value={content.history.pastMedical}
                    />
                    <ContentField
                        label="Riwayat keluarga"
                        value={content.history.family}
                    />
                    <ContentField
                        label="Riwayat sosial"
                        value={content.history.social}
                    />
                </dl>
            </section>

            <section
                aria-labelledby="review-examination-title"
                className="border-t border-border pt-6"
            >
                <h3
                    id="review-examination-title"
                    className="text-lg font-semibold"
                >
                    Pemeriksaan
                </h3>
                <dl className="mt-3 grid gap-3 md:grid-cols-2">
                    <ContentField
                        label="Pemeriksaan umum"
                        value={content.examination.general}
                    />
                    <ContentField
                        label="Pemeriksaan terfokus"
                        value={content.examination.focused}
                    />
                </dl>
            </section>

            <section
                aria-labelledby="review-assessment-plan-title"
                className="border-t border-border pt-6"
            >
                <h3
                    id="review-assessment-plan-title"
                    className="text-lg font-semibold"
                >
                    Asesmen dan rencana
                </h3>
                <dl className="mt-3 grid gap-3 md:grid-cols-2">
                    <div className="md:col-span-2">
                        <ContentField
                            label="Ringkasan asesmen"
                            value={content.assessmentSummary}
                        />
                    </div>
                    <ContentField
                        label="Rencana layanan"
                        value={content.plan.carePlan}
                    />
                    <ContentField
                        label="Edukasi"
                        value={content.plan.education}
                    />
                    <ContentField
                        label="Rencana tindak lanjut"
                        value={content.plan.followUp}
                    />
                    <ContentField
                        label="Disposisi yang direncanakan"
                        value={content.plan.intendedDisposition}
                    />
                </dl>
            </section>
        </div>
    );
}

export default function ClinicalReviewWorkspace({
    encounter,
    patient,
    session,
    document,
    formOptions,
    urls,
}: ClinicalReviewWorkspaceProps) {
    const form = useForm<ReviewForm>({
        request_key: formOptions.requestKey,
        action: 'REQUEST_CHANGES',
        comment: '',
        findings: [],
    });
    const [finding, setFinding] = useState<ClinicalFinding>({
        code: '',
        severity: 'BLOCKING',
        message: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const submitter = (event.nativeEvent as SubmitEvent)
            .submitter as HTMLButtonElement | null;
        const action =
            submitter?.value === 'APPROVE_SIMULATION'
                ? 'APPROVE_SIMULATION'
                : 'REQUEST_CHANGES';
        const hasFinding =
            finding.code.trim() !== '' || finding.message.trim() !== '';

        form.transform((data) => ({
            ...data,
            action,
            findings: hasFinding ? [finding] : [],
        }));
        form.post(urls.storeDecision, { preserveScroll: true });
    }

    return (
        <>
            <Head
                title={`Tinjau ${document.label} v${document.versionNumber}`}
            />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Supervisi versi klinis · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Tinjau {document.label}
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Bandingkan isi, provenance, observasi terstruktur,
                            dan hash. Keputusan hanya berlaku pada versi yang
                            sedang ditampilkan.
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
                    actingAs="Supervisor · tinjauan versi terhubung"
                />

                <section
                    aria-labelledby="approval-boundary-title"
                    className="rounded-lg border border-violet-200 bg-violet-50 px-5 py-4"
                >
                    <div className="flex gap-3">
                        <ShieldCheck
                            className="mt-0.5 size-5 shrink-0 text-violet-700"
                            aria-hidden="true"
                        />
                        <div>
                            <h2
                                id="approval-boundary-title"
                                className="font-semibold text-violet-950"
                            >
                                Persetujuan simulasi, bukan tanda tangan klinis
                            </h2>
                            <p className="mt-1 text-sm leading-6 text-violet-950">
                                Persetujuan mengonfirmasi bahwa versi dan hash
                                ini dapat melanjutkan skenario pembelajaran. Ini
                                bukan validasi layanan pasien nyata, kredensial
                                rumah sakit, atau keputusan diagnosis otomatis.
                            </p>
                        </div>
                    </div>
                </section>

                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_25rem]">
                    <div className="space-y-5">
                        <section className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                                <div>
                                    <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                        Konten tidak dapat diedit
                                    </p>
                                    <h2 className="mt-1 text-xl font-semibold">
                                        Isi versi yang diajukan
                                    </h2>
                                </div>
                                <Badge variant="outline">{document.type}</Badge>
                            </div>

                            <div className="space-y-6 p-5">
                                <ClinicalVersionStamp
                                    versionNumber={document.versionNumber}
                                    schemaVersion={document.schemaVersion}
                                    contentHash={document.contentHash}
                                    status={document.status}
                                />

                                <dl className="grid gap-3 rounded-md border border-border p-4 text-sm md:grid-cols-3">
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Penulis
                                        </dt>
                                        <dd className="mt-1 font-semibold">
                                            {document.author}
                                        </dd>
                                        <dd className="text-xs text-muted-foreground">
                                            {document.authorRole}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Kejadian klinis
                                        </dt>
                                        <dd className="mt-1 font-semibold">
                                            {formatDateTime(
                                                document.clinicalOccurrenceAt,
                                            )}{' '}
                                            WIB
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Direkam sistem
                                        </dt>
                                        <dd className="mt-1 font-semibold">
                                            {formatDateTime(
                                                document.recordedAt,
                                            )}{' '}
                                            WIB
                                        </dd>
                                    </div>
                                </dl>

                                {document.type === 'MEDICAL_ASSESSMENT' ? (
                                    <MedicalContent
                                        content={
                                            document.content as MedicalAssessmentContent
                                        }
                                    />
                                ) : (
                                    <NursingContent
                                        content={
                                            document.content as NursingIntakeContent
                                        }
                                    />
                                )}

                                {document.conditions.length > 0 && (
                                    <section
                                        aria-labelledby="review-diagnosis-title"
                                        className="border-t border-border pt-6"
                                    >
                                        <h3
                                            id="review-diagnosis-title"
                                            className="text-lg font-semibold"
                                        >
                                            Pernyataan diagnosis oleh klinisi
                                        </h3>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Teks ini merupakan sumber klinis.
                                            Kode klasifikasi belum ditetapkan
                                            dan harus melalui telaah RMIK
                                            terpisah.
                                        </p>
                                        <ol className="mt-3 space-y-3">
                                            {document.conditions.map(
                                                (condition) => (
                                                    <li
                                                        key={condition.publicId}
                                                        className="rounded-md border border-border p-4"
                                                    >
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge variant="outline">
                                                                {
                                                                    condition
                                                                        .role
                                                                        .label
                                                                }
                                                            </Badge>
                                                            <Badge variant="outline">
                                                                {
                                                                    condition
                                                                        .certainty
                                                                        .label
                                                                }
                                                            </Badge>
                                                            <Badge
                                                                variant="outline"
                                                                className="border-amber-200 bg-amber-50 text-amber-900"
                                                            >
                                                                Belum dikoding
                                                            </Badge>
                                                        </div>
                                                        <p className="mt-3 text-sm leading-6 font-semibold">
                                                            {
                                                                condition.authoredText
                                                            }
                                                        </p>
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    </section>
                                )}

                                {document.serviceRequests.length > 0 && (
                                    <section
                                        aria-labelledby="review-service-request-title"
                                        className="border-t border-border pt-6"
                                    >
                                        <h3
                                            id="review-service-request-title"
                                            className="text-lg font-semibold"
                                        >
                                            Pesanan pemeriksaan simulasi
                                        </h3>
                                        <div className="mt-3 space-y-3">
                                            {document.serviceRequests.map(
                                                (request) => (
                                                    <article
                                                        key={request.publicId}
                                                        className="rounded-md border border-border p-4"
                                                    >
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge variant="outline">
                                                                {
                                                                    request.requestType
                                                                }
                                                            </Badge>
                                                            <Badge variant="outline">
                                                                {
                                                                    request.priority
                                                                }
                                                            </Badge>
                                                        </div>
                                                        <h4 className="mt-3 font-semibold">
                                                            {
                                                                request.authoredService
                                                            }
                                                        </h4>
                                                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                                            {
                                                                request.clinicalQuestion
                                                            }
                                                        </p>
                                                        {request.sourceDiagnosis && (
                                                            <p className="mt-2 text-xs text-primary">
                                                                Sumber:{' '}
                                                                {
                                                                    request.sourceDiagnosis
                                                                }
                                                            </p>
                                                        )}
                                                    </article>
                                                ),
                                            )}
                                        </div>
                                    </section>
                                )}

                                {document.medicationRequests.length > 0 && (
                                    <section
                                        aria-labelledby="review-medication-request-title"
                                        className="border-t border-border pt-6"
                                    >
                                        <h3
                                            id="review-medication-request-title"
                                            className="text-lg font-semibold"
                                        >
                                            Resep simulasi yang ditulis
                                        </h3>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Persetujuan medis mengaktifkan resep
                                            untuk telaah farmasi manusia; bukan
                                            pernyataan bahwa resep aman
                                            otomatis.
                                        </p>
                                        <div className="mt-3 space-y-3">
                                            {document.medicationRequests.map(
                                                (request) => (
                                                    <article
                                                        key={request.publicId}
                                                        className="rounded-md border border-border p-4"
                                                    >
                                                        <h4 className="font-semibold">
                                                            {
                                                                request.authoredMedication
                                                            }{' '}
                                                            {request.strength}
                                                        </h4>
                                                        <p className="mt-2 text-sm leading-6">
                                                            {request.doseValue}{' '}
                                                            {request.doseUnit} ·{' '}
                                                            {request.route} ·{' '}
                                                            {request.frequency}{' '}
                                                            · {request.duration}
                                                        </p>
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            Jumlah{' '}
                                                            {
                                                                request.quantityValue
                                                            }{' '}
                                                            {
                                                                request.quantityUnit
                                                            }
                                                            {' · '}
                                                            {request.directions}
                                                        </p>
                                                        {request.sourceDiagnosis && (
                                                            <p className="mt-2 text-xs text-primary">
                                                                Sumber:{' '}
                                                                {
                                                                    request.sourceDiagnosis
                                                                }
                                                            </p>
                                                        )}
                                                    </article>
                                                ),
                                            )}
                                        </div>
                                    </section>
                                )}
                            </div>
                        </section>

                        {document.observations.length > 0 && (
                            <section className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white">
                                <div className="flex items-center gap-3 border-b border-border px-5 py-4">
                                    <HeartPulse
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                            LOINC + UCUM
                                        </p>
                                        <h2 className="mt-1 text-xl font-semibold">
                                            Observasi terstruktur
                                        </h2>
                                    </div>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[700px] text-left text-sm">
                                        <caption className="sr-only">
                                            Observasi tanda vital pada versi
                                            klinis yang ditinjau
                                        </caption>
                                        <thead className="border-b border-border bg-muted/40 text-xs text-muted-foreground uppercase">
                                            <tr>
                                                <th
                                                    scope="col"
                                                    className="px-5 py-3"
                                                >
                                                    Observasi
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-5 py-3"
                                                >
                                                    Kode
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-5 py-3"
                                                >
                                                    Nilai
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="px-5 py-3"
                                                >
                                                    Pemetaan
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-border">
                                            {document.observations.map(
                                                (observation) => (
                                                    <tr key={observation.code}>
                                                        <th
                                                            scope="row"
                                                            className="px-5 py-4 font-semibold"
                                                        >
                                                            {
                                                                observation.display
                                                            }
                                                        </th>
                                                        <td className="px-5 py-4 font-mono text-xs">
                                                            LOINC{' '}
                                                            {observation.code}
                                                        </td>
                                                        <td className="px-5 py-4 font-semibold">
                                                            {observation.value}{' '}
                                                            {
                                                                observation.unitDisplay
                                                            }
                                                        </td>
                                                        <td className="px-5 py-4 font-mono text-xs text-muted-foreground">
                                                            {
                                                                observation.mappingVersion
                                                            }
                                                            <br />
                                                            UCUM{' '}
                                                            {
                                                                observation.unitCode
                                                            }
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        )}
                    </div>

                    <aside className="space-y-5">
                        <form
                            onSubmit={submit}
                            className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                        >
                            <div className="border-b border-border px-5 py-4">
                                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                    Keputusan supervisor
                                </p>
                                <h2 className="mt-1 text-xl font-semibold">
                                    Tinjauan versi {document.versionNumber}
                                </h2>
                            </div>

                            {!document.canReview && (
                                <div
                                    role="status"
                                    className="border-b border-border bg-muted/50 px-5 py-4 text-sm text-muted-foreground"
                                >
                                    Versi ini sudah memiliki keputusan dan tidak
                                    dapat ditinjau ulang.
                                </div>
                            )}

                            <InputError
                                message={errors.workflow ?? errors.request_key}
                                className="mx-5 mt-5"
                            />

                            <fieldset
                                disabled={
                                    !document.canReview || form.processing
                                }
                                className="m-0 min-w-0 space-y-5 border-0 p-5 disabled:opacity-70"
                            >
                                <div>
                                    <Label htmlFor="review-comment">
                                        Catatan keputusan
                                    </Label>
                                    <Textarea
                                        id="review-comment"
                                        value={form.data.comment}
                                        onChange={(event) =>
                                            form.setData(
                                                'comment',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Wajib untuk permintaan perbaikan; opsional untuk persetujuan."
                                        aria-invalid={Boolean(errors.comment)}
                                        className="mt-1"
                                    />
                                    <InputError
                                        message={errors.comment}
                                        className="mt-1"
                                    />
                                </div>

                                <section
                                    aria-labelledby="finding-title"
                                    className="rounded-md border border-border bg-muted/30 p-4"
                                >
                                    <h3
                                        id="finding-title"
                                        className="font-semibold"
                                    >
                                        Temuan terstruktur (opsional)
                                    </h3>
                                    <div className="mt-3 space-y-3">
                                        <div>
                                            <Label htmlFor="finding-code">
                                                Kode temuan
                                            </Label>
                                            <Input
                                                id="finding-code"
                                                value={finding.code}
                                                onChange={(event) =>
                                                    setFinding({
                                                        ...finding,
                                                        code: event.target
                                                            .value,
                                                    })
                                                }
                                                placeholder="Contoh: HANDOFF_CLARITY"
                                                className="mt-1 bg-white"
                                            />
                                            <InputError
                                                message={
                                                    errors['findings.0.code']
                                                }
                                                className="mt-1"
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="finding-severity">
                                                Tingkat
                                            </Label>
                                            <select
                                                id="finding-severity"
                                                value={finding.severity}
                                                onChange={(event) =>
                                                    setFinding({
                                                        ...finding,
                                                        severity: event.target
                                                            .value as ClinicalFinding['severity'],
                                                    })
                                                }
                                                className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                            >
                                                <option value="BLOCKING">
                                                    Menghalangi persetujuan
                                                </option>
                                                <option value="NON_BLOCKING">
                                                    Tidak menghalangi
                                                </option>
                                                <option value="INFORMATIONAL">
                                                    Informasional
                                                </option>
                                            </select>
                                        </div>
                                        <div>
                                            <Label htmlFor="finding-message">
                                                Uraian temuan
                                            </Label>
                                            <Textarea
                                                id="finding-message"
                                                value={finding.message}
                                                onChange={(event) =>
                                                    setFinding({
                                                        ...finding,
                                                        message:
                                                            event.target.value,
                                                    })
                                                }
                                                className="mt-1 min-h-20 bg-white"
                                            />
                                            <InputError
                                                message={
                                                    errors['findings.0.message']
                                                }
                                                className="mt-1"
                                            />
                                        </div>
                                    </div>
                                </section>

                                <div className="space-y-2 border-t border-border pt-5">
                                    <Button
                                        type="submit"
                                        name="action"
                                        value="REQUEST_CHANGES"
                                        variant="outline"
                                        className="w-full justify-center border-amber-300 text-amber-900 hover:bg-amber-50"
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
                                        className="w-full justify-center bg-emerald-700 hover:bg-emerald-800"
                                    >
                                        <CheckCircle2
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Setujui untuk simulasi
                                    </Button>
                                </div>
                            </fieldset>
                        </form>

                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-2">
                                <FileSearch
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                <h2 className="text-lg font-semibold">
                                    Riwayat tindakan
                                </h2>
                            </div>
                            <ol className="mt-4 space-y-4">
                                {document.reviews.map((review) => (
                                    <li
                                        key={review.publicId}
                                        className="rounded-md border border-border p-4"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <Badge variant="outline">
                                                {review.action.label}
                                            </Badge>
                                            <Clock3
                                                className="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        </div>
                                        <p className="mt-3 flex items-center gap-2 text-sm font-semibold">
                                            <UserRound
                                                className="size-4 text-primary"
                                                aria-hidden="true"
                                            />
                                            {review.actor}
                                        </p>
                                        <time
                                            dateTime={review.occurredAt}
                                            className="mt-1 block text-xs text-muted-foreground"
                                        >
                                            {formatDateTime(review.occurredAt)}{' '}
                                            WIB
                                        </time>
                                        {review.comment && (
                                            <p className="mt-3 text-sm leading-6">
                                                {review.comment}
                                            </p>
                                        )}
                                        {review.findings &&
                                            review.findings.length > 0 && (
                                                <ul className="mt-3 space-y-2">
                                                    {review.findings.map(
                                                        (item) => (
                                                            <li
                                                                key={`${item.code}-${item.message}`}
                                                                className="rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-950"
                                                            >
                                                                <strong>
                                                                    {item.code}
                                                                </strong>{' '}
                                                                · {item.message}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                    </li>
                                ))}
                            </ol>
                        </section>

                        <section className="rounded-lg border border-orange-200 bg-orange-50 p-5">
                            <div className="flex gap-3 text-[#743719]">
                                <AlertTriangle
                                    className="mt-0.5 size-5 shrink-0"
                                    aria-hidden="true"
                                />
                                <p className="text-sm leading-6">
                                    Jangan menyetujui berdasarkan status saja.
                                    Cocokkan penulis, waktu, isi, observasi, dan
                                    hash versi yang tampil.
                                </p>
                            </div>
                        </section>
                    </aside>
                </div>
            </div>
        </>
    );
}
