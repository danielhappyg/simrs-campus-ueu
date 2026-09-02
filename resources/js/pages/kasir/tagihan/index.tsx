import { Head } from '@inertiajs/react';
import { FinanceWorklist } from '@/components/finance/finance-worklist';
import type { FinanceWorklistProps } from '@/components/finance/types';

export default function FinanceBillWorklist(props: FinanceWorklistProps) {
    return (
        <>
            <Head title="Daftar Tagihan" />
            <FinanceWorklist {...props} />
        </>
    );
}
