import { Head, Link, router } from '@inertiajs/react';
import { Activity, ArrowRight, Search, Siren } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CareSettingSubnav } from '@/components/care-setting-subnav';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { TriageChip, triagePresentation } from './emergency-shared';
import { emergencyFieldClass, formatEmergencyDate } from './operation';
import type { EmergencyWorklistProps } from './types';

export function EmergencyWorklist({
    encounters,
    filters,
    indexPath = '/pemeriksaan/igd',
    showPathPrefix = '/pemeriksaan/igd',
    canOpen,
    payerOptions = [],
    mode = 'igd',
}: EmergencyWorklistProps & { mode?: 'igd' | 'triage' }) {
    const [q, setQ] = useState(filters.q);
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [payer, setPayer] = useState(filters.payer ?? '');

    const apply = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            indexPath,
            {
                q: q || undefined,
                date_from: dateFrom || undefined,
                date_to: dateTo || undefined,
                payer: payer || undefined,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head
                title={mode === 'triage' ? 'Triage IGD' : 'Pemeriksaan · IGD'}
            />
            <main className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-4 px-3 py-4 md:px-5 md:py-5">
                <CareSettingSubnav
                    items={[
                        {
                            href: '/pemeriksaan/rawat-jalan',
                            label: 'Rawat Jalan',
                        },
                        {
                            href: '/pemeriksaan/igd',
                            label: 'IGD',
                            active: mode === 'igd',
                        },
                        {
                            href: '/pemeriksaan/rawat-inap',
                            label: 'Rawat Inap',
                        },
                        {
                            href: '/pemeriksaan/triage',
                            label: 'Triage',
                            active: mode === 'triage',
                        },
                        {
                            href: '/pemeriksaan/laboratorium',
                            label: 'Laboratorium',
                        },
                        { href: '/pemeriksaan/radiologi', label: 'Radiologi' },
                    ]}
                />

                <header className="clinical-shadow overflow-hidden rounded-xl border border-border bg-card">
                    <div className="grid md:grid-cols-[minmax(0,1fr)_18rem]">
                        <div className="p-4 md:p-5">
                            <p className="text-[0.68rem] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                                Kendali episode gawat darurat
                            </p>
                            <h1 className="mt-1 flex items-center gap-2 text-2xl font-semibold text-foreground">
                                <Siren
                                    aria-hidden="true"
                                    className="size-6 text-primary"
                                />
                                {mode === 'triage'
                                    ? 'Worklist triage IGD'
                                    : 'Pemeriksaan IGD'}
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-muted-foreground">
                                {mode === 'triage'
                                    ? 'Pantau waktu tunggu, kategori manual terakhir, dan kebutuhan asesmen ulang.'
                                    : 'Buka episode untuk melihat riwayat triage, dokumentasi klinis, diagnostik, dan disposisi dalam satu alur.'}
                            </p>
                        </div>
                        <aside className="border-t border-sidebar-border bg-sidebar p-4 text-sidebar-foreground md:border-t-0 md:border-l">
                            <p className="text-xs font-semibold tracking-wide uppercase opacity-75">
                                Episode aktif
                            </p>
                            <p className="mt-1 font-mono text-4xl font-bold tabular-nums">
                                {encounters.length}
                            </p>
                            <p className="mt-1 text-xs opacity-75">
                                Urutan mengikuti waktu pendaftaran. Prioritas
                                klinis tetap ditetapkan manual oleh perawat.
                            </p>
                        </aside>
                    </div>
                </header>

                <form
                    onSubmit={apply}
                    aria-label="Filter worklist IGD"
                    className="grid gap-3 rounded-xl border border-border bg-card p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-[minmax(16rem,1fr)_10rem_10rem_11rem_auto]"
                >
                    <label className="text-xs font-semibold text-foreground">
                        Cari pasien atau nomor RM
                        <span className="relative block">
                            <Search
                                aria-hidden="true"
                                className="absolute top-4 left-3 size-4 text-muted-foreground"
                            />
                            <input
                                value={q}
                                onChange={(event) => setQ(event.target.value)}
                                className={cn(emergencyFieldClass, 'pl-9')}
                                placeholder="Nama / nomor RM"
                            />
                        </span>
                    </label>
                    <label className="text-xs font-semibold text-foreground">
                        Dari tanggal
                        <input
                            type="date"
                            value={dateFrom}
                            onChange={(event) =>
                                setDateFrom(event.target.value)
                            }
                            className={emergencyFieldClass}
                        />
                    </label>
                    <label className="text-xs font-semibold text-foreground">
                        Sampai tanggal
                        <input
                            type="date"
                            value={dateTo}
                            onChange={(event) => setDateTo(event.target.value)}
                            className={emergencyFieldClass}
                        />
                    </label>
                    <label className="text-xs font-semibold text-foreground">
                        Penjamin
                        <select
                            value={payer}
                            onChange={(event) => setPayer(event.target.value)}
                            className={emergencyFieldClass}
                        >
                            <option value="">Semua penjamin</option>
                            {payerOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button type="submit" className="min-h-11 self-end">
                        Terapkan
                    </Button>
                </form>

                <section
                    aria-labelledby="igd-worklist-title"
                    className="clinical-shadow overflow-hidden rounded-xl border border-border bg-card"
                >
                    <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                        <h2
                            id="igd-worklist-title"
                            className="font-semibold text-foreground"
                        >
                            Daftar episode IGD
                        </h2>
                        <span className="text-xs text-muted-foreground">
                            {encounters.length} episode
                        </span>
                    </div>
                    {encounters.length === 0 ? (
                        <div className="p-8 text-center text-sm text-muted-foreground">
                            Tidak ada episode yang cocok dengan filter ini.
                        </div>
                    ) : (
                        <div className="divide-y divide-border">
                            {encounters.map((encounter) => {
                                const code =
                                    encounter.triage?.current_category ?? null;

                                return (
                                    <article
                                        key={encounter.public_id}
                                        className="relative grid gap-3 p-4 pl-6 transition-colors hover:bg-muted/35 md:grid-cols-[minmax(15rem,1.35fr)_minmax(13rem,1fr)_10rem_8rem] md:items-center"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'absolute inset-y-0 left-0 w-1.5',
                                                code
                                                    ? triagePresentation[code]
                                                          .rail
                                                    : 'bg-border',
                                            )}
                                        />
                                        <div className="min-w-0">
                                            <p className="truncate font-semibold text-foreground">
                                                {encounter.patient.full_name ??
                                                    'Nama pasien belum tersedia'}
                                            </p>
                                            <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                                                {encounter.patient
                                                    .medical_record_number ??
                                                    '—'}{' '}
                                                · {encounter.public_id}
                                            </p>
                                            <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">
                                                {encounter.chief_complaint ||
                                                    'Keluhan utama belum dicatat.'}
                                            </p>
                                        </div>
                                        <div>
                                            <TriageChip
                                                code={code}
                                                cue={
                                                    encounter.triage
                                                        ?.current_category_text_cue
                                                }
                                            />
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {encounter.triage
                                                    ?.last_assessed_at
                                                    ? `Terakhir ${formatEmergencyDate(encounter.triage.last_assessed_at)}`
                                                    : 'Menunggu asesmen awal'}
                                            </p>
                                        </div>
                                        <div className="text-sm">
                                            <p className="text-xs text-muted-foreground">
                                                Terdaftar
                                            </p>
                                            <p className="mt-0.5 font-semibold">
                                                {formatEmergencyDate(
                                                    encounter.registered_at,
                                                )}
                                            </p>
                                            {typeof encounter.waiting_minutes ===
                                            'number' ? (
                                                <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                    Menunggu{' '}
                                                    {encounter.waiting_minutes}{' '}
                                                    menit
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="flex justify-end">
                                            {canOpen ? (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                    className="min-h-11 w-full md:w-auto"
                                                >
                                                    <Link
                                                        href={`${showPathPrefix}/${encounter.public_id}`}
                                                        aria-label={`Buka episode IGD ${encounter.patient.full_name ?? encounter.public_id}`}
                                                    >
                                                        Buka{' '}
                                                        <ArrowRight aria-hidden="true" />
                                                    </Link>
                                                </Button>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                    <Activity
                                                        aria-hidden="true"
                                                        className="size-4"
                                                    />{' '}
                                                    Lihat saja
                                                </span>
                                            )}
                                        </div>
                                    </article>
                                );
                            })}
                        </div>
                    )}
                </section>
            </main>
        </>
    );
}

export function EmergencyTriageWorklist(props: EmergencyWorklistProps) {
    return (
        <EmergencyWorklist
            {...props}
            mode="triage"
            indexPath="/pemeriksaan/triage"
            showPathPrefix="/pemeriksaan/triage"
        />
    );
}
