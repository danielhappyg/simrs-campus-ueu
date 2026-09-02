import { Head } from '@inertiajs/react';
import { LaboratoryWorklist } from '@/components/clinical/laboratory/laboratory-worklist';
import type { LaboratoryWorklistProps } from '@/components/clinical/laboratory/types';

export type { LaboratoryWorklistProps } from '@/components/clinical/laboratory/types';

export default function PemeriksaanLaboratorium(
    props: LaboratoryWorklistProps,
) {
    return (
        <>
            <Head title="Worklist Laboratorium" />
            <LaboratoryWorklist {...props} />
        </>
    );
}
