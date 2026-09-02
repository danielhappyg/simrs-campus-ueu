import {
    CheckCircle2,
    CircleDot,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Label } from '@/components/ui/label';
import { formatLaboratoryDate, laboratoryFieldClass } from './operation';
import type {
    LaboratoryComponentDefinition,
    LaboratoryComponentResult,
    LaboratoryCriticalCommunication,
    LaboratoryInterpretation,
    LaboratoryOption,
    LaboratoryOrderProjection,
    LaboratoryResultProjection,
} from './types';

function LaboratoryCriticalCommunicationEvidence({
    communication,
    title,
    ariaLabel,
}: {
    communication: LaboratoryCriticalCommunication;
    title: string;
    ariaLabel: string;
}) {
    return (
        <section
            aria-label={ariaLabel}
            className="mt-3 flex gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-red-900"
        >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            <div>
                <p className="font-semibold">{title}</p>
                <p className="mt-1 text-sm">
                    {formatLaboratoryDate(communication.communicated_at)} ·{' '}
                    {communication.method_label} ·{' '}
                    {communication.recipient_physician_name} ·{' '}
                    {communication.outcome_label}
                </p>
                {communication.note ? (
                    <p className="mt-1 text-sm whitespace-pre-wrap">
                        {communication.note}
                    </p>
                ) : null}
            </div>
        </section>
    );
}

