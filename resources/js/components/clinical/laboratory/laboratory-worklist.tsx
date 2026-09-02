import { Link, router, useForm } from '@inertiajs/react';
import {
    ExternalLink,
    FlaskConical,
    ListChecks,
    TestTube2,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    emptyComponentValues,
    LaboratoryErrors,
    LaboratoryProgressRail,
    LaboratoryResultFields,
    LaboratoryResultTable,
    LaboratoryVerifiedEvidence,
} from './laboratory-shared';
import type { LaboratoryComponentFormValue } from './laboratory-shared';
import {
    formatLaboratoryDate,
    laboratoryFieldClass,
    newLaboratoryOperationKey,
} from './operation';
import type {
    LaboratoryOrderProjection,
    LaboratoryWorklistProps,
} from './types';

export type { LaboratoryWorklistProps } from './types';

type OpenTask = 'collect' | 'assess' | 'result' | 'verify' | 'amend' | null;

type CriticalCommunicationInput = {
    communicated_at: string;
    communication_method: string;
    recipient_user_public_id: string;
    outcome: string;
    note: string;
};

function emptyCriticalCommunication(
    recipientUserPublicId: string | null,
): CriticalCommunicationInput {
    return {
        communicated_at: '',
        communication_method: '',
        recipient_user_public_id: recipientUserPublicId ?? '',
        outcome: '',
        note: '',
    };
}

