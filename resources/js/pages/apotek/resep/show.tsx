import { Head } from '@inertiajs/react';
import { PharmacyPrescriptionWorkflow } from '@/components/clinical/pharmacy/pharmacy-prescription-workflow';
import { PharmacySubnav } from '@/components/clinical/pharmacy/pharmacy-shared';
import type { PharmacyPrescriptionPageProps } from '@/components/clinical/pharmacy/types';

export default function PharmacyPrescriptionShow({
    prescription,
    permissions,
    read_error,
}: PharmacyPrescriptionPageProps) {
    return (
        <>
            <Head title={`Prescription ${prescription.public_id}`} />
            <div className="min-h-screen bg-slate-50 pb-12">
                <div className="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
                    <PharmacySubnav current="queue" />
                    {read_error ? (
                        <div
                            role="alert"
                            className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900"
                        >
                            {read_error}
                        </div>
                    ) : null}
                    <PharmacyPrescriptionWorkflow
                        prescription={prescription}
                        permissions={permissions}
                    />
                </div>
            </div>
        </>
    );
}
