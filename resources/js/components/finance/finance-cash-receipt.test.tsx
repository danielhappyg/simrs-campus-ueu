import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { FinanceCashReceiptView } from './finance-cash-receipt';
import type { FinanceCashReceiptProps } from './types';

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
}));

const props: FinanceCashReceiptProps = {
    definition_version: 'EXACT_CASH_SETTLEMENT_AND_RECEIPT_V1',
    generated_at: '2 Sep 2026, 16.45',
    back_url: '/kasir/tagihan/01K00000000000000000000001',
    receipt: {
        public_id: '01K00000000000000000000060',
        receipt_number: 'KWT-20260902-01K00000000000000000000060',
        bill_number: 'TAG-01K00000000000000000000001',
        bill_version: 2,
        encounter_public_id: '01K00000000000000000000001',
        patient_name: 'Pasien Pendidikan Satu',
        medical_record_number: 'RM-000001',
        care_setting: 'INPATIENT',
        payment_method: 'CASH',
        state: 'SETTLED',
        amount: 105001,
        settled_at: '2026-09-02T09:00:00Z',
        cashier_name: 'Kasir Pendidikan',
        coverage_profile:
            'PHARMACY_RADIOLOGY_LABORATORY_AND_ACCOMMODATION_TARIFF_SOURCE_V1',
        coverage_label:
            'Obat, pemeriksaan penunjang, dan akomodasi pada versi tagihan ini.',
        coverage_exclusion:
            'Layanan lain di luar versi tagihan ini dan klaim tidak dinyatakan lunas.',
        source_domains: [
            'PHARMACY',
            'RADIOLOGY',
            'LABORATORY',
            'ACCOMMODATION',
        ],
        content_digest: 'a'.repeat(64),
        correction_state: 'ACTIVE',
        correction_public_id: null,
        correction_url: null,
    },
};

describe('finance cash receipt', () => {
    it('renders an attributable exact receipt and uses the browser print action', async () => {
        const user = userEvent.setup();
        const print = vi.spyOn(window, 'print').mockImplementation(() => {});
        const { container } = render(<FinanceCashReceiptView {...props} />);

        expect(
            screen.getByRole('heading', { name: 'Kuitansi Pelunasan' }),
        ).toBeVisible();
        expect(screen.getByText('Lunas · Tunai')).toBeVisible();
        expect(
            screen.getByText(
                (content) => content.replace(/\s/g, '') === 'Rp105.001',
            ),
        ).toBeVisible();
        expect(screen.getByText('Kasir Pendidikan')).toBeVisible();
        expect(screen.getByText('Akomodasi')).toBeVisible();
        expect(
            screen.getByText(/Kuitansi ini melunasi hanya versi tagihan/i),
        ).toHaveTextContent('Layanan lain di luar versi tagihan ini');
        expect(
            screen.getByRole('link', { name: 'Kembali ke Tagihan' }),
        ).toHaveAttribute('href', props.back_url);

        await user.click(
            screen.getByRole('button', { name: 'Cetak Kuitansi' }),
        );
        expect(print).toHaveBeenCalledOnce();

        const result = await axe.run(container, {
            runOnly: {
                type: 'tag',
                values: ['wcag2a', 'wcag2aa', 'wcag21a'],
            },
        });
        expect(result.violations).toHaveLength(0);

        for (const target of container.querySelectorAll<HTMLElement>(
            'button, a[href]',
        )) {
            expect(target.className).toMatch(/min-h-11/);
        }
    });

    it('marks the immutable original as corrected only after refund completion', async () => {
        const { container } = render(
            <FinanceCashReceiptView
                {...props}
                receipt={{
                    ...props.receipt,
                    correction_state: 'REFUND_COMPLETED',
                    correction_public_id: '01K00000000000000000000071',
                    correction_url:
                        '/kasir/koreksi-pelunasan/01K00000000000000000000071',
                }}
            />,
        );

        expect(
            screen.getByText('Dikoreksi · tunai dikembalikan'),
        ).toBeVisible();
        expect(screen.queryByText('Lunas · Tunai')).not.toBeInTheDocument();
        expect(
            screen.getByText(/tidak lagi berstatus pelunasan aktif/i),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Lihat Bukti Koreksi' }),
        ).toHaveAttribute(
            'href',
            '/kasir/koreksi-pelunasan/01K00000000000000000000071',
        );

        const result = await axe.run(container, {
            runOnly: {
                type: 'tag',
                values: ['wcag2a', 'wcag2aa', 'wcag21a'],
            },
        });
        expect(result.violations).toHaveLength(0);
    });
});