function WorklistOrder({
    order,
    props,
}: {
    order: LaboratoryOrderProjection;
    props: LaboratoryWorklistProps;
}) {
    const [openTask, setOpenTask] = useState<OpenTask>(null);
    const isLegacy = order.source === 'LEGACY_READ_ONLY';
    const actionableSpecimen = order.specimens.at(-1) ?? null;
    const latestComponents =
        order.result?.amendments.at(-1)?.components ??
        order.result?.components ??
        [];
    const resultHasCritical =
        order.result?.components.some(
            (component) => component.interpretation === 'CRITICAL',
        ) ?? false;

    const collectForm = useForm({
        expected_order_version: order.version,
        note: '',
        idempotency_key: newLaboratoryOperationKey('collect'),
    });
    const receiveForm = useForm({
        specimen_public_id: actionableSpecimen?.public_id ?? '',
        idempotency_key: newLaboratoryOperationKey('receive'),
    });
    const acceptForm = useForm({
        expected_order_version: order.version,
        idempotency_key: newLaboratoryOperationKey('accept'),
    });
    const rejectForm = useForm({
        expected_order_version: order.version,
        reason_code: '',
        note: '',
        idempotency_key: newLaboratoryOperationKey('reject'),
    });
    const resultForm = useForm({
        expected_order_version: order.version,
        expected_result_version: order.result?.version ?? 0,
        specimen_public_id: order.accepted_specimen_public_id ?? '',
        results: emptyComponentValues(
            order.examination.components,
            order.result?.state === 'DRAFT' ? order.result.components : [],
        ),
        idempotency_key: newLaboratoryOperationKey('result-draft'),
    });
    const verifyForm = useForm({
        expected_order_version: order.version,
        expected_result_version: order.result?.version ?? 0,
        critical_communication: resultHasCritical
            ? emptyCriticalCommunication(order.ordering_physician_public_id)
            : null,
        idempotency_key: newLaboratoryOperationKey('result-verify'),
    });
    const amendmentForm = useForm<{
        expected_order_version: number;
        expected_result_version: number;
        reason_code: string;
        results: LaboratoryComponentFormValue[];
        critical_communication: CriticalCommunicationInput | null;
        idempotency_key: string;
    }>({
        expected_order_version: order.version,
        expected_result_version: order.result?.version ?? 0,
        reason_code: '',
        results: emptyComponentValues(
            order.examination.components,
            latestComponents,
        ),
        critical_communication: null,
        idempotency_key: newLaboratoryOperationKey('result-amend'),
    });
    const errors = {
        ...collectForm.errors,
        ...receiveForm.errors,
        ...acceptForm.errors,
        ...rejectForm.errors,
        ...resultForm.errors,
        ...verifyForm.errors,
        ...amendmentForm.errors,
    };
    const draftHasCritical = resultHasCritical;
    const amendmentHasCritical = amendmentForm.data.results.some(
        (component) => component.interpretation === 'CRITICAL',
    );

    const post = (
        enabled: boolean,
        url: string | null,
        form: {
            post: (url: string, options: Record<string, unknown>) => void;
        },
        errorBag: string,
    ) => {
        if (!enabled || !url) {
            return;
        }

        form.post(url, { preserveScroll: true, errorBag });
    };

    const submitResult = (event: FormEvent) => {
        event.preventDefault();
        post(
            props.permissions.can_save_result,
            order.actions.save_result_url,
            resultForm,
            `laboratoryResult.${order.public_id}`,
        );
    };
    const verify = (event: FormEvent) => {
        event.preventDefault();
        post(
            props.permissions.can_verify_result,
            order.actions.verify_result_url,
            verifyForm,
            `laboratoryVerify.${order.public_id}`,
        );
    };
    const amend = (event: FormEvent) => {
        event.preventDefault();
        post(
            props.permissions.can_verify_result,
            order.actions.amend_result_url,
            amendmentForm,
            `laboratoryAmendment.${order.public_id}`,
        );
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
                    <p>{formatLaboratoryDate(order.ordered_at)}</p>
                    {isLegacy ? (
                        <span className="mt-2 inline-flex min-h-7 items-center rounded-full bg-slate-200 px-3 text-xs font-semibold text-slate-800">
                            Arsip baca-saja
                        </span>
                    ) : null}
                </div>
                <Link
                    href={order.encounter_url}
                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-slate-300 px-3 text-sm font-semibold text-slate-800 outline-none hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#1b75bc]"
                >
                    Buka episode <ExternalLink className="size-4" />
                </Link>
            </div>

            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span
                        className={`inline-flex min-h-7 items-center rounded-full px-3 text-xs font-semibold ${order.priority === 'URGENT' ? 'bg-red-100 text-red-900' : 'bg-[#e8f4fb] text-[#145a8d]'}`}
                    >
                        {order.priority === 'URGENT'
                            ? 'Prioritas segera'
                            : 'Prioritas rutin'}
                    </span>
                    <p className="text-sm text-slate-600">
                        {order.examination.specimen_type}
                    </p>
                </div>
                {!isLegacy && order.state !== 'CANCELLED' ? (
                    <LaboratoryProgressRail order={order} />
                ) : null}
                <div className="rounded-md bg-slate-50 p-3 text-sm">
                    <p className="font-semibold text-slate-800">
                        Pertanyaan klinis
                    </p>
                    <p className="mt-1 whitespace-pre-wrap text-slate-700">
                        {order.clinical_question}
                    </p>
                </div>

                <LaboratoryErrors
                    errors={errors}
                    title="Tindakan belum tersimpan."
                />

                <div className="flex flex-wrap gap-2">
                    {!isLegacy &&
                    props.permissions.can_collect &&
                    order.actions.collect_url ? (
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={() => setOpenTask('collect')}
                        >
                            <TestTube2 className="mr-2 size-4" /> Ambil spesimen
                        </Button>
                    ) : null}
                    {!isLegacy &&
                    props.permissions.can_process_specimen &&
                    order.actions.receive_url ? (
                        <Button
                            type="button"
                            className="min-h-11"
                            onClick={() =>
                                post(
                                    true,
                                    order.actions.receive_url,
                                    receiveForm,
                                    `laboratoryReceive.${order.public_id}`,
                                )
                            }
                            disabled={receiveForm.processing}
                        >
                            Terima di unit lab
                        </Button>
                    ) : null}
                    {!isLegacy &&
                    props.permissions.can_process_specimen &&
                    (order.actions.accept_url || order.actions.reject_url) ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setOpenTask('assess')}
                        >
                            Nilai kelayakan spesimen
                        </Button>
                    ) : null}
                    {!isLegacy &&
                    props.permissions.can_save_result &&
                    order.actions.save_result_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setOpenTask('result')}
                        >
                            Susun hasil
                        </Button>
                    ) : null}
                    {!isLegacy &&
                    props.permissions.can_verify_result &&
                    order.actions.verify_result_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setOpenTask('verify')}
                        >
                            Verifikasi hasil
                        </Button>
                    ) : null}
                    {!isLegacy &&
                    props.permissions.can_verify_result &&
                    order.actions.amend_result_url ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setOpenTask('amend')}
                        >
                            Tambah adendum
                        </Button>
                    ) : null}
                </div>

                {openTask === 'collect' && order.actions.collect_url ? (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            post(
                                props.permissions.can_collect,
                                order.actions.collect_url,
                                collectForm,
                                `laboratoryCollect.${order.public_id}`,
                            );
                        }}
                        className="space-y-3 rounded-lg border border-[#b9d9ed] bg-[#f4f9fc] p-4"
                    >
                        <Label htmlFor={`work-collect-${order.public_id}`}>
                            Catatan pengambilan (opsional)
                        </Label>
                        <textarea
                            id={`work-collect-${order.public_id}`}
                            rows={3}
                            className={laboratoryFieldClass}
                            value={collectForm.data.note}
                            onChange={(event) =>
                                collectForm.setData('note', event.target.value)
                            }
                        />
                        <Button type="submit" className="min-h-11">
                            Simpan pengambilan
                        </Button>
                    </form>
                ) : null}

                {openTask === 'assess' && actionableSpecimen ? (
                    <div className="space-y-4 rounded-lg border border-[#b9d9ed] bg-[#f4f9fc] p-4">
                        <h4 className="font-semibold text-slate-950">
                            Kelayakan {actionableSpecimen.label_identifier}
                        </h4>
                        <div className="flex flex-wrap gap-2">
                            {order.actions.accept_url ? (
                                <Button
                                    type="button"
                                    className="min-h-11"
                                    onClick={() =>
                                        post(
                                            props.permissions
                                                .can_process_specimen,
                                            order.actions.accept_url,
                                            acceptForm,
                                            `laboratoryAccept.${order.public_id}`,
                                        )
                                    }
                                >
                                    Terima untuk pemeriksaan
                                </Button>
                            ) : null}
                        </div>
                        {order.actions.reject_url ? (
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    post(
                                        props.permissions.can_process_specimen,
                                        order.actions.reject_url,
                                        rejectForm,
                                        `laboratoryReject.${order.public_id}`,
                                    );
                                }}
                                className="grid gap-3 rounded-md border border-red-200 bg-white p-3 sm:grid-cols-2"
                            >
                                <div>
                                    <Label
                                        htmlFor={`reject-reason-${order.public_id}`}
                                    >
                                        Alasan penolakan
                                    </Label>
                                    <select
                                        id={`reject-reason-${order.public_id}`}
                                        className={laboratoryFieldClass}
                                        value={rejectForm.data.reason_code}
                                        onChange={(event) =>
                                            rejectForm.setData(
                                                'reason_code',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    >
                                        <option value="">Pilih alasan</option>
                                        {props.rejection_reason_options.map(
                                            (reason) => (
                                                <option
                                                    key={reason.value}
                                                    value={reason.value}
                                                >
                                                    {reason.label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                                <div>
                                    <Label
                                        htmlFor={`reject-note-${order.public_id}`}
                                    >
                                        Catatan (opsional)
                                    </Label>
                                    <textarea
                                        id={`reject-note-${order.public_id}`}
                                        rows={2}
                                        className={laboratoryFieldClass}
                                        value={rejectForm.data.note}
                                        onChange={(event) =>
                                            rejectForm.setData(
                                                'note',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    className="min-h-11 sm:col-span-2 sm:w-fit"
                                >
                                    Tolak dan minta pengambilan ulang
                                </Button>
                            </form>
                        ) : null}
                    </div>
                ) : null}

                {order.result?.state === 'VERIFIED' ? (
                    <section className="rounded-lg border-l-4 border-[#1b75bc] bg-[#f4f9fc] p-4">
                        <LaboratoryVerifiedEvidence
                            result={order.result}
                            showAcknowledgement={!isLegacy}
                        />
                    </section>
                ) : order.result ? (
                    <section className="rounded-lg border-l-4 border-[#1b75bc] bg-[#f4f9fc] p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <FlaskConical className="size-4 text-[#1b75bc]" />
                                <h4 className="font-semibold text-slate-950">
                                    Draft hasil
                                </h4>
                            </div>
                            <span className="text-xs font-semibold text-[#145a8d]">
                                Versi {order.result.version}
                            </span>
                        </div>
                        <LaboratoryResultTable components={latestComponents} />
                    </section>
                ) : null}

                {openTask === 'result' && order.actions.save_result_url ? (
                    <form
                        onSubmit={submitResult}
                        className="space-y-4 rounded-lg border border-[#b9d9ed] bg-slate-50 p-4"
                    >
                        <h4 className="font-semibold text-slate-950">
                            Draft hasil lengkap
                        </h4>
                        <LaboratoryResultFields
                            definitions={order.examination.components}
                            values={
                                resultForm.data
                                    .results as LaboratoryComponentFormValue[]
                            }
                            interpretationOptions={props.interpretation_options}
                            idPrefix={`result-${order.public_id}`}
                            onChange={(values) =>
                                resultForm.setData('results', values)
                            }
                        />
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={resultForm.processing}
                        >
                            Simpan Draft
                        </Button>
                    </form>
                ) : null}

                {openTask === 'verify' && order.actions.verify_result_url ? (
                    <form
                        onSubmit={verify}
                        className="space-y-4 rounded-lg border border-emerald-200 bg-emerald-50/40 p-4"
                    >
                        <h4 className="font-semibold text-slate-950">
                            Verifikasi hasil
                        </h4>
                        {draftHasCritical ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <p className="flex gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900 sm:col-span-2">
                                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                    Komponen kritis memerlukan catatan
                                    komunikasi sebelum verifikasi.
                                </p>
                                <div>
                                    <Label
                                        htmlFor={`critical-time-${order.public_id}`}
                                    >
                                        Waktu komunikasi
                                    </Label>
                                    <input
                                        id={`critical-time-${order.public_id}`}
                                        type="datetime-local"
                                        className={laboratoryFieldClass}
                                        value={
                                            verifyForm.data
                                                .critical_communication
                                                ?.communicated_at ?? ''
                                        }
                                        onChange={(event) =>
                                            verifyForm.setData(
                                                'critical_communication',
                                                {
                                                    ...verifyForm.data
                                                        .critical_communication!,
                                                    communicated_at:
                                                        event.target.value,
                                                },
                                            )
                                        }
                                        required
                                    />
                                </div>
                                {verifyForm.data.critical_communication
                                    ?.outcome === 'ESCALATED' ? (
                                    <div>
                                        <Label
                                            htmlFor={`critical-recipient-${order.public_id}`}
                                        >
                                            Dokter penerima eskalasi
                                        </Label>
                                        <select
                                            id={`critical-recipient-${order.public_id}`}
                                            className={laboratoryFieldClass}
                                            value={
                                                verifyForm.data
                                                    .critical_communication
                                                    .recipient_user_public_id
                                            }
                                            onChange={(event) =>
                                                verifyForm.setData(
                                                    'critical_communication',
                                                    {
                                                        ...verifyForm.data
                                                            .critical_communication!,
                                                        recipient_user_public_id:
                                                            event.target.value,
                                                    },
                                                )
                                            }
                                            required
                                        >
                                            <option value="">
                                                Pilih dokter penerima
                                            </option>
                                            {props.critical_communication_recipient_options.map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>
                                ) : verifyForm.data.critical_communication
                                      ?.outcome === 'COMMUNICATED' ? (
                                    <div>
                                        <p className="text-sm font-medium text-slate-800">
                                            Dokter penerima
                                        </p>
                                        <p className="mt-1 min-h-11 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900">
                                            {order.ordering_physician_name}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-600">
                                            Tersampaikan selalu dicatat kepada
                                            dokter pemesan.
                                        </p>
                                    </div>
                                ) : (
                                    <p className="self-end rounded-md bg-white px-3 py-2 text-sm text-slate-600">
                                        Pilih hasil komunikasi untuk menentukan
                                        dokter penerima.
                                    </p>
                                )}
                                <div>
                                    <Label
                                        htmlFor={`critical-method-${order.public_id}`}
                                    >
                                        Metode
                                    </Label>
                                    <select
                                        id={`critical-method-${order.public_id}`}
                                        className={laboratoryFieldClass}
                                        value={
                                            verifyForm.data
                                                .critical_communication
                                                ?.communication_method ?? ''
                                        }
                                        onChange={(event) =>
                                            verifyForm.setData(
                                                'critical_communication',
                                                {
                                                    ...verifyForm.data
                                                        .critical_communication!,
                                                    communication_method:
                                                        event.target.value,
                                                },
                                            )
                                        }
                                        required
                                    >
                                        <option value="">Pilih metode</option>
                                        {props.communication_method_options.map(
                                            (option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                                <div>
                                    <Label
                                        htmlFor={`critical-outcome-${order.public_id}`}
                                    >
                                        Hasil komunikasi
                                    </Label>
                                    <select
                                        id={`critical-outcome-${order.public_id}`}
                                        className={laboratoryFieldClass}
                                        value={
                                            verifyForm.data
                                                .critical_communication
                                                ?.outcome ?? ''
                                        }
                                        onChange={(event) => {
                                            const outcome = event.target.value;
                                            verifyForm.setData(
                                                'critical_communication',
                                                {
                                                    ...verifyForm.data
                                                        .critical_communication!,
                                                    outcome,
                                                    recipient_user_public_id:
                                                        outcome ===
                                                        'COMMUNICATED'
                                                            ? (order.ordering_physician_public_id ??
                                                              '')
                                                            : '',
                                                },
                                            );
                                        }}
                                        required
                                    >
                                        <option value="">Pilih hasil</option>
                                        {props.communication_outcome_options.map(
                                            (option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                                <div className="sm:col-span-2">
                                    <Label
                                        htmlFor={`critical-note-${order.public_id}`}
                                    >
                                        {verifyForm.data.critical_communication
                                            ?.outcome === 'ESCALATED'
                                            ? 'Catatan komunikasi (wajib untuk eskalasi)'
                                            : 'Catatan komunikasi (opsional)'}
                                    </Label>
                                    <textarea
                                        id={`critical-note-${order.public_id}`}
                                        rows={3}
                                        className={laboratoryFieldClass}
                                        value={
                                            verifyForm.data
                                                .critical_communication?.note ??
                                            ''
                                        }
                                        onChange={(event) =>
                                            verifyForm.setData(
                                                'critical_communication',
                                                {
                                                    ...verifyForm.data
                                                        .critical_communication!,
                                                    note: event.target.value,
                                                },
                                            )
                                        }
                                        required={
                                            verifyForm.data
                                                .critical_communication
                                                ?.outcome === 'ESCALATED'
                                        }
                                    />
                                    {verifyForm.data.critical_communication
                                        ?.outcome === 'ESCALATED' ? (
                                        <p className="mt-1 text-xs font-medium text-red-800">
                                            Jelaskan alasan eskalasi dan konteks
                                            serah-terima kepada dokter jaga.
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                        ) : (
                            <p className="text-sm text-slate-700">
                                Verifikasi mengunci isi Draft saat ini sebagai
                                hasil terverifikasi.
                            </p>
                        )}
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={verifyForm.processing}
                        >
                            Verifikasi hasil
                        </Button>
                    </form>
                ) : null}

                {openTask === 'amend' && order.actions.amend_result_url ? (
                    <form
                        onSubmit={amend}
                        className="space-y-4 rounded-lg border border-amber-200 bg-amber-50/50 p-4"
                    >
                        <div>
                            <Label htmlFor={`amend-reason-${order.public_id}`}>
                                Alasan koreksi
                            </Label>
                            <select
                                id={`amend-reason-${order.public_id}`}
                                className={laboratoryFieldClass}
                                value={amendmentForm.data.reason_code}
                                onChange={(event) =>
                                    amendmentForm.setData(
                                        'reason_code',
                                        event.target.value,
                                    )
                                }
                                required
                            >
                                <option value="">Pilih alasan</option>
                                {props.amendment_reason_options.map(
                                    (option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                        <LaboratoryResultFields
                            definitions={order.examination.components}
                            values={
                                amendmentForm.data
                                    .results as LaboratoryComponentFormValue[]
                            }
                            interpretationOptions={props.interpretation_options}
                            idPrefix={`amend-${order.public_id}`}
                            onChange={(values) => {
                                amendmentForm.setData('results', values);
                                const hasCritical = values.some(
                                    (component) =>
                                        component.interpretation === 'CRITICAL',
                                );

                                if (
                                    !hasCritical &&
                                    amendmentForm.data.critical_communication
                                ) {
                                    amendmentForm.setData(
                                        'critical_communication',
                                        null,
                                    );
                                }
                            }}
                        />
                        {amendmentHasCritical ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <p className="flex gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900 sm:col-span-2">
                                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                    Setiap adendum dengan nilai kritis
                                    memerlukan komunikasi baru khusus untuk
                                    versi ini.
                                </p>
                                <div>
                                    <Label
                                        htmlFor={`amend-critical-time-${order.public_id}`}
                                    >
                                        Waktu komunikasi adendum
                                    </Label>
                                    <input
                                        id={`amend-critical-time-${order.public_id}`}
                                        type="datetime-local"
                                        className={laboratoryFieldClass}
                                        value={
                                            amendmentForm.data
                                                .critical_communication
                                                ?.communicated_at ?? ''
                                        }
                                        onChange={(event) =>
                                            amendmentForm.setData(
                                                'critical_communication',
                                                {
                                                    ...(amendmentForm.data
                                                        .critical_communication ??
                                                        emptyCriticalCommunication(
                                                            order.ordering_physician_public_id,
                                                        )),
                                                    communicated_at:
                                                        event.target.value,
                                                },
                                            )
                                        }
                                        required
                                    />
                                </div>
                                {amendmentForm.data.critical_communication
                                    ?.outcome === 'ESCALATED' ? (
                                    <div>
                                        <Label
                                            htmlFor={`amend-critical-recipient-${order.public_id}`}
                                        >
                                            Dokter penerima eskalasi adendum
                                        </Label>
                                        <select
                                            id={`amend-critical-recipient-${order.public_id}`}
                                            className={laboratoryFieldClass}
                                            value={
                                                amendmentForm.data
                                                    .critical_communication
                                                    .recipient_user_public_id
                                            }
                                            onChange={(event) =>
                                                amendmentForm.setData(
                                                    'critical_communication',
                                                    {
                                                        ...amendmentForm.data
                                                            .critical_communication!,
                                                        recipient_user_public_id:
                                                            event.target.value,
                                                    },
                                                )
                                            }
                                            required
                                        >
                                            <option value="">
                                                Pilih dokter penerima
                                            </option>
                                            {props.critical_communication_recipient_options.map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>
                                ) : amendmentForm.data.critical_communication
                                      ?.outcome === 'COMMUNICATED' ? (
                                    <div>
                                        <p className="text-sm font-medium text-slate-800">
                                            Dokter penerima adendum
                                        </p>
                                        <p className="mt-1 min-h-11 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900">
                                            {order.ordering_physician_name}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-600">
                                            Tersampaikan selalu dicatat kepada
                                            dokter pemesan.
                                        </p>
                                    </div>
                                ) : (
                                    <p className="self-end rounded-md bg-white px-3 py-2 text-sm text-slate-600">
                                        Pilih hasil komunikasi untuk menentukan
                                        dokter penerima.
                                    </p>
                                )}
                                <div>
                                    <Label
                                        htmlFor={`amend-critical-method-${order.public_id}`}
                                    >
                                        Metode komunikasi adendum
                                    </Label>
                                    <select
                                        id={`amend-critical-method-${order.public_id}`}
                                        className={laboratoryFieldClass}
                                        value={
                                            amendmentForm.data
                                                .critical_communication
                                                ?.communication_method ?? ''
                                        }
                                        onChange={(event) =>
                                            amendmentForm.setData(
                                                'critical_communication',
                                                {
                                                    ...(amendmentForm.data
                                                        .critical_communication ??
                                                        emptyCriticalCommunication(
                                                            order.ordering_physician_public_id,
                                                        )),
                                                    communication_method:
                                                        event.target.value,
                                                },
                                            )
                                        }
                                        required
                                    >
                                        <option value="">Pilih metode</option>
                                        {props.communication_method_options.map(
                                            (option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                                <div>
                                    <Label
                                        htmlFor={`amend-critical-outcome-${order.public_id}`}
                                    >
                                        Hasil komunikasi adendum
                                    </Label>
                                    <select
                                        id={`amend-critical-outcome-${order.public_id}`}
                                        className={laboratoryFieldClass}
                                        value={
                                            amendmentForm.data
                                                .critical_communication
                                                ?.outcome ?? ''
                                        }
                                        onChange={(event) => {
                                            const outcome = event.target.value;
                                            amendmentForm.setData(
                                                'critical_communication',
                                                {
                                                    ...(amendmentForm.data
                                                        .critical_communication ??
                                                        emptyCriticalCommunication(
                                                            order.ordering_physician_public_id,
                                                        )),
                                                    outcome,
                                                    recipient_user_public_id:
                                                        outcome ===
                                                        'COMMUNICATED'
                                                            ? (order.ordering_physician_public_id ??
                                                              '')
                                                            : '',
                                                },
                                            );
                                        }}
                                        required
                                    >
                                        <option value="">Pilih hasil</option>
                                        {props.communication_outcome_options.map(
                                            (option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </div>
                                <div className="sm:col-span-2">
                                    <Label
                                        htmlFor={`amend-critical-note-${order.public_id}`}
                                    >
                                        {amendmentForm.data
                                            .critical_communication?.outcome ===
                                        'ESCALATED'
                                            ? 'Catatan komunikasi adendum (wajib untuk eskalasi)'
                                            : 'Catatan komunikasi adendum (opsional)'}
                                    </Label>
                                    <textarea
                                        id={`amend-critical-note-${order.public_id}`}
                                        rows={3}
                                        className={laboratoryFieldClass}
                                        value={
                                            amendmentForm.data
                                                .critical_communication?.note ??
                                            ''
                                        }
                                        onChange={(event) =>
                                            amendmentForm.setData(
                                                'critical_communication',
                                                {
                                                    ...(amendmentForm.data
                                                        .critical_communication ??
                                                        emptyCriticalCommunication(
                                                            order.ordering_physician_public_id,
                                                        )),
                                                    note: event.target.value,
                                                },
                                            )
                                        }
                                        required={
                                            amendmentForm.data
                                                .critical_communication
                                                ?.outcome === 'ESCALATED'
                                        }
                                    />
                                    {amendmentForm.data.critical_communication
                                        ?.outcome === 'ESCALATED' ? (
                                        <p className="mt-1 text-xs font-medium text-red-800">
                                            Jelaskan alasan eskalasi dan konteks
                                            serah-terima kepada dokter jaga.
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                        ) : null}
                        <Button
                            type="submit"
                            className="min-h-11"
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

export function LaboratoryWorklist(props: LaboratoryWorklistProps) {
    const [filters, setFilters] = useState(props.filters);
    const [loading, setLoading] = useState(false);

    const submitFilters = (event: FormEvent) => {
        event.preventDefault();
        router.get('/pemeriksaan/laboratorium', filters, {
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
                    { href: '/pemeriksaan/rawat-jalan', label: 'Rawat Jalan' },
                    { href: '/pemeriksaan/igd', label: 'IGD' },
                    { href: '/pemeriksaan/rawat-inap', label: 'Rawat Inap' },
                    { href: '/pemeriksaan/triage', label: 'Triage' },
                    {
                        href: '/pemeriksaan/laboratorium',
                        label: 'Laboratorium',
                        active: true,
                    },
                    { href: '/pemeriksaan/radiologi', label: 'Radiologi' },
                ]}
            />
            <header className="flex items-start gap-3">
                <span className="grid size-12 shrink-0 place-items-center rounded-lg bg-[#1b75bc] text-white">
                    <ListChecks className="size-6" />
                </span>
                <div>
                    <p className="font-mono text-xs font-semibold tracking-[0.15em] text-[#145a8d]">
                        LABORATORIUM
                    </p>
                    <h1 className="font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold text-slate-950">
                        Worklist Spesimen & Hasil
                    </h1>
                    <p className="mt-1 text-sm text-slate-600">
                        Satu alur kerja untuk rawat jalan, IGD, dan rawat inap.
                    </p>
                </div>
            </header>
            <p className="sr-only" role="status" aria-live="polite">
                {loading
                    ? 'Memuat worklist laboratorium.'
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
                className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[1fr_190px_210px_170px_auto] lg:items-end"
            >
                <div>
                    <Label htmlFor="laboratory-q">
                        Cari pasien, RM, atau pemeriksaan
                    </Label>
                    <input
                        id="laboratory-q"
                        className={laboratoryFieldClass}
                        value={filters.q}
                        onChange={(event) =>
                            setFilters({ ...filters, q: event.target.value })
                        }
                    />
                </div>
                <div>
                    <Label htmlFor="laboratory-setting">Jenis layanan</Label>
                    <select
                        id="laboratory-setting"
                        className={laboratoryFieldClass}
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
                    <Label htmlFor="laboratory-state">Status</Label>
                    <select
                        id="laboratory-state"
                        className={laboratoryFieldClass}
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
                <div>
                    <Label htmlFor="laboratory-priority">Prioritas</Label>
                    <select
                        id="laboratory-priority"
                        className={laboratoryFieldClass}
                        value={filters.priority}
                        onChange={(event) =>
                            setFilters({
                                ...filters,
                                priority: event.target
                                    .value as typeof filters.priority,
                            })
                        }
                    >
                        <option value="">Semua prioritas</option>
                        {props.filter_options.priorities.map((option) => (
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
                            key={`${order.public_id}:${order.version}:${order.result?.version ?? 0}:${order.specimens.length}:${order.result?.amendments.length ?? 0}`}
                            order={order}
                            props={props}
                        />
                    ))
                ) : (
                    <div className="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center">
                        <FlaskConical className="mx-auto size-8 text-slate-400" />
                        <p className="mt-3 font-semibold text-slate-800">
                            Tidak ada permintaan pada filter ini.
                        </p>
                    </div>
                )}
            </div>
        </main>
    );
}
