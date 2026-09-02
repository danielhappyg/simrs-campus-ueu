import { render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import RebuildHome from '@/pages/rebuild/home';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: MockLinkProps) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        props: {
            flash: { success: null, error: null },
        },
    }),
}));

const baseProps = {
    encounters: {
        available: true,
        totals: {
            rawat_jalan: 12,
            igd: 4,
            rawat_inap: 7,
        },
        read_error: null,
    },
    occupancy: {
        available: true,
        totals: {
            active_wards: 2,
            active_beds: 15,
            occupied_beds: 7,
            available_beds: 8,
        },
        read_error: null,
    },
    actions: [
        {
            setting: 'rawat_jalan' as const,
            kind: 'examination',
            label: 'Buka pemeriksaan',
            href: '/pemeriksaan/rawat-jalan',
        },
        {
            setting: 'occupancy' as const,
            kind: 'occupancy',
            label: 'Buka sensus tempat tidur',
            href: '/manajemen-data/bangsal',
        },
    ],
};

describe('operational home', () => {
    it('shows separate RJ, IGD, and RI totals with only server-authorized actions', () => {
        render(<RebuildHome {...baseProps} />);

        expect(
            screen.getByRole('heading', {
                name: 'Satu pandangan untuk tiga area layanan',
            }),
        ).toBeInTheDocument();

        const rawatJalan = screen
            .getByRole('heading', { name: 'Rawat Jalan' })
            .closest('article');
        const igd = screen
            .getByRole('heading', { name: 'Instalasi Gawat Darurat' })
            .closest('article');
        const rawatInap = screen
            .getByRole('heading', { name: 'Rawat Inap' })
            .closest('article');

        expect(rawatJalan).not.toBeNull();
        expect(igd).not.toBeNull();
        expect(rawatInap).not.toBeNull();
        expect(within(rawatJalan!).getByText('12')).toBeInTheDocument();
        expect(within(igd!).getByText('4')).toBeInTheDocument();
        expect(within(rawatInap!).getByText('7')).toBeInTheDocument();

        expect(
            screen.getByRole('link', { name: 'Buka pemeriksaan' }),
        ).toHaveAttribute('href', '/pemeriksaan/rawat-jalan');
        expect(
            screen.queryByRole('link', { name: /review RM/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Buka sensus tempat tidur' }),
        ).toHaveAttribute('href', '/manajemen-data/bangsal');
    });

    it('presents managed occupancy totals as an aggregate definition list', () => {
        render(<RebuildHome {...baseProps} />);

        const occupancy = screen
            .getByRole('heading', { name: 'Hunian rawat inap' })
            .closest('section');

        expect(occupancy).not.toBeNull();
        expect(
            within(occupancy!).getByText('Bangsal aktif'),
        ).toBeInTheDocument();
        expect(within(occupancy!).getByText('15')).toBeInTheDocument();
        expect(within(occupancy!).getByText('7')).toBeInTheDocument();
        expect(within(occupancy!).getByText('8')).toBeInTheDocument();
    });

    it('shows explicit unavailable states instead of presenting zero as live data', () => {
        render(
            <RebuildHome
                encounters={{
                    available: false,
                    totals: {
                        rawat_jalan: null,
                        igd: null,
                        rawat_inap: null,
                    },
                    read_error:
                        'Ringkasan kunjungan belum dapat dimuat. Silakan coba lagi.',
                }}
                occupancy={{
                    available: false,
                    totals: {
                        active_wards: 0,
                        active_beds: 0,
                        occupied_beds: 0,
                        available_beds: 0,
                    },
                    read_error:
                        'Status hunian rawat inap belum dapat dimuat. Silakan coba lagi.',
                }}
                actions={[]}
            />,
        );

        expect(screen.getAllByRole('alert')).toHaveLength(2);
        expect(
            screen.getByText(
                'Ringkasan kunjungan belum dapat dimuat. Silakan coba lagi.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Status hunian rawat inap belum dapat dimuat. Silakan coba lagi.',
            ),
        ).toBeInTheDocument();
        expect(screen.getAllByText('—')).toHaveLength(3);
    });

    it('omits occupancy entirely when the role is not permitted to view it', () => {
        render(<RebuildHome {...baseProps} occupancy={null} />);

        expect(
            screen.queryByRole('heading', { name: 'Hunian rawat inap' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Buka sensus tempat tidur' }),
        ).not.toBeInTheDocument();
    });
});
