import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    ClipboardList,
    FileText,
    Pill,
    Receipt,
    Stethoscope,
    UserRoundPlus,
    Users,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type SessionOption = {
    code: string;
    publicId: string;
    courseCode: string | null;
    scenarioTitle: string | null;
};

type Props = {
    sessions: SessionOption[];
    selectedSession: {
        publicId: string;
        code: string;
        courseCode: string | null;
        scenarioTitle: string | null;
    } | null;
    canOpenPendaftaran: boolean;
    summary: {
        population: number;
        pasienLama: number;
        pasienBaruPool: number;
        kunjunganTerjadwal: number;
        antreanPoli: number;
        encounters: number;
    };
    clinicQueue: Array<{
        publicId: string;
        appointmentCode: string;
        status: { code: string; label: string };
        patientName: string;
        mrn: string | null;
        location: string | null;
        encounterUrl: string | null;
        canCheckIn: boolean;
    }>;
    recentEncounters: Array<{
        publicId: string;
        number: string;
        status: { code: string; label: string };
        patientName: string;
        mrn: string | null;
        location: string | null;
        url: string;
    }>;
    urls: {
        pendaftaran: string;
        pemeriksaan: string;
        rekamMedis: string;
        apotek: string;
        klaim: string;
        kerjaSaya: string;
    };
};

const moduleShortcuts = [
    { key: 'pendaftaran', title: 'Pendaftaran', icon: UserRoundPlus },
    { key: 'pemeriksaan', title: 'Pemeriksaan', icon: Stethoscope },
    { key: 'rekamMedis', title: 'Rekam Medis', icon: FileText },
    { key: 'apotek', title: 'Apotek', icon: Pill },
    { key: 'klaim', title: 'Klaim', icon: Receipt },
] as const;

