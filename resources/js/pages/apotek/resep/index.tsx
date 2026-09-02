import { Head } from '@inertiajs/react';
import { PharmacyWorklist } from '@/components/clinical/pharmacy/pharmacy-worklist';
import type { PharmacyWorklistProps } from '@/components/clinical/pharmacy/types';

export default function PharmacyQueue(props: PharmacyWorklistProps) {
    return (
        <>
            <Head title="Antrean Apotek" />
            <PharmacyWorklist {...props} />
        </>
    );
}
