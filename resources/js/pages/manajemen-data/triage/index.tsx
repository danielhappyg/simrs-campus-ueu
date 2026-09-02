import { Head } from '@inertiajs/react';
import { EmergencyTriageVocabularyMaster } from '@/components/clinical/emergency/emergency-triage-vocabulary-master';
import type { EmergencyTriageVocabularyMasterProps } from '@/components/clinical/emergency/types';

export type { EmergencyTriageVocabularyMasterProps } from '@/components/clinical/emergency/types';

export default function ManajemenDataTriage(
    props: EmergencyTriageVocabularyMasterProps,
) {
    return (
        <>
            <Head title="Master Kosakata Triase IGD" />
            <EmergencyTriageVocabularyMaster {...props} />
        </>
    );
}
