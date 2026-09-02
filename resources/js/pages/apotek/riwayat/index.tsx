import { Head } from '@inertiajs/react';
import { PharmacyWorklist } from '@/components/clinical/pharmacy/pharmacy-worklist';
import type { PharmacyWorklistProps } from '@/components/clinical/pharmacy/types';

export default function PharmacyHistory(props: PharmacyWorklistProps) {
    return (
        <>
            <Head title="Riwayat Apotek" />
            <PharmacyWorklist {...props} mode="history" />
        </>
    );
}
