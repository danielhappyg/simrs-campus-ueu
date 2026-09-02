import { Head } from '@inertiajs/react';
import { FinanceCashReceiptView } from '@/components/finance/finance-cash-receipt';
import type { FinanceCashReceiptProps } from '@/components/finance/types';

export default function FinanceCashReceiptPage(props: FinanceCashReceiptProps) {
    return (
        <>
            <Head title={`Kuitansi ${props.receipt.receipt_number}`} />
            <FinanceCashReceiptView {...props} />
        </>
    );
}
