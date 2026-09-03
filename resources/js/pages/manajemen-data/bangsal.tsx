import { Head, Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { WardBedCensus } from '@/components/inpatient/ward-bed-census';
import { WardBedMasterDialog } from '@/components/inpatient/ward-bed-master-dialog';
import { WardBedMasterPanel } from '@/components/inpatient/ward-bed-master-panel';
import type {
    WardBedCensusProps,
    WardBedFilters,
    WardBedMasterAction,
} from '@/components/inpatient/ward-bed-types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { BreadcrumbItem } from '@/types';

const fieldClass =
    'border-input min-h-11 w-full rounded-md border bg-white px-3 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30 disabled:cursor-not-allowed disabled:opacity-60';

function generatedAtLabel(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Waktu perhitungan tidak tersedia';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export default function ManajemenDataBangsal({
    generated_at,
    filters,
    totals,
    wards,
    permissions,
    commands,
    filter_options,
    reason_options,
    read_error = null,
}: WardBedCensusProps) {
    const [filterValues, setFilterValues] = useState<WardBedFilters>(filters);
    const [loading, setLoading] = useState(false);
    const [activeAction, setActiveAction] =
        useState<WardBedMasterAction | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const actionTriggerRef = useRef<HTMLButtonElement | null>(null);
    const pageProps = usePage().props;
    const { flash } = pageProps;
    const canManageTriage = (
        (pageProps.auth as { capabilities?: string[] } | undefined)
            ?.capabilities ?? []
    ).includes('master.emergency.triage.manage');
    const successMessage =
        typeof flash?.success === 'string' && flash.success !== ''
            ? flash.success
            : announcement;
    const hasFilters = Object.values(filters).some((value) => value !== '');

    const visit = (nextFilters: WardBedFilters) => {
        router.get(
            '/manajemen-data/bangsal',
            Object.fromEntries(
                Object.entries(nextFilters).filter(([, value]) => value !== ''),
            ),
            {
                preserveState: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    };

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        visit(filterValues);
    };

    const clearFilters = () => {
        const emptyFilters: WardBedFilters = {
            q: '',
            ward_code: '',
            service_class: '',
            occupancy_state: '',
            master_state: '',
        };
        setFilterValues(emptyFilters);
        visit(emptyFilters);
    };

    const openAction = (
        action: WardBedMasterAction,
        trigger: HTMLButtonElement,
    ) => {
        if (!permissions.can_manage_master || !action.url) {
            return;
        }

        actionTriggerRef.current = trigger;
        setAnnouncement('');
        setActiveAction(action);
    };

    const closeAction = () => {
        setActiveAction(null);
        queueMicrotask(() => actionTriggerRef.current?.focus());
    };

    return (
        <>
            <Head title="Bangsal & Tempat Tidur" />

            <div
                className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-4 px-3 py-4 md:px-5 md:py-5"
                aria-busy={loading || undefined}
            >
                <nav
                    aria-label="Manajemen data"
                    className="flex flex-wrap gap-1 rounded-lg border border-[#e2e8f0] bg-white p-1"
                >
                    <Link
                        href="/manajemen-data/bangsal"
                        aria-current="page"
                        className="inline-flex min-h-11 items-center rounded-md bg-[#123b63] px-3 text-sm font-medium text-white"
                    >
                        Bangsal & Tempat Tidur
                    </Link>
                    <Link
                        href="/manajemen-data/laboratorium"
                        className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-950"
                    >
                        Pemeriksaan Laboratorium
                    </Link>
                    <Link
                        href="/manajemen-data/radiologi"
                        className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-[#475569] hover:bg-[#f1f5f9] hover:text-[#0f172a]"
                    >
                        Pemeriksaan Radiologi
                    </Link>
                    {canManageTriage ? (
                        <Link
                            href="/manajemen-data/triage"
                            className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-[#475569] hover:bg-[#f1f5f9] hover:text-[#0f172a]"
                        >
                            Kosakata Triase IGD
                        </Link>
                    ) : null}
                </nav>

                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-[#1b75bc] uppercase">
                            Rawat inap
                        </p>
                        <h1 className="mt-1 text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                            Bangsal & Tempat Tidur
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-[#64748b]">
                            Ketersediaan dihitung dari kunjungan rawat inap
                            aktif. Data tidak dapat diubah dari tabel sensus.
                        </p>
                    </div>
                    <p className="text-xs text-[#64748b]">
                        Dihitung {generatedAtLabel(generated_at)}
                    </p>
                </header>

                {loading ? (
                    <p
                        role="status"
                        aria-live="polite"
                        className="rounded-md border border-[#bfdbfe] bg-[#eff6ff] px-3 py-2 text-sm text-[#1e40af]"
                    >
                        Memuat data ketersediaan…
                    </p>
                ) : null}

                {typeof flash?.error === 'string' && flash.error !== '' ? (
                    <p
                        role="alert"
                        aria-live="assertive"
                        className="rounded-md border border-[#fecaca] bg-[#fef2f2] px-3 py-2 text-sm text-[#991b1b]"
                    >
                        {flash.error}
                    </p>
                ) : null}

                {successMessage ? (
                    <p
                        role="status"
                        aria-live="polite"
                        className="rounded-md border border-[#bbf7d0] bg-[#f0fdf4] px-3 py-2 text-sm text-[#166534]"
                    >
                        {successMessage}
                    </p>
                ) : null}

                <form
                    onSubmit={applyFilters}
                    className="rounded-xl border border-[#d7e6f3] bg-[#f5f9fc] p-3"
                    aria-label="Filter sensus tempat tidur"
                >
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                        <div className="grid gap-1 lg:col-span-2">
                            <label
                                htmlFor="bed-census-q"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Kata kunci
                            </label>
                            <Input
                                id="bed-census-q"
                                className={fieldClass}
                                value={filterValues.q}
                                onChange={(event) =>
                                    setFilterValues((current) => ({
                                        ...current,
                                        q: event.target.value,
                                    }))
                                }
                                placeholder="Kode, nama, atau ruang"
                                disabled={loading}
                            />
                        </div>
                        <div className="grid gap-1">
                            <label
                                htmlFor="bed-census-ward"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Bangsal
                            </label>
                            <select
                                id="bed-census-ward"
                                className={fieldClass}
                                value={filterValues.ward_code}
                                onChange={(event) =>
                                    setFilterValues((current) => ({
                                        ...current,
                                        ward_code: event.target.value,
                                    }))
                                }
                                disabled={loading}
                            >
                                <option value="">Semua bangsal</option>
                                {filter_options.wards.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-1">
                            <label
                                htmlFor="bed-census-class"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Kelas
                            </label>
                            <select
                                id="bed-census-class"
                                className={fieldClass}
                                value={filterValues.service_class}
                                onChange={(event) =>
                                    setFilterValues((current) => ({
                                        ...current,
                                        service_class: event.target.value,
                                    }))
                                }
                                disabled={loading}
                            >
                                <option value="">Semua kelas</option>
                                {filter_options.service_classes.map(
                                    (option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                        <div className="grid gap-1">
                            <label
                                htmlFor="bed-census-occupancy"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Status TT
                            </label>
                            <select
                                id="bed-census-occupancy"
                                className={fieldClass}
                                value={filterValues.occupancy_state}
                                onChange={(event) =>
                                    setFilterValues((current) => ({
                                        ...current,
                                        occupancy_state: event.target.value,
                                    }))
                                }
                                disabled={loading}
                            >
                                <option value="">Semua</option>
                                <option value="AVAILABLE">Tersedia</option>
                                <option value="OCCUPIED">Terisi</option>
                                <option value="RETIRED">Dinonaktifkan</option>
                            </select>
                        </div>
                        <div className="grid gap-1">
                            <label
                                htmlFor="bed-census-master-state"
                                className="text-[0.65rem] font-medium tracking-wide text-[#64748b] uppercase"
                            >
                                Status data
                            </label>
                            <select
                                id="bed-census-master-state"
                                className={fieldClass}
                                value={filterValues.master_state}
                                onChange={(event) =>
                                    setFilterValues((current) => ({
                                        ...current,
                                        master_state: event.target.value,
                                    }))
                                }
                                disabled={loading}
                            >
                                <option value="">Semua</option>
                                <option value="ACTIVE">Aktif</option>
                                <option value="RETIRED">Dinonaktifkan</option>
                            </select>
                        </div>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={loading}
                        >
                            Terapkan filter
                        </Button>
                        {hasFilters ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                disabled={loading}
                                onClick={clearFilters}
                            >
                                Hapus filter
                            </Button>
                        ) : null}
                    </div>
                </form>

                {read_error ? (
                    <div
                        role="alert"
                        className="rounded-xl border border-[#fecaca] bg-[#fef2f2] px-4 py-4 text-sm text-[#991b1b]"
                    >
                        <p className="font-semibold">
                            Data ketersediaan belum dapat dimuat.
                        </p>
                        <p className="mt-1">{read_error}</p>
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-3 min-h-11 border-[#f3b8b8] bg-white text-[#991b1b] hover:bg-[#fff7f7]"
                            onClick={() => router.reload()}
                        >
                            Muat ulang halaman
                        </Button>
                    </div>
                ) : permissions.can_view_census ? (
                    <>
                        {totals.active_beds > 0 &&
                        totals.available_beds === 0 ? (
                            <p
                                role="status"
                                className="rounded-lg border border-[#fed7aa] bg-[#fff7ed] px-3 py-2 text-sm text-[#9a3412]"
                            >
                                Tidak ada tempat tidur tersedia untuk pilihan
                                ini.
                            </p>
                        ) : null}

                        <WardBedMasterPanel
                            wards={wards}
                            canManage={permissions.can_manage_master}
                            createWardUrl={
                                permissions.can_manage_master
                                    ? commands.create_ward_url
                                    : null
                            }
                            onAction={openAction}
                        />

                        <WardBedCensus
                            wards={wards}
                            totals={totals}
                            canManage={permissions.can_manage_master}
                            hasFilters={hasFilters}
                            onClearFilters={clearFilters}
                            onAction={openAction}
                        />
                    </>
                ) : (
                    <div
                        role="alert"
                        className="rounded-xl border border-[#fecaca] bg-[#fef2f2] px-4 py-4 text-sm text-[#991b1b]"
                    >
                        Anda tidak memiliki akses untuk melihat sensus tempat
                        tidur.
                    </div>
                )}
            </div>

            <WardBedMasterDialog
                action={permissions.can_manage_master ? activeAction : null}
                reasonOptions={reason_options}
                returnFocusRef={actionTriggerRef}
                onDismiss={closeAction}
                onSuccess={setAnnouncement}
            />
        </>
    );
}

ManajemenDataBangsal.layout = () => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Manajemen Data', href: '/manajemen-data/bangsal' },
        { title: 'Bangsal & Tempat Tidur', href: '/manajemen-data/bangsal' },
    ] satisfies BreadcrumbItem[],
});
