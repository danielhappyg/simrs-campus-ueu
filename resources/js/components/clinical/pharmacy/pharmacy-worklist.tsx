import { router } from '@inertiajs/react';
import { ClipboardCheck, Filter, History } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { pharmacyFieldClass } from './operation';
import { PharmacyPrescriptionCard } from './pharmacy-prescription-card';
import { PharmacyEmptyState, PharmacySubnav } from './pharmacy-shared';
import type { PharmacyWorklistProps } from './types';

export function PharmacyWorklist({
    prescriptions,
    filters,
    filter_options: options,
    permissions,
    generated_at,
    read_error,
    mode = 'queue',
}: PharmacyWorklistProps & { mode?: 'queue' | 'history' }) {
    const action = mode === 'history' ? '/apotek/riwayat' : '/apotek/resep';
    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get(action, Object.fromEntries(data), {
            preserveState: true,
            replace: true,
        });
    };
    const operational =
        permissions.can_verify ||
        permissions.can_prepare ||
        permissions.can_handover ||
        permissions.can_return;

    return (
        <main className="min-h-screen bg-slate-50 pb-12">
            <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                <PharmacySubnav
                    current={mode === 'history' ? 'history' : 'queue'}
                />
                <header className="relative overflow-hidden rounded-2xl bg-[#123b5d] p-6 text-white shadow-sm">
                    <span
                        aria-hidden="true"
                        className="absolute inset-y-0 left-0 w-2 bg-[#24a69a]"
                    />
                    <div className="relative flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="flex items-center gap-2 text-xs font-semibold tracking-[0.14em] text-sky-100 uppercase">
                                {mode === 'history' ? (
                                    <History className="size-4" />
                                ) : (
                                    <ClipboardCheck className="size-4" />
                                )}
                                Apotek lintas layanan
                            </p>
                            <h1 className="mt-2 font-['IBM_Plex_Sans_Condensed'] text-3xl font-semibold">
                                {mode === 'history'
                                    ? 'Riwayat Resep & Penyerahan'
                                    : operational
                                      ? 'Antrean, Verifikasi & Penyerahan'
                                      : 'Resep & Obat'}
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-sky-50">
                                {mode === 'history'
                                    ? 'Telusuri keputusan, jumlah, lot, retur, dan sumber nilai biaya secara utuh.'
                                    : 'Satu antrean untuk resep rawat jalan, IGD, dan rawat inap dengan tanggung jawab per peran.'}
                            </p>
                        </div>
                        <p className="rounded-md bg-white/10 px-3 py-2 font-mono text-xs">
                            Diperbarui {generated_at}
                        </p>
                    </div>
                </header>

                {read_error ? (
                    <div
                        role="alert"
                        className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900"
                    >
                        <p className="font-semibold">
                            Antrean belum dapat dibaca.
                        </p>
                        <p>{read_error}</p>
                    </div>
                ) : null}

                <form
                    onSubmit={applyFilters}
                    className="rounded-xl border border-border bg-card p-4 shadow-sm"
                >
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Filter
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                        Saring resep
                    </h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="sm:col-span-2">
                            <Label htmlFor="pharmacy-query">
                                Pasien, RM, atau resep
                            </Label>
                            <input
                                id="pharmacy-query"
                                name="q"
                                defaultValue={filters.q}
                                className={pharmacyFieldClass}
                            />
                        </div>
                        <div>
                            <Label htmlFor="pharmacy-setting">Layanan</Label>
                            <select
                                id="pharmacy-setting"
                                name="care_setting"
                                defaultValue={filters.care_setting}
                                className={pharmacyFieldClass}
                            >
                                <option value="">Semua layanan</option>
                                {options.care_settings.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="pharmacy-state">Status</Label>
                            <select
                                id="pharmacy-state"
                                name="state"
                                defaultValue={filters.state}
                                className={pharmacyFieldClass}
                            >
                                <option value="">Semua status</option>
                                {options.states.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="pharmacy-depot-filter">Depo</Label>
                            <select
                                id="pharmacy-depot-filter"
                                name="depot"
                                defaultValue={filters.depot}
                                className={pharmacyFieldClass}
                            >
                                <option value="">Semua depo</option>
                                {options.depots.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="mt-3 flex justify-end">
                        <Button type="submit" className="min-h-11">
                            Terapkan filter
                        </Button>
                    </div>
                </form>

                <section aria-labelledby="pharmacy-results-heading">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <h2
                            id="pharmacy-results-heading"
                            className="font-['IBM_Plex_Sans_Condensed'] text-2xl font-semibold"
                        >
                            {prescriptions.length} resep ditemukan
                        </h2>
                    </div>
                    {prescriptions.length ? (
                        <div className="grid gap-4 lg:grid-cols-2">
                            {prescriptions.map((prescription) => (
                                <PharmacyPrescriptionCard
                                    key={prescription.public_id}
                                    prescription={prescription}
                                />
                            ))}
                        </div>
                    ) : (
                        <PharmacyEmptyState
                            title="Belum ada resep sesuai filter"
                            body="Ubah filter atau kembali lagi setelah resep dipesan."
                        />
                    )}
                </section>
            </div>
        </main>
    );
}
