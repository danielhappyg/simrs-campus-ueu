import { Head } from '@inertiajs/react';
import type { FinanceCashierCollectionDetailProps } from '@/components/finance/cashier-collection-types';
import { FinanceCashierCollectionDetail } from '@/components/finance/finance-cashier-collection-batch';

export default function FinanceCashierCollectionDetailPage(
    props: FinanceCashierCollectionDetailProps,
) {
    return (
        <>
            <Head title={`Batch ${props.batch.batch_number}`} />
            <FinanceCashierCollectionDetail {...props} />
        </>
    );
}
