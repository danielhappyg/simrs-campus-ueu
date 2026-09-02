import { ExternalLink, ShieldCheck } from 'lucide-react';
import { LaboratoryEncounterPanel } from '../laboratory/laboratory-encounter-panel';
import { RadiologyEncounterPanel } from '../radiology/radiology-encounter-panel';
import { EmergencyDiagnosticAssignmentHistory } from './emergency-follow-up-history';
import { EvidenceTime, TriageChip } from './emergency-shared';
import { EmergencyTriageTimeline } from './emergency-triage-panel';
import type {
    EmergencyDocumentVersion,
    SourceEmergencyProjection,
} from './types';

const documentLabels: Record<string, Record<string, string>> = {
    NURSING: {
        arrival_condition: 'Kondisi saat diterima',
        focused_assessment: 'Asesmen terfokus',
        interventions: 'Intervensi',
        response_evaluation: 'Respons dan evaluasi',
        safety_observation_needs: 'Kebutuhan keselamatan / observasi',
        handoff_note: 'Catatan serah terima',
    },
    MEDICAL: {
        anamnesis: 'Anamnesis',
        focused_physical_examination: 'Pemeriksaan fisik terfokus',
        clinical_impression: 'Kesan klinis',
        problem_list: 'Daftar masalah',
        treatment_action_plan: 'Rencana tindakan / terapi',
        diagnostic_order_rationale: 'Alasan pemeriksaan penunjang',
        disposition_readiness_note: 'Kesiapan disposisi',
    },
};

function FinalDocument({
    document,
}: {
    document: EmergencyDocumentVersion | null;
}) {
    if (!document) {
        return (
            <p className="rounded-lg border border-dashed border-border p-3 text-sm text-muted-foreground">
                Dokumen Final tidak tersedia pada proyeksi ini.
            </p>
        );
    }

    return (
        <article className="rounded-lg border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-semibold">
                        {document.document_type === 'NURSING'
                            ? 'Dokumentasi keperawatan Final'
                            : 'Dokumentasi medis Final'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Versi {document.version}
                    </p>
                </div>
                <div className="text-right">
                    <p className="text-xs font-semibold">
                        {document.author.name ?? 'Penulis tidak tersedia'}
                    </p>
                    <EvidenceTime value={document.finalized_at} />
                </div>
            </div>
            <dl className="mt-3 grid gap-3 md:grid-cols-2">
                {Object.entries(document.fields).map(([key, value]) => (
                    <div key={key}>
                        <dt className="text-xs font-semibold text-muted-foreground">
                            {documentLabels[document.document_type]?.[key] ??
                                key.replaceAll('_', ' ')}
                        </dt>
                        <dd className="mt-0.5 text-sm whitespace-pre-wrap">
                            {value || '—'}
                        </dd>
                    </div>
                ))}
            </dl>
        </article>
    );
}

export function SourceEmergencyProvenancePanel({
    projection,
}: {
    projection: SourceEmergencyProjection;
}) {
    const currentDisposition = projection.dispositions.at(-1) ?? null;

    return (
        <section
            aria-labelledby="source-igd-title"
            className="space-y-4 rounded-xl border border-primary/20 bg-primary/3 p-4 md:p-5"
        >
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[0.68rem] font-semibold tracking-[0.13em] text-muted-foreground uppercase">
                        Provenans episode asal
                    </p>
                    <h2
                        id="source-igd-title"
                        className="mt-0.5 flex items-center gap-2 text-lg font-semibold"
                    >
                        <ShieldCheck
                            aria-hidden="true"
                            className="size-5 text-primary"
                        />{' '}
                        Bukti IGD asal
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Rekam sumber ditampilkan baca saja dan tidak disalin
                        menjadi dokumentasi Rawat Inap.
                    </p>
                </div>
                <a
                    href={`/pemeriksaan/igd/${projection.source_encounter.public_id}`}
                    className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-primary hover:underline"
                >
                    Buka episode IGD{' '}
                    <ExternalLink aria-hidden="true" className="size-4" />
                </a>
            </header>
            <div className="grid gap-3 rounded-lg border border-border bg-card p-4 md:grid-cols-4">
                <div>
                    <p className="text-xs text-muted-foreground">Pasien</p>
                    <p className="font-semibold">
                        {projection.source_encounter.patient.full_name ?? '—'}
                    </p>
                </div>
                <div>
                    <p className="text-xs text-muted-foreground">Nomor RM</p>
                    <p className="font-mono text-sm font-semibold">
                        {projection.source_encounter.patient
                            .medical_record_number ?? '—'}
                    </p>
                </div>
                <div>
                    <p className="text-xs text-muted-foreground">
                        Triage terakhir
                    </p>
                    <div className="mt-1">
                        <TriageChip
                            code={
                                projection.source_encounter.triage
                                    ?.current_category ?? null
                            }
                            cue={
                                projection.source_encounter.triage
                                    ?.current_category_text_cue
                            }
                        />
                    </div>
                </div>
                <div>
                    <p className="text-xs text-muted-foreground">
                        Disposisi aktif
                    </p>
                    <p className="font-semibold">
                        {currentDisposition?.label ?? '—'}
                    </p>
                </div>
            </div>
            <details
                open
                className="rounded-lg border border-border bg-card p-4"
            >
                <summary className="min-h-11 cursor-pointer py-2 font-semibold">
                    Linimasa triage ({projection.triage.length})
                </summary>
                <div className="mt-3">
                    <EmergencyTriageTimeline assessments={projection.triage} />
                </div>
            </details>
            <div className="grid gap-3 xl:grid-cols-2">
                <FinalDocument document={projection.final_nursing_document} />
                <FinalDocument document={projection.final_medical_document} />
            </div>
            <details className="rounded-lg border border-border bg-card p-4">
                <summary className="min-h-11 cursor-pointer py-2 font-semibold">
                    Riwayat disposisi ({projection.dispositions.length})
                </summary>
                <ol className="mt-3 space-y-2">
                    {projection.dispositions.map((item) => (
                        <li
                            key={item.public_id}
                            className="rounded-md bg-muted/30 p-3 text-sm"
                        >
                            <div className="flex flex-wrap justify-between gap-2">
                                <p className="font-semibold">
                                    v{item.version} · {item.label}
                                </p>
                                <EvidenceTime value={item.signed_at} />
                            </div>
                            {item.correction_reason ? (
                                <p className="mt-1">
                                    <span className="font-semibold">
                                        Koreksi:
                                    </span>{' '}
                                    {item.correction_reason}
                                </p>
                            ) : null}
                            <p className="mt-1 text-xs text-muted-foreground">
                                {item.physician.name ?? 'Dokter tidak tersedia'}
                            </p>
                        </li>
                    ))}
                </ol>
            </details>
            <EmergencyDiagnosticAssignmentHistory
                items={projection.follow_up.diagnostic_assignment_history}
            />
            <div className="grid gap-4 xl:grid-cols-2">
                <LaboratoryEncounterPanel projection={projection.laboratory} />
                <RadiologyEncounterPanel projection={projection.radiology} />
            </div>
        </section>
    );
}
