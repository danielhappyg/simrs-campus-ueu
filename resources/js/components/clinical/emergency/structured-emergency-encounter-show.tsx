import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ClipboardCheck,
    FileText,
    History,
    Pill,
    ScanLine,
    TestTube2,
} from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { LaboratoryEncounterPanel } from '../laboratory/laboratory-encounter-panel';
import { PharmacyEncounterPanel } from '../pharmacy/pharmacy-encounter-panel';
import { RadiologyEncounterPanel } from '../radiology/radiology-encounter-panel';
import { EmergencyDispositionPanel } from './emergency-disposition-panel';
import { EmergencyDocumentationPanel } from './emergency-document-panel';
import { TriageChip } from './emergency-shared';
import { EmergencyTriagePanel } from './emergency-triage-panel';
import { formatEmergencyDate } from './operation';
import type { EmergencyShowProps } from './types';

const tabs = [
    { id: 'triage', label: 'Triage', icon: Activity },
    { id: 'documentation', label: 'Dokumentasi', icon: FileText },
    { id: 'laboratory', label: 'Laboratorium', icon: TestTube2 },
    { id: 'radiology', label: 'Radiologi', icon: ScanLine },
    { id: 'pharmacy', label: 'Resep & Obat', icon: Pill },
    { id: 'disposition', label: 'Disposisi', icon: ClipboardCheck },
    { id: 'legacy', label: 'Catatan lama', icon: History },
] as const;
type TabId = (typeof tabs)[number]['id'];

const statusLabel: Record<string, string> = {
    REGISTERED: 'Terdaftar',
    IN_EXAMINATION: 'Dalam pemeriksaan',
    READY_FOR_RM: 'Siap ditinjau RMIK',
    CLOSED: 'Ditutup',
    CANCELLED: 'Dibatalkan',
};