export default function HospitalDesk({
    sessions,
    selectedSession,
    canOpenPendaftaran,
    summary,
    clinicQueue,
    recentEncounters,
    urls,
}: Props) {
    return (
        <>
            <Head title="Meja kerja rumah sakit" />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-6 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            SIMRS Campus UEU · Meja kerja
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Desk operasional simulasi
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Modul rumah sakit adalah pintu utama. Kerja saya
                            tetap tersedia untuk tugas yang ditugaskan, tetapi
                            bukan satu-satunya jalan masuk.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {sessions.length > 1 && (
                            <select
                                className="h-10 rounded-md border border-input bg-white px-3 text-sm"
                                value={selectedSession?.code ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        '/desk',
                                        { session: event.target.value },
                                        { preserveState: true },
                                    )
                                }
                                aria-label="Pilih sesi simulasi"
                            >
                                {sessions.map((session) => (
                                    <option key={session.code} value={session.code}>
                                        {session.code}
                                    </option>
                                ))}
                            </select>
                        )}
                        <Button asChild variant="outline">
                            <Link href={urls.kerjaSaya}>
                                <ClipboardList className="size-4" aria-hidden />
                                Kerja saya
                            </Link>
                        </Button>
                        {canOpenPendaftaran && (
                            <Button asChild>
                                <Link href={urls.pendaftaran}>
                                    <UserRoundPlus className="size-4" aria-hidden />
                                    Buka Pendaftaran
                                </Link>
                            </Button>
                        )}
                    </div>
                </header>

                {selectedSession && (
                    <p className="text-sm text-muted-foreground">
                        Sesi aktif:{' '}
                        <span className="font-mono font-medium text-foreground">
                            {selectedSession.code}
                        </span>
                        {selectedSession.scenarioTitle
                            ? ` · ${selectedSession.scenarioTitle}`
                            : null}
                    </p>
                )}

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        {
                            label: 'Populasi sintetis',
                            value: summary.population,
                            icon: Users,
                        },
                        {
                            label: 'Pasien lama',
                            value: summary.pasienLama,
                            icon: Users,
                        },
                        {
                            label: 'Kunjungan terjadwal',
                            value: summary.kunjunganTerjadwal,
                            icon: UserRoundPlus,
                        },
                        {
                            label: 'Antrean poli',
                            value: summary.antreanPoli,
                            icon: Stethoscope,
                        },
                    ].map((item) => (
                        <div
                            key={item.label}
                            className="rounded-2xl border border-border bg-white p-4"
                        >
                            <div className="flex items-center justify-between">
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {item.label}
                                </p>
                                <item.icon
                                    className="size-4 text-primary"
                                    aria-hidden
                                />
                            </div>
                            <p className="mt-3 text-3xl font-semibold tabular-nums">
                                {item.value}
                            </p>
                        </div>
                    ))}
                </section>

                <section className="grid gap-3 md:grid-cols-3 xl:grid-cols-5">
                    {moduleShortcuts.map((module) => {
                        const href =
                            module.key === 'pendaftaran'
                                ? urls.pendaftaran
                                : module.key === 'pemeriksaan'
                                  ? urls.pemeriksaan
                                  : module.key === 'rekamMedis'
                                    ? urls.rekamMedis
                                    : module.key === 'apotek'
                                      ? urls.apotek
                                      : urls.klaim;

                        return (
                            <Link
                                key={module.key}
                                href={href}
                                className="group rounded-2xl border border-border bg-white p-4 transition hover:border-primary/40 hover:bg-secondary/40"
                            >
                                <module.icon
                                    className="size-5 text-primary"
                                    aria-hidden
                                />
                                <p className="mt-3 font-semibold">{module.title}</p>
                                <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground group-hover:text-primary">
                                    Buka modul
                                    <ArrowRight className="size-3" aria-hidden />
                                </p>
                            </Link>
                        );
                    })}
                </section>

                <div className="grid gap-5 xl:grid-cols-2">
                    <section className="rounded-2xl border border-border bg-white">
                        <div className="border-b border-border px-5 py-4">
                            <h2 className="text-lg font-semibold">
                                Antrean &amp; kunjungan
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Setelah check-in, pasien tetap terlihat di sini
                                dan di modul sibling.
                            </p>
                        </div>
                        {clinicQueue.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                                Belum ada kunjungan aktif pada sesi ini.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border">
                                {clinicQueue.map((row) => (
                                    <li
                                        key={row.publicId}
                                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                    >
                                        <div>
                                            <p className="font-semibold">
                                                {row.patientName}
                                            </p>
                                            <p className="font-mono text-xs text-muted-foreground">
                                                {row.mrn ?? '—'} · {row.appointmentCode}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant="outline">
                                                {row.status.label}
                                            </Badge>
                                            {row.encounterUrl && (
                                                <Button asChild size="sm" variant="outline">
                                                    <Link href={row.encounterUrl}>
                                                        RM peek
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="rounded-2xl border border-border bg-white">
                        <div className="border-b border-border px-5 py-4">
                            <h2 className="text-lg font-semibold">
                                Encounter terbaru
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Dunia yang sama untuk Pemeriksaan, RM, Apotek,
                                dan Klaim.
                            </p>
                        </div>
                        {recentEncounters.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                                Belum ada encounter pada sesi ini.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border">
                                {recentEncounters.map((encounter) => (
                                    <li
                                        key={encounter.publicId}
                                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                    >
                                        <div>
                                            <p className="font-semibold">
                                                {encounter.patientName}
                                            </p>
                                            <p className="font-mono text-xs text-muted-foreground">
                                                {encounter.number} ·{' '}
                                                {encounter.status.label}
                                            </p>
                                        </div>
                                        <Button asChild size="sm" variant="outline">
                                            <Link href={encounter.url}>
                                                Buka
                                                <ArrowRight
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                            </Link>
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

HospitalDesk.layout = {
    breadcrumbs: [{ title: 'Meja kerja', href: '/desk' }],
};
