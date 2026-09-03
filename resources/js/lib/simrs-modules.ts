export type SimrsModuleCategory = {
    slug: string;
    label: string;
    /** Phase 3 outpatient slice — dedicated live route */
    live?: boolean;
};

/**
 * Vendor-inspired top-level categories. Keep in sync with
 * App\Support\SimrsModuleCategories.
 */
export const SIMRS_MODULE_CATEGORIES: readonly SimrsModuleCategory[] = [
    { slug: 'pendaftaran', label: 'Pendaftaran', live: true },
    { slug: 'pemeriksaan', label: 'Pemeriksaan', live: true },
    { slug: 'rm', label: 'RM', live: true },
    { slug: 'klaim', label: 'Klaim' },
    { slug: 'laporan', label: 'Laporan' },
    { slug: 'bpjs', label: 'BPJS' },
    { slug: 'apotek', label: 'Apotek', live: true },
    { slug: 'gf', label: 'GF' },
    { slug: 'kasir', label: 'Kasir', live: true },
    { slug: 'manajemen-data', label: 'Manajemen Data' },
    { slug: 'iot', label: 'IoT' },
    { slug: 'farmasi-ibs', label: 'Farmasi IBS' },
    { slug: 'help', label: 'Help' },
] as const;

const DEDICATED_HREFS: Record<string, string> = {
    pendaftaran: '/modul/pendaftaran',
    pemeriksaan: '/modul/pemeriksaan',
    rm: '/modul/rm',
    klaim: '/modul/klaim',
    laporan: '/modul/laporan',
    bpjs: '/modul/bpjs',
    apotek: '/modul/apotek',
    gf: '/modul/gf',
    kasir: '/modul/kasir',
    'manajemen-data': '/modul/manajemen-data',
    iot: '/modul/iot',
    'farmasi-ibs': '/modul/farmasi-ibs',
    help: '/modul/help',
};

const MODULE_PATH_PREFIXES: Record<string, readonly string[]> = {
    pendaftaran: ['/modul/pendaftaran', '/pendaftaran/'],
    pemeriksaan: ['/modul/pemeriksaan', '/pemeriksaan/'],
    rm: ['/modul/rm', '/rm/'],
    klaim: ['/modul/klaim', '/klaim/'],
    laporan: ['/modul/laporan', '/laporan/'],
    bpjs: ['/modul/bpjs', '/bpjs/'],
    apotek: ['/modul/apotek', '/apotek/'],
    gf: ['/modul/gf', '/gf/'],
    kasir: ['/modul/kasir', '/kasir/'],
    'manajemen-data': ['/modul/manajemen-data', '/manajemen-data/'],
    iot: ['/modul/iot', '/iot/'],
    'farmasi-ibs': ['/modul/farmasi-ibs', '/farmasi-ibs/'],
    help: ['/modul/help', '/help/'],
};

export function moduleHref(slug: string): string {
    return DEDICATED_HREFS[slug] ?? `/modul/${slug}`;
}

export function isActiveModule(slug: string, pathname: string): boolean {
    const prefixes = MODULE_PATH_PREFIXES[slug] ?? [`/modul/${slug}`];

    return prefixes.some((prefix) => {
        const exact = prefix.replace(/\/$/, '');

        return pathname === exact || pathname.startsWith(prefix);
    });
}

export function isLiveModule(
    slug: string,
    capabilities: readonly string[] = [],
): boolean {
    void capabilities;

    return Boolean(DEDICATED_HREFS[slug]);
}
