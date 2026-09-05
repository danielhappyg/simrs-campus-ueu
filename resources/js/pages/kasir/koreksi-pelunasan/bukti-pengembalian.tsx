import { Head } from '@inertiajs/react';
import { FinanceSettlementRefundReceiptView } from '@/components/finance/finance-cash-settlement-correction';
import type { FinanceCashSettlementRefundReceiptProps } from '@/components/finance/types';

export default function FinanceSettlementRefundReceiptPage(
    props: FinanceCashSettlementRefundReceiptProps,
) {
    return (
        <>
            <Head
                title={`Cash Return Receipt ${props.receipt.correction_number}`}
            />
            <FinanceSettlementRefundReceiptView {...props} />
        </>
    );
}
