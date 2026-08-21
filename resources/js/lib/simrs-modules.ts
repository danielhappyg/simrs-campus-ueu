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
    { slug: 'apotek', label: 'Apotek' },
    { slug: 'gf', label: 'GF' },
    { slug: 'kasir', label: 'Kasir' },
    { slug: 'manajemen-data', label: 'Manajemen Data' },
    { slug: 'iot', label: 'IoT' },
    { slug: 'farmasi-ibs', label: 'Farmasi IBS' },
    { slug: 'help', label: 'Help' },
] as const;

const DEDICATED_HREFS: Record<string, string> = {
    pendaftaran: '/pendaftaran/rawat-jalan',
    pemeriksaan: '/pemeriksaan/rawat-jalan',
    rm: '/rm/rawat-jalan',
};

export function moduleHref(slug: string): string {
    return DEDICATED_HREFS[slug] ?? `/modul/${slug}`;
}

export function isLiveModule(slug: string): boolean {
    return Boolean(DEDICATED_HREFS[slug]);
}
