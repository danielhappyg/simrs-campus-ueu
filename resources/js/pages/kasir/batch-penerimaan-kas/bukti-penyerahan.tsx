import { Head } from '@inertiajs/react';
import type { FinanceCashDepositHandoffReceiptProps } from '@/components/finance/cashier-collection-types';
import { FinanceCashDepositHandoffReceiptView } from '@/components/finance/finance-cashier-collection-batch';

export default function FinanceCashDepositHandoffReceiptPage(
    props: FinanceCashDepositHandoffReceiptProps,
) {
    return (
        <>
            <Head title={`Bukti Penyerahan ${props.receipt.handoff_number}`} />
            <FinanceCashDepositHandoffReceiptView {...props} />
        </>
    );
}
