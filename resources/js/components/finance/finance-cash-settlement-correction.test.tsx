import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    FinanceSettlementCorrectionCaseView,
    FinanceSettlementCorrectionRequestPanel,
    FinanceSettlementCorrectionWorklist,
    FinanceSettlementRefundReceiptView,
} from './finance-cash-settlement-correction';
import type {
    FinanceCashSettlementCorrectionCase,
    FinanceCashSettlementCorrectionDetailProps,
    FinanceCashSettlementCorrectionWorklistProps,
    FinanceCashSettlementRefundReceiptProps,
    FinanceCashSettlementSummary,
} from './types';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string;
        children?: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setDataState] = useState(initial);

        return {
            data,
            errors: inertia.errors,
            processing: false,
            setData: (field: keyof T, value: T[keyof T]) =>
                setDataState((current) => ({ ...current, [field]: value })),
            post: (
                url: string,
                options?: { onError?: () => void; onSuccess?: () => void },
            ) => {
                inertia.post(url, data, options);

                if (Object.keys(inertia.errors).length) {
                    options?.onError?.();
                } else {
                    options?.onSuccess?.();
                }
            },
        };
    },
}));

const correctionCase: FinanceCashSettlementCorrectionCase = {
    public_id: '01K00000000000000000000071',
    correction_number: 'KOR-20260902-0001',
    settlement_public_id: '01K00000000000000000000060',
    receipt_number: 'KWT-20260902-0001',
    amount: 105001,
    reason_code: 'DUPLICATE_COLLECTION',
    explanation:
        'Pembayaran yang sama telah diterima pada loket lain sebelum kuitansi ini dibuat.',
    requesting_cashier_name: 'Kasir Pendidikan',
    requester_is_actor: false,
    requested_at: '2026-09-02T09:15:00Z',
    state: 'CORRECTION_REQUESTED',
    fingerprint: 'a'.repeat(64),
    events: [],
    show_url: '/kasir/koreksi-pelunasan/01K00000000000000000000071',
    refund_receipt_url: null,
};

const worklistProps: FinanceCashSettlementCorrectionWorklistProps = {
    definition_version: 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1',
    generated_at: '2 Sep 2026, 16.45',
    cases: [correctionCase],
};

const detailProps: FinanceCashSettlementCorrectionDetailProps = {
    definition_version: 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1',
    generated_at: '2 Sep 2026, 16.45',
    case: correctionCase,
    permissions: { can_review: true, can_complete_refund: true },
    commands: {
        review_url:
            '/kasir/koreksi-pelunasan/01K00000000000000000000071/tinjau',
        complete_refund_url:
            '/kasir/koreksi-pelunasan/01K00000000000000000000071/pengembalian',
    },
    back_url: '/kasir/koreksi-pelunasan',
};

const settlement: FinanceCashSettlementSummary = {
    public_id: '01K00000000000000000000060',
    receipt_number: 'KWT-20260902-0001',
    amount: 105001,
    payment_method: 'CASH',
    state: 'SETTLED',
    settled_at: '2026-09-02T09:00:00Z',
    content_digest: 'b'.repeat(64),
    correction_state: 'ACTIVE',
    correction_public_id: null,
    correction_request_available: true,
    correction_url: null,
    receipt_url: '/kasir/pelunasan/01K00000000000000000000060/kuitansi',
};

const refundReceiptProps: FinanceCashSettlementRefundReceiptProps = {
    definition_version: 'APPEND_ONLY_CASH_SETTLEMENT_CORRECTION_V1',
    generated_at: '2 Sep 2026, 17.15',
    back_url: correctionCase.show_url,
    receipt: {
        correction_public_id: correctionCase.public_id,
        correction_number: correctionCase.correction_number,
        original_settlement_public_id: correctionCase.settlement_public_id,
        original_receipt_number: correctionCase.receipt_number,
        amount: correctionCase.amount,
        reason_code: correctionCase.reason_code,
        explanation: correctionCase.explanation,
        requesting_cashier_name: correctionCase.requesting_cashier_name,
        approving_supervisor_name: 'Supervisor Kasir Pendidikan',
        completion_actor_name: 'Supervisor Kasir Sore',
        requested_at: correctionCase.requested_at,
        approved_at: '2026-09-02T09:30:00Z',
        completed_at: '2026-09-02T09:45:00Z',
        state: 'REFUND_COMPLETED',
        content_digest: 'c'.repeat(64),
    },
};

async function expectAccessible(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a'] },
    });
    expect(result.violations).toHaveLength(0);
}

function expectFortyFourPixelTargets(container: HTMLElement) {
    for (const target of container.querySelectorAll<HTMLElement>(
        'button, a[href], input, select, textarea',
    )) {
        const touchClass =
            target instanceof HTMLInputElement &&
            (target.type === 'checkbox' || target.type === 'radio')
                ? target.closest('label')?.className
                : target.className;
        expect(touchClass, target.outerHTML).toMatch(/min-h-(?:11|28)/);
    }
}