export function StructuredEmergencyEncounterShow({
    initialTab = 'triage',
    ...props
}: EmergencyShowProps & { initialTab?: TabId }) {
    const {
        encounter,
        triage,
        documentation,
        laboratory,
        radiology,
        pharmacy,
        follow_up,
        disposition,
        legacy_entries,
    } = props;
    const { flash } = usePage().props;
    const [activeTab, setActiveTab] = useState<TabId>(initialTab);
    const selectTab = (tab: TabId) => {
        setActiveTab(tab);
        document.getElementById(`emergency-tab-${tab}`)?.focus();
    };
    const keyDown = (event: KeyboardEvent<HTMLButtonElement>, tab: TabId) => {
        const index = tabs.findIndex(({ id }) => id === tab);
        let next: number | null = null;

        if (event.key === 'ArrowRight') {
            next = (index + 1) % tabs.length;
        }

        if (event.key === 'ArrowLeft') {
            next = (index - 1 + tabs.length) % tabs.length;
        }

        if (event.key === 'Home') {
            next = 0;
        }

        if (event.key === 'End') {
            next = tabs.length - 1;
        }

        if (next !== null) {
            event.preventDefault();
            selectTab(tabs[next].id);
        }
    };

    return (
        <>
            <Head title={`IGD — ${encounter.patient.full_name ?? 'Episode'}`} />
            <main className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-4 px-3 py-4 md:px-5 md:py-5">
                {typeof flash?.error === 'string' && flash.error ? (
                    <div
                        role="alert"
                        className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                    >
                        {flash.error}
                    </div>
                ) : null}
                {typeof flash?.success === 'string' && flash.success ? (
                    <div
                        role="status"
                        className="rounded-lg border border-success/30 bg-success/5 p-3 text-sm text-success"
                    >
                        {flash.success}
                    </div>
                ) : null}
                <Link
                    href="/pemeriksaan/igd"
                    className="inline-flex min-h-11 items-center self-start text-sm font-semibold text-primary hover:underline"
                >
                    ← Kembali ke worklist IGD
                </Link>
                <header className="clinical-shadow overflow-hidden rounded-xl border border-border bg-card">
                    <div className="grid lg:grid-cols-[minmax(0,1fr)_23rem]">
                        <div className="p-4 md:p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="text-[0.68rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                                        Episode gawat darurat
                                    </p>
                                    <h1 className="mt-1 text-2xl font-semibold">
                                        {encounter.patient.full_name ??
                                            'Nama pasien belum tersedia'}
                                    </h1>
                                    <p className="mt-1 font-mono text-xs text-muted-foreground">
                                        {encounter.patient
                                            .medical_record_number ?? '—'}{' '}
                                        · {encounter.public_id}
                                    </p>
                                </div>
                                <span className="rounded-full bg-secondary px-3 py-1.5 text-xs font-semibold">
                                    {statusLabel[encounter.status] ??
                                        encounter.status}
                                </span>
                            </div>
                            <dl className="mt-5 grid gap-3 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Terdaftar
                                    </dt>
                                    <dd className="mt-0.5 font-semibold">
                                        {formatEmergencyDate(
                                            encounter.registered_at,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Dokter
                                    </dt>
                                    <dd className="mt-0.5 font-semibold">
                                        {encounter.doctor_name ??
                                            'Belum ditetapkan'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Keluhan utama
                                    </dt>
                                    <dd className="mt-0.5">
                                        {encounter.chief_complaint || '—'}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <aside className="relative border-t border-sidebar-border bg-sidebar p-4 text-sidebar-foreground lg:border-t-0 lg:border-l">
                            <span
                                aria-hidden="true"
                                className="absolute inset-y-0 left-0 w-1 bg-sidebar-primary"
                            />
                            <p className="text-[0.68rem] font-semibold tracking-[0.13em] uppercase opacity-70">
                                Triage terkini
                            </p>
                            <div className="mt-2">
                                <TriageChip
                                    code={triage.current?.category.code ?? null}
                                    cue={triage.current?.category.text_cue}
                                />
                            </div>
                            <p className="mt-3 text-sm font-semibold">
                                {triage.current?.category.text_cue ??
                                    'Menunggu asesmen awal'}
                            </p>
                            <p className="mt-1 text-xs opacity-75">
                                {triage.current
                                    ? `Dinilai ${formatEmergencyDate(triage.current.observed_at)} · ${triage.assessments.length - 1} asesmen ulang`
                                    : 'Dokumentasi dan disposisi terkunci sampai triage awal Final.'}
                            </p>
                        </aside>
                    </div>
                </header>
                <div
                    className="sticky top-0 z-10 overflow-x-auto rounded-xl border border-border bg-card/95 p-1 shadow-sm backdrop-blur"
                    role="tablist"
                    aria-label="Bagian episode IGD"
                >
                    {tabs.map(({ id, label, icon: Icon }) => (
                        <button
                            key={id}
                            id={`emergency-tab-${id}`}
                            role="tab"
                            aria-selected={activeTab === id}
                            aria-controls={`emergency-panel-${id}`}
                            tabIndex={activeTab === id ? 0 : -1}
                            type="button"
                            onClick={() => selectTab(id)}
                            onKeyDown={(event) => keyDown(event, id)}
                            className={`inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold ${activeTab === id ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                        >
                            <Icon aria-hidden="true" className="size-4" />
                            {label}
                        </button>
                    ))}
                </div>
                <div
                    id={`emergency-panel-${activeTab}`}
                    role="tabpanel"
                    aria-labelledby={`emergency-tab-${activeTab}`}
                >
                    {activeTab === 'triage' ? (
                        <EmergencyTriagePanel projection={triage} />
                    ) : null}
                    {activeTab === 'documentation' ? (
                        <EmergencyDocumentationPanel
                            projection={documentation}
                        />
                    ) : null}
                    {activeTab === 'laboratory' ? (
                        <LaboratoryEncounterPanel projection={laboratory} />
                    ) : null}
                    {activeTab === 'radiology' ? (
                        <RadiologyEncounterPanel projection={radiology} />
                    ) : null}
                    {activeTab === 'pharmacy' ? (
                        pharmacy ? (
                            <PharmacyEncounterPanel projection={pharmacy} />
                        ) : (
                            <div className="rounded-xl border border-dashed border-border bg-card p-6 text-center text-sm text-muted-foreground">
                                Data resep belum tersedia untuk episode ini.
                            </div>
                        )
                    ) : null}
                    {activeTab === 'disposition' ? (
                        <EmergencyDispositionPanel
                            disposition={disposition}
                            followUp={follow_up}
                        />
                    ) : null}
                    {activeTab === 'legacy' ? (
                        <section className="clinical-shadow rounded-xl border border-border bg-card p-4 md:p-5">
                            <h2 className="font-semibold">
                                Catatan lama · baca saja
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Catatan berikut dipertahankan sebagai riwayat.
                                Seluruh pencatatan baru menggunakan formulir IGD
                                terstruktur.
                            </p>
                            {legacy_entries.length ? (
                                <ol className="mt-4 space-y-3">
                                    {legacy_entries.map((entry) => (
                                        <li
                                            key={entry.public_id}
                                            className="rounded-lg border border-border bg-muted/25 p-3"
                                        >
                                            <div className="flex flex-wrap justify-between gap-2">
                                                <p className="text-xs font-semibold">
                                                    {entry.entry_type ===
                                                    'NURSING_INTAKE'
                                                        ? 'Catatan keperawatan lama'
                                                        : 'Catatan medis lama'}
                                                </p>
                                                <span className="text-xs text-muted-foreground">
                                                    {formatEmergencyDate(
                                                        entry.created_at,
                                                    )}
                                                </span>
                                            </div>
                                            <p className="mt-2 text-sm whitespace-pre-wrap">
                                                {entry.body}
                                            </p>
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {entry.author_name ??
                                                    'Penulis tidak tersedia'}
                                            </p>
                                        </li>
                                    ))}
                                </ol>
                            ) : (
                                <div className="mt-4">
                                    <EmergencyEmptyLegacy />
                                </div>
                            )}
                        </section>
                    ) : null}
                </div>
            </main>
        </>
    );
}

function EmergencyEmptyLegacy() {
    return (
        <p className="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground">
            Tidak ada catatan lama pada episode ini.
        </p>
    );
}
