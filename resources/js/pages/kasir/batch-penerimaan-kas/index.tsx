import { Head } from '@inertiajs/react';
import type { FinanceCashierCollectionWorklistProps } from '@/components/finance/cashier-collection-types';
import { FinanceCashierCollectionWorklist } from '@/components/finance/finance-cashier-collection-batch';

export default function FinanceCashierCollectionWorklistPage(
    props: FinanceCashierCollectionWorklistProps,
) {
    return (
        <>
            <Head title="Batch Penerimaan Kas" />
            <FinanceCashierCollectionWorklist {...props} />
        </>
    );
}
