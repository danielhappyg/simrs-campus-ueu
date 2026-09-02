import { StructuredEmergencyEncounterShow } from '@/components/clinical/emergency/structured-emergency-encounter-show';
import type { EmergencyShowProps } from '@/components/clinical/emergency/types';
import type { BreadcrumbItem } from '@/types';

export default function PemeriksaanTriageShow(props: EmergencyShowProps) {
    return <StructuredEmergencyEncounterShow {...props} initialTab="triage" />;
}

PemeriksaanTriageShow.layout = (props: EmergencyShowProps) => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Triage', href: '/pemeriksaan/triage' },
        {
            title: props.encounter.patient.full_name ?? 'Detail',
            href: `/pemeriksaan/triage/${props.encounter.public_id}`,
        },
    ] satisfies BreadcrumbItem[],
});
