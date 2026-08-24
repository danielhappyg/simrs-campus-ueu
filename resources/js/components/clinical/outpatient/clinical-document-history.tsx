import type { ClinicalDocumentVersion } from './types';

const documentLabel = {
    NURSING_ASSESSMENT: 'Asesmen keperawatan',
    MEDICAL_ASSESSMENT: 'Asesmen medis',
};

const fieldLabel: Record<string, string> = {
    nursing_assessment: 'Asesmen keperawatan',
    anamnesis: 'Anamnesis',
    objective_examination: 'Pemeriksaan objektif',
    clinical_assessment: 'Asesmen klinis',
    care_plan: 'Rencana pelayanan',
    additional_notes: 'Catatan tambahan',
};

export function ClinicalDocumentHistory({
    versions,
    emptyMessage = 'Belum ada versi dokumen terstruktur.',
}: {
    versions: ClinicalDocumentVersion[];
    emptyMessage?: string;
}) {
    if (versions.length === 0) {
        return <p className="text-sm text-muted-foreground">{emptyMessage}</p>;
    }

    return (
        <ol aria-label="Riwayat versi dokumen klinis" className="space-y-2">
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
                                        Versi {version.version} ·{' '}
                                        {version.actor_name ??
                                            'Aktor tidak tersedia'}
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
                                            : 'Draf'}
                                    </span>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {timestamp
                                            ? new Date(
                                                  timestamp,
                                              ).toLocaleString('id-ID')
                                            : 'Waktu tidak tersedia'}
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
                                                {value || '—'}
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
