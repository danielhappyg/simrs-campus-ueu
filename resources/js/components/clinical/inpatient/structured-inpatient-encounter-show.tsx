import { Head, Link, usePage } from '@inertiajs/react';
import {
    BedSingle,
    Building2,
    CalendarClock,
    ClipboardCheck,
    FileText,
    History,
    MapPin,
    Pill,
    ScanLine,
    ShieldCheck,
    TestTube2,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { SourceEmergencyProvenancePanel } from '@/components/clinical/emergency/source-emergency-provenance-panel';
import { LaboratoryEncounterPanel } from '@/components/clinical/laboratory/laboratory-encounter-panel';
import { PharmacyEncounterPanel } from '@/components/clinical/pharmacy/pharmacy-encounter-panel';
import { RadiologyEncounterPanel } from '@/components/clinical/radiology/radiology-encounter-panel';
import { cn } from '@/lib/utils';
import { InpatientBedTransferPanel } from './inpatient-bed-transfer-panel';
import { InpatientDailyDocumentForm } from './inpatient-daily-document-form';
import { InpatientDischargeSummaryPanel } from './inpatient-discharge-summary-panel';
import { InpatientDocumentHistory } from './inpatient-document-history';
import { InpatientLocationHistoryPanel } from './inpatient-location-history';
import {
    formatClinicalDate,
    inpatientDocumentTypeLabel,
    inpatientStatusLabel,
} from './presentation';
import type {
    InpatientDailyDocument,
    InpatientDailyDocumentType,
    InpatientDocumentationShowProps,
} from './types';
import { useUnsavedInpatientDocumentGuard } from './use-unsaved-inpatient-document-guard';

const tabs = [
    { id: 'daily', label: 'Daily documentation', icon: FileText },
    { id: 'discharge', label: 'Discharge summary', icon: ClipboardCheck },
    { id: 'placement', label: 'Placement history', icon: MapPin },
    { id: 'laboratory', label: 'Laboratory', icon: TestTube2 },
    { id: 'radiology', label: 'Radiology', icon: ScanLine },
    { id: 'pharmacy', label: 'Prescriptions & medicines', icon: Pill },
    {
        id: 'source-emergency',
        label: 'Emergency Department source evidence',
        icon: ShieldCheck,
    },
    { id: 'versions', label: 'Document version history', icon: History },
    { id: 'legacy', label: 'Legacy notes', icon: CalendarClock },
] as const;

type TabId = (typeof tabs)[number]['id'];

function actorDocument(
    documents: InpatientDailyDocument[],
    type: InpatientDailyDocumentType,
    editableDocumentPublicId: string | null,
) {
    if (editableDocumentPublicId === null) {
        return undefined;
    }

    return documents.find(
        (document) =>
            document.public_id === editableDocumentPublicId &&
            document.document_type === type &&
            document.is_current_actor_document,
    );
}

export default function StructuredInpatientEncounterShow({
    encounter,
    legacyEntries,
    documentation,
    permissions,
    actions,
    discharge_summary,
    inpatient_discharge_coding_source,
    inpatient_discharge,
    inpatient_summary_addendum,
    location_history,
    laboratory,
    radiology,
    pharmacy,
    source_emergency,
}: InpatientDocumentationShowProps) {
    const { flash } = usePage().props;
    const [activeTab, setActiveTab] = useState<TabId>('daily');
    const [dirtyDocuments, setDirtyDocuments] = useState<
        Record<InpatientDailyDocumentType, boolean>
    >({ NURSING_DAILY: false, MEDICAL_DAILY: false });
    const [dischargeSummaryDirty, setDischargeSummaryDirty] = useState(false);
    const [dischargeCodingSourceDirty, setDischargeCodingSourceDirty] =
        useState(false);
    const [dischargeSummaryAddendumDirty, setDischargeSummaryAddendumDirty] =
        useState(false);
    const nursingDocument = useMemo(
        () =>
            actorDocument(
                documentation.documents,
                'NURSING_DAILY',
                permissions.nursing.editable_document_public_id,
            ),
        [
            documentation.documents,
            permissions.nursing.editable_document_public_id,
        ],
    );
    const medicalDocument = useMemo(
        () =>
            actorDocument(
                documentation.documents,
                'MEDICAL_DAILY',
                permissions.medical.editable_document_public_id,
            ),
        [
            documentation.documents,
            permissions.medical.editable_document_public_id,
        ],
    );
    const otherDocuments = documentation.documents.filter(
        (document) => !document.is_current_actor_document,
    );
    const hasUnsavedChanges =
        Object.values(dirtyDocuments).some(Boolean) ||
        dischargeSummaryDirty ||
        dischargeCodingSourceDirty ||
        dischargeSummaryAddendumDirty;

    useUnsavedInpatientDocumentGuard(hasUnsavedChanges);

    const handleDirtyChange = useCallback(
        (type: InpatientDailyDocumentType, dirty: boolean) => {
            setDirtyDocuments((current) =>
                current[type] === dirty
                    ? current
                    : { ...current, [type]: dirty },
            );
        },
        [],
    );

    const selectTab = (tab: TabId) => {
        setActiveTab(tab);
        document.getElementById(`inpatient-tab-${tab}`)?.focus();
    };

    const handleTabKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        tab: TabId,
    ) => {
        const currentIndex = tabs.findIndex(({ id }) => id === tab);
        let nextIndex: number | null = null;

        if (event.key === 'ArrowRight') {
            nextIndex = (currentIndex + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
            nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = tabs.length - 1;
        }

        if (nextIndex !== null) {
            event.preventDefault();
            selectTab(tabs[nextIndex].id);
        }
    };

    return (
        <>
            <Head
                title={`Inpatient Care — ${encounter.patient.full_name ?? 'Encounter'}`}
            />
            <main className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-4 px-3 py-4 md:px-5 md:py-5">
                {typeof flash?.error === 'string' && flash.error ? (
                    <div
                        role="alert"
                        className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                    >
                        {flash.error}
                    </div>
                ) : null}
                {typeof flash?.success === 'string' && flash.success ? (
                    <div
                        role="status"
                        className="rounded-lg border border-success/30 bg-success/5 px-3 py-2 text-sm text-success"
                    >
                        {flash.success}
                    </div>
                ) : null}

                <Link
                    href="/pemeriksaan/rawat-inap"
                    className="inline-flex min-h-11 items-center self-start text-sm font-semibold text-primary hover:underline"
                >
                    ← Back to inpatient list
                </Link>

                <header className="clinical-shadow overflow-hidden rounded-xl border border-border bg-card">
                    <div className="grid lg:grid-cols-[minmax(0,1fr)_minmax(24rem,0.72fr)]">
                        <div className="p-4 md:p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="text-[0.7rem] font-semibold tracking-[0.13em] text-muted-foreground uppercase">
                                        Inpatient episode · longitudinal
                                        documentation
                                    </p>
                                    <h1 className="mt-1 text-2xl font-semibold text-foreground">
                                        {encounter.patient.full_name ??
                                            'Patient name unavailable'}
                                    </h1>
                                    <p className="mt-1 font-mono text-xs text-muted-foreground">
                                        {encounter.patient
                                            .medical_record_number ?? '—'}
                                        {' · '}
                                        {encounter.public_id}
                                    </p>
                                </div>
                                <span className="rounded-full bg-secondary px-3 py-1.5 text-xs font-semibold text-secondary-foreground">
                                    {inpatientStatusLabel[encounter.status] ??
                                        encounter.status}
                                </span>
                            </div>
                            <dl className="mt-5 grid gap-3 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Admission started
                                    </dt>
                                    <dd className="mt-0.5 font-semibold">
                                        {formatClinicalDate(
                                            encounter.registered_at,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Document definition
                                    </dt>
                                    <dd className="mt-0.5 font-mono text-[0.7rem] font-semibold break-all">
                                        {documentation.definition_version}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Saved versions
                                    </dt>
                                    <dd className="mt-0.5 font-semibold">
                                        {documentation.versions.length} versions
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        {encounter.placement ? (
                            <aside
                                aria-label={
                                    inpatient_discharge.record
                                        ? 'Last placement'
                                        : 'Episode placement'
                                }
                                className="relative overflow-hidden border-t border-sidebar-border bg-sidebar p-4 text-sidebar-foreground lg:border-t-0 lg:border-l"
                            >
                                <div
                                    aria-hidden="true"
                                    className="absolute top-0 bottom-0 left-0 w-1 bg-sidebar-primary"
                                />
                                <p className="text-[0.68rem] font-semibold tracking-[0.13em] uppercase opacity-70">
                                    {inpatient_discharge.record
                                        ? 'Last placement'
                                        : 'Episode placement'}
                                </p>
                                <div className="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                                    <div className="flex gap-3">
                                        <Building2
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 text-sidebar-primary"
                                        />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold">
                                                {
                                                    encounter.placement
                                                        .ward_display_name
                                                }
                                            </p>
                                            <p className="font-mono text-[0.7rem] opacity-70">
                                                {encounter.placement.ward_code}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex gap-3">
                                        <MapPin
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 text-sidebar-primary"
                                        />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold">
                                                {encounter.placement.room_label}
                                            </p>
                                            <p className="text-[0.7rem] opacity-70">
                                                {
                                                    encounter.placement
                                                        .service_class
                                                }
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex gap-3">
                                        <BedSingle
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 text-sidebar-primary"
                                        />
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold">
                                                {
                                                    encounter.placement
                                                        .bed_display_name
                                                }
                                            </p>
                                            <p className="font-mono text-[0.7rem] opacity-70">
                                                {encounter.placement.bed_code}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <InpatientBedTransferPanel
                                    action={location_history.transfer}
                                    disabledByUnsavedDocument={
                                        hasUnsavedChanges
                                    }
                                />
                            </aside>
                        ) : (
                            <aside
                                aria-label="Placement unavailable"
                                className="border-t border-border bg-muted/40 p-4 lg:border-t-0 lg:border-l"
                            >
                                <p className="text-sm font-semibold">
                                    Managed placement is unavailable
                                </p>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    This legacy episode remains readable but
                                    cannot receive structured daily
                                    documentation.
                                </p>
                            </aside>
                        )}
                    </div>
                </header>

                <div className="overflow-x-auto border-b border-border">
                    <div
                        role="tablist"
                        aria-label="Inpatient episode sections"
                        className="flex min-w-max gap-1"
                    >
                        {tabs.map(({ id, label, icon: Icon }) => (
                            <button
                                key={id}
                                id={`inpatient-tab-${id}`}
                                type="button"
                                role="tab"
                                aria-selected={activeTab === id}
                                aria-controls={`inpatient-panel-${id}`}
                                tabIndex={activeTab === id ? 0 : -1}
                                onClick={() => setActiveTab(id)}
                                onKeyDown={(event) =>
                                    handleTabKeyDown(event, id)
                                }
                                className={cn(
                                    'inline-flex min-h-11 items-center gap-2 border-b-2 px-3 text-sm font-semibold transition-colors',
                                    activeTab === id
                                        ? 'border-primary text-primary'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Icon aria-hidden="true" className="size-4" />
                                {label}
                                {id === 'versions' ? (
                                    <span className="rounded-full bg-secondary px-1.5 py-0.5 text-[0.68rem] text-secondary-foreground">
                                        {documentation.versions.length}
                                    </span>
                                ) : null}
                            </button>
                        ))}
                    </div>
                </div>

                <section
                    id="inpatient-panel-daily"
                    role="tabpanel"
                    aria-labelledby="inpatient-tab-daily"
                    hidden={activeTab !== 'daily'}
                    className="space-y-4"
                >
                    {documentation.available ? (
                        <div className="grid gap-4 xl:grid-cols-2">
                            <InpatientDailyDocumentForm
                                key={`nursing-${nursingDocument?.public_id ?? 'new'}-${nursingDocument?.version ?? 0}`}
                                type="NURSING_DAILY"
                                definitionVersion={
                                    documentation.definition_version
                                }
                                document={nursingDocument}
                                permission={permissions.nursing}
                                actions={actions.nursing}
                                onDirtyChange={handleDirtyChange}
                            />
                            <InpatientDailyDocumentForm
                                key={`medical-${medicalDocument?.public_id ?? 'new'}-${medicalDocument?.version ?? 0}`}
                                type="MEDICAL_DAILY"
                                definitionVersion={
                                    documentation.definition_version
                                }
                                document={medicalDocument}
                                permission={permissions.medical}
                                actions={actions.medical}
                                onDirtyChange={handleDirtyChange}
                            />
                        </div>
                    ) : (
                        <div
                            role="status"
                            className="rounded-xl border border-warning/30 bg-warning/5 p-4"
                        >
                            <h2 className="text-sm font-semibold text-foreground">
                                Structured daily documentation is unavailable
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {documentation.unavailable_reason ??
                                    'This episode does not yet have an active managed inpatient placement.'}
                            </p>
                        </div>
                    )}

                    {otherDocuments.length > 0 ? (
                        <section
                            aria-labelledby="other-daily-documents"
                            className="rounded-xl border border-border bg-card p-4"
                        >
                            <h2
                                id="other-daily-documents"
                                className="text-sm font-semibold"
                            >
                                Daily notes by other healthcare professionals
                            </h2>
                            <p className="mt-1 text-xs text-muted-foreground">
                                This document is read-only. Each author has
                                their own version history.
                            </p>
                            <ul className="mt-3 grid gap-2 md:grid-cols-2">
                                {otherDocuments.map((document) => (
                                    <li
                                        key={document.public_id}
                                        className="rounded-lg border border-border bg-muted/30 p-3"
                                    >
                                        <p className="text-xs font-semibold text-secondary-foreground">
                                            {
                                                inpatientDocumentTypeLabel[
                                                    document.document_type
                                                ]
                                            }
                                        </p>
                                        <p className="mt-1 text-sm font-semibold">
                                            {document.author.name ?? '—'} ·{' '}
                                            {document.service_date}
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {document.state === 'FINAL'
                                                ? 'Final'
                                                : 'Draft'}{' '}
                                            · Version {document.version}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}
                </section>

                <section
                    id="inpatient-panel-discharge"
                    role="tabpanel"
                    aria-labelledby="inpatient-tab-discharge"
                    hidden={activeTab !== 'discharge'}
                >
                    <InpatientDischargeSummaryPanel
                        projection={discharge_summary}
                        codingSourceProjection={
                            inpatient_discharge_coding_source
                        }
                        routineDischargeProjection={inpatient_discharge}
                        summaryAddendumProjection={inpatient_summary_addendum}
                        disabledByUnsavedDocument={hasUnsavedChanges}
                        onDirtyChange={setDischargeSummaryDirty}
                        onCodingSourceDirtyChange={
                            setDischargeCodingSourceDirty
                        }
                        onSummaryAddendumDirtyChange={
                            setDischargeSummaryAddendumDirty
                        }
                    />
                </section>

                <section
                    id="inpatient-panel-placement"
                    role="tabpanel"
                    aria-labelledby="inpatient-tab-placement"
                    hidden={activeTab !== 'placement'}
                >
                    <InpatientLocationHistoryPanel
                        history={location_history}
                        placementReleased={inpatient_discharge.record !== null}
                    />
                </section>

                <section
                    id="inpatient-panel-laboratory"
                    role="tabpanel"
                    aria-labelledby="inpatient-tab-laboratory"
                    hidden={activeTab !== 'laboratory'}
                >
                    {laboratory ? (
                        <LaboratoryEncounterPanel projection={laboratory} />
                    ) : (
                        <div className="rounded-xl border border-dashed border-border bg-card p-6 text-center text-sm text-muted-foreground">
                            Laboratory data are unavailable for this episode.
                        </div>
                    )}
                </section>

                <section
                    id="inpatient-panel-radiology"
                    role="tabpanel"
                    aria-labelledby="inpatient-tab-radiology"
                    hidden={activeTab !== 'radiology'}
                >
                    {radiology ? (
                        <RadiologyEncounterPanel projection={radiology} />
                    ) : (
                        <div className="rounded-xl border border-dashed border-border bg-card p-6 text-center text-sm text-muted-foreground">
                            Radiology data are unavailable for this episode.
                        </div>
                    )}
                </section>

                <section
                    id="inpatient-panel-pharmacy"
                    role="tabpanel"
                    aria-labelledby="inpatient-tab-pharmacy"
                    hidden={activeTab !== 'pharmacy'}
                >
                    {pharmacy ? (
                        <PharmacyEncounterPanel projection={pharmacy} />
                    ) : (
                        <div className="rounded-xl border border-dashed border-border bg-card p-6 text-center text-sm text-muted-foreground">
                            Prescription data are unavailable for this episode.
                        </div>
                    )}
                </section>

                <section
                    id="inpatient-panel-source-emergency"
                    role="tabpanel"
                    aria-labelledby="inpatient-tab-source-emergency"
                    hidden={activeTab !== 'source-emergency'}
                >
                    {source_emergency ? (
                        <SourceEmergencyProvenancePanel
                            projection={source_emergency}
                        />
                    ) : (
                        <div className="rounded-xl border border-dashed border-border bg-card p-6 text-center text-sm text-muted-foreground">
                            This episode did not originate from an Emergency
                            Department handoff.
                        </div>
                    )}
                </section>

                <section
                    id="inpatient-panel-versions"
                    role="tabpanel"
                    aria-labelledby="inpatient-tab-versions"
                    hidden={activeTab !== 'versions'}
                >
                    <div className="mb-4">
                        <h2 className="text-lg font-semibold">
                            Immutable longitudinal history
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Version order, actor, date of service, and placement
                            snapshot are preserved for every record.
                        </p>
                    </div>
                    <InpatientDocumentHistory
                        versions={documentation.versions}
                    />
                </section>

                <section
                    id="inpatient-panel-legacy"
                    role="tabpanel"
                    aria-labelledby="inpatient-tab-legacy"
                    hidden={activeTab !== 'legacy'}
                >
                    <div className="mb-4">
                        <h2 className="text-lg font-semibold">
                            Legacy clinical notes
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            These notes are preserved as read-only and are not
                            converted into structured daily documentation.
                        </p>
                    </div>
                    {legacyEntries.length > 0 ? (
                        <ol className="grid gap-3 lg:grid-cols-2">
                            {legacyEntries.map((entry) => (
                                <li
                                    key={entry.public_id}
                                    className="rounded-xl border border-border bg-card p-4"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <h3 className="text-sm font-semibold">
                                            {entry.entry_type}
                                        </h3>
                                        <time className="text-xs text-muted-foreground">
                                            {formatClinicalDate(
                                                entry.created_at,
                                            )}
                                        </time>
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {entry.author_name ??
                                            'Author unavailable'}
                                    </p>
                                    <p className="mt-3 text-sm leading-6 whitespace-pre-wrap">
                                        {entry.body}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <div className="rounded-xl border border-dashed border-border bg-card p-6 text-center text-sm text-muted-foreground">
                            No legacy clinical notes for this episode.
                        </div>
                    )}
                </section>
            </main>
        </>
    );
}
