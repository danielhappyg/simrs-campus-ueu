import { Link, router, useForm } from '@inertiajs/react';
import {
    ExternalLink,
    FilePenLine,
    ListChecks,
    Stethoscope,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    formatRadiologyDate,
    newRadiologyOperationKey,
    radiologyFieldClass,
} from './operation';
import type { RadiologyOrderProjection, RadiologyWorklistProps } from './types';

export type { RadiologyWorklistProps } from './types';

function WorklistOrder({
    order,
    canPerform,
    canReport,
    amendmentReasons,
}: {
    order: RadiologyOrderProjection;
    canPerform: boolean;
    canReport: boolean;
    amendmentReasons: RadiologyWorklistProps['amendment_reason_options'];
}) {
    const errorRef = useRef<HTMLDivElement>(null);
    const [showReport, setShowReport] = useState(false);
    const [showAmendment, setShowAmendment] = useState(false);
    const performForm = useForm({
        expected_version: order.version,
        idempotency_key: newRadiologyOperationKey('perform'),
    });
    const reportForm = useForm({
        expected_version: order.report?.version ?? 0,
        fields: {
            findings: order.report?.findings ?? '',
            impression: order.report?.impression ?? '',
            recommendation: order.report?.recommendation ?? '',
        },
        idempotency_key: newRadiologyOperationKey('report-draft'),
    });
    const verifyForm = useForm({
        expected_version: order.report?.version ?? 0,
        idempotency_key: newRadiologyOperationKey('report-verify'),
    });
    const amendmentForm = useForm({
        expected_report_version: order.report?.version ?? 0,
        reason: '',
        amended_statement: '',
        idempotency_key: newRadiologyOperationKey('report-amend'),
    });
    const errors = {
        ...performForm.errors,
        ...reportForm.errors,
        ...verifyForm.errors,
        ...amendmentForm.errors,
    };
    const errorMessages = Object.values(errors);
    const errorFingerprint = errorMessages.join('|');

    useEffect(() => {
        if (errorMessages.length > 0) {
            errorRef.current?.focus();
        }
    }, [errorFingerprint, errorMessages.length]);

    const perform = () => {
        if (!canPerform || !order.actions.perform_url) {
            return;
        }

        performForm.post(order.actions.perform_url, {
            preserveScroll: true,
            errorBag: `radiologyPerform.${order.public_id}`,
        });
    };

    const saveReport = (event: FormEvent) => {
        event.preventDefault();

        if (!canReport || !order.actions.save_report_url) {
            return;
        }

        reportForm.post(order.actions.save_report_url, {
            preserveScroll: true,
            errorBag: `radiologyReport.${order.public_id}`,
        });
    };

    const verify = () => {
        if (!canReport || !order.actions.verify_report_url) {
            return;
        }

        verifyForm.post(order.actions.verify_report_url, {
            preserveScroll: true,
            errorBag: `radiologyVerify.${order.public_id}`,
        });
    };

    const amend = (event: FormEvent) => {
        event.preventDefault();

        if (!canReport || !order.actions.amend_report_url) {
            return;
        }

        amendmentForm.post(order.actions.amend_report_url, {
            preserveScroll: true,
            errorBag: `radiologyAmendment.${order.public_id}`,
        });
    };

    return (
        <article className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="grid gap-4 border-b border-slate-100 p-4 lg:grid-cols-[1.2fr_1fr_auto] lg:items-start">
                <div>
                    <p className="font-mono text-xs font-semibold tracking-wide text-[#145a8d]">
                        {order.examination.code} · {order.care_setting}
                    </p>
                    <h3 className="font-['IBM_Plex_Sans_Condensed'] text-xl font-semibold text-slate-950">
                        {order.examination.display_name}
                    </h3>
                    <p className="mt-1 text-sm text-slate-600">
                        {order.patient.display_name} · RM{' '}
                        {order.patient.medical_record_number}
                    </p>
                </div>
                <div className="text-sm text-slate-600">
                    <p className="font-semibold text-slate-800">
                        {order.care_location_label}
                    </p>
                    <p>{order.encounter_number}</p>
                    <p>{formatRadiologyDate(order.ordered_at)}</p>
                </div>
                <Link
                    href={order.encounter_url}
                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-slate-300 px-3 text-sm font-semibold text-slate-800 outline-none hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc]"
                >
                    Buka episode <ExternalLink className="size-4" />
                </Link>
            </div>

            <div className="space-y-4 p-4">
                <div className="rounded-md bg-slate-50 p-3 text-sm">
                    <p className="font-semibold text-slate-800">
                        Pertanyaan klinis
                    </p>
                    <p className="mt-1 whitespace-pre-wrap text-slate-700">
                        {order.clinical_question}
                    </p>
                </div>

                {errorMessages.length > 0 ? (
                    <div
                        ref={errorRef}
                        tabIndex={-1}
                        role="alert"
                        className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 outline-none focus-visible:ring-2 focus-visible:ring-red-600"
                    >
                        <p className="font-semibold">
                            Tindakan belum tersimpan.
                        </p>
                        <ul className="mt-1 list-disc pl-5">
                            {errorMessages.map((message) => (
                                <li key={message}>{message}</li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    {canPerform &&
                    order.state === 'ORDERED' &&
                    order.actions.perform_url ? (
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={perform}
                            disabled={performForm.processing}
                        >
                            <Stethoscope className="mr-2 size-4" /> Catat
                            pemeriksaan selesai
                        </Button>
                    ) : null}
                    {canReport &&
                    order.state === 'PERFORMED' &&
                    order.actions.save_report_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setShowReport((value) => !value)}
                        >
                            <FilePenLine className="mr-2 size-4" /> Susun
                            laporan
                        </Button>
                    ) : null}
                    {canReport &&
                    order.report?.state === 'VERIFIED' &&
                    order.actions.amend_report_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setShowAmendment((value) => !value)}
                        >
                            Tambah adendum
                        </Button>
                    ) : null}
                </div>

                {order.report ? (
                    <section
                        aria-label="Laporan radiologi"
                        className="rounded-md border-l-4 border-[#1b75bc] bg-[#f4f9fc] p-4"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h4 className="font-semibold text-slate-950">
                                Laporan{' '}
                                {order.report.state === 'VERIFIED'
                                    ? 'terverifikasi'
                                    : 'Draft'}
                            </h4>
                            <span className="text-xs font-semibold text-[#145a8d]">
                                Versi {order.report.version}
                            </span>
                        </div>
                        <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="font-semibold text-slate-700">
                                    Temuan
                                </dt>
                                <dd className="mt-1 whitespace-pre-wrap text-slate-800">
                                    {order.report.findings}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-semibold text-slate-700">
                                    Kesimpulan
                                </dt>
                                <dd className="mt-1 whitespace-pre-wrap text-slate-800">
                                    {order.report.impression}
                                </dd>
                            </div>
                        </dl>
                        {order.report.amendments.map((amendment) => (
                            <div
                                key={amendment.public_id}
                                className="mt-3 border-t border-[#b9d9ed] pt-3 text-sm"
                            >
                                <p className="font-semibold text-slate-800">
                                    Adendum · {amendment.reason}
                                </p>
                                <p className="mt-1 whitespace-pre-wrap text-slate-700">
                                    {amendment.amended_statement}
                                </p>
                            </div>
                        ))}
                        {order.report.acknowledgement ? (
                            <p
                                className={`mt-3 border-t border-[#b9d9ed] pt-3 text-xs font-semibold ${order.report.acknowledgement.is_current ? 'text-emerald-800' : 'text-amber-900'}`}
                            >
                                {order.report.acknowledgement.is_current
                                    ? `Sudah diketahui oleh ${order.report.acknowledgement.physician_name}`
                                    : 'Pengetahuan dokter sudah tidak current setelah adendum terbaru.'}
                            </p>
                        ) : order.report.state === 'VERIFIED' ? (
                            <p className="mt-3 border-t border-[#b9d9ed] pt-3 text-xs font-semibold text-amber-900">
                                Menunggu diketahui dokter pemesan.
                            </p>
                        ) : null}
                    </section>
                ) : null}

                {showReport && canReport && order.actions.save_report_url ? (
                    <form
                        onSubmit={saveReport}
                        className="grid gap-4 rounded-lg border border-[#b9d9ed] bg-white p-4 sm:grid-cols-2"
                    >
                        <h4 className="font-semibold text-slate-950 sm:col-span-2">
                            Laporan radiologi
                        </h4>
                        <div className="sm:col-span-2">
                            <p className="text-sm font-medium text-slate-800">
                                Pemeriksaan
                            </p>
                            <p className="mt-1 min-h-11 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900">
                                {order.examination.code} ·{' '}
                                {order.examination.display_name}
                            </p>
                        </div>
                        {(
                            [
                                'findings',
                                'impression',
                                'recommendation',
                            ] as const
                        ).map((field) => (
                            <div key={field}>
                                <Label htmlFor={`${field}-${order.public_id}`}>
                                    {field === 'findings'
                                        ? 'Temuan'
                                        : field === 'impression'
                                          ? 'Kesimpulan'
                                          : 'Rekomendasi (opsional)'}
                                </Label>
                                <textarea
                                    id={`${field}-${order.public_id}`}
                                    rows={4}
                                    className={radiologyFieldClass}
                                    value={reportForm.data.fields[field]}
                                    onChange={(event) =>
                                        reportForm.setData('fields', {
                                            ...reportForm.data.fields,
                                            [field]: event.target.value,
                                        })
                                    }
                                    required={field !== 'recommendation'}
                                />
                            </div>
                        ))}
                        <div className="flex flex-wrap gap-2 sm:col-span-2">
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={reportForm.processing}
                            >
                                Simpan Draft
                            </Button>
                            {order.report?.state === 'DRAFT' &&
                            order.actions.verify_report_url ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={verify}
                                    disabled={verifyForm.processing}
                                >
                                    Verifikasi laporan
                                </Button>
                            ) : null}
                        </div>
                    </form>
                ) : null}

                {showAmendment &&
                canReport &&
                order.actions.amend_report_url ? (
                    <form
                        onSubmit={amend}
                        className="grid gap-4 rounded-lg border border-amber-200 bg-amber-50/50 p-4 sm:grid-cols-2"
                    >
                        <div>
                            <Label htmlFor={`amend-reason-${order.public_id}`}>
                                Alasan adendum
                            </Label>
                            <select
                                id={`amend-reason-${order.public_id}`}
                                className={radiologyFieldClass}
                                value={amendmentForm.data.reason}
                                onChange={(event) =>
                                    amendmentForm.setData(
                                        'reason',
                                        event.target.value,
                                    )
                                }
                                required
                            >
                                <option value="">Pilih alasan</option>
                                {amendmentReasons.map((reason) => (
                                    <option
                                        key={reason.value}
                                        value={reason.value}
                                    >
                                        {reason.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label
                                htmlFor={`amend-statement-${order.public_id}`}
                            >
                                Pernyataan koreksi
                            </Label>
                            <textarea
                                id={`amend-statement-${order.public_id}`}
                                rows={4}
                                className={radiologyFieldClass}
                                value={amendmentForm.data.amended_statement}
                                onChange={(event) =>
                                    amendmentForm.setData(
                                        'amended_statement',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                        </div>
                        <Button
                            type="submit"
                            className="min-h-11 sm:col-span-2 sm:w-fit"
                            disabled={amendmentForm.processing}
                        >
                            Simpan adendum terverifikasi
                        </Button>
                    </form>
                ) : null}
            </div>
        </article>
    );
}

export function RadiologyWorklist(props: RadiologyWorklistProps) {
    const [filters, setFilters] = useState(props.filters);
    const [loading, setLoading] = useState(false);

    const submitFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get('/pemeriksaan/radiologi', filters, {
            preserveState: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    return (
        <main className="mx-auto w-full max-w-7xl space-y-5 p-4 sm:p-6">
            <CareSettingSubnav
                items={[
                    {
                        href: '/pemeriksaan/rawat-jalan',
                        label: 'Rawat Jalan',
                    },
                    { href: '/pemeriksaan/igd', label: 'IGD' },
                    {
                        href: '/pemeriksaan/rawat-inap',
                        label: 'Rawat Inap',
                    },
                    { href: '/pemeriksaan/triage', label: 'Triage' },
                    {
                        href: '/pemeriksaan/laboratorium',
                        label: 'Laboratorium',
                    },
                    {
                        href: '/pemeriksaan/radiologi',
                        label: 'Radiologi',
                        active: true,
                    },
                ]}
            />
            <header className="flex items-start gap-3">
                <span className="grid size-12 shrink-0 place-items-center rounded-lg bg-[#1b75bc] text-white">
                    <ListChecks className="size-6" />
                </span>
                <div>
                    <p className="font-mono text-xs font-semibold tracking-[0.15em] text-[#145a8d]">
                        RADIOLOGI
                    </p>
                    <h1 className="font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold text-slate-950">
                        Worklist Pemeriksaan
                    </h1>
                    <p className="mt-1 text-sm text-slate-600">
                        Satu antrean kerja untuk rawat jalan, IGD, dan rawat
                        inap.
                    </p>
                </div>
            </header>
            <p className="sr-only" role="status" aria-live="polite">
                {loading
                    ? 'Memuat worklist.'
                    : `${props.orders.length} permintaan ditampilkan.`}
            </p>
            {props.read_error ? (
                <div
                    role="alert"
                    className="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-900"
                >
                    {props.read_error}
                </div>
            ) : null}
            <form
                onSubmit={submitFilters}
                className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[1fr_220px_220px_auto] md:items-end"
            >
                <div>
                    <Label htmlFor="radiology-q">
                        Cari pasien, RM, atau pemeriksaan
                    </Label>
                    <input
                        id="radiology-q"
                        className={radiologyFieldClass}
                        value={filters.q}
                        onChange={(event) =>
                            setFilters({ ...filters, q: event.target.value })
                        }
                    />
                </div>
                <div>
                    <Label htmlFor="radiology-setting">Jenis layanan</Label>
                    <select
                        id="radiology-setting"
                        className={radiologyFieldClass}
                        value={filters.care_setting}
                        onChange={(event) =>
                            setFilters({
                                ...filters,
                                care_setting: event.target
                                    .value as typeof filters.care_setting,
                            })
                        }
                    >
                        <option value="">Semua layanan</option>
                        {props.filter_options.care_settings.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <Label htmlFor="radiology-state">Status</Label>
                    <select
                        id="radiology-state"
                        className={radiologyFieldClass}
                        value={filters.state}
                        onChange={(event) =>
                            setFilters({
                                ...filters,
                                state: event.target
                                    .value as typeof filters.state,
                            })
                        }
                    >
                        <option value="">Semua status</option>
                        {props.filter_options.states.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
                <Button type="submit" className="min-h-11" disabled={loading}>
                    Terapkan
                </Button>
            </form>
            <div className="space-y-4">
                {props.orders.length ? (
                    props.orders.map((order) => (
                        <WorklistOrder
                            key={`${order.public_id}:${order.version}:${order.report?.version ?? 0}:${order.report?.amendments.length ?? 0}`}
                            order={order}
                            canPerform={props.permissions.can_perform}
                            canReport={props.permissions.can_report}
                            amendmentReasons={props.amendment_reason_options}
                        />
                    ))
                ) : (
                    <div className="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center">
                        <ListChecks className="mx-auto size-8 text-slate-400" />
                        <p className="mt-3 font-semibold text-slate-800">
                            Tidak ada permintaan pada filter ini.
                        </p>
                    </div>
                )}
            </div>
        </main>
    );
}
