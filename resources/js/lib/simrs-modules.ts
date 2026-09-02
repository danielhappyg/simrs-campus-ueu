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
    pendaftaran: '/pendaftaran/rawat-jalan',
    pemeriksaan: '/pemeriksaan/rawat-jalan',
    rm: '/rm/rawat-jalan',
    apotek: '/apotek/resep',
    kasir: '/kasir/tagihan',
    'manajemen-data': '/manajemen-data/bangsal',
};

const REQUIRED_CAPABILITIES: Record<string, readonly string[]> = {
    pendaftaran: ['patient.search', 'encounter.list'],
    pemeriksaan: ['encounter.list'],
    rm: ['encounter.list', 'rmik.review'],
    apotek: ['clinical.pharmacy.prescription.view'],
    kasir: ['finance.bill.view'],
    'manajemen-data': ['inpatient.occupancy.view'],
};

export function moduleHref(
    slug: string,
    capabilities: readonly string[] = [],
): string {
    if (
        slug === 'kasir' &&
        capabilities.includes('finance.cashier-collection.view')
    ) {
        return '/kasir/batch-penerimaan-kas';
    }

    if (
        slug === 'kasir' &&
        !capabilities.includes('finance.bill.view') &&
        capabilities.includes('finance.settlement-correction.view')
    ) {
        return '/kasir/koreksi-pelunasan';
    }

    if (
        slug === 'apotek' &&
        !capabilities.includes('clinical.pharmacy.prescription.view') &&
        capabilities.includes('master.pharmacy.inventory.manage')
    ) {
        return '/manajemen-data/apotek';
    }

    if (
        slug === 'manajemen-data' &&
        !capabilities.includes('inpatient.occupancy.view')
    ) {
        if (capabilities.includes('finance.tariff.view')) {
            return '/manajemen-data/tarif-komponen-biaya';
        }

        if (capabilities.includes('master.laboratory.examination.manage')) {
            return '/manajemen-data/laboratorium';
        }

        if (capabilities.includes('master.radiology.examination.manage')) {
            return '/manajemen-data/radiologi';
        }

        if (capabilities.includes('master.emergency.triage.manage')) {
            return '/manajemen-data/triage';
        }

        if (capabilities.includes('master.pharmacy.inventory.manage')) {
            return '/manajemen-data/apotek';
        }
    }

    return DEDICATED_HREFS[slug] ?? `/modul/${slug}`;
}

export function isLiveModule(
    slug: string,
    capabilities: readonly string[] = [],
): boolean {
    const requiredCapabilities = REQUIRED_CAPABILITIES[slug];

    if (slug === 'kasir') {
        return capabilities.some((capability) =>
            [
                'finance.bill.view',
                'finance.settlement-correction.view',
                'finance.cashier-collection.view',
            ].includes(capability),
        );
    }

    if (slug === 'manajemen-data') {
        return capabilities.some((capability) =>
            [
                'inpatient.occupancy.view',
                'master.laboratory.examination.manage',
                'master.radiology.examination.manage',
                'master.emergency.triage.manage',
                'master.pharmacy.inventory.manage',
                'finance.tariff.view',
            ].includes(capability),
        );
    }

    if (slug === 'apotek') {
        return capabilities.some((capability) =>
            [
                'clinical.pharmacy.prescription.view',
                'master.pharmacy.inventory.manage',
            ].includes(capability),
        );
    }

    return (
        Boolean(DEDICATED_HREFS[slug]) &&
        Boolean(requiredCapabilities) &&
        requiredCapabilities.every((capability) =>
            capabilities.includes(capability),
        )
    );
}
