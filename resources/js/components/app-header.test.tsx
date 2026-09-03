import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AppHeader } from '@/components/app-header';
import { moduleHref, SIMRS_MODULE_CATEGORIES } from '@/lib/simrs-modules';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
    prefetch?: boolean;
};

const appHeaderMock = vi.hoisted(() => ({
    capabilities: [] as string[],
    url: '/',
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
        url: appHeaderMock.url,
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
        appHeaderMock.url = '/';
    });

    it('exposes every Sahabat module as a landing link', () => {
        render(<AppHeader />);

        expect(
            screen.getByRole('note', {
                name: 'Status operasional sistem',
            }),
        ).toHaveTextContent('Mode Kampus');

        expect(screen.queryByText('Soon')).not.toBeInTheDocument();

        for (const category of SIMRS_MODULE_CATEGORIES) {
            const links = screen.getAllByRole('link', {
                name: category.label,
            });
            expect(links.length).toBeGreaterThan(0);

            for (const link of links) {
                expect(link).toHaveAttribute('href', moduleHref(category.slug));
            }
        }
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
    });

    it('keeps RM active on an operational RM page', () => {
        appHeaderMock.url = '/rm/rawat-jalan';

        render(<AppHeader />);

        const links = screen.getAllByRole('link', { name: 'RM' });
        expect(links.some((link) => link.getAttribute('aria-current') === 'page')).toBe(
            true,
        );
    });
});
