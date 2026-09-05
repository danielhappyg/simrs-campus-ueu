import { Head } from '@inertiajs/react';
import { FinanceBillDetail } from '@/components/finance/finance-bill-detail';
import type { FinanceBillDetailProps } from '@/components/finance/types';

export default function FinanceBillShow(props: FinanceBillDetailProps) {
    return (
        <>
            <Head title={`Bill ${props.bill.bill_number}`} />
            <FinanceBillDetail {...props} />
        </>
    );
}
