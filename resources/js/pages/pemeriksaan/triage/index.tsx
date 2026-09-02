import { EmergencyTriageWorklist } from '@/components/clinical/emergency/emergency-worklist';
import type { EmergencyWorklistProps } from '@/components/clinical/emergency/types';
import type { BreadcrumbItem } from '@/types';

export default function PemeriksaanTriageIndex(props: EmergencyWorklistProps) {
    return <EmergencyTriageWorklist {...props} />;
}

PemeriksaanTriageIndex.layout = () => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Triage', href: '/pemeriksaan/triage' },
    ] satisfies BreadcrumbItem[],
});
