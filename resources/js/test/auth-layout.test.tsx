import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import AuthSimpleLayout from '@/layouts/auth/auth-simple-layout';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
};

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: MockLinkProps) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        props: {
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

describe('authentication layout', () => {
    it('keeps the form title as the single page heading at every breakpoint', () => {
        render(
            <AuthSimpleLayout
                title="Masuk"
                description="Masuk ke SIMRS Campus UEU dengan akun yang diberikan."
            >
                <form aria-label="Formulir masuk" />
            </AuthSimpleLayout>,
        );

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Masuk',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('note', {
                name: 'Status operasional sistem',
            }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Mode Kampus')).not.toBeInTheDocument();
    });
});
