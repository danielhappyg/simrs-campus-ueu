import LegacyFreeTextEncounterShow from '@/components/clinical/legacy-free-text-encounter-show';
import StructuredOutpatientEncounterShow from '@/components/clinical/outpatient/structured-outpatient-encounter-show';
import type { OutpatientShowProps } from '@/components/clinical/outpatient/types';
import type { BreadcrumbItem } from '@/types';

type LegacyProps = Parameters<typeof LegacyFreeTextEncounterShow>[0];
type Props = OutpatientShowProps | LegacyProps;

export default function PemeriksaanRawatJalanShow(props: Props) {
    if (props.variant === 'rawat-jalan' && 'documentation' in props) {
        return <StructuredOutpatientEncounterShow {...props} />;
    }

    return <LegacyFreeTextEncounterShow {...(props as LegacyProps)} />;
}

PemeriksaanRawatJalanShow.layout = (props: Props) => {
    const variant = props.variant ?? 'rawat-jalan';
    const indexPath =
        'indexPath' in props && props.indexPath
            ? props.indexPath
            : variant === 'igd'
              ? '/pemeriksaan/igd'
              : variant === 'rawat-inap'
                ? '/pemeriksaan/rawat-inap'
                : '/pemeriksaan/rawat-jalan';

    return {
        breadcrumbs: [
            { title: 'Beranda', href: '/' },
            { title: 'Pemeriksaan', href: indexPath },
            {
                title: props.encounter.patient.full_name ?? 'Detail',
                href: `${indexPath}/${props.encounter.public_id}`,
            },
        ] satisfies BreadcrumbItem[],
    };
};
