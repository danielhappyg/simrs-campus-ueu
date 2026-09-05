import { EmergencyWorklist } from '@/components/clinical/emergency/emergency-worklist';
import type { EmergencyWorklistProps } from '@/components/clinical/emergency/types';
import type { BreadcrumbItem } from '@/types';

export default function PemeriksaanIgdIndex(props: EmergencyWorklistProps) {
    return <EmergencyWorklist {...props} />;
}

PemeriksaanIgdIndex.layout = () => ({
    breadcrumbs: [
        { title: 'Home', href: '/' },
        { title: 'Emergency Department', href: '/pemeriksaan/igd' },
    ] satisfies BreadcrumbItem[],
});
