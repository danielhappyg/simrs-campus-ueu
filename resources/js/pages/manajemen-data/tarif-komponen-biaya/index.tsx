import { Head } from '@inertiajs/react';
import type { TariffMasterProps } from '@/components/finance/tariff-master-types';
import { TariffMasterWorkspace } from '@/components/finance/tariff-master-workspace';

export default function TarifKomponenBiaya(props: TariffMasterProps) {
    return (
        <>
            <Head title="Tariffs & Charge Components" />
            <TariffMasterWorkspace {...props} />
        </>
    );
}
