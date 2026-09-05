import { useForm } from '@inertiajs/react';
import { Activity, ClipboardPlus, RefreshCcw, ShieldAlert } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { DocumentErrorSummary } from '../outpatient/document-error-summary';
import {
    EvidenceTime,
    TriageChip,
    observationLabel,
    triagePresentation,
} from './emergency-shared';
import {
    emergencyFieldClass,
    newEmergencyOperationKey,
    toLocalDateTimeInput,
} from './operation';
import type {
    EmergencyAbcdeObservation,
    EmergencyConsciousness,
    EmergencyObservationState,
    EmergencyTriageAssessment,
    EmergencyTriageCode,
    EmergencyTriageProjection,
    EmergencyVitals,
} from './types';

const abcdeFields = [
    ['airway', 'A · Airway'],
    ['breathing', 'B · Breathing'],
    ['circulation', 'C · Circulation'],
    ['disability', 'D · Disability / neurological status'],
    ['exposure', 'E · Exposure / complete examination'],
] as const;

const vitalFields = [
    ['respiratory_rate', 'Respiratory rate', 'breaths/min', 0, 100, 1],
    ['pulse', 'Pulse', 'beats/min', 0, 300, 1],
    ['systolic_pressure', 'Systolic pressure', 'mmHg', 0, 300, 1],
    ['diastolic_pressure', 'Diastolic pressure', 'mmHg', 0, 300, 1],
    ['oxygen_saturation', 'Oxygen saturation', '%', 0, 100, 1],
    ['temperature', 'Temperature', '°C', 20, 45, 0.1],
    ['pain_score', 'Pain score', '0–10', 0, 10, 1],
    ['weight', 'Weight', 'kg', 0.1, 500, 0.1],
] as const;

const consciousnessOptions: Array<[EmergencyConsciousness, string]> = [
    ['ALERT', 'Alert · fully conscious'],
    ['VOICE', 'Voice · responds to voice'],
    ['PAIN', 'Pain · responds to pain'],
    ['UNRESPONSIVE', 'Unresponsive · no response'],
];

function initialAbcde(): Record<
    keyof EmergencyTriageAssessment['abcde'],
    EmergencyAbcdeObservation
> {
    return Object.fromEntries(
        abcdeFields.map(([key]) => [key, { state: 'NOT_ASSESSED', note: '' }]),
    ) as Record<
        keyof EmergencyTriageAssessment['abcde'],
        EmergencyAbcdeObservation
    >;
}

function initialVitals(): EmergencyVitals {
    return {
        respiratory_rate: null,
        pulse: null,
        systolic_pressure: null,
        diastolic_pressure: null,
        oxygen_saturation: null,
        temperature: null,
        pain_score: null,
        weight: null,
    };
}

type TriageFormData = {
    vocabulary_public_id: string;
    vocabulary_version: number;
    expected_assessment_version: number;
    category_code: EmergencyTriageCode | '';
    observed_at: string;
    late_entry_reason: string;
    reassessment_reason: string;
    presenting_concern: string;
    clinical_basis: string;
    arrival_condition: string;
    abcde: Record<
        keyof EmergencyTriageAssessment['abcde'],
        EmergencyAbcdeObservation
    >;
    consciousness: EmergencyConsciousness;
    vitals: EmergencyVitals;
    unobtainable_fields: string[];
    unobtainable_reason: string;
    trauma: boolean;
    trauma_note: string;
    isolation_precaution: boolean;
    isolation_note: string;
    handoff_note: string;
    idempotency_key: string;
};

