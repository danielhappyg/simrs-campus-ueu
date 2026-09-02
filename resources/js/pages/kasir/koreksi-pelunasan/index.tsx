import { Head } from '@inertiajs/react';
import { FinanceSettlementCorrectionWorklist } from '@/components/finance/finance-cash-settlement-correction';
import type { FinanceCashSettlementCorrectionWorklistProps } from '@/components/finance/types';

export default function FinanceSettlementCorrectionWorklistPage(
    props: FinanceCashSettlementCorrectionWorklistProps,
) {
    return (
        <>
            <Head title="Koreksi Pelunasan Tunai" />
            <FinanceSettlementCorrectionWorklist {...props} />
        </>
    );
}
