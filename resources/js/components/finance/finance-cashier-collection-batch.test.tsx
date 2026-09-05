import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    FinanceCashDepositHandoffReceiptProps,
    FinanceCashierCollectionAction,
    FinanceCashierCollectionBatch,
    FinanceCashierCollectionDetailProps,
    FinanceCashierCollectionWorklistProps,
} from './cashier-collection-types';
import {
    FinanceCashDepositHandoffReceiptView,
    FinanceCashierCollectionDetail,
    FinanceCashierCollectionWorklist,
} from './finance-cashier-collection-batch';

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

const denied = (reason: string): FinanceCashierCollectionAction => ({
    allowed: false,
    url: null,
    denial_reason: reason,
});

const batch: FinanceCashierCollectionBatch = {
    public_id: '01K00000000000000000000101',
    batch_number: 'BPK-20260902-0001',
    cashier_name: 'Kasir Pendidikan Pagi',
    opened_at: '2026-09-02T01:00:00Z',
    frozen_at: null,
    verified_at: null,
    handed_off_at: null,
    state: 'OPEN',
    membership_count: 2,
    gross_amount: 250000,
    completed_refund_amount: 50000,
    expected_net_amount: 200000,
    counted_amount: null,
    variance_amount: null,
    content_digest: 'a'.repeat(64),
    state_fingerprint: 'b'.repeat(64),
    integrity: { status: 'OK', message: null },
    members: [
        {
            public_id: '01K00000000000000000000102',
            settlement_public_id: '01K00000000000000000000103',
            receipt_number: 'KWT-20260902-0001',
            amount: 150000,
            collected_at: '2026-09-02T01:15:00Z',
            settlement_content_digest: 'c'.repeat(64),
            content_digest: 'd'.repeat(64),
        },
        {
            public_id: '01K00000000000000000000104',
            settlement_public_id: '01K00000000000000000000105',
            receipt_number: 'KWT-20260902-0002',
            amount: 100000,
            collected_at: '2026-09-02T01:45:00Z',
            settlement_content_digest: 'e'.repeat(64),
            content_digest: 'f'.repeat(64),
        },
    ],
    events: [],
    handoff: null,
    show_url: '/kasir/batch-penerimaan-kas/01K00000000000000000000101',
};

const worklistProps: FinanceCashierCollectionWorklistProps = {
    definition_version: 'APPEND_ONLY_CASHIER_COLLECTION_BATCH_V1',
    generated_at: '2 Sep 2026, 08.30',
    actor_role: 'cashier',
    batches: [batch],
    open_batch: denied('Kasir sudah memiliki satu batch terbuka.'),
};

const detailProps: FinanceCashierCollectionDetailProps = {
    definition_version: 'APPEND_ONLY_CASHIER_COLLECTION_BATCH_V1',
    generated_at: '2 Sep 2026, 08.30',
    actor_role: 'cashier',
    batch,
    actions: {
        request_close: {
            allowed: true,
            url: `${batch.show_url}/ajukan-tutup`,
            denial_reason: null,
        },
        recount: denied('Batch belum memerlukan hitung ulang.'),
        verify: denied('Hanya supervisor kasir yang dapat memverifikasi.'),
        create_handoff: denied('Batch belum diverifikasi.'),
    },
    back_url: '/kasir/batch-penerimaan-kas',
};

const receiptProps: FinanceCashDepositHandoffReceiptProps = {
    definition_version: 'APPEND_ONLY_CASHIER_COLLECTION_BATCH_V1',
    generated_at: '2 Sep 2026, 10.10',
    back_url: batch.show_url,
    receipt: {
        public_id: '01K00000000000000000000120',
        handoff_number: 'BST-20260902-0001',
        batch_public_id: batch.public_id,
        batch_number: batch.batch_number,
        cashier_name: batch.cashier_name,
        supervisor_name: 'Supervisor Kasir Pendidikan',
        membership_count: 2,
        gross_amount: 250000,
        completed_refund_amount: 50000,
        expected_net_amount: 200000,
        counted_amount: 200000,
        variance_amount: 0,
        opened_at: batch.opened_at,
        frozen_at: '2026-09-02T02:00:00Z',
        verified_at: '2026-09-02T02:30:00Z',
        handed_off_at: '2026-09-02T03:00:00Z',
        batch_content_digest: 'a'.repeat(64),
        verified_event_digest: 'g'.repeat(64),
        content_digest: 'h'.repeat(64),
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
        'button, a[href], input, textarea',
    )) {
        const touchClass =
            target instanceof HTMLInputElement && target.type === 'checkbox'
                ? target.closest('label')?.className
                : target.className;
        expect(touchClass, target.outerHTML).toMatch(/min-h-(?:11|28)/);
    }
}

