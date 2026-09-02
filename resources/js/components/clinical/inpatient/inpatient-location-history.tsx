import { ArrowDown, BedSingle, History } from 'lucide-react';
import { PlacementSnapshot } from './placement-snapshot';
import { formatClinicalDate } from './presentation';
import type { InpatientLocationHistory } from './types';

type Props = {
    history: InpatientLocationHistory;
    placementReleased?: boolean;
};

export function InpatientLocationHistoryPanel({
    history,
    placementReleased = false,
}: Props) {
    return (
        <section
            aria-labelledby="location-history-heading"
            className="space-y-4"
        >
            <header className="rounded-xl border border-border bg-card p-4">
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <History aria-hidden="true" className="size-5" />
                    </div>
                    <div>
                        <h2
                            id="location-history-heading"
                            className="text-base font-semibold"
                        >
                            Riwayat penempatan
                        </h2>
                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                            Urutan penerimaan dan perpindahan tempat tidur yang
                            tersimpan pada episode ini.
                        </p>
                    </div>
                </div>
            </header>

            {history.history_baseline === 'LEGACY_CURRENT_PLACEMENT' &&
            history.current_placement ? (
                <div
                    role="note"
                    className="rounded-xl border border-warning/30 bg-warning/5 p-4"
                >
                    <p className="text-sm font-semibold text-foreground">
                        Titik awal dari penempatan yang sudah ada
                    </p>
                    <p className="mt-1 text-sm leading-6 text-muted-foreground">
                        Episode ini dibuat sebelum pencatatan riwayat lokasi
                        dimulai.{' '}
                        {placementReleased
                            ? 'Penempatan terakhir ditampilkan sebagai titik awal dan sudah dilepas saat episode menjadi Siap RM.'
                            : 'Penempatan saat ini ditampilkan sebagai titik awal, bukan kejadian yang dibuat ulang.'}
                    </p>
                    <div className="mt-3">
                        <PlacementSnapshot
                            snapshot={history.current_placement}
                            compact
                        />
                    </div>
                </div>
            ) : null}

            {history.events.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border bg-muted/20 p-6 text-center">
                    <BedSingle
                        aria-hidden="true"
                        className="mx-auto size-6 text-muted-foreground"
                    />
                    <p className="mt-2 text-sm font-semibold">
                        Belum ada perpindahan tercatat
                    </p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Riwayat akan bertambah saat penempatan episode berubah.
                    </p>
                </div>
            ) : (
                <ol className="relative space-y-4 border-l-2 border-primary/25 pl-5">
                    {history.events.map((event) => (
                        <li
                            key={event.public_id}
                            className="clinical-shadow relative rounded-xl border border-border bg-card p-4"
                        >
                            <span
                                aria-hidden="true"
                                className="absolute top-5 -left-[1.72rem] flex size-3 rounded-full border-2 border-background bg-primary"
                            />
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-primary uppercase">
                                        Urutan {event.sequence}
                                    </p>
                                    <h3 className="mt-1 text-sm font-semibold">
                                        {event.event_type ===
                                        'ADMISSION_LOCATION'
                                            ? 'Penerimaan rawat inap'
                                            : 'Transfer tempat tidur'}
                                    </h3>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {formatClinicalDate(event.occurred_at)}
                                </p>
                            </div>

                            {event.from_placement ? (
                                <div className="mt-4 space-y-3">
                                    <div>
                                        <p className="mb-2 text-xs font-semibold text-muted-foreground">
                                            Dari
                                        </p>
                                        <PlacementSnapshot
                                            snapshot={event.from_placement}
                                            compact
                                        />
                                    </div>
                                    <ArrowDown
                                        aria-hidden="true"
                                        className="mx-auto size-4 text-primary"
                                    />
                                </div>
                            ) : null}
                            <div className="mt-3">
                                <p className="mb-2 text-xs font-semibold text-muted-foreground">
                                    Ke
                                </p>
                                <PlacementSnapshot
                                    snapshot={event.to_placement}
                                    compact
                                />
                            </div>

                            <dl className="mt-4 grid gap-2 border-t border-border pt-3 text-xs sm:grid-cols-2">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Dicatat oleh
                                    </dt>
                                    <dd className="mt-0.5 font-semibold">
                                        {event.actor.name ?? 'Petugas'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Alasan
                                    </dt>
                                    <dd className="mt-0.5 leading-5 font-semibold break-words">
                                        {event.reason ??
                                            'Penerimaan awal episode'}
                                    </dd>
                                </div>
                            </dl>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