function TriageForm({ projection }: { projection: EmergencyTriageProjection }) {
    const isReassessment = projection.current !== null;
    const actionUrl = isReassessment
        ? projection.actions.reassess_url
        : projection.actions.finalize_initial_url;
    const form = useForm<TriageFormData>({
        vocabulary_public_id: projection.vocabulary?.public_id ?? '',
        vocabulary_version: projection.vocabulary?.version ?? 0,
        expected_assessment_version: projection.current?.version ?? 0,
        category_code: projection.current?.category.code ?? '',
        observed_at: toLocalDateTimeInput(),
        late_entry_reason: '',
        reassessment_reason: '',
        presenting_concern: projection.current?.presenting_concern ?? '',
        clinical_basis: '',
        arrival_condition: projection.current?.arrival_condition ?? '',
        abcde: projection.current?.abcde ?? initialAbcde(),
        consciousness: projection.current?.consciousness ?? 'ALERT',
        vitals: projection.current?.vitals ?? initialVitals(),
        unobtainable_fields: projection.current?.unobtainable_fields ?? [],
        unobtainable_reason: '',
        trauma: projection.current?.trauma ?? false,
        trauma_note: projection.current?.trauma_note ?? '',
        isolation_precaution: projection.current?.isolation_precaution ?? false,
        isolation_note: projection.current?.isolation_note ?? '',
        handoff_note: '',
        idempotency_key: newEmergencyOperationKey(
            isReassessment ? 'reassess' : 'initial-triage',
        ),
    });
    const [attempted, setAttempted] = useState(false);
    const firstErrorRef = useRef<HTMLDivElement>(null);
    const categories = projection.vocabulary?.categories ?? [];
    const missingExpected = vitalFields
        .filter(
            ([key]) =>
                key !== 'weight' &&
                form.data.vitals[key] === null &&
                !form.data.unobtainable_fields.includes(key),
        )
        .map(([, label]) => label);
    const incompleteAbcde = abcdeFields
        .filter(
            ([key]) =>
                form.data.abcde[key].state !== 'ASSESSED_NO_CONCERN' &&
                !(form.data.abcde[key].note ?? '').trim(),
        )
        .map(([, label]) => label);
    const localErrors = useMemo(() => {
        const errors: Record<string, string | undefined> = {};

        if (!attempted) {
            return errors;
        }

        if (!form.data.category_code) {
            errors.category_code = 'Select a manual triage category.';
        }

        if (!form.data.presenting_concern.trim()) {
            errors.presenting_concern = 'Enter the presenting concern.';
        }

        if (!form.data.clinical_basis.trim()) {
            errors.clinical_basis =
                'Explain the clinical basis for the selected category.';
        }

        if (!form.data.arrival_condition.trim()) {
            errors.arrival_condition = 'Enter the condition on arrival.';
        }

        if (isReassessment && !form.data.reassessment_reason.trim()) {
            errors.reassessment_reason = 'Enter the reassessment reason.';
        }

        if (missingExpected.length > 0) {
            errors.vitals = `Enter or mark as unobtainable: ${missingExpected.join(', ')}.`;
        }

        if (incompleteAbcde.length > 0) {
            errors.abcde = `Add notes for: ${incompleteAbcde.join(', ')}.`;
        }

        if (
            form.data.unobtainable_fields.length > 0 &&
            !form.data.unobtainable_reason.trim()
        ) {
            errors.unobtainable_reason =
                'Explain why the observations could not be obtained.';
        }

        return errors;
    }, [
        attempted,
        form.data,
        incompleteAbcde,
        isReassessment,
        missingExpected,
    ]);
    const allErrors = useMemo(
        () => ({ ...form.errors, ...localErrors }),
        [form.errors, localErrors],
    );

    useEffect(() => {
        if (Object.values(allErrors).some(Boolean)) {
            firstErrorRef.current?.focus();
        }
    }, [allErrors]);

    const updateAbcde = (
        key: keyof TriageFormData['abcde'],
        field: keyof EmergencyAbcdeObservation,
        value: string,
    ) => {
        form.setData('abcde', {
            ...form.data.abcde,
            [key]: { ...form.data.abcde[key], [field]: value },
        });
    };

    const toggleUnobtainable = (
        key: keyof EmergencyVitals,
        checked: boolean,
    ) => {
        const paired =
            key === 'systolic_pressure' || key === 'diastolic_pressure'
                ? ['systolic_pressure', 'diastolic_pressure']
                : [key];
        const next = new Set(form.data.unobtainable_fields);
        paired.forEach((name) =>
            checked ? next.add(name) : next.delete(name),
        );
        form.setData((current) => ({
            ...current,
            unobtainable_fields: [...next],
            vitals: checked
                ? {
                      ...current.vitals,
                      ...Object.fromEntries(paired.map((name) => [name, null])),
                  }
                : current.vitals,
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setAttempted(true);

        if (!actionUrl || Object.values(localErrors).some(Boolean)) {
            return;
        }

        form.post(actionUrl, {
            preserveScroll: true,
            onSuccess: () =>
                form.setData(
                    'idempotency_key',
                    newEmergencyOperationKey(
                        isReassessment ? 'reassess' : 'initial-triage',
                    ),
                ),
        });
    };

    if (!projection.permission.can_write || !actionUrl) {
        return null;
    }

    return (
        <form
            onSubmit={submit}
            className="space-y-5"
            aria-label={
                isReassessment
                    ? 'Triage reassessment form'
                    : 'Initial triage assessment form'
            }
        >
            <div ref={firstErrorRef} tabIndex={-1}>
                <DocumentErrorSummary errors={allErrors} />
            </div>
            <fieldset>
                <legend className="text-sm font-semibold text-foreground">
                    Manual priority category
                </legend>
                <p className="mt-1 text-xs text-muted-foreground">
                    Select based on clinical assessment. The system does not
                    calculate or recommend a category.
                </p>
                <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    {categories.map((category) => {
                        const style = triagePresentation[category.code];

                        return (
                            <label
                                key={category.code}
                                className={cn(
                                    'relative flex min-h-20 cursor-pointer gap-3 overflow-hidden rounded-lg border p-3 pl-5 transition-shadow outline-none focus-within:ring-2 focus-within:ring-ring',
                                    form.data.category_code === category.code
                                        ? 'border-primary ring-1 ring-primary'
                                        : 'border-border',
                                )}
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'absolute inset-y-0 left-0 w-2',
                                        style.rail,
                                    )}
                                />
                                <input
                                    type="radio"
                                    name="category_code"
                                    value={category.code}
                                    checked={
                                        form.data.category_code ===
                                        category.code
                                    }
                                    onChange={() =>
                                        form.setData(
                                            'category_code',
                                            category.code,
                                        )
                                    }
                                    className="mt-0.5 size-4"
                                />
                                <span>
                                    <span className="block text-sm font-bold">
                                        {category.display_name}
                                    </span>
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        {category.text_cue}
                                    </span>
                                </span>
                            </label>
                        );
                    })}
                </div>
            </fieldset>

            <div className="grid gap-4 lg:grid-cols-2">
                <label className="text-sm font-semibold">
                    Observation time
                    <input
                        type="datetime-local"
                        value={form.data.observed_at}
                        onChange={(e) =>
                            form.setData('observed_at', e.target.value)
                        }
                        className={emergencyFieldClass}
                        required
                    />
                </label>
                {isReassessment ? (
                    <label className="text-sm font-semibold">
                        Reassessment reason
                        <textarea
                            value={form.data.reassessment_reason}
                            onChange={(e) =>
                                form.setData(
                                    'reassessment_reason',
                                    e.target.value,
                                )
                            }
                            className={cn(emergencyFieldClass, 'min-h-24')}
                            required
                        />
                    </label>
                ) : (
                    <label className="text-sm font-semibold">
                        Late-entry reason{' '}
                        <span className="font-normal text-muted-foreground">
                            (if needed)
                        </span>
                        <textarea
                            value={form.data.late_entry_reason}
                            onChange={(e) =>
                                form.setData(
                                    'late_entry_reason',
                                    e.target.value,
                                )
                            }
                            className={cn(emergencyFieldClass, 'min-h-24')}
                        />
                    </label>
                )}
                <label className="text-sm font-semibold">
                    Presenting concern
                    <textarea
                        value={form.data.presenting_concern}
                        onChange={(e) =>
                            form.setData('presenting_concern', e.target.value)
                        }
                        className={cn(emergencyFieldClass, 'min-h-24')}
                        required
                    />
                </label>
                <label className="text-sm font-semibold">
                    Clinical basis for category
                    <textarea
                        value={form.data.clinical_basis}
                        onChange={(e) =>
                            form.setData('clinical_basis', e.target.value)
                        }
                        className={cn(emergencyFieldClass, 'min-h-24')}
                        required
                    />
                </label>
                <label className="text-sm font-semibold lg:col-span-2">
                    Condition on arrival
                    <textarea
                        value={form.data.arrival_condition}
                        onChange={(e) =>
                            form.setData('arrival_condition', e.target.value)
                        }
                        className={cn(emergencyFieldClass, 'min-h-24')}
                        required
                    />
                </label>
            </div>

            <fieldset>
                <legend className="text-sm font-semibold">
                    Manual ABCDE assessment
                </legend>
                <div className="mt-3 grid gap-3 xl:grid-cols-5">
                    {abcdeFields.map(([key, label]) => (
                        <div
                            key={key}
                            className="rounded-lg border border-border bg-muted/20 p-3"
                        >
                            <label className="text-xs font-bold">
                                {label}
                                <select
                                    value={form.data.abcde[key].state}
                                    onChange={(e) =>
                                        updateAbcde(
                                            key,
                                            'state',
                                            e.target
                                                .value as EmergencyObservationState,
                                        )
                                    }
                                    className={emergencyFieldClass}
                                >
                                    {Object.entries(observationLabel).map(
                                        ([value, optionLabel]) => (
                                            <option key={value} value={value}>
                                                {optionLabel}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </label>
                            <label className="mt-2 block text-xs font-semibold">
                                Note
                                <textarea
                                    value={form.data.abcde[key].note ?? ''}
                                    onChange={(e) =>
                                        updateAbcde(key, 'note', e.target.value)
                                    }
                                    className={cn(
                                        emergencyFieldClass,
                                        'min-h-20',
                                    )}
                                    required={
                                        form.data.abcde[key].state !==
                                        'ASSESSED_NO_CONCERN'
                                    }
                                />
                            </label>
                        </div>
                    ))}
                </div>
            </fieldset>

            <fieldset>
                <legend className="text-sm font-semibold">
                    Consciousness and vital signs
                </legend>
                <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label className="text-xs font-semibold sm:col-span-2">
                        Consciousness
                        <select
                            value={form.data.consciousness}
                            onChange={(e) =>
                                form.setData(
                                    'consciousness',
                                    e.target.value as EmergencyConsciousness,
                                )
                            }
                            className={emergencyFieldClass}
                        >
                            {consciousnessOptions.map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    {vitalFields.map(([key, label, unit, min, max, step]) => {
                        const unavailable =
                            form.data.unobtainable_fields.includes(key);

                        return (
                            <div
                                key={key}
                                className="rounded-lg border border-border p-3"
                            >
                                <label className="text-xs font-semibold">
                                    {label}{' '}
                                    <span className="font-normal text-muted-foreground">
                                        ({unit})
                                    </span>
                                    <input
                                        type="number"
                                        min={min}
                                        max={max}
                                        step={step}
                                        value={form.data.vitals[key] ?? ''}
                                        disabled={unavailable}
                                        onChange={(e) =>
                                            form.setData('vitals', {
                                                ...form.data.vitals,
                                                [key]:
                                                    e.target.value === ''
                                                        ? null
                                                        : Number(
                                                              e.target.value,
                                                          ),
                                            })
                                        }
                                        className={emergencyFieldClass}
                                    />
                                </label>
                                <label className="mt-2 flex min-h-11 items-center gap-2 text-xs">
                                    <input
                                        type="checkbox"
                                        checked={unavailable}
                                        onChange={(e) =>
                                            toggleUnobtainable(
                                                key,
                                                e.target.checked,
                                            )
                                        }
                                        className="size-4"
                                    />{' '}
                                    Unobtainable
                                </label>
                            </div>
                        );
                    })}
                </div>
                {form.data.unobtainable_fields.length > 0 ? (
                    <label className="mt-3 block text-sm font-semibold">
                        Reason observations could not be obtained
                        <textarea
                            value={form.data.unobtainable_reason}
                            onChange={(e) =>
                                form.setData(
                                    'unobtainable_reason',
                                    e.target.value,
                                )
                            }
                            className={cn(emergencyFieldClass, 'min-h-20')}
                            required
                        />
                    </label>
                ) : null}
            </fieldset>

            <div className="grid gap-3 md:grid-cols-2">
                <div className="rounded-lg border border-border p-3">
                    <label className="flex min-h-11 items-center gap-2 text-sm font-semibold">
                        <input
                            type="checkbox"
                            checked={form.data.trauma}
                            onChange={(e) =>
                                form.setData('trauma', e.target.checked)
                            }
                            className="size-4"
                        />{' '}
                        Ada trauma
                    </label>
                    {form.data.trauma ? (
                        <label className="mt-2 block text-xs font-semibold">
                            Trauma note
                            <textarea
                                value={form.data.trauma_note}
                                onChange={(e) =>
                                    form.setData('trauma_note', e.target.value)
                                }
                                className={cn(emergencyFieldClass, 'min-h-20')}
                            />
                        </label>
                    ) : null}
                </div>
                <div className="rounded-lg border border-border p-3">
                    <label className="flex min-h-11 items-center gap-2 text-sm font-semibold">
                        <input
                            type="checkbox"
                            checked={form.data.isolation_precaution}
                            onChange={(e) =>
                                form.setData(
                                    'isolation_precaution',
                                    e.target.checked,
                                )
                            }
                            className="size-4"
                        />{' '}
                        Perlu kewaspadaan isolasi
                    </label>
                    {form.data.isolation_precaution ? (
                        <label className="mt-2 block text-xs font-semibold">
                            Isolation note
                            <textarea
                                value={form.data.isolation_note}
                                onChange={(e) =>
                                    form.setData(
                                        'isolation_note',
                                        e.target.value,
                                    )
                                }
                                className={cn(emergencyFieldClass, 'min-h-20')}
                            />
                        </label>
                    ) : null}
                </div>
                <label className="text-sm font-semibold md:col-span-2">
                    Handoff note
                    <textarea
                        value={form.data.handoff_note}
                        onChange={(e) =>
                            form.setData('handoff_note', e.target.value)
                        }
                        className={cn(emergencyFieldClass, 'min-h-24')}
                    />
                </label>
            </div>

            <div className="flex justify-end border-t border-border pt-4">
                <Button
                    type="submit"
                    disabled={form.processing}
                    className="min-h-11"
                >
                    {isReassessment ? (
                        <RefreshCcw aria-hidden="true" />
                    ) : (
                        <ClipboardPlus aria-hidden="true" />
                    )}
                    {isReassessment
                        ? 'Save reassessment'
                        : 'Finalize initial assessment'}
                </Button>
            </div>
        </form>
    );
}

export function EmergencyTriageTimeline({
    assessments,
}: {
    assessments: EmergencyTriageAssessment[];
}) {
    if (assessments.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground">
                No triage assessment has been recorded.
            </div>
        );
    }

    return (
        <ol className="space-y-3">
            {assessments.map((assessment, index) => (
                <li
                    key={assessment.public_id}
                    className="relative overflow-hidden rounded-lg border border-border bg-card p-4 pl-6"
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'absolute inset-y-0 left-0 w-2',
                            triagePresentation[assessment.category.code].rail,
                        )}
                    />
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                {index === 0
                                    ? 'Initial assessment'
                                    : `Reassessment ${index}`}{' '}
                                · v{assessment.version}
                            </p>
                            <div className="mt-1">
                                <TriageChip
                                    code={assessment.category.code}
                                    cue={assessment.category.text_cue}
                                />
                            </div>
                        </div>
                        <div className="text-right">
                            <p className="text-sm font-semibold">
                                {assessment.assessor.name ??
                                    'Nurse unavailable'}
                            </p>
                            <EvidenceTime value={assessment.observed_at} />
                        </div>
                    </div>
                    {assessment.reassessment_reason ? (
                        <p className="mt-3 rounded-md bg-muted p-2 text-sm">
                            <span className="font-semibold">
                                Reassessment reason:
                            </span>{' '}
                            {assessment.reassessment_reason}
                        </p>
                    ) : null}
                    <dl className="mt-3 grid gap-3 text-sm md:grid-cols-3">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Presenting concern
                            </dt>
                            <dd className="mt-0.5 whitespace-pre-wrap">
                                {assessment.presenting_concern}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Clinical basis
                            </dt>
                            <dd className="mt-0.5 whitespace-pre-wrap">
                                {assessment.clinical_basis}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Condition on arrival
                            </dt>
                            <dd className="mt-0.5 whitespace-pre-wrap">
                                {assessment.arrival_condition}
                            </dd>
                        </div>
                    </dl>
                    <details className="mt-3 rounded-md border border-border bg-muted/20 p-3">
                        <summary className="min-h-11 cursor-pointer py-2 text-sm font-semibold">
                            View ABCDE, vital signs, and recorded evidence
                        </summary>
                        <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                            {abcdeFields.map(([key, label]) => (
                                <div key={key}>
                                    <p className="text-xs font-bold">{label}</p>
                                    <p className="mt-0.5 text-xs">
                                        {
                                            observationLabel[
                                                assessment.abcde[key].state
                                            ]
                                        }
                                    </p>
                                    <p className="mt-1 text-xs whitespace-pre-wrap text-muted-foreground">
                                        {assessment.abcde[key].note ||
                                            'No note.'}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <dl className="mt-4 grid gap-2 text-xs sm:grid-cols-4">
                            {vitalFields.map(([key, label, unit]) => (
                                <div key={key}>
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="font-mono font-semibold">
                                        {assessment.unobtainable_fields.includes(
                                            key,
                                        )
                                            ? 'Unobtainable'
                                            : assessment.vitals[key] === null
                                              ? '—'
                                              : `${assessment.vitals[key]} ${unit}`}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <p className="mt-4 text-xs text-muted-foreground">
                            Direkam{' '}
                            {assessment.recorded_at
                                ? new Date(
                                      assessment.recorded_at,
                                  ).toLocaleString('en-GB')
                                : '—'}{' '}
                            · Digest{' '}
                            {assessment.content_digest
                                ? `${assessment.content_digest.slice(0, 12)}…`
                                : 'tersimpan'}
                        </p>
                    </details>
                </li>
            ))}
        </ol>
    );
}

export function EmergencyTriagePanel({
    projection,
}: {
    projection: EmergencyTriageProjection;
}) {
    const [showForm, setShowForm] = useState(projection.current === null);

    return (
        <section
            aria-labelledby="emergency-triage-title"
            className="clinical-shadow rounded-xl border border-border bg-card p-4 md:p-5"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[0.68rem] font-semibold tracking-[0.13em] text-muted-foreground uppercase">
                        Prioritas klinis manual
                    </p>
                    <h2
                        id="emergency-triage-title"
                        className="mt-0.5 flex items-center gap-2 text-lg font-semibold"
                    >
                        <Activity
                            aria-hidden="true"
                            className="size-5 text-primary"
                        />{' '}
                        Triage and reassessment
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        The nurse selects the category based on ABCDE assessment
                        and direct observation.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <TriageChip
                        code={projection.current?.category.code ?? null}
                        cue={projection.current?.category.text_cue}
                    />
                    {projection.permission.can_write &&
                    (projection.actions.finalize_initial_url ||
                        projection.actions.reassess_url) ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={() => setShowForm((value) => !value)}
                        >
                            {showForm
                                ? 'Close form'
                                : projection.current
                                  ? 'Reassessment'
                                  : 'Start triage'}
                        </Button>
                    ) : null}
                </div>
            </div>
            {!projection.vocabulary ? (
                <div
                    role="alert"
                    className="mt-4 flex gap-2 rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm text-warning"
                >
                    <ShieldAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0"
                    />{' '}
                    The active triage catalogue is unavailable. New assessments
                    cannot be saved.
                </div>
            ) : null}
            {showForm && projection.vocabulary ? (
                <div className="mt-5 border-t border-border pt-5">
                    <TriageForm projection={projection} />
                </div>
            ) : null}
            <div className="mt-5 border-t border-border pt-5">
                <EmergencyTriageTimeline assessments={projection.assessments} />
            </div>
        </section>
    );
}