describe('append-only cash settlement correction UI', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.errors = {};
    });

    it('renders an Indonesian worklist that distinguishes approved from completed', async () => {
        const approvedCase: FinanceCashSettlementCorrectionCase = {
            ...correctionCase,
            public_id: '01K00000000000000000000072',
            correction_number: 'KOR-20260902-0002',
            state: 'REFUND_APPROVED',
            show_url: '/kasir/koreksi-pelunasan/01K00000000000000000000072',
        };
        const { container } = render(
            <FinanceSettlementCorrectionWorklist
                {...worklistProps}
                cases={[correctionCase, approvedCase]}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Cash Settlement Correction' }),
        ).toBeVisible();
        expect(
            screen.getByRole('table', {
                name: 'Cash settlement correction cases',
            }),
        ).toBeVisible();
        expect(
            screen.getByText('Refund approved, cash not yet returned'),
        ).toBeVisible();
        expect(screen.getAllByRole('link', { name: 'Open Case' })).toHaveLength(
            2,
        );

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('submits a closed reason and settlement digest without a manual amount', async () => {
        const user = userEvent.setup();
        const requestUrl = '/kasir/pelunasan/settlement-1/koreksi';
        const { container } = render(
            <FinanceSettlementCorrectionRequestPanel
                settlement={settlement}
                requestUrl={requestUrl}
            />,
        );

        expect(container.querySelector('input[type="number"]')).toBeNull();
        await user.selectOptions(
            screen.getByLabelText('Correction reason'),
            'DUPLICATE_COLLECTION',
        );
        await user.type(
            screen.getByLabelText('Incident explanation'),
            'Penerimaan kas yang sama telah dicatat pada loket lain.',
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: /I confirm this request/i,
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Submit Correction Request' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            requestUrl,
            expect.objectContaining({
                reason_code: 'DUPLICATE_COLLECTION',
                expected_settlement_digest: 'b'.repeat(64),
                confirm_request: true,
            }),
            expect.any(Object),
        );
        expect(inertia.post.mock.calls[0]?.[1]).not.toHaveProperty('amount');
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('keeps correction submission disabled until the explanation reaches eight characters', async () => {
        const user = userEvent.setup();
        render(
            <FinanceSettlementCorrectionRequestPanel
                settlement={settlement}
                requestUrl="/kasir/pelunasan/settlement-1/koreksi"
            />,
        );

        await user.selectOptions(
            screen.getByLabelText('Correction reason'),
            'WRONG_BILL',
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: /I confirm this request/i,
            }),
        );
        const explanation = screen.getByLabelText('Incident explanation');
        const submit = screen.getByRole('button', {
            name: 'Submit Correction Request',
        });

        await user.type(explanation, '1234567');
        expect(submit).toBeDisabled();
        await user.type(explanation, '8');
        expect(submit).toBeEnabled();
    });

    it('records supervisor approval while clearly leaving cash return outstanding', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <FinanceSettlementCorrectionCaseView {...detailProps} />,
        );

        expect(
            screen.getByText(
                /Approval does not mean the cash has been returned/i,
            ),
        ).toBeVisible();
        await user.click(
            screen.getByRole('radio', {
                name: /Approve full refund/i,
            }),
        );
        await user.type(
            screen.getByLabelText('Decision basis'),
            'Duplikasi penerimaan terkonfirmasi dari bukti kuitansi.',
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: /I have matched the receipt/i,
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Record Decision' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            detailProps.commands.review_url,
            expect.objectContaining({
                decision: 'REFUND_APPROVED',
                expected_case_fingerprint: 'a'.repeat(64),
                confirm_review: true,
            }),
            expect.any(Object),
        );
        expect(inertia.post.mock.calls[0]?.[1]).not.toHaveProperty('amount');
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('requires confirmation that exact cash was handed back before completion', async () => {
        const user = userEvent.setup();
        const approved: FinanceCashSettlementCorrectionCase = {
            ...correctionCase,
            state: 'REFUND_APPROVED',
            events: [
                {
                    public_id: '01K00000000000000000000073',
                    sequence: 1,
                    event_type: 'REFUND_APPROVED',
                    actor_name: 'Supervisor Kasir Pendidikan',
                    explanation: 'Duplikasi penerimaan terkonfirmasi.',
                    amount: 105001,
                    occurred_at: '2026-09-02T09:30:00Z',
                    content_digest: 'd'.repeat(64),
                },
            ],
        };
        const { container } = render(
            <FinanceSettlementCorrectionCaseView
                {...detailProps}
                case={approved}
            />,
        );

        expect(screen.getByText('Refund not yet completed')).toBeVisible();
        const complete = screen.getByRole('button', {
            name: 'Record Completed Refund',
        });
        expect(complete).toBeDisabled();
        await user.click(
            screen.getByRole('checkbox', {
                name: /exact cash amount of/i,
            }),
        );
        await user.click(complete);

        expect(inertia.post).toHaveBeenCalledWith(
            detailProps.commands.complete_refund_url,
            expect.objectContaining({
                expected_case_fingerprint: 'a'.repeat(64),
                confirm_cash_returned: true,
            }),
            expect.any(Object),
        );
        expect(inertia.post.mock.calls[0]?.[1]).not.toHaveProperty('amount');
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('prints an attributable refund receipt linked to the immutable original', async () => {
        const user = userEvent.setup();
        const print = vi.spyOn(window, 'print').mockImplementation(() => {});
        const { container } = render(
            <FinanceSettlementRefundReceiptView {...refundReceiptProps} />,
        );

        expect(
            screen.getByRole('heading', { name: 'Bukti Pengembalian Tunai' }),
        ).toBeVisible();
        expect(screen.getByText(correctionCase.receipt_number)).toBeVisible();
        expect(screen.getByText('Supervisor Kasir Pendidikan')).toBeVisible();
        expect(screen.getByText('Supervisor Kasir Sore')).toBeVisible();
        await user.click(
            screen.getByRole('button', { name: 'Print Refund Receipt' }),
        );
        expect(print).toHaveBeenCalledOnce();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });
});
