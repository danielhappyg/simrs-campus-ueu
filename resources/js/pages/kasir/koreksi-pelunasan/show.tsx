import { Head } from '@inertiajs/react';
import { FinanceSettlementCorrectionCaseView } from '@/components/finance/finance-cash-settlement-correction';
import type { FinanceCashSettlementCorrectionDetailProps } from '@/components/finance/types';

export default function FinanceSettlementCorrectionCasePage(
    props: FinanceCashSettlementCorrectionDetailProps,
) {
    return (
        <>
            <Head title={`Koreksi ${props.case.correction_number}`} />
            <FinanceSettlementCorrectionCaseView {...props} />
        </>
    );
}
