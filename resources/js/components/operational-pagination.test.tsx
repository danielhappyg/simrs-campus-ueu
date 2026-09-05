import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { OperationalPagination } from '@/components/operational-pagination';
import type { OperationalPaginationMeta } from '@/components/operational-pagination';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
    preserveScroll?: boolean;
    preserveState?: boolean;
};

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: MockLinkProps) => {
        const { preserveScroll, preserveState, ...anchorProps } = props;
        void preserveScroll;
        void preserveState;

        return (
            <a href={href} {...anchorProps}>
                {children}
            </a>
        );
    },
}));

const middlePage: OperationalPaginationMeta = {
    current_page: 2,
    last_page: 3,
    per_page: 50,
    total: 101,
    from: 51,
    to: 100,
    prev_page_url: '/pendaftaran/rawat-jalan?encounter_page=1',
    next_page_url: '/pendaftaran/rawat-jalan?encounter_page=3',
};

describe('operational pagination', () => {
    it('announces the full range and exposes previous and next links', () => {
        render(
            <OperationalPagination
                pagination={middlePage}
                itemLabel="pendaftaran hari ini"
            />,
        );

        expect(
            screen.getByRole('navigation', {
                name: 'pendaftaran hari ini page navigation',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(/Showing 51–100 of 101/)).toHaveTextContent(
            'Page 2 of 3',
        );
        const previousLink = screen.getByRole('link', { name: 'Previous' });
        expect(previousLink).toHaveAttribute(
            'href',
            '/pendaftaran/rawat-jalan?encounter_page=1',
        );
        expect(previousLink).toHaveClass('min-h-11', 'min-w-11');
        expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
            'href',
            '/pendaftaran/rawat-jalan?encounter_page=3',
        );
    });

    it('renders unavailable directions as disabled text, not fake links', () => {
        render(
            <OperationalPagination
                pagination={{
                    ...middlePage,
                    current_page: 1,
                    from: 1,
                    to: 50,
                    prev_page_url: null,
                }}
                itemLabel="kunjungan"
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'Previous' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Previous')).toHaveAttribute(
            'aria-disabled',
            'true',
        );
        expect(screen.getByRole('link', { name: 'Next' })).toBeVisible();
    });

    it('keeps available pagination links in logical keyboard order', async () => {
        const user = userEvent.setup();

        render(
            <OperationalPagination
                pagination={middlePage}
                itemLabel="pendaftaran hari ini"
            />,
        );

        await user.tab();
        expect(screen.getByRole('link', { name: 'Previous' })).toHaveFocus();
        await user.tab();
        expect(screen.getByRole('link', { name: 'Next' })).toHaveFocus();
    });

    it.each([
        ['first', { ...middlePage, current_page: 1, prev_page_url: null }],
        ['middle', middlePage],
        [
            'last',
            {
                ...middlePage,
                current_page: 3,
                from: 101,
                to: 101,
                next_page_url: null,
            },
        ],
    ])(
        'has no detected axe violations in the %s-page state',
        async (_state, pagination) => {
            const { container } = render(
                <OperationalPagination
                    pagination={pagination}
                    itemLabel="kunjungan"
                />,
            );

            const result = await axe.run(container);

            expect(
                result.violations,
                JSON.stringify(
                    result.violations.map((violation) => ({
                        id: violation.id,
                        nodes: violation.nodes.map((node) => node.html),
                    })),
                ),
            ).toHaveLength(0);
        },
    );

    it('stays absent when there is no result set', () => {
        const { container } = render(
            <OperationalPagination pagination={null} itemLabel="kunjungan" />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
