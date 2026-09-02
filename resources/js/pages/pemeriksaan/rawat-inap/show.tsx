import StructuredInpatientEncounterShow from '@/components/clinical/inpatient/structured-inpatient-encounter-show';
import type { InpatientDocumentationShowProps } from '@/components/clinical/inpatient/types';
import type { BreadcrumbItem } from '@/types';

export default function PemeriksaanRawatInapShow(
    props: InpatientDocumentationShowProps,
) {
    return <StructuredInpatientEncounterShow {...props} />;
}

PemeriksaanRawatInapShow.layout = (props: InpatientDocumentationShowProps) => ({
    breadcrumbs: [
        { title: 'Beranda', href: '/' },
        { title: 'Pemeriksaan rawat inap', href: '/pemeriksaan/rawat-inap' },
        {
            title: props.encounter.patient.full_name ?? 'Detail',
            href: `/pemeriksaan/rawat-inap/${props.encounter.public_id}`,
        },
    ] satisfies BreadcrumbItem[],
});
