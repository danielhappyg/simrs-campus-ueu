import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { AppHeader } from '@/components/app-header';
import { SIMRS_MODULE_CATEGORIES } from '@/lib/simrs-modules';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
    prefetch?: boolean;
};

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        prefetch: _prefetch,
        ...props
    }: MockLinkProps) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: '/',
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Admin Demo',
                    email: 'admin@example.com',
                },
            },
            sidebarOpen: false,
            name: 'SIMRS Campus UEU',
            environment: {
                mode: 'SIMULATION',
                syntheticOnly: true,
                banner: '',
                restriction: '',
            },
        },
    }),
}));

describe('application header navigation', () => {
    it('exposes live module links and muted Soon labels without fake navigation', () => {
        render(<AppHeader />);

        expect(screen.queryByText(/simulasi/i)).not.toBeInTheDocument();

        const navs = screen.getAllByRole('navigation', {
            name: 'Navigasi modul',
        });
        expect(navs.length).toBeGreaterThanOrEqual(1);

        const live = SIMRS_MODULE_CATEGORIES.filter(
            (category) => category.live,
        );
        const soon = SIMRS_MODULE_CATEGORIES.filter(
            (category) => !category.live,
        );

        for (const category of live) {
            expect(
                screen.getAllByRole('link', {
                    name: new RegExp(category.label, 'i'),
                }).length,
            ).toBeGreaterThan(0);
        }

        for (const category of soon) {
            expect(screen.getAllByText(category.label).length).toBeGreaterThan(
                0,
            );
            expect(
                screen.queryByRole('link', {
                    name: new RegExp(`^${category.label}$`, 'i'),
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
});
