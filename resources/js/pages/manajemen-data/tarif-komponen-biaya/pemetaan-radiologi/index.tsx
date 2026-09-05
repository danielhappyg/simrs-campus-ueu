import { Head } from '@inertiajs/react';
import type { RadiologyTariffMappingProps } from '@/components/finance/radiology-tariff-mapping-types';
import { RadiologyTariffMappingWorkspace } from '@/components/finance/radiology-tariff-mapping-workspace';

export type { RadiologyTariffMappingProps } from '@/components/finance/radiology-tariff-mapping-types';

export default function RadiologyTariffMappingPage(
    props: RadiologyTariffMappingProps,
) {
    return (
        <>
            <Head title="Mapping Radiology" />
            <RadiologyTariffMappingWorkspace {...props} />
        </>
    );
}