export function LaboratoryErrors({
    errors,
    title = 'Periksa kembali isian berikut:',
}: {
    errors: Record<string, string>;
    title?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const messages = Object.values(errors);
    const fingerprint = messages.join('|');

    useEffect(() => {
        if (messages.length > 0) {
            ref.current?.focus();
        }
    }, [fingerprint, messages.length]);

    return messages.length ? (
        <div
            ref={ref}
            tabIndex={-1}
            role="alert"
            className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 outline-none focus-visible:ring-2 focus-visible:ring-red-600"
        >
            <p className="font-semibold">{title}</p>
            <ul className="mt-1 list-disc pl-5">
                {messages.map((message, index) => (
                    <li key={`${message}-${index}`}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}

export function LaboratoryProgressRail({
    order,
}: {
    order: LaboratoryOrderProjection;
}) {
    const accepted = order.specimens.some(
        (specimen) => specimen.state === 'ACCEPTED',
    );
    const steps = [
        { label: 'Dipesan', complete: true },
        { label: 'Dikumpulkan', complete: order.specimens.length > 0 },
        { label: 'Diterima', complete: accepted },
        { label: 'Diverifikasi', complete: order.result?.state === 'VERIFIED' },
        {
            label: 'Diketahui',
            complete: order.result?.acknowledgement?.is_current === true,
        },
    ];

    return (
        <ol
            aria-label="Alur pemeriksaan laboratorium"
            className="grid grid-cols-5 gap-1"
        >
            {steps.map((step, index) => (
                <li key={step.label} className="min-w-0 text-center">
                    <div className="flex items-center" aria-hidden="true">
                        <span
                            className={`h-px flex-1 ${index === 0 ? 'bg-transparent' : step.complete ? 'bg-[#1b75bc]' : 'bg-slate-200'}`}
                        />
                        <span
                            className={`grid size-7 shrink-0 place-items-center rounded-full border-2 ${step.complete ? 'border-[#1b75bc] bg-[#1b75bc] text-white' : 'border-slate-300 bg-white text-slate-400'}`}
                        >
                            {step.complete ? (
                                <CheckCircle2 className="size-4" />
                            ) : (
                                <CircleDot className="size-3" />
                            )}
                        </span>
                        <span
                            className={`h-px flex-1 ${index === steps.length - 1 ? 'bg-transparent' : steps[index + 1]?.complete ? 'bg-[#1b75bc]' : 'bg-slate-200'}`}
                        />
                    </div>
                    <span className="mt-1 block truncate text-[11px] font-medium text-slate-600">
                        {step.label}
                    </span>
                </li>
            ))}
        </ol>
    );
}

export function LaboratoryResultTable({
    components,
}: {
    components: LaboratoryComponentResult[];
}) {
    return (
        <div className="mt-3 overflow-x-auto rounded-md border border-slate-200 bg-white">
            <table className="w-full min-w-[620px] text-left text-sm">
                <caption className="sr-only">
                    Komponen hasil laboratorium
                </caption>
                <thead className="bg-slate-50 text-xs text-slate-600 uppercase">
                    <tr>
                        <th className="px-3 py-2 font-semibold">Komponen</th>
                        <th className="px-3 py-2 font-semibold">Hasil</th>
                        <th className="px-3 py-2 font-semibold">Rujukan</th>
                        <th className="px-3 py-2 font-semibold">
                            Interpretasi
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {components.map((component) => (
                        <tr
                            key={component.code}
                            className="border-t border-slate-100"
                        >
                            <td className="px-3 py-2.5">
                                <p className="font-semibold text-slate-900">
                                    {component.display_name}
                                </p>
                                <p className="font-mono text-xs text-slate-500">
                                    {component.code}
                                </p>
                            </td>
                            <td className="px-3 py-2.5 font-medium text-slate-950">
                                {component.value}
                                {component.unit_text
                                    ? ` ${component.unit_text}`
                                    : ''}
                                {component.note ? (
                                    <p className="mt-1 text-xs font-normal text-slate-600">
                                        {component.note}
                                    </p>
                                ) : null}
                            </td>
                            <td className="px-3 py-2.5 text-slate-600">
                                {component.reference_text || '—'}
                            </td>
                            <td className="px-3 py-2.5">
                                <span
                                    className={`inline-flex min-h-7 items-center rounded-full px-2.5 text-xs font-semibold ${
                                        component.interpretation === 'CRITICAL'
                                            ? 'bg-red-100 text-red-900'
                                            : component.interpretation ===
                                                'ABNORMAL'
                                              ? 'bg-amber-100 text-amber-900'
                                              : 'bg-emerald-50 text-emerald-800'
                                    }`}
                                >
                                    {component.interpretation === 'CRITICAL'
                                        ? 'Kritis'
                                        : component.interpretation ===
                                            'ABNORMAL'
                                          ? 'Abnormal'
                                          : 'Normal'}
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function LaboratoryVerifiedEvidence({
    result,
    showAcknowledgement = true,
}: {
    result: LaboratoryResultProjection;
    showAcknowledgement?: boolean;
}) {
    const baseVersion = result.amendments[0]
        ? result.amendments[0].version - 1
        : result.version;
    const latestAmendment = result.amendments.at(-1);
    const currentComponents = latestAmendment?.components ?? result.components;
    const currentHasCritical = currentComponents.some(
        (component) => component.interpretation === 'CRITICAL',
    );
    const currentBaseCommunication =
        currentHasCritical && !latestAmendment
            ? result.critical_communication
            : null;

    return (
        <div className="mt-3 space-y-4">
            <section aria-label="Hasil terverifikasi awal">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p className="flex items-center gap-2 font-semibold text-slate-950">
                            <ShieldCheck className="size-4 text-[#1b75bc]" />
                            Hasil terverifikasi awal
                        </p>
                        <p className="mt-1 text-xs text-slate-600">
                            Draft oleh {result.author_name} ·{' '}
                            {formatLaboratoryDate(result.saved_at)}
                        </p>
                        <p className="text-xs text-slate-600">
                            Diverifikasi oleh {result.verifier_name ?? '—'} ·{' '}
                            {formatLaboratoryDate(result.verified_at)}
                        </p>
                    </div>
                    <span className="text-xs font-semibold text-[#145a8d]">
                        Versi {baseVersion}
                    </span>
                </div>
                <LaboratoryResultTable components={result.components} />
            </section>

            {result.amendments.length ? (
                <section aria-label="Riwayat adendum terverifikasi">
                    <h5 className="font-semibold text-slate-950">
                        Riwayat adendum terverifikasi
                    </h5>
                    <ol className="mt-2 space-y-3">
                        {result.amendments.map((amendment) => (
                            <li
                                key={amendment.public_id}
                                className="rounded-md border border-amber-200 bg-amber-50/60 p-3"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-semibold text-amber-950">
                                            {amendment.reason_label}
                                        </p>
                                        <p className="mt-1 text-xs text-amber-900">
                                            Ditandatangani{' '}
                                            {amendment.signer_name} ·{' '}
                                            {formatLaboratoryDate(
                                                amendment.signed_at,
                                            )}
                                        </p>
                                    </div>
                                    <span className="text-xs font-semibold text-amber-900">
                                        Versi {amendment.version}
                                    </span>
                                </div>
                                <LaboratoryResultTable
                                    components={amendment.components}
                                />
                                {amendment.critical_communication ? (
                                    <LaboratoryCriticalCommunicationEvidence
                                        communication={
                                            amendment.critical_communication
                                        }
                                        title={
                                            currentHasCritical &&
                                            latestAmendment?.public_id ===
                                                amendment.public_id &&
                                            amendment.critical_communication
                                                ? 'Komunikasi nilai kritis pada adendum · masih berlaku pada hasil saat ini'
                                                : 'Komunikasi nilai kritis pada adendum'
                                        }
                                        ariaLabel={`Komunikasi nilai kritis adendum versi ${amendment.version}`}
                                    />
                                ) : null}
                            </li>
                        ))}
                    </ol>
                </section>
            ) : null}

            {currentBaseCommunication ? (
                <LaboratoryCriticalCommunicationEvidence
                    communication={currentBaseCommunication}
                    title="Komunikasi nilai kritis yang berlaku saat ini"
                    ariaLabel="Komunikasi nilai kritis saat ini"
                />
            ) : null}

            {showAcknowledgement ? (
                <section
                    aria-label="Pengetahuan dokter pemesan"
                    className={`rounded-md px-3 py-2 text-sm ${result.acknowledgement?.is_current ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-950'}`}
                >
                    {result.acknowledgement?.is_current ? (
                        <>
                            <p className="font-semibold">
                                Sudah diketahui dokter pemesan
                            </p>
                            <p className="mt-1 text-xs">
                                {result.acknowledgement.physician_name} ·{' '}
                                {formatLaboratoryDate(
                                    result.acknowledgement.acknowledged_at,
                                )}
                            </p>
                        </>
                    ) : result.acknowledgement ? (
                        <>
                            <p className="font-semibold">
                                Pengetahuan sebelumnya tidak lagi current
                            </p>
                            <p className="mt-1 text-xs">
                                {result.acknowledgement.physician_name} ·{' '}
                                {formatLaboratoryDate(
                                    result.acknowledgement.acknowledged_at,
                                )}{' '}
                                · perlu diketahui kembali setelah adendum
                                terbaru.
                            </p>
                        </>
                    ) : (
                        <p className="font-semibold">
                            Menunggu diketahui dokter pemesan
                        </p>
                    )}
                </section>
            ) : null}
        </div>
    );
}

export type LaboratoryComponentFormValue = {
    code: string;
    value: string;
    interpretation: LaboratoryInterpretation | '';
    note: string;
};

export function emptyComponentValues(
    components: LaboratoryComponentDefinition[],
    existing: LaboratoryComponentResult[] = [],
): LaboratoryComponentFormValue[] {
    return components.map((component) => {
        const current = existing.find((item) => item.code === component.code);

        return {
            code: component.code,
            value: current?.value ?? '',
            interpretation: current?.interpretation ?? '',
            note: current?.note ?? '',
        };
    });
}

export function LaboratoryResultFields({
    definitions,
    values,
    interpretationOptions,
    idPrefix,
    onChange,
}: {
    definitions: LaboratoryComponentDefinition[];
    values: LaboratoryComponentFormValue[];
    interpretationOptions: LaboratoryOption[];
    idPrefix: string;
    onChange: (values: LaboratoryComponentFormValue[]) => void;
}) {
    const update = (
        index: number,
        patch: Partial<LaboratoryComponentFormValue>,
    ) =>
        onChange(
            values.map((value, currentIndex) =>
                currentIndex === index ? { ...value, ...patch } : value,
            ),
        );

    return (
        <div className="space-y-3">
            {definitions.map((definition, index) => (
                <fieldset
                    key={definition.code}
                    className="grid gap-3 rounded-md border border-slate-200 bg-white p-3 md:grid-cols-[1.3fr_1fr_1fr]"
                >
                    <legend className="px-1 font-mono text-xs font-semibold tracking-wide text-[#145a8d]">
                        {definition.code} · {definition.display_name}
                    </legend>
                    <div>
                        <Label htmlFor={`${idPrefix}-value-${index}`}>
                            Nilai hasil
                            {definition.unit_text
                                ? ` (${definition.unit_text})`
                                : ''}
                        </Label>
                        <input
                            id={`${idPrefix}-value-${index}`}
                            type={
                                definition.value_kind === 'NUMERIC'
                                    ? 'text'
                                    : 'text'
                            }
                            inputMode={
                                definition.value_kind === 'NUMERIC'
                                    ? 'decimal'
                                    : undefined
                            }
                            className={laboratoryFieldClass}
                            value={values[index]?.value ?? ''}
                            onChange={(event) =>
                                update(index, { value: event.target.value })
                            }
                            required
                        />
                        <p className="mt-1 text-xs text-slate-500">
                            Rujukan: {definition.reference_text || '—'}
                        </p>
                    </div>
                    <div>
                        <Label htmlFor={`${idPrefix}-flag-${index}`}>
                            Interpretasi manual
                        </Label>
                        <select
                            id={`${idPrefix}-flag-${index}`}
                            className={laboratoryFieldClass}
                            value={values[index]?.interpretation ?? ''}
                            onChange={(event) =>
                                update(index, {
                                    interpretation: event.target
                                        .value as LaboratoryComponentFormValue['interpretation'],
                                })
                            }
                            required
                        >
                            <option value="">Pilih interpretasi</option>
                            {interpretationOptions
                                .filter(
                                    (option) =>
                                        option.value !== 'CRITICAL' ||
                                        definition.critical_allowed,
                                )
                                .map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor={`${idPrefix}-note-${index}`}>
                            Catatan (opsional)
                        </Label>
                        <textarea
                            id={`${idPrefix}-note-${index}`}
                            rows={2}
                            className={laboratoryFieldClass}
                            value={values[index]?.note ?? ''}
                            onChange={(event) =>
                                update(index, { note: event.target.value })
                            }
                        />
                    </div>
                </fieldset>
            ))}
        </div>
    );
}
