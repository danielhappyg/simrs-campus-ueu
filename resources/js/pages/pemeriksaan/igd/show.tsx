import { StructuredEmergencyEncounterShow } from '@/components/clinical/emergency/structured-emergency-encounter-show';
import type { EmergencyShowProps } from '@/components/clinical/emergency/types';
import type { BreadcrumbItem } from '@/types';

export default function PemeriksaanIgdShow(props: EmergencyShowProps) {
    return (
        <StructuredEmergencyEncounterShow
            {...props}
            initialTab={props.initialTab ?? 'triage'}
        />
    );
}

PemeriksaanIgdShow.layout = (props: EmergencyShowProps) => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Pemeriksaan IGD', href: '/pemeriksaan/igd' },
        {
            title: props.encounter.patient.full_name ?? 'Detail',
            href: `/pemeriksaan/igd/${props.encounter.public_id}`,
        },
    ] satisfies BreadcrumbItem[],
});