describe('cashier collection batch UI', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.errors = {};
    });

    it('uses server action flags for opening and explains a denial', async () => {
        const { container } = render(
            <FinanceCashierCollectionWorklist
                {...worklistProps}
                batches={[]}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Cash Collection Batch' }),
        ).toBeVisible();
        expect(
            screen.getByText('Kasir sudah memiliki satu batch terbuka.'),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Open Batch' }),
        ).not.toBeInTheDocument();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('shows role-appropriate links to adjacent cashier workflows', async () => {
        const cashier = render(
            <FinanceCashierCollectionWorklist {...worklistProps} />,
        );

        expect(screen.getByRole('link', { name: 'Bill List' })).toHaveAttribute(
            'href',
            '/kasir/tagihan',
        );
        expect(
            screen.getByRole('link', { name: 'Settlement Correction' }),
        ).toHaveAttribute('href', '/kasir/koreksi-pelunasan');
        expectFortyFourPixelTargets(cashier.container);
        await expectAccessible(cashier.container);

        cashier.unmount();
        const supervisor = render(
            <FinanceCashierCollectionWorklist
                {...worklistProps}
                actor_role="cashier_supervisor"
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'Bill List' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Settlement Correction' }),
        ).toHaveAttribute('href', '/kasir/koreksi-pelunasan');
        expectFortyFourPixelTargets(supervisor.container);
        await expectAccessible(supervisor.container);
    });

    it('opens a batch only after deliberate confirmation', async () => {
        const user = userEvent.setup();
        const openUrl = '/kasir/batch-penerimaan-kas/buka';
        const { container } = render(
            <FinanceCashierCollectionWorklist
                {...worklistProps}
                batches={[]}
                open_batch={{
                    allowed: true,
                    url: openUrl,
                    denial_reason: null,
                }}
            />,
        );

        const open = screen.getByRole('button', { name: 'Open Batch' });
        const confirmation = screen.getByRole('checkbox', {
            name: /Open one new batch in my name/i,
        });
        expect(open).toBeDisabled();
        await user.tab();
        await user.tab();
        await user.tab();
        expect(confirmation).toHaveFocus();
        await user.keyboard(' ');
        await user.tab();
        expect(open).toHaveFocus();
        await user.keyboard('{Enter}');

        expect(inertia.post).toHaveBeenCalledWith(
            openUrl,
            expect.objectContaining({ confirm_open: true }),
            expect.any(Object),
        );
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('submits counted cash when requesting close without a manual expected amount', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <FinanceCashierCollectionDetail {...detailProps} />,
        );

        expect(screen.getByText('Expected net cash')).toBeVisible();
        expect(screen.getByText('Not yet calculated')).toBeVisible();
        await user.type(
            screen.getByLabelText('Counted physical cash'),
            '200000',
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: /Freeze 2 receipts/i,
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Submit Batch Closure' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            detailProps.actions.request_close.url,
            expect.objectContaining({
                counted_amount: 200000,
                expected_state_fingerprint: 'b'.repeat(64),
                confirm_close: true,
            }),
            expect.any(Object),
        );
        expect(inertia.post.mock.calls[0]?.[1]).not.toHaveProperty(
            'expected_net_amount',
        );
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('shows a non-zero variance and records an append-only recount', async () => {
        const user = userEvent.setup();
        const recountBatch: FinanceCashierCollectionBatch = {
            ...batch,
            state: 'RECOUNT_REQUIRED',
            frozen_at: '2026-09-02T02:00:00Z',
            counted_amount: 195000,
            variance_amount: -5000,
            events: [
                {
                    public_id: '01K00000000000000000000110',
                    sequence: 1,
                    event_type: 'CLOSE_REQUESTED',
                    actor_name: batch.cashier_name,
                    membership_count: 2,
                    gross_amount: 250000,
                    completed_refund_amount: 50000,
                    expected_net_amount: 200000,
                    counted_amount: 195000,
                    variance_amount: -5000,
                    explanation: null,
                    occurred_at: '2026-09-02T02:00:00Z',
                    membership_digest: 'i'.repeat(64),
                    content_digest: 'j'.repeat(64),
                },
            ],
        };
        const recountUrl = `${batch.show_url}/hitung-ulang`;
        const { container } = render(
            <FinanceCashierCollectionDetail
                {...detailProps}
                batch={recountBatch}
                actions={{
                    ...detailProps.actions,
                    recount: {
                        allowed: true,
                        url: recountUrl,
                        denial_reason: null,
                    },
                }}
            />,
        );

        expect(screen.getAllByText(/−IDR\s*5,000/).length).toBeGreaterThan(0);
        expect(screen.getByText('Shortage')).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: 'Verify Batch Closure',
            }),
        ).not.toBeInTheDocument();
        await user.type(
            screen.getByLabelText('Counted physical cash'),
            '200000',
        );
        await user.type(
            screen.getByLabelText('Recount notes'),
            'Uang pecahan terselip telah ditemukan dan dihitung kembali.',
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: /This result is a new observation/i,
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Record Recount' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            recountUrl,
            expect.objectContaining({
                counted_amount: 200000,
                expected_state_fingerprint: 'b'.repeat(64),
                confirm_recount: true,
            }),
            expect.any(Object),
        );
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('shows zero variance but still obeys the server supervisor flag', async () => {
        const zeroBatch: FinanceCashierCollectionBatch = {
            ...batch,
            state: 'AWAITING_SUPERVISOR',
            frozen_at: '2026-09-02T02:00:00Z',
            counted_amount: 200000,
            variance_amount: 0,
        };
        const { container } = render(
            <FinanceCashierCollectionDetail
                {...detailProps}
                actor_role="cashier_supervisor"
                batch={zeroBatch}
                actions={{
                    ...detailProps.actions,
                    verify: denied(
                        'Supervisor ini sama dengan kasir pemilik batch.',
                    ),
                }}
            />,
        );

        expect(screen.getByText('Matched')).toBeVisible();
        expect(
            screen.getByText('Supervisor ini sama dengan kasir pemilik batch.'),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: 'Verify Batch Closure',
            }),
        ).not.toBeInTheDocument();
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('verifies zero variance and hands off without editable financial amounts', async () => {
        const user = userEvent.setup();
        const verifyUrl = `${batch.show_url}/verifikasi`;
        const zeroBatch: FinanceCashierCollectionBatch = {
            ...batch,
            state: 'AWAITING_SUPERVISOR',
            frozen_at: '2026-09-02T02:00:00Z',
            counted_amount: 200000,
            variance_amount: 0,
        };
        const { rerender } = render(
            <FinanceCashierCollectionDetail
                {...detailProps}
                actor_role="cashier_supervisor"
                batch={zeroBatch}
                actions={{
                    ...detailProps.actions,
                    verify: {
                        allowed: true,
                        url: verifyUrl,
                        denial_reason: null,
                    },
                }}
            />,
        );

        await user.click(
            screen.getByRole('checkbox', {
                name: /I am a supervisor other than the cashier/i,
            }),
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Verify Batch Closure',
            }),
        );
        expect(inertia.post.mock.calls[0]?.[1]).not.toHaveProperty('amount');
        expect(inertia.post.mock.calls[0]?.[1]).not.toHaveProperty(
            'counted_amount',
        );
        expect(inertia.post.mock.calls[0]?.[1]).toEqual(
            expect.objectContaining({
                expected_state_fingerprint: 'b'.repeat(64),
            }),
        );

        inertia.post.mockReset();
        const handoffUrl = `${batch.show_url}/penyerahan`;
        rerender(
            <FinanceCashierCollectionDetail
                {...detailProps}
                batch={{
                    ...zeroBatch,
                    state: 'VERIFIED',
                    verified_at: '2026-09-02T02:30:00Z',
                }}
                actions={{
                    ...detailProps.actions,
                    create_handoff: {
                        allowed: true,
                        url: handoffUrl,
                        denial_reason: null,
                    },
                }}
            />,
        );
        expect(screen.getByText(/not treasury collection/i)).toBeVisible();
        await user.click(
            screen.getByRole('checkbox', {
                name: /Hand over exactly.*200,000/i,
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Hand Over Deposit' }),
        );
        expect(inertia.post.mock.calls[0]?.[1]).not.toHaveProperty('amount');
        expect(inertia.post.mock.calls[0]?.[1]).not.toHaveProperty(
            'expected_net_amount',
        );
        expect(inertia.post.mock.calls[0]?.[1]).toEqual(
            expect.objectContaining({
                expected_state_fingerprint: 'b'.repeat(64),
            }),
        );
    });

    it('fails closed on integrity errors even if an action URL is present', async () => {
        const { container } = render(
            <FinanceCashierCollectionDetail
                {...detailProps}
                batch={{
                    ...batch,
                    integrity: {
                        status: 'FAILED',
                        message:
                            'Digest anggota batch tidak cocok dengan bukti pembekuan.',
                    },
                }}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Batch evidence is inconsistent',
        );
        expect(
            screen.queryByRole('button', { name: 'Submit Batch Closure' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByText(
                'Digest anggota batch tidak cocok dengan bukti pembekuan.',
            ).length,
        ).toBeGreaterThan(0);
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('prints an attributable handoff proof that remains pending treasury', async () => {
        const user = userEvent.setup();
        const print = vi.spyOn(window, 'print').mockImplementation(() => {});
        const { container } = render(
            <FinanceCashDepositHandoffReceiptView {...receiptProps} />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Bukti Penyerahan Setoran',
            }),
        ).toBeVisible();
        expect(
            screen.getByRole('heading', {
                name: 'Menunggu penerimaan treasury',
            }),
        ).toBeVisible();
        expect(screen.getByText('Supervisor Kasir Pendidikan')).toBeVisible();
        expect(
            screen.getByText(/Belum merupakan penerimaan treasury/i),
        ).toBeVisible();
        await user.click(
            screen.getByRole('button', { name: 'Print Handover Receipt' }),
        );
        expect(print).toHaveBeenCalledOnce();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });
});
