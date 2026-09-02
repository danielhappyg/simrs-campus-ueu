import { Head } from '@inertiajs/react';
import { RadiologyWorklist } from '@/components/clinical/radiology/radiology-worklist';
import type { RadiologyWorklistProps } from '@/components/clinical/radiology/types';

export type { RadiologyWorklistProps } from '@/components/clinical/radiology/types';

export default function PemeriksaanRadiologi(props: RadiologyWorklistProps) {
    return (
        <>
            <Head title="Worklist Radiologi" />
            <RadiologyWorklist {...props} />
        </>
    );
}
