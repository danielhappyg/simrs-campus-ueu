import { Head } from '@inertiajs/react';
import { PharmacyMasterPanel } from '@/components/clinical/pharmacy/pharmacy-master-panel';
import type { PharmacyMasterProps } from '@/components/clinical/pharmacy/types';

export default function PharmacyMasterPage(props: PharmacyMasterProps) {
    return (
        <>
            <Head title="Master Obat & Depo" />
            <PharmacyMasterPanel {...props} />
        </>
    );
}
