import { Head } from '@inertiajs/react';
import { RadiologyMasterPanel } from '@/components/clinical/radiology/radiology-master-panel';
import type { RadiologyMasterProps } from '@/components/clinical/radiology/types';

export type { RadiologyMasterProps } from '@/components/clinical/radiology/types';

export default function ManajemenDataRadiologi(props: RadiologyMasterProps) {
    return (
        <>
            <Head title="Master Pemeriksaan Radiologi" />
            <RadiologyMasterPanel {...props} />
        </>
    );
}
