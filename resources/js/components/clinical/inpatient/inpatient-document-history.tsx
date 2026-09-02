import { CalendarDays, LockKeyhole, UserRound } from 'lucide-react';
import { PlacementSnapshot } from './placement-snapshot';
import {
    formatClinicalDate,
    inpatientDocumentStateLabel,
    inpatientDocumentTypeLabel,
} from './presentation';
import type { InpatientDailyDocumentVersion } from './types';

const fieldLabels: Record<string, string> = {
    nursing_observation: 'Observasi keperawatan',
    nursing_intervention: 'Intervensi keperawatan',
    nursing_evaluation: 'Evaluasi keperawatan',
    subjective: 'Subjektif',
    objective: 'Objektif',
    assessment: 'Asesmen',
    plan: 'Rencana',
    additional_notes: 'Catatan tambahan',
};

type Props = {
    versions: InpatientDailyDocumentVersion[];
};

export function InpatientDocumentHistory({ versions }: Props) {
    if (versions.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-border bg-card p-6 text-center">
                <LockKeyhole
                    aria-hidden="true"
                    className="mx-auto size-5 text-muted-foreground"
                />
                <p className="mt-2 text-sm font-semibold">
                    Belum ada versi dokumentasi harian
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                    Versi yang tersimpan akan tampil sebagai riwayat hanya-baca.
                </p>
            </div>
        );
    }

    return (
        <ol className="relative space-y-4 before:absolute before:top-3 before:bottom-3 before:left-[0.68rem] before:w-px before:bg-primary/25">
            {versions.map((version) => {
                const fields = Object.entries(version.fields).filter(
                    (entry): entry is [string, string] =>
                        typeof entry[1] === 'string' && entry[1].trim() !== '',
                );

                return (
                    <li key={version.public_id} className="relative pl-8">
                        <span
                            aria-hidden="true"
                            className="absolute top-2 left-1.5 size-2.5 rounded-full border-2 border-card bg-primary ring-4 ring-background"
                        />
                        <article className="clinical-shadow overflow-hidden rounded-xl border border-border bg-card">
                            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-border bg-muted/40 px-4 py-3">
                                <div>
                                    <p className="text-xs font-semibold text-secondary-foreground">
                                        {
                                            inpatientDocumentTypeLabel[
                                                version.document_type
                                            ]
                                        }
                                    </p>
                                    <h3 className="mt-0.5 text-sm font-semibold text-foreground">
                                        {
                                            inpatientDocumentStateLabel[
                                                version.state
                                            ]
                                        }{' '}
                                        · versi {version.version}
                                    </h3>
                                </div>
                                <span className="rounded-full bg-secondary px-2.5 py-1 font-mono text-[0.68rem] font-semibold text-secondary-foreground">
                                    {version.service_date}
                                </span>
                            </header>
                            <div className="space-y-4 p-4">
                                <dl className="grid gap-2 text-xs sm:grid-cols-3">
                                    <div className="flex gap-2">
                                        <UserRound
                                            aria-hidden="true"
                                            className="mt-0.5 size-3.5 text-muted-foreground"
                                        />
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Penulis dokumen
                                            </dt>
                                            <dd className="font-semibold">
                                                {version.author_name ?? '—'}
                                            </dd>
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <UserRound
                                            aria-hidden="true"
                                            className="mt-0.5 size-3.5 text-muted-foreground"
                                        />
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Aktor versi
                                            </dt>
                                            <dd className="font-semibold">
                                                {version.actor_name ?? '—'}
                                            </dd>
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <CalendarDays
                                            aria-hidden="true"
                                            className="mt-0.5 size-3.5 text-muted-foreground"
                                        />
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Dicatat
                                            </dt>
                                            <dd className="font-semibold">
                                                {formatClinicalDate(
                                                    version.created_at,
                                                )}
                                            </dd>
                                        </div>
                                    </div>
                                </dl>

                                <div className="grid gap-3 md:grid-cols-2">
                                    {fields.length > 0 ? (
                                        fields.map(([key, value]) => (
                                            <div
                                                key={key}
                                                className="rounded-lg border border-border bg-background p-3"
                                            >
                                                <h4 className="text-xs font-semibold text-muted-foreground">
                                                    {fieldLabels[key] ?? key}
                                                </h4>
                                                <p className="mt-1 text-sm leading-6 whitespace-pre-wrap text-foreground">
                                                    {value}
                                                </p>
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            Versi ini belum memiliki isi
                                            terstruktur.
                                        </p>
                                    )}
                                </div>

                                <details className="rounded-lg border border-border bg-muted/30">
                                    <summary className="cursor-pointer px-3 py-2 text-xs font-semibold text-secondary-foreground">
                                        Lihat snapshot penempatan yang tidak
                                        berubah
                                    </summary>
                                    <div className="border-t border-border p-3">
                                        <PlacementSnapshot
                                            snapshot={
                                                version.placement_snapshot
                                            }
                                            compact
                                        />
                                        <p className="mt-2 font-mono text-[0.68rem] text-muted-foreground">
                                            Status kunjungan saat versi dibuat:{' '}
                                            {version.encounter_status_snapshot}
                                        </p>
                                    </div>
                                </details>
                            </div>
                        </article>
                    </li>
                );
            })}
        </ol>
    );
}
