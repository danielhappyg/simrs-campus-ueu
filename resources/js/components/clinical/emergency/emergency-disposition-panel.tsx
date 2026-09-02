import { useForm } from '@inertiajs/react';
import {
    BedSingle,
    CheckCircle2,
    ClipboardCheck,
    History,
    UserCheck,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { DocumentErrorSummary } from '../outpatient/document-error-summary';
import { EmergencyDiagnosticAssignmentHistory } from './emergency-follow-up-history';
import {
    EmergencyEmptyState,
    EvidenceTime,
    PendingRequirement,
} from './emergency-shared';
import {
    emergencyFieldClass,
    newEmergencyOperationKey,
    toLocalDateTimeInput,
} from './operation';
import type {
    EmergencyCorrectionIntent,
    EmergencyDiagnosticFollowUpItem,
    EmergencyDispositionCode,
    EmergencyDispositionDetails,
    EmergencyDispositionProjection,
    EmergencyDispositionVersion,
    EmergencyFollowUpProjection,
} from './types';

const dispositionOptions: Array<{
    code: EmergencyDispositionCode;
    label: string;
    description: string;
}> = [
    {
        code: 'PULANG',
        label: 'Pulang',
        description: 'Pasien pulang dengan instruksi dan tindak lanjut.',
    },
    {
        code: 'DIRUJUK',
        label: 'Dirujuk',
        description: 'Rencana rujukan dan serah terima lokal.',
    },
    {
        code: 'RAWAT_INAP',
        label: 'Rawat inap',
        description:
            'Keputusan dokter; penempatan tempat tidur oleh pendaftaran.',
    },
    {
        code: 'MENINGGAL_DI_IGD',
        label: 'Meninggal di IGD',
        description: 'Fakta episode yang dicatat dokter.',
    },
    {
        code: 'DOA',
        label: 'DOA',
        description: 'Fakta kedatangan yang dicatat dokter.',
    },
];

const detailFields: Record<
    EmergencyDispositionCode,
    Array<{ key: string; label: string }>
> = {
    PULANG: [
        { key: 'condition_at_discharge', label: 'Kondisi saat pulang' },
        { key: 'instructions', label: 'Instruksi pasien / keluarga' },
        { key: 'warning_signs', label: 'Tanda bahaya' },
        { key: 'follow_up_plan', label: 'Rencana tindak lanjut' },
    ],
    DIRUJUK: [
        { key: 'destination', label: 'Tujuan rujukan' },
        { key: 'clinical_reason', label: 'Alasan klinis' },
        { key: 'transport_plan', label: 'Rencana transportasi' },
        { key: 'handoff_note', label: 'Catatan serah terima' },
    ],
    RAWAT_INAP: [
        { key: 'admission_reason', label: 'Alasan rawat inap' },
        {
            key: 'receiving_unit_handoff_note',
            label: 'Catatan untuk unit penerima',
        },
    ],
    MENINGGAL_DI_IGD: [
        { key: 'event_time', label: 'Waktu kejadian' },
        { key: 'clinical_note', label: 'Catatan klinis terbatas' },
    ],
    DOA: [
        {
            key: 'arrival_declaration_time',
            label: 'Waktu kedatangan / pernyataan',
        },
        { key: 'clinical_note', label: 'Catatan klinis terbatas' },
    ],
};

function FollowUpItem({
    item,
    projection,
}: {
    item: EmergencyDiagnosticFollowUpItem;
    projection: EmergencyFollowUpProjection;
}) {
    const proposeForm = useForm({
        order_type: item.order_type,
        order_public_id: item.order_public_id,
        expected_result_fingerprint: item.fingerprint,
        assignee_physician_public_id:
            projection.physician_options[0]?.value ?? '',
        assignment_reason: '',
        effective_at: toLocalDateTimeInput(),
        handoff_note: '',
        expected_assignment_version: item.current?.version ?? 0,
        idempotency_key: newEmergencyOperationKey(
            'diagnostic-follow-up-propose',
        ),
    });
    const acceptForm = useForm({
        order_type: item.order_type,
        order_public_id: item.order_public_id,
        proposal_public_id: item.current?.public_id ?? '',
        expected_proposal_fingerprint: item.current?.fingerprint ?? '',
        expected_result_fingerprint: item.fingerprint,
        idempotency_key: newEmergencyOperationKey(
            'diagnostic-follow-up-accept',
        ),
    });
    const propose = (event: FormEvent) => {
        event.preventDefault();

        if (!item.actions.propose_url) {
            return;
        }

        proposeForm.post(item.actions.propose_url, {
            preserveScroll: true,
        });
    };

    return (
        <article className="rounded-lg border border-border bg-card p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-semibold">{item.label}</p>
                    <p className="text-xs text-muted-foreground">
                        {item.order_type === 'LABORATORY'
                            ? 'Laboratorium'
                            : 'Radiologi'}{' '}
                        · {item.order_public_id}
                    </p>
                </div>
                <span className="rounded-full bg-warning/10 px-2 py-1 text-xs font-semibold text-warning">
                    Belum selesai
                </span>
            </div>
            {item.current ? (
                <div className="mt-3 rounded-lg border border-border bg-muted/25 p-3 text-sm">
                    <p className="font-semibold">
                        {item.current.assignee.name ?? 'Dokter tidak tersedia'}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {item.current.state === 'ACCEPTED'
                            ? 'Tugas telah diterima'
                            : 'Menunggu penerimaan'}{' '}
                        · v{item.current.version}
                    </p>
                    <p className="mt-2 whitespace-pre-wrap">
                        {item.current.reason}
                    </p>
                    <EvidenceTime
                        value={
                            item.current.accepted_at ?? item.current.proposed_at
                        }
                    />
                </div>
            ) : (
                <div className="mt-3">
                    <EmergencyEmptyState
                        title="Belum ada penugasan"
                        body="Dokter pemesan tetap bertanggung jawab sampai penugasan diterima."
                    />
                </div>
            )}
            {projection.permission.can_propose && item.actions.propose_url ? (
                <form
                    onSubmit={propose}
                    className="mt-4 grid gap-3 md:grid-cols-2"
                >
                    <label className="text-xs font-semibold">
                        Dokter penerima
                        <select
                            value={
                                proposeForm.data.assignee_physician_public_id
                            }
                            onChange={(e) =>
                                proposeForm.setData(
                                    'assignee_physician_public_id',
                                    e.target.value,
                                )
                            }
                            className={emergencyFieldClass}
                            required
                        >
                            {projection.physician_options.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="text-xs font-semibold">
                        Mulai berlaku
                        <input
                            type="datetime-local"
                            value={proposeForm.data.effective_at}
                            onChange={(e) =>
                                proposeForm.setData(
                                    'effective_at',
                                    e.target.value,
                                )
                            }
                            className={emergencyFieldClass}
                            required
                        />
                    </label>
                    <label className="text-xs font-semibold">
                        Alasan penugasan
                        <textarea
                            value={proposeForm.data.assignment_reason}
                            onChange={(e) =>
                                proposeForm.setData(
                                    'assignment_reason',
                                    e.target.value,
                                )
                            }
                            className={cn(emergencyFieldClass, 'min-h-20')}
                            required
                        />
                    </label>
                    <label className="text-xs font-semibold">
                        Catatan serah terima
                        <textarea
                            value={proposeForm.data.handoff_note}
                            onChange={(e) =>
                                proposeForm.setData(
                                    'handoff_note',
                                    e.target.value,
                                )
                            }
                            className={cn(emergencyFieldClass, 'min-h-20')}
                            required
                        />
                    </label>
                    <div className="md:col-span-2 md:text-right">
                        <Button
                            type="submit"
                            disabled={proposeForm.processing}
                            className="min-h-11"
                        >
                            Ajukan penanggung jawab
                        </Button>
                    </div>
                </form>
            ) : null}
            {projection.permission.can_accept &&
            item.current?.state === 'PROPOSED' &&
            item.actions.accept_url ? (
                <div className="mt-3 text-right">
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        disabled={acceptForm.processing}
                        onClick={() =>
                            acceptForm.post(item.actions.accept_url!, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Terima penugasan
                    </Button>
                </div>
            ) : null}
            {item.history.length > 0 ? (
                <details className="mt-4 border-t border-border pt-3">
                    <summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold">
                        Riwayat penugasan ({item.history.length})
                    </summary>
                    <ol className="mt-2 space-y-2">
                        {item.history.map((assignment) => (
                            <li
                                key={assignment.public_id}
                                className="rounded-md bg-muted/30 p-3 text-xs"
                            >
                                <p className="font-semibold">
                                    v{assignment.version} ·{' '}
                                    {assignment.assignee.name ??
                                        'Dokter tidak tersedia'}{' '}
                                    · {assignment.state}
                                </p>
                                <p className="mt-1">{assignment.reason}</p>
                                <p className="mt-1 text-muted-foreground">
                                    Diajukan{' '}
                                    {assignment.proposed_by.name ?? '—'} ·{' '}
                                    {assignment.proposed_at
                                        ? new Date(
                                              assignment.proposed_at,
                                          ).toLocaleString('id-ID')
                                        : '—'}
                                </p>
                            </li>
                        ))}
                    </ol>
                </details>
            ) : null}
        </article>
    );
}

function FollowUpPanel({
    projection,
}: {
    projection: EmergencyFollowUpProjection;
}) {
    return (
        <section
            aria-labelledby="follow-up-title"
            className="rounded-xl border border-border bg-card p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                        Hasil penunjang belum selesai
                    </p>
                    <h3
                        id="follow-up-title"
                        className="mt-0.5 flex items-center gap-2 font-semibold"
                    >
                        <UserCheck
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />{' '}
                        Penanggung jawab per order
                    </h3>
                </div>
                <span className="rounded-full bg-warning/10 px-2.5 py-1 text-xs font-semibold text-warning">
                    {projection.unresolved_diagnostic_count} belum selesai
                </span>
            </div>
            {projection.unresolved_diagnostics.length ? (
                <div className="mt-3 space-y-3">
                    {projection.unresolved_diagnostics.map((item) => (
                        <FollowUpItem
                            key={`${item.order_type}:${item.order_public_id}`}
                            item={item}
                            projection={projection}
                        />
                    ))}
                </div>
            ) : (
                <div className="mt-3">
                    <EmergencyEmptyState
                        title="Tidak ada hasil yang perlu dialihkan"
                        body="Seluruh pemeriksaan penunjang sudah selesai atau tetap berada pada dokter pemesan."
                    />
                </div>
            )}
            <div className="mt-4">
                <EmergencyDiagnosticAssignmentHistory
                    items={projection.diagnostic_assignment_history}
                />
            </div>
        </section>
    );
}

function DispositionForm({
    projection,
}: {
    projection: EmergencyDispositionProjection;
}) {
    const [initialExpiry] = useState(() =>
        toLocalDateTimeInput(
            new Date(Date.now() + 60 * 60 * 1000).toISOString(),
        ),
    );
    const [code, setCode] = useState<EmergencyDispositionCode>('PULANG');
    const [details, setDetails] = useState<EmergencyDispositionDetails>({});
    const createsCorrectionIntent =
        projection.current?.code === 'RAWAT_INAP' &&
        projection.handoff?.state === 'COMPLETED' &&
        projection.actions.create_correction_intent_url !== null;
    const form = useForm({
        expected_disposition_version: projection.current?.version ?? 0,
        disposition_type: code,
        payload: details,
        replacement_type: code,
        replacement_payload: details,
        reason: '',
        expires_at: initialExpiry,
        idempotency_key: newEmergencyOperationKey('disposition-sign'),
    });
    const [attempted, setAttempted] = useState(false);
    const requirementsComplete = Object.values(projection.requirements).every(
        Boolean,
    );
    const actionUrl = createsCorrectionIntent
        ? projection.actions.create_correction_intent_url
        : projection.current
          ? projection.actions.correct_url
          : projection.actions.sign_url;
    const required = detailFields[code].filter(
        ({ key }) => !(details[key] ?? '').trim(),
    );
    const errors = {
        ...form.errors,
        ...(attempted && required.length
            ? {
                  details: `Lengkapi: ${required.map(({ label }) => label).join(', ')}.`,
              }
            : {}),
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setAttempted(true);

        if (!actionUrl || required.length) {
            return;
        }

        form.transform((data) => ({
            ...(projection.current
                ? {
                      expected_disposition_version:
                          data.expected_disposition_version,
                      replacement_type: code,
                      replacement_payload: details,
                      reason: data.reason,
                      ...(createsCorrectionIntent
                          ? { expires_at: data.expires_at }
                          : {}),
                  }
                : {
                      disposition_type: code,
                      payload: details,
                  }),
            idempotency_key: data.idempotency_key,
        }));
        form.post(actionUrl, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <DocumentErrorSummary errors={errors} />
            <fieldset>
                <legend className="text-sm font-semibold">
                    Pilih disposisi
                </legend>
                <div className="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                    {dispositionOptions.map((option) => (
                        <label
                            key={option.code}
                            className={cn(
                                'flex min-h-24 cursor-pointer gap-2 rounded-lg border p-3 focus-within:ring-2 focus-within:ring-ring',
                                code === option.code
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'border-border',
                            )}
                        >
                            <input
                                type="radio"
                                name="disposition"
                                value={option.code}
                                checked={code === option.code}
                                onChange={() => {
                                    setCode(option.code);
                                    setDetails({});
                                }}
                                className="mt-0.5 size-4"
                            />
                            <span>
                                <span className="block text-sm font-semibold">
                                    {option.label}
                                </span>
                                <span className="mt-1 block text-xs text-muted-foreground">
                                    {option.description}
                                </span>
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>
            <div className="grid gap-3 md:grid-cols-2">
                {detailFields[code].map(({ key, label }) => (
                    <label key={key} className="text-sm font-semibold">
                        {label}
                        {key.endsWith('_at') || key.endsWith('_time') ? (
                            <input
                                type="datetime-local"
                                value={details[key] ?? ''}
                                onChange={(e) =>
                                    setDetails((current) => ({
                                        ...current,
                                        [key]: e.target.value,
                                    }))
                                }
                                className={emergencyFieldClass}
                                required
                            />
                        ) : (
                            <textarea
                                value={details[key] ?? ''}
                                onChange={(e) =>
                                    setDetails((current) => ({
                                        ...current,
                                        [key]: e.target.value,
                                    }))
                                }
                                className={cn(emergencyFieldClass, 'min-h-24')}
                                required
                            />
                        )}
                    </label>
                ))}
            </div>
            {projection.current ? (
                <label className="block text-sm font-semibold">
                    Alasan koreksi
                    <textarea
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        className={cn(emergencyFieldClass, 'min-h-20')}
                        required
                    />
                </label>
            ) : null}
            {createsCorrectionIntent ? (
                <label className="block text-sm font-semibold">
                    Berlaku sampai
                    <input
                        type="datetime-local"
                        value={form.data.expires_at}
                        onChange={(event) =>
                            form.setData('expires_at', event.target.value)
                        }
                        className={emergencyFieldClass}
                        required
                    />
                    <span className="mt-1 block text-xs font-normal text-muted-foreground">
                        Petugas pendaftaran harus mengeksekusi maksud koreksi
                        sebelum waktu ini dan sebelum episode Rawat Inap
                        memiliki bukti lanjutan.
                    </span>
                </label>
            ) : null}
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing || !requirementsComplete}
                    className="min-h-11"
                >
                    {createsCorrectionIntent
                        ? 'Ajukan maksud koreksi'
                        : projection.current
                          ? 'Tandatangani koreksi'
                          : 'Tandatangani disposisi'}
                </Button>
            </div>
            {!requirementsComplete ? (
                <p role="status" className="text-right text-xs text-warning">
                    Lengkapi seluruh persyaratan di atas sebelum
                    penandatanganan.
                </p>
            ) : null}
        </form>
    );
}

function RevokeCorrectionIntent({
    intent,
}: {
    intent: EmergencyCorrectionIntent;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        expected_intent_fingerprint: intent.fingerprint,
        reason: '',
        idempotency_key: newEmergencyOperationKey('correction-intent-revoke'),
    });

    if (intent.state !== 'PENDING' || !intent.actions.revoke_url) {
        return null;
    }

    return open ? (
        <form
            className="mt-3 rounded-md border border-destructive/25 bg-background p-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(intent.actions.revoke_url!, { preserveScroll: true });
            }}
        >
            <label className="text-sm font-semibold">
                Alasan pencabutan
                <textarea
                    className={cn(emergencyFieldClass, 'min-h-20')}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    required
                />
            </label>
            <div className="mt-3 flex flex-wrap gap-2">
                <Button
                    type="submit"
                    variant="destructive"
                    className="min-h-11"
                    disabled={form.processing || !form.data.reason.trim()}
                >
                    Cabut maksud koreksi
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={() => setOpen(false)}
                >
                    Kembali
                </Button>
            </div>
        </form>
    ) : (
        <Button
            type="button"
            variant="outline"
            className="mt-3 min-h-11 text-destructive"
            onClick={() => setOpen(true)}
        >
            Cabut maksud koreksi
        </Button>
    );
}

function HandoffPanel({
    projection,
}: {
    projection: EmergencyDispositionProjection;
}) {
    const handoffForm = useForm({
        disposition_public_id: projection.current?.public_id ?? '',
        expected_disposition_version: projection.current?.version ?? 0,
        bed_public_id: projection.bed_options[0]?.public_id ?? '',
        idempotency_key: newEmergencyOperationKey('inpatient-handoff'),
    });
    const pendingIntent = projection.correction_intents.find(
        (intent) => intent.state === 'PENDING',
    );
    const compensateForm = useForm({
        correction_intent_public_id: pendingIntent?.public_id ?? '',
        idempotency_key: newEmergencyOperationKey('handoff-compensate'),
    });

    if (projection.current?.code !== 'RAWAT_INAP' && !projection.handoff) {
        return null;
    }

    return (
        <section className="rounded-xl border border-border bg-card p-4">
            <h3 className="flex items-center gap-2 font-semibold">
                <BedSingle aria-hidden="true" className="size-4 text-primary" />{' '}
                Serah terima ke Rawat Inap
            </h3>
            {projection.handoff ? (
                <div className="mt-3 rounded-lg border border-success/30 bg-success/5 p-3 text-sm">
                    <p className="font-semibold text-success">
                        {projection.handoff.state === 'COMPENSATED'
                            ? 'Serah terima telah dikompensasi'
                            : 'Serah terima selesai'}
                    </p>
                    <p className="mt-1">
                        {projection.handoff.ward_display_name ?? 'Unit'} ·{' '}
                        {projection.handoff.bed_code ?? 'Tempat tidur'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Petugas: {projection.handoff.registrar_name ?? '—'} ·{' '}
                        {projection.handoff.completed_at
                            ? new Date(
                                  projection.handoff.completed_at,
                              ).toLocaleString('id-ID')
                            : '—'}
                    </p>
                    {projection.handoff.target_encounter_url ? (
                        <a
                            href={projection.handoff.target_encounter_url}
                            className="mt-2 inline-flex min-h-11 items-center font-semibold text-primary hover:underline"
                        >
                            Buka episode Rawat Inap
                        </a>
                    ) : null}
                </div>
            ) : null}
            {projection.permissions.can_handoff &&
            projection.actions.handoff_url &&
            !projection.handoff ? (
                <form
                    className="mt-4 flex flex-col gap-3 md:flex-row md:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();
                        handoffForm.post(projection.actions.handoff_url!, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="flex-1 text-sm font-semibold">
                        Tempat tidur tersedia
                        <select
                            value={handoffForm.data.bed_public_id}
                            onChange={(e) =>
                                handoffForm.setData(
                                    'bed_public_id',
                                    e.target.value,
                                )
                            }
                            className={emergencyFieldClass}
                            required
                        >
                            {projection.bed_options.map((bed) => (
                                <option
                                    key={bed.public_id}
                                    value={bed.public_id}
                                >
                                    {bed.ward_display_name} · {bed.room_label} ·{' '}
                                    {bed.display_name} · {bed.service_class}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button
                        type="submit"
                        disabled={
                            handoffForm.processing ||
                            !handoffForm.data.bed_public_id
                        }
                        className="min-h-11"
                    >
                        Tempatkan pasien
                    </Button>
                </form>
            ) : null}
            {pendingIntent ? (
                <div className="mt-4 rounded-lg border border-warning/30 bg-warning/5 p-3 text-sm">
                    <p className="font-semibold text-warning">
                        Koreksi dokter menunggu eksekusi
                    </p>
                    <p className="mt-1">
                        Pengganti: {pendingIntent.replacement_label}
                    </p>
                    <p className="mt-1 text-xs">{pendingIntent.reason}</p>
                    {projection.permissions.can_compensate &&
                    projection.actions.compensate_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-3 min-h-11"
                            disabled={compensateForm.processing}
                            onClick={() =>
                                compensateForm.post(
                                    projection.actions.compensate_url!,
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Batalkan episode RI dan aktifkan koreksi
                        </Button>
                    ) : null}
                    <RevokeCorrectionIntent intent={pendingIntent} />
                </div>
            ) : null}
        </section>
    );
}

function DispositionVersionCard({
    version,
}: {
    version: EmergencyDispositionVersion;
}) {
    return (
        <li className="rounded-lg border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-semibold">{version.label}</p>
                    <p className="text-xs text-muted-foreground">
                        Versi {version.version}
                        {version.supersedes_public_id
                            ? ' · koreksi'
                            : ' · awal'}
                    </p>
                </div>
                <div className="text-right">
                    <p className="text-xs font-semibold">
                        {version.physician.name ?? 'Dokter tidak tersedia'}
                    </p>
                    <EvidenceTime value={version.signed_at} />
                </div>
            </div>
            {version.correction_reason ? (
                <p className="mt-3 rounded-md bg-warning/5 p-2 text-sm">
                    <span className="font-semibold">Alasan koreksi:</span>{' '}
                    {version.correction_reason}
                </p>
            ) : null}
            <dl className="mt-3 grid gap-2 md:grid-cols-2">
                {Object.entries(version.details).map(([key, value]) => (
                    <div key={key}>
                        <dt className="text-xs font-semibold text-muted-foreground">
                            {key.replaceAll('_', ' ')}
                        </dt>
                        <dd className="mt-0.5 text-sm whitespace-pre-wrap">
                            {value || '—'}
                        </dd>
                    </div>
                ))}
            </dl>
            {version.content_digest ? (
                <p className="mt-3 font-mono text-[0.65rem] text-muted-foreground">
                    Jejak {version.content_digest}
                </p>
            ) : null}
        </li>
    );
}

export function EmergencyDispositionPanel({
    disposition,
    followUp,
}: {
    disposition: EmergencyDispositionProjection;
    followUp: EmergencyFollowUpProjection;
}) {
    const requirementsComplete = Object.values(disposition.requirements).every(
        Boolean,
    );
    const canShowForm =
        (disposition.permissions.can_sign ||
            disposition.permissions.can_correct) &&
        (disposition.actions.sign_url ||
            disposition.actions.correct_url ||
            disposition.actions.create_correction_intent_url);

    return (
        <section aria-labelledby="disposition-title" className="space-y-4">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[0.68rem] font-semibold tracking-[0.13em] text-muted-foreground uppercase">
                        Keputusan dokter
                    </p>
                    <h2
                        id="disposition-title"
                        className="mt-0.5 flex items-center gap-2 text-lg font-semibold"
                    >
                        <ClipboardCheck
                            aria-hidden="true"
                            className="size-5 text-primary"
                        />{' '}
                        Disposisi dan serah terima
                    </h2>
                </div>
                {disposition.current ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-xs font-semibold text-success">
                        <CheckCircle2 aria-hidden="true" className="size-3.5" />{' '}
                        {disposition.current.label} · v
                        {disposition.current.version}
                    </span>
                ) : null}
            </header>
            <div className="grid gap-4 xl:grid-cols-[20rem_minmax(0,1fr)]">
                <div className="rounded-xl border border-border bg-card p-4">
                    <h3 className="font-semibold">
                        Persyaratan penandatanganan
                    </h3>
                    <ul className="mt-3 space-y-2">
                        <PendingRequirement
                            complete={
                                disposition.requirements.initial_triage_final
                            }
                        >
                            Asesmen triage awal sudah Final
                        </PendingRequirement>
                        <PendingRequirement
                            complete={disposition.requirements.nursing_final}
                        >
                            Dokumentasi keperawatan sudah Final
                        </PendingRequirement>
                        <PendingRequirement
                            complete={disposition.requirements.medical_final}
                        >
                            Dokumentasi medis sudah Final
                        </PendingRequirement>
                        <PendingRequirement
                            complete={
                                disposition.requirements
                                    .diagnostic_follow_up_resolved
                            }
                        >
                            Tindak lanjut hasil penunjang sudah diterima atau
                            tidak diperlukan
                        </PendingRequirement>
                    </ul>
                </div>
                <FollowUpPanel projection={followUp} />
            </div>
            {canShowForm ? (
                <div
                    className={cn(
                        'rounded-xl border bg-card p-4',
                        requirementsComplete
                            ? 'border-border'
                            : 'border-warning/30',
                    )}
                >
                    <DispositionForm projection={disposition} />
                </div>
            ) : null}
            <HandoffPanel projection={disposition} />
            <section className="rounded-xl border border-border bg-muted/20 p-4">
                <h3 className="flex items-center gap-2 font-semibold">
                    <History aria-hidden="true" className="size-4" /> Riwayat
                    disposisi
                </h3>
                {disposition.history.length ? (
                    <ol className="mt-3 space-y-3">
                        {disposition.history.map((version) => (
                            <DispositionVersionCard
                                key={version.public_id}
                                version={version}
                            />
                        ))}
                    </ol>
                ) : (
                    <div className="mt-3">
                        <EmergencyEmptyState
                            title="Belum ada disposisi"
                            body="Keputusan akhir hanya dapat ditandatangani dokter setelah dokumen wajib Final."
                        />
                    </div>
                )}
                {disposition.correction_intents.length ? (
                    <details className="mt-4 border-t border-border pt-3">
                        <summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold">
                            Riwayat maksud koreksi (
                            {disposition.correction_intents.length})
                        </summary>
                        <ol className="mt-2 space-y-2">
                            {disposition.correction_intents.map((intent) => (
                                <li
                                    key={intent.public_id}
                                    className="rounded-md bg-card p-3 text-xs"
                                >
                                    <p className="font-semibold">
                                        {intent.replacement_label} ·{' '}
                                        {intent.state}
                                    </p>
                                    <p className="mt-1">{intent.reason}</p>
                                    <p className="mt-1 text-muted-foreground">
                                        {intent.physician_name ?? 'Dokter'} ·
                                        berlaku sampai{' '}
                                        {new Date(
                                            intent.expires_at,
                                        ).toLocaleString('id-ID')}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </details>
                ) : null}
            </section>
        </section>
    );
}
