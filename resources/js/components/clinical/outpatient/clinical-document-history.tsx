import type {
    ClinicalDocumentFieldValue,
    ClinicalDocumentVersion,
} from './types';

const documentLabel = {
    NURSING_ASSESSMENT: 'Nursing assessment',
    MEDICAL_ASSESSMENT: 'Medical assessment',
};

const fieldLabel: Record<string, string> = {
    nursing_assessment: 'Nursing assessment',
    anamnesis: 'Subjective',
    objective_examination: 'Objective',
    clinical_assessment: 'Assessment',
    care_plan: 'Plan',
    diagnosis_text: 'Diagnosis',
    primary_icd10: 'Primary diagnosis (ICD-10)',
    secondary_icd10: 'Secondary diagnoses (ICD-10)',
    procedures_icd9cm: 'Procedures (ICD-9-CM)',
    additional_notes: 'Additional notes',
};

function presentField(value: ClinicalDocumentFieldValue): string {
    if (typeof value === 'string') {
        return value || '—';
    }

    if (value === null) {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.length
            ? value.map((item) => `${item.code} — ${item.display}`).join('\n')
            : '—';
    }

    return `${value.code} — ${value.display}`;
}

export function ClinicalDocumentHistory({
    versions,
    emptyMessage = 'No structured document versions yet.',
}: {
    versions: ClinicalDocumentVersion[];
    emptyMessage?: string;
}) {
    if (versions.length === 0) {
        return <p className="text-sm text-muted-foreground">{emptyMessage}</p>;
    }

    return (
        <ol
            aria-label="Clinical document version history"
            className="space-y-2"
        >
            {versions.map((version) => {
                const timestamp = version.finalized_at ?? version.created_at;

                return (
                    <li key={version.public_id}>
                        <article className="rounded-md border border-border bg-muted/30 p-3">
                            <header className="flex flex-wrap items-start justify-between gap-2 border-b border-border pb-2">
                                <div>
                                    <h3 className="text-sm font-semibold text-foreground">
                                        {documentLabel[version.document_type]}
                                    </h3>
                                    <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                                        Version {version.version} ·{' '}
                                        {version.actor_name ??
                                            'Actor unavailable'}
                                    </p>
                                </div>
                                <div className="text-right">
                                    <span
                                        className={
                                            version.state === 'FINAL'
                                                ? 'rounded-md bg-success/10 px-2 py-1 text-xs font-semibold text-success'
                                                : 'rounded-md bg-warning/10 px-2 py-1 text-xs font-semibold text-warning'
                                        }
                                    >
                                        {version.state === 'FINAL'
                                            ? 'Final'
                                            : 'Draft'}
                                    </span>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {timestamp
                                            ? new Date(
                                                  timestamp,
                                              ).toLocaleString('en-GB')
                                            : 'Time unavailable'}
                                    </p>
                                </div>
                            </header>
                            <dl className="mt-2 grid gap-x-4 gap-y-2 md:grid-cols-2">
                                {Object.entries(version.fields).map(
                                    ([key, value]) => (
                                        <div key={key} className="min-w-0">
                                            <dt className="text-xs font-semibold text-secondary-foreground">
                                                {fieldLabel[key] ?? key}
                                            </dt>
                                            <dd className="mt-0.5 text-sm whitespace-pre-wrap text-foreground">
                                                {presentField(value)}
                                            </dd>
                                        </div>
                                    ),
                                )}
                            </dl>
                        </article>
                    </li>
                );
            })}
        </ol>
    );
}
