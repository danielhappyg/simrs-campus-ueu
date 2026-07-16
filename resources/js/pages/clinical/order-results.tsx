import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ClipboardCheck,
    FileClock,
    FlaskConical,
    RefreshCw,
    Send,
    ShieldCheck,
} from 'lucide-react';
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
    DiagnosticResultRecord,
    OrderResultWorkspaceProps,
} from '@/types';

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

function ReleaseResultForm({
    requestKey,
    url,
    correction,
}: {
    requestKey: string;
    url: string;
    correction: boolean;
}) {
    const form = useForm({
        request_key: requestKey,
        report_code: '',
        report_display: '',
        effective_at: new Date().toISOString().slice(0, 16),
        narrative_conclusion: '',
        components: [] as Array<{
            code: string;
            display: string;
            value: string;
            unit: string;
        }>,
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(url, { preserveScroll: true });
    }

    return (
        <form
            onSubmit={submit}
            className="mt-4 rounded-md border border-sky-200 bg-sky-50 p-4"
        >
            <div className="flex items-center gap-2 text-primary">
                {correction ? (
                    <RefreshCw className="size-4" aria-hidden="true" />
                ) : (
                    <FlaskConical className="size-4" aria-hidden="true" />
                )}
                <h4 className="font-semibold">
                    {correction
                        ? 'Rilis versi hasil terkoreksi'
                        : 'Rilis hasil sintetis final'}
                </h4>
            </div>
            {correction && (
                <p className="mt-2 text-xs leading-5 text-[#174c68]">
                    Versi lama tetap tersimpan. Acknowledgement terhadap versi
                    lama tidak berlaku untuk koreksi baru.
                </p>
            )}
            <InputError
                message={errors.workflow ?? errors.request_key}
                className="mt-3"
            />
            <fieldset
                disabled={form.processing}
                className="mt-3 grid gap-3 border-0 p-0 md:grid-cols-2"
            >
                <div>
                    <Label htmlFor={`report-code-${requestKey}`}>
                        Kode laporan skenario
                    </Label>
                    <Input
                        id={`report-code-${requestKey}`}
                        value={form.data.report_code}
                        onChange={(event) =>
                            form.setData('report_code', event.target.value)
                        }
                        placeholder="Contoh: LAB-SIM-001"
                        className="mt-1 bg-white"
                    />
                    <InputError message={errors.report_code} className="mt-1" />
                </div>
                <div>
                    <Label htmlFor={`report-display-${requestKey}`}>
                        Nama laporan
                    </Label>
                    <Input
                        id={`report-display-${requestKey}`}
                        value={form.data.report_display}
                        onChange={(event) =>
                            form.setData('report_display', event.target.value)
                        }
                        className="mt-1 bg-white"
                    />
                    <InputError
                        message={errors.report_display}
                        className="mt-1"
                    />
                </div>
                <div>
                    <Label htmlFor={`effective-at-${requestKey}`}>
                        Waktu efektif hasil
                    </Label>
                    <Input
                        id={`effective-at-${requestKey}`}
                        type="datetime-local"
                        value={form.data.effective_at}
                        onChange={(event) =>
                            form.setData('effective_at', event.target.value)
                        }
                        className="mt-1 bg-white"
                    />
                    <InputError
                        message={errors.effective_at}
                        className="mt-1"
                    />
                </div>
                <div className="md:col-span-2">
                    <Label htmlFor={`result-conclusion-${requestKey}`}>
                        Kesimpulan naratif sintetis
                    </Label>
                    <Textarea
                        id={`result-conclusion-${requestKey}`}
                        value={form.data.narrative_conclusion}
                        onChange={(event) =>
                            form.setData(
                                'narrative_conclusion',
                                event.target.value,
                            )
                        }
                        className="mt-1 bg-white"
                    />
                    <InputError
                        message={errors.narrative_conclusion}
                        className="mt-1"
                    />
                </div>
                <div className="flex justify-end md:col-span-2">
                    <Button type="submit" size="sm">
                        <Send className="size-4" aria-hidden="true" />
                        {correction
                            ? 'Rilis koreksi sintetis'
                            : 'Rilis hasil sintetis'}
                    </Button>
                </div>
            </fieldset>
        </form>
    );
}

function AcknowledgeResultForm({ result }: { result: DiagnosticResultRecord }) {
    const form = useForm({
        request_key: result.acknowledgement.requestKey,
        outcome: 'ACKNOWLEDGED',
        comment: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(result.acknowledgement.url, { preserveScroll: true });
    }

    return (
        <form
            onSubmit={submit}
            className="mt-4 rounded-md border border-emerald-200 bg-emerald-50 p-4"
        >
            <h4 className="flex items-center gap-2 font-semibold text-emerald-950">
                <ClipboardCheck className="size-4" aria-hidden="true" />
                Acknowledge versi saat ini
            </h4>
            <InputError
                message={errors.workflow ?? errors.request_key}
                className="mt-2"
            />
            <fieldset
                disabled={form.processing}
                className="mt-3 space-y-3 border-0 p-0"
            >
                <div>
                    <Label htmlFor={`ack-outcome-${result.publicId}`}>
                        Hasil tinjauan
                    </Label>
                    <select
                        id={`ack-outcome-${result.publicId}`}
                        value={form.data.outcome}
                        onChange={(event) =>
                            form.setData('outcome', event.target.value)
                        }
                        className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <option value="ACKNOWLEDGED">Telah ditinjau</option>
                        <option value="ACKNOWLEDGED_PLAN_REVIEW_REQUIRED">
                            Telah ditinjau — rencana perlu dikaji ulang
                        </option>
                    </select>
                </div>
                <div>
                    <Label htmlFor={`ack-comment-${result.publicId}`}>
                        Catatan
                    </Label>
                    <Textarea
                        id={`ack-comment-${result.publicId}`}
                        value={form.data.comment}
                        onChange={(event) =>
                            form.setData('comment', event.target.value)
                        }
                        className="mt-1 min-h-20 bg-white"
                    />
                    <InputError message={errors.comment} className="mt-1" />
                </div>
                <Button type="submit" size="sm">
                    <CheckCircle2 className="size-4" aria-hidden="true" />
                    Simpan acknowledgement
                </Button>
            </fieldset>
        </form>
    );
}

export default function OrderResultsWorkspace({
    encounter,
    patient,
    session,
    assignment,
    serviceRequests,
    urls,
}: OrderResultWorkspaceProps) {
    return (
        <>
            <Head title="Pesanan dan hasil simulasi" />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Result loop · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Pesanan &amp; Hasil Simulasi
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Setiap hasil ditautkan ke pesanan dan versi asesmen
                            medis sumber. Koreksi membuat versi baru dan harus
                            diakui kembali oleh pemesan.
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

                <section className="rounded-lg border border-orange-200 bg-orange-50 px-5 py-4">
                    <div className="flex gap-3">
                        <AlertTriangle
                            className="mt-0.5 size-5 shrink-0 text-[#a3471f]"
                            aria-hidden="true"
                        />
                        <div>
                            <h2 className="font-semibold text-[#743719]">
                                Hasil sepenuhnya sintetis
                            </h2>
                            <p className="mt-1 text-sm leading-6 text-[#743719]">
                                Rilis hasil hanya memasukkan fixture
                                pembelajaran. Tidak ada koneksi laboratorium,
                                radiologi, alat, atau endpoint produksi. Sistem
                                tidak menafsirkan hasil menjadi diagnosis atau
                                terapi.
                            </p>
                        </div>
                    </div>
                </section>

                {serviceRequests.length === 0 ? (
                    <section className="clinical-shadow rounded-lg border border-border bg-white px-6 py-14 text-center">
                        <FlaskConical
                            className="mx-auto size-9 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <h2 className="mt-3 text-xl font-semibold">
                            Belum ada pesanan pemeriksaan
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Pesanan muncul setelah versi asesmen medis yang
                            memuatnya disetujui untuk simulasi.
                        </p>
                    </section>
                ) : (
                    <div className="space-y-5">
                        {serviceRequests.map((request) => {
                            const currentResult = request.results.find(
                                (result) => result.current,
                            );

                            return (
                                <article
                                    key={request.publicId}
                                    className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant="outline">
                                                    {request.requestType}
                                                </Badge>
                                                <Badge variant="outline">
                                                    {request.priority}
                                                </Badge>
                                                <Badge variant="outline">
                                                    {request.status}
                                                </Badge>
                                            </div>
                                            <h2 className="mt-2 text-xl font-semibold">
                                                {request.authoredService}
                                            </h2>
                                            <p className="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">
                                                {request.clinicalQuestion}
                                            </p>
                                        </div>
                                        <span className="font-mono text-xs text-muted-foreground">
                                            Pesanan #{request.sequenceNumber}
                                        </span>
                                    </div>

                                    <div className="grid items-start gap-5 p-5 xl:grid-cols-[22rem_minmax(0,1fr)]">
                                        <div className="space-y-4">
                                            <ClinicalVersionStamp
                                                compact
                                                versionNumber={
                                                    request.source
                                                        .medicalVersionNumber
                                                }
                                                schemaVersion="medical-assessment.v1"
                                                contentHash={
                                                    request.source
                                                        .medicalContentHash
                                                }
                                                status={{
                                                    code: 'APPROVED',
                                                    label: 'Sumber disetujui',
                                                }}
                                            />
                                            {request.source.diagnosis && (
                                                <div className="rounded-md border border-border p-4">
                                                    <p className="text-[0.68rem] font-bold tracking-wider text-muted-foreground uppercase">
                                                        Diagnosis sumber
                                                    </p>
                                                    <p className="mt-2 text-sm leading-6 font-semibold">
                                                        {
                                                            request.source
                                                                .diagnosis
                                                        }
                                                    </p>
                                                </div>
                                            )}
                                            <p className="text-xs text-muted-foreground">
                                                Ditulis {request.requester} ·{' '}
                                                {formatDateTime(
                                                    request.authoredAt,
                                                )}{' '}
                                                WIB
                                            </p>
                                        </div>

                                        <div>
                                            {request.results.length === 0 ? (
                                                <div className="rounded-md border border-dashed border-border px-5 py-10 text-center">
                                                    <FileClock
                                                        className="mx-auto size-8 text-muted-foreground"
                                                        aria-hidden="true"
                                                    />
                                                    <h3 className="mt-3 font-semibold">
                                                        Menunggu hasil sintetis
                                                    </h3>
                                                </div>
                                            ) : (
                                                <ol className="space-y-4">
                                                    {request.results.map(
                                                        (result) => (
                                                            <li
                                                                key={
                                                                    result.publicId
                                                                }
                                                                className={cn(
                                                                    'rounded-md border p-4',
                                                                    result.current
                                                                        ? 'border-primary bg-sky-50/50'
                                                                        : 'border-border bg-muted/30',
                                                                )}
                                                            >
                                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                                    <div className="flex flex-wrap items-center gap-2">
                                                                        <Badge variant="outline">
                                                                            v
                                                                            {
                                                                                result.versionNumber
                                                                            }
                                                                        </Badge>
                                                                        <Badge variant="outline">
                                                                            {
                                                                                result
                                                                                    .status
                                                                                    .label
                                                                            }
                                                                        </Badge>
                                                                        {result.current && (
                                                                            <Badge className="bg-primary text-white">
                                                                                Versi
                                                                                saat
                                                                                ini
                                                                            </Badge>
                                                                        )}
                                                                    </div>
                                                                    <span className="font-mono text-xs text-muted-foreground">
                                                                        {
                                                                            result.reportCode
                                                                        }
                                                                    </span>
                                                                </div>
                                                                <h3 className="mt-3 font-semibold">
                                                                    {
                                                                        result.reportDisplay
                                                                    }
                                                                </h3>
                                                                <p className="mt-2 text-sm leading-6 whitespace-pre-wrap">
                                                                    {
                                                                        result
                                                                            .content
                                                                            .narrativeConclusion
                                                                    }
                                                                </p>
                                                                <dl className="mt-3 grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                                                                    <div>
                                                                        <dt>
                                                                            Efektif
                                                                        </dt>
                                                                        <dd className="font-medium text-foreground">
                                                                            {formatDateTime(
                                                                                result.effectiveAt,
                                                                            )}{' '}
                                                                            WIB
                                                                        </dd>
                                                                    </div>
                                                                    <div>
                                                                        <dt>
                                                                            Perilis
                                                                        </dt>
                                                                        <dd className="font-medium text-foreground">
                                                                            {
                                                                                result.performer
                                                                            }
                                                                        </dd>
                                                                    </div>
                                                                </dl>
                                                                <code className="mt-3 block rounded border border-border bg-white p-2 font-mono text-[0.65rem] break-all text-muted-foreground">
                                                                    SHA-256{' '}
                                                                    {
                                                                        result.contentHash
                                                                    }
                                                                </code>

                                                                {result
                                                                    .acknowledgements
                                                                    .length >
                                                                    0 && (
                                                                    <div className="mt-3 rounded border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-950">
                                                                        <p className="font-semibold">
                                                                            Acknowledgement
                                                                        </p>
                                                                        {result.acknowledgements.map(
                                                                            (
                                                                                acknowledgement,
                                                                            ) => (
                                                                                <p
                                                                                    key={
                                                                                        acknowledgement.publicId
                                                                                    }
                                                                                    className="mt-1"
                                                                                >
                                                                                    {
                                                                                        acknowledgement.actor
                                                                                    }{' '}
                                                                                    ·{' '}
                                                                                    {
                                                                                        acknowledgement.outcome
                                                                                    }
                                                                                </p>
                                                                            ),
                                                                        )}
                                                                    </div>
                                                                )}

                                                                {result
                                                                    .acknowledgement
                                                                    .allowed && (
                                                                    <AcknowledgeResultForm
                                                                        result={
                                                                            result
                                                                        }
                                                                    />
                                                                )}
                                                            </li>
                                                        ),
                                                    )}
                                                </ol>
                                            )}

                                            {request.release.allowed && (
                                                <ReleaseResultForm
                                                    requestKey={
                                                        request.release
                                                            .requestKey
                                                    }
                                                    url={request.release.url}
                                                    correction={Boolean(
                                                        currentResult,
                                                    )}
                                                />
                                            )}
                                        </div>
                                    </div>
                                </article>
                            );
                        })}
                    </div>
                )}

                <section className="rounded-lg border border-emerald-200 bg-emerald-50 p-5">
                    <div className="flex gap-3 text-emerald-950">
                        <ShieldCheck
                            className="mt-0.5 size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <p className="text-sm leading-6">
                            Handoff berikutnya terbuka hanya setelah semua hasil
                            saat ini diakui. Koreksi baru otomatis membuat
                            acknowledgement versi sebelumnya tidak mencukupi.
                        </p>
                    </div>
                </section>
            </div>
        </>
    );
}
