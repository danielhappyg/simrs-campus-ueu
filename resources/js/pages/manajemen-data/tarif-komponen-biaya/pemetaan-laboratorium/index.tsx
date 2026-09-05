import { Head } from '@inertiajs/react';
import type { LaboratoryTariffMappingProps } from '@/components/finance/laboratory-tariff-mapping-types';
import { LaboratoryTariffMappingWorkspace } from '@/components/finance/laboratory-tariff-mapping-workspace';

export type { LaboratoryTariffMappingProps } from '@/components/finance/laboratory-tariff-mapping-types';

export default function LaboratoryTariffMappingPage(
    props: LaboratoryTariffMappingProps,
) {
    return (
        <>
            <Head title="Mapping Laboratory" />
            <LaboratoryTariffMappingWorkspace {...props} />
        </>
    );
}
