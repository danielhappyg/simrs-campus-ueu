import { Head } from '@inertiajs/react';
import type { AccommodationTariffMappingProps } from '@/components/finance/accommodation-tariff-mapping-types';
import { AccommodationTariffMappingWorkspace } from '@/components/finance/accommodation-tariff-mapping-workspace';

export type { AccommodationTariffMappingProps } from '@/components/finance/accommodation-tariff-mapping-types';

export default function AccommodationTariffMappingPage(
    props: AccommodationTariffMappingProps,
) {
    return (
        <>
            <Head title="Pemetaan Akomodasi Rawat Inap" />
            <AccommodationTariffMappingWorkspace {...props} />
        </>
    );
}
