import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AppHeader } from '@/components/app-header';
import { SIMRS_MODULE_CATEGORIES } from '@/lib/simrs-modules';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
    prefetch?: boolean;
};

const appHeaderMock = vi.hoisted(() => ({
    capabilities: [] as string[],
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: MockLinkProps) => {
        const { prefetch, ...anchorProps } = props;
        void prefetch;

        return (
            <a href={href} {...anchorProps}>
                {children}
            </a>
        );
    },
    usePage: () => ({
        url: '/',
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Admin Demo',
                    email: 'admin@example.com',
                },
                capabilities: appHeaderMock.capabilities,
            },
            sidebarOpen: false,
            name: 'SIMRS Campus UEU',
            environment: {
                mode: 'SIMULATION',
                syntheticOnly: true,
                banner: 'SIMULASI — DATA SINTETIS',
                restriction: '',
            },
        },
    }),
}));

describe('application header navigation', () => {
    beforeEach(() => {
        appHeaderMock.capabilities = [];
    });

    it('exposes live module links and muted Soon labels without fake navigation', () => {
        appHeaderMock.capabilities = [
            'patient.search',
            'encounter.list',
            'inpatient.occupancy.view',
        ];

        render(<AppHeader />);

        expect(
            screen.getByRole('note', {
                name: 'Status operasional sistem',
            }),
        ).toHaveTextContent('Mode Kampus');

        const navs = screen.getAllByRole('navigation', {
            name: 'Navigasi modul',
        });
        expect(navs.length).toBeGreaterThanOrEqual(1);

        const live = ['Pendaftaran', 'Pemeriksaan', 'Manajemen Data'];
        const soon = SIMRS_MODULE_CATEGORIES.map(
            (category) => category.label,
        ).filter((label) => !live.includes(label));

        for (const label of live) {
            expect(
                screen.getAllByRole('link', {
                    name: new RegExp(label, 'i'),
                }).length,
            ).toBeGreaterThan(0);
        }

        for (const label of soon) {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
            expect(
                screen.queryByRole('link', {
                    name: new RegExp(`^${label}$`, 'i'),
                }),
            ).not.toBeInTheDocument();
        }

        expect(screen.getAllByText('Soon').length).toBeGreaterThan(0);
        expect(
            screen.getAllByRole('link', { name: /Beranda/i }).length,
        ).toBeGreaterThan(0);
    });

    it('opens the mobile module menu accessibly', async () => {
        const user = userEvent.setup();

        render(<AppHeader />);

        await user.click(
            screen.getByRole('button', { name: 'Buka menu navigasi' }),
        );

        expect(
            screen.getByRole('heading', { name: 'Navigasi utama' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Tutup' })).toBeVisible();
        expect(screen.getByText('Modul berikutnya')).toBeInTheDocument();
    });

    it('activates Manajemen Data only for a permitted bed-census viewer', () => {
        appHeaderMock.capabilities = ['inpatient.occupancy.view'];

        render(<AppHeader />);

        const links = screen.getAllByRole('link', {
            name: 'Manajemen Data',
        });
        expect(links.length).toBeGreaterThan(0);

        for (const link of links) {
            expect(link).toHaveAttribute('href', '/manajemen-data/bangsal');
        }

        expect(
            screen.queryByText('Manajemen Data', { selector: 'span' }),
        ).not.toBeInTheDocument();
    });

    it('opens radiology master data for the exact radiology management capability', () => {
        appHeaderMock.capabilities = ['master.radiology.examination.manage'];

        render(<AppHeader />);

        const links = screen.getAllByRole('link', {
            name: 'Manajemen Data',
        });

        for (const link of links) {
            expect(link).toHaveAttribute('href', '/manajemen-data/radiologi');
        }
    });

    it('opens laboratory master data for the exact laboratory management capability', () => {
        appHeaderMock.capabilities = ['master.laboratory.examination.manage'];

        render(<AppHeader />);

        const links = screen.getAllByRole('link', {
            name: 'Manajemen Data',
        });

        for (const link of links) {
            expect(link).toHaveAttribute(
                'href',
                '/manajemen-data/laboratorium',
            );
        }
    });

    it('opens triage vocabulary management for the exact emergency triage master capability', () => {
        appHeaderMock.capabilities = ['master.emergency.triage.manage'];

        render(<AppHeader />);

        const links = screen.getAllByRole('link', {
            name: 'Manajemen Data',
        });

        for (const link of links) {
            expect(link).toHaveAttribute('href', '/manajemen-data/triage');
        }
    });

    it('opens Apotek for a prescription viewer', () => {
        appHeaderMock.capabilities = ['clinical.pharmacy.prescription.view'];

        render(<AppHeader />);

        const links = screen.getAllByRole('link', { name: 'Apotek' });

        for (const link of links) {
            expect(link).toHaveAttribute('href', '/apotek/resep');
        }
    });

    it('opens pharmacy master data for the exact inventory controller capability', () => {
        appHeaderMock.capabilities = ['master.pharmacy.inventory.manage'];

        render(<AppHeader />);

        for (const link of screen.getAllByRole('link', {
            name: 'Manajemen Data',
        })) {
            expect(link).toHaveAttribute('href', '/manajemen-data/apotek');
        }

        for (const link of screen.getAllByRole('link', { name: 'Apotek' })) {
            expect(link).toHaveAttribute('href', '/manajemen-data/apotek');
        }
    });

    it('opens tariff and cost-component masters for a tariff viewer', () => {
        appHeaderMock.capabilities = ['finance.tariff.view'];

        render(<AppHeader />);

        for (const link of screen.getAllByRole('link', {
            name: 'Manajemen Data',
        })) {
            expect(link).toHaveAttribute(
                'href',
                '/manajemen-data/tarif-komponen-biaya',
            );
        }
    });

    it('opens Kasir only for a bill viewer', () => {
        appHeaderMock.capabilities = ['finance.bill.view'];

        render(<AppHeader />);

        const links = screen.getAllByRole('link', { name: 'Kasir' });
        expect(links.length).toBeGreaterThan(0);

        for (const link of links) {
            expect(link).toHaveAttribute('href', '/kasir/tagihan');
        }

        expect(screen.queryByRole('link', { name: 'Apotek' })).toBeNull();
    });

    it('opens the cashier collection worklist for a collection viewer', () => {
        appHeaderMock.capabilities = ['finance.cashier-collection.view'];

        render(<AppHeader />);

        const links = screen.getAllByRole('link', { name: 'Kasir' });
        expect(links.length).toBeGreaterThan(0);

        for (const link of links) {
            expect(link).toHaveAttribute('href', '/kasir/batch-penerimaan-kas');
        }
    });

    it('prefers the cashier collection worklist when other cashier capabilities are also present', () => {
        appHeaderMock.capabilities = [
            'finance.bill.view',
            'finance.settlement-correction.view',
            'finance.cashier-collection.view',
        ];

        render(<AppHeader />);

        for (const link of screen.getAllByRole('link', { name: 'Kasir' })) {
            expect(link).toHaveAttribute('href', '/kasir/batch-penerimaan-kas');
        }
    });

    it('opens the correction worklist for a cashier supervisor', () => {
        appHeaderMock.capabilities = [
            'finance.settlement-correction.view',
            'finance.settlement-correction.review',
            'finance.settlement-refund.complete',
        ];

        render(<AppHeader />);

        const links = screen.getAllByRole('link', { name: 'Kasir' });
        expect(links.length).toBeGreaterThan(0);

        for (const link of links) {
            expect(link).toHaveAttribute('href', '/kasir/koreksi-pelunasan');
        }
    });

    it('does not activate Manajemen Data for an unrelated manage-only capability', () => {
        appHeaderMock.capabilities = ['master.inpatient.ward-bed.manage'];

        render(<AppHeader />);

        expect(
            screen.queryByRole('link', { name: 'Manajemen Data' }),
        ).not.toBeInTheDocument();
    });

    it('does not activate RM without rmik.review capability', () => {
        appHeaderMock.capabilities = ['encounter.list'];

        render(<AppHeader />);

        expect(
            screen.queryByRole('link', { name: 'RM' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByText('RM', { selector: 'span' }).length,
        ).toBeGreaterThan(0);
    });

    it('activates RM only when encounter list and RM review are both permitted', () => {
        appHeaderMock.capabilities = ['encounter.list', 'rmik.review'];

        render(<AppHeader />);

        const links = screen.getAllByRole('link', { name: 'RM' });
        expect(links.length).toBeGreaterThan(0);

        for (const link of links) {
            expect(link).toHaveAttribute('href', '/rm/rawat-jalan');
        }
    });
});
