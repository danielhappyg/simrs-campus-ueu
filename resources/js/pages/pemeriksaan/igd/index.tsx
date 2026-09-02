import { EmergencyWorklist } from '@/components/clinical/emergency/emergency-worklist';
import type { EmergencyWorklistProps } from '@/components/clinical/emergency/types';
import type { BreadcrumbItem } from '@/types';

export default function PemeriksaanIgdIndex(props: EmergencyWorklistProps) {
    return <EmergencyWorklist {...props} />;
}

PemeriksaanIgdIndex.layout = () => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Pemeriksaan IGD', href: '/pemeriksaan/igd' },
    ] satisfies BreadcrumbItem[],
});
