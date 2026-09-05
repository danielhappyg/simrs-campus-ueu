import { Head } from '@inertiajs/react';
import { LaboratoryMasterPanel } from '@/components/clinical/laboratory/laboratory-master-panel';
import type { LaboratoryMasterProps } from '@/components/clinical/laboratory/types';

export type { LaboratoryMasterProps } from '@/components/clinical/laboratory/types';

export default function ManajemenDataLaboratorium(
    props: LaboratoryMasterProps,
) {
    return (
        <>
            <Head title="Laboratory Examination Master" />
            <LaboratoryMasterPanel {...props} />
        </>
    );
}
