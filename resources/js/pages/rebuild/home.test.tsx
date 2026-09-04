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
    census: {
        available: true,
        by_setting: {
            rawat_jalan: {
                registered: 4,
                in_examination: 5,
                ready_for_rm: 3,
                total_active: 12,
            },
            igd: {
                registered: 1,
                in_examination: 2,
                ready_for_rm: 1,
                total_active: 4,
            },
            rawat_inap: {
                registered: 2,
                in_examination: 3,
                ready_for_rm: 2,
                total_active: 7,
            },
        },
        read_error: null,
    },
    queues: [
        {
            id: 'queue.in_exam.rj',
            label: 'Dalam pemeriksaan RJ',
            hint: 'Kunjungan poliklinik yang sedang dilayani.',
            count: 5,
            href: '/pemeriksaan/rawat-jalan',
            tone: 'navy' as const,
        },
        {
            id: 'queue.occupancy',
            label: 'Sensus tempat tidur',
            hint: 'Tempat tidur terisi pada bangsal terkelola.',
            count: 7,
            href: '/manajemen-data/bangsal',
            tone: 'slate' as const,
        },
    ],
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
            setting: 'igd' as const,
            kind: 'registration',
            label: 'Buka pendaftaran',
            href: '/pendaftaran/igd',
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
    it('shows the operate desk, census chips, and only uncovered module shortcuts', () => {
        render(<RebuildHome {...baseProps} />);

        expect(
            screen.getByRole('heading', {
                name: 'Meja kerja hari ini',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Ringkasan layanan dan antrian kerja hari ini.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Arus layanan' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Antrian kerja' }),
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
        expect(within(rawatJalan!).getByText('Terdaftar')).toBeInTheDocument();
        expect(
            within(rawatJalan!).getByText('Pemeriksaan'),
        ).toBeInTheDocument();
        expect(within(rawatJalan!).getByText('Siap RM')).toBeInTheDocument();

        expect(
            screen.getByRole('link', { name: 'Dalam pemeriksaan RJ: 5' }),
        ).toHaveAttribute('href', '/pemeriksaan/rawat-jalan');
        expect(
            screen.getByRole('link', { name: 'Sensus tempat tidur: 7' }),
        ).toHaveAttribute('href', '/manajemen-data/bangsal');
        expect(
            screen.queryByRole('link', { name: /review RM/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Buka pemeriksaan' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Buka pendaftaran' }),
        ).toHaveAttribute('href', '/pendaftaran/igd');
        expect(
            screen.queryByRole('link', { name: 'Buka sensus tempat tidur' }),
        ).not.toBeInTheDocument();
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
                census={{
                    available: false,
                    by_setting: {
                        rawat_jalan: {
                            registered: null,
                            in_examination: null,
                            ready_for_rm: null,
                            total_active: null,
                        },
                        igd: {
                            registered: null,
                            in_examination: null,
                            ready_for_rm: null,
                            total_active: null,
                        },
                        rawat_inap: {
                            registered: null,
                            in_examination: null,
                            ready_for_rm: null,
                            total_active: null,
                        },
                    },
                    read_error:
                        'Ringkasan kunjungan belum dapat dimuat. Silakan coba lagi.',
                }}
                queues={[]}
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
        expect(screen.getAllByText('—')).toHaveLength(12);
        expect(
            screen.getByText('Tidak ada antrian kerja untuk akses akun ini.'),
        ).toBeInTheDocument();
    });

    it('omits occupancy entirely when the role is not permitted to view it', () => {
        render(
            <RebuildHome
                {...baseProps}
                occupancy={null}
                queues={baseProps.queues.filter(
                    (queue) => queue.id !== 'queue.occupancy',
                )}
            />,
        );

        expect(
            screen.queryByRole('heading', { name: 'Hunian rawat inap' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Sensus tempat tidur/ }),
        ).not.toBeInTheDocument();
    });
});
